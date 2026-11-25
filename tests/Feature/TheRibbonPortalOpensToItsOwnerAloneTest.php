<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Customer;
use App\Models\CustomerPortalLink;
use App\Models\CustomerPortalSession;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\MarketingSettings;
use App\Support\RibbonPortal\Access;
use App\Support\RibbonPortal\Portal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\RibbonPortalShop;
use Tests\TestCase;

/**
 * بوابةُ RIBBON — رقمُ الهاتف يفتح صفحةَ صاحبه، ولا تُعرف إلّا في متجرها.
 *
 *   - رابطٌ واحدٌ للجميع وخانةٌ واحدة: رقمُ الهاتف المسجَّل. فتفتح مشترياتُه ويُذكر الجهاز.
 *     قرارُ المالك: لا رقمَ جهازٍ ولا اعتمادَ ولا رمزَ ولا كلمةَ مرور.
 *   - ولا حسابَ يُنشأ، ورقمٌ لا عميلَ له لا يفتح شيئًا، والمحاولاتُ محدودة.
 *   - وصفحةٌ أوقفها المتجرُ لا تُفتح برقمها حتّى يُسمح بها.
 *   - وتُغلق الجلساتُ بالإيقاف وتغيّر الرقم وحذف العميل ساعتَها.
 *   - ولا يبلغ أحدٌ فاتورةَ رقمٍ آخر بعنوانها ولا بمعرّفها.
 *   - ومتجرٌ آخر لا بابَ له ولا زرَّ ولا مفتاحَ في صفحاته — ولو كتب العنوانَ بيده.
 *   - والعملُ عليها لمن اختاره صاحبُ النشاط، ولا يفتح له شيئًا آخر، والإيقافُ لمن يدير.
 *   - ولا رسالةَ ولا قناةَ مراسلةٍ فيها.
 */
class TheRibbonPortalOpensToItsOwnerAloneTest extends TestCase
{
    use RefreshDatabase;
    use RibbonPortalShop;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-29 10:00:00');
        app()->setLocale('ar');
        Http::preventStrayRequests();
        $this->openRibbon();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(User $user): static
    {
        $this->defaultCookies = [];
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function grant(array $share, array $manage = []): void
    {
        Portal::saveStaff((int) $this->ribbon->id, $share, $manage);
    }

    private function stop(User $by, Customer $customer): TestResponse
    {
        return $this->staff($by)->deleteJson(route('admin.customers.portal.stop', $customer->id));
    }

    private function allow(User $by, Customer $customer): TestResponse
    {
        return $this->staff($by)->postJson(route('admin.customers.portal.allow', $customer->id));
    }

    private function card(User $by, Customer $customer): array
    {
        return $this->staff($by)->get(route('admin.customers.show', $customer->id))->assertOk()->viewData('page')['props']['portalCustomer'];
    }

    private function settings(User $by): array
    {
        return $this->staff($by)->get(route('admin.settings.portal.index'))->assertOk()->viewData('page')['props'];
    }

    private function at(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /* ═════════════ الرقمُ يفتح صفحةَ صاحبه ═════════════ */

    public function test_the_door_asks_for_the_phone_alone_and_shows_nothing_before_it(): void
    {
        $order = $this->order($this->maryam, 'INV-1');
        $page = $this->my()->assertOk()->getContent();

        // خانةٌ واحدةٌ ظاهرةٌ ساعةَ الفتح: رقمُ الهاتف — لا رمزَ ولا كلمةَ مرورٍ ولا حسابَ جديدًا
        $this->assertMatchesRegularExpression('/<form[^>]*data-testid="portal-login"(?![^>]*hidden)/', $page);
        $this->assertSame(1, preg_match_all('/<input\b/', $page));
        $this->assertStringContainsString('name="phone" type="tel"', $page);
        foreach (['يرجى طلب رابطك', 'رابطك الشخصي', 'رمز', 'رقم جهازك', 'اعتماد', 'فاتورتك من', 'personal link', 'code', 'مريم', 'INV-1', '91234567'] as $not) {
            $this->assertStringNotContainsString($not, (string) preg_replace('#<(style|script).*?</\1>#su', '', $page), $not);
        }
        $en = $this->visitor(null)->withCookie('rb_my_lang', 'en')->get('http://ribbon.abaadapp.om/my')->getContent();
        $this->assertStringContainsString('Your phone number registered with us', $en);
        $this->assertDoesNotMatchRegularExpression('/type="password"|register"|signup|sign up|إنشاء حساب|حساب جديد/iu', $page.$en);

        // وبلا رقمٍ لا شيءَ يُقرأ
        $this->myJson('/data')->assertStatus(401);
        $this->myJson('/points')->assertStatus(401);
        $this->my("/paper/order/{$order->id}")->assertRedirect();
        $this->my("/paper/order/{$order->id}/sheet")->assertForbidden();
        $this->my("/paper/order/{$order->id}/pdf")->assertForbidden();
    }

    public function test_the_phone_number_opens_every_purchase_at_once_and_the_device_is_remembered(): void
    {
        $this->order($this->maryam, 'INV-1');
        $this->order($this->maryam, 'INV-2', ['channel' => 'website']);

        $answer = $this->ask('91234567')->assertOk()->assertExactJson(['ok' => true]);
        $cookie = (string) $answer->getCookie(Access::COOKIE)->getValue();
        $home = $this->my('', $cookie)->assertOk()->assertSee('مريم البلوشي');
        // المشترياتُ في الصفحة نفسها: لا خطوةَ بين الرقم وبينها
        foreach (['INV-1', 'INV-2'] as $number) {
            $this->assertStringContainsString($number, $home->getContent());
        }
        $this->assertStringNotContainsString('portal-login', $home->getContent());
        // وكلَّ ثلاثين ثانية تتحدّث، فما يشتريه يظهر وحده
        $this->assertStringContainsString('setInterval(tick, 30000)', $home->getContent());
        $this->order($this->maryam, 'INV-3');
        $this->assertStringContainsString('INV-3', $this->myJson('/data?all=1', $cookie)->assertOk()->getContent());

        // والجهازُ يُذكر: يدخل بلا كتابة شيء، وكعكتُه نفسُها تُجدَّد بكلّ زيارة
        $again = $home->getCookie(Access::COOKIE);
        $this->assertSame($cookie, $again->getValue());
        $this->assertSame(now()->addDays(Access::DAYS)->timestamp, $again->getExpiresTime());
        $this->assertSame(90, Access::DAYS, 'تسعون يومًا تتجدّد بالزيارة');
        foreach ([1, 2, 3] as $visit) {
            Carbon::setTestNow(now()->addDays(Access::DAYS - 1));
            $this->myJson('/data', $cookie)->assertOk();
        }
        $this->my('', $cookie)->assertSee('مريم البلوشي');
        // ومن دخل ولم يعد: تسعون يومًا كذلك
        $unseen = $this->enter($this->maryam);
        Carbon::setTestNow(now()->addDays(Access::DAYS)->subMinute());
        $this->myJson('/data', $unseen)->assertOk();
    }

    public function test_the_number_opens_however_it_was_written_and_for_a_contact_phone(): void
    {
        foreach (['91234567', '+968 9123 4567', '00968-91234567', '٩١٢٣٤٥٦٧', ' 9123 4567 '] as $i => $written) {
            $this->my('', (string) $this->at('10.3.0.'.$i)->ask($written)->assertOk()->getCookie(Access::COOKIE)->getValue())->assertSee('مريم البلوشي');
        }
        $sara = $this->buyer('سارة', null, null, ['contact_phone' => '9222 3333']);
        $this->my('', $this->enter($sara, '92223333'))->assertSee('سارة');
        // ورقمُ تواصلٍ لمن له هاتفٌ آخر لا يفتح: صفحتُه بهاتفه
        $this->buyer('ليلى', '94440000', null, ['contact_phone' => '95550000']);
        $this->ask('95550000')->assertStatus(422);
    }

    public function test_a_number_of_no_customer_opens_nothing_and_makes_no_account(): void
    {
        $this->buyer('جارة', '99999999', $this->neighbour);
        $this->buyer('محذوفة', '98887777')->delete();

        $said = [];
        // لا عميلَ له، وعميلُ متجرٍ آخر، وعميلٌ محذوف
        foreach (['90000001', '99999999', '98887777'] as $phone) {
            $r = $this->ask($phone)->assertStatus(422);
            $this->assertNull($r->getCookie(Access::COOKIE), $phone);
            $said[] = $r->json('message');
        }
        $this->assertSame(['لم نجد مشتريات بهذا الرقم. تأكد منه، أو اسأل RIBBON عن الرقم المسجّل باسمك.'], array_values(array_unique($said)));

        // وما ليس رقمًا يُقال له
        foreach (['', 'مريم', '123', '<b>', str_repeat('9', 60), "91234567' OR '1'='1"] as $odd) {
            $this->assertNull($this->ask($odd)->assertStatus(422)->getCookie(Access::COOKIE), $odd);
        }
        $this->assertSame(1, Customer::where('business_id', $this->ribbon->id)->count(), 'ولا حسابَ يُنشأ');
        $this->assertSame([0, 0], [CustomerPortalSession::count(), CustomerPortalLink::count()]);

        // ومن سُجّل بعدها يدخل، ولو لم يشترِ بعد
        $new = $this->buyer('عميلة جديدة', '90000001');
        $this->my('', $this->enter($new))->assertSee('عميلة جديدة')->assertSee('لا توجد مشتريات مسجّلة باسمك بعد');
    }

    public function test_entering_another_number_on_the_same_device_leaves_the_first(): void
    {
        $this->order($this->maryam, 'INV-M');
        $fatima = $this->buyer('فاطمة', '92222222');
        $this->order($fatima, 'INV-F');

        $first = $this->enter($this->maryam);
        $second = (string) $this->ask('92222222', $first)->assertOk()->getCookie(Access::COOKIE)->getValue();
        $this->assertNotSame($first, $second);
        $this->my('', $second)->assertSee('فاطمة')->assertDontSee('INV-M');
        $this->myJson('/data', $first)->assertStatus(401);
        // ورقمٌ خطأ لا يُخرج من هو داخل
        $this->ask('90000001', $second)->assertStatus(422);
        $this->myJson('/data', $second)->assertOk();
    }

    public function test_another_shops_customer_and_a_twin_number_are_kept_apart(): void
    {
        $twin = $this->buyer('مريم الجار', '91234567', $this->neighbour);
        $this->order($twin, 'NEIGHBOUR-9');
        $mine = $this->order($this->maryam, 'INV-MINE', ['ordered_at' => now()->subDays(3)]);

        $seen = $this->myJson('/data', $this->enter($this->maryam))->getContent();
        $this->assertStringContainsString('INV-MINE', $seen);
        $this->assertStringNotContainsString('NEIGHBOUR-9', $seen);

        // سجلّان برقمٍ واحد في المتجر نفسه لا يُدمجان: يُفتح الذي اشترى آخرًا
        $same = $this->buyer('مريم الثانية', null, null, ['contact_phone' => '91234567']);
        $this->assertStringContainsString('INV-MINE', $this->myJson('/data', $this->enter($this->maryam))->getContent(), 'سجلٌّ لم يشترِ لا يحجب من اشترى');
        $this->order($same, 'INV-SECOND', ['ordered_at' => now()->subDay()]);
        $cookie = $this->enter($this->maryam);
        $this->assertStringContainsString('INV-SECOND', $this->myJson('/data', $cookie)->getContent());
        $this->assertStringNotContainsString('INV-MINE', $this->myJson('/data', $cookie)->getContent());
        $this->my("/paper/order/{$mine->id}", $cookie)->assertNotFound();
        $this->order($this->maryam, 'INV-LATEST', ['ordered_at' => now()]);
        $this->assertStringContainsString('INV-LATEST', $this->myJson('/data', $this->enter($this->maryam))->getContent());
        $this->assertSame(2, Customer::where('business_id', $this->ribbon->id)->count());
    }

    public function test_attempts_are_limited_per_number_per_address_and_for_unknown_numbers(): void
    {
        // خمسةٌ للرقم من عنوانٍ واحد في ربع ساعة، بأيّ صيغةٍ كُتب
        for ($i = 1; $i <= 5; $i++) {
            $this->ask('91234567')->assertOk();
        }
        $blocked = $this->ask('91234567')->assertStatus(429);
        $this->assertGreaterThan(0, $blocked->json('wait'));
        $this->assertStringContainsString('محاولات كثيرة', (string) $blocked->json('message'));
        $this->assertNull($blocked->getCookie(Access::COOKIE));
        $this->ask('+968 91234567')->assertStatus(429);
        // ومن عنوانٍ آخر يُقبل: غريبٌ لا يُغلق البابَ على صاحب الرقم. ومن دخل من قبل لا يُسأل
        $this->at('10.9.8.7')->ask('91234567')->assertOk();
        $this->at('127.0.0.1');
        Carbon::setTestNow(now()->addMinutes(16));
        $this->ask('91234567')->assertOk();

        // وعشرون للرقم في اليوم ولو تعدّدت عناوينُها
        $this->buyer('هند', '95550000');
        for ($i = 0; $i < 20; $i++) {
            $this->at('10.1.1.'.$i)->ask('95550000')->assertOk();
        }
        $this->at('10.1.2.1')->ask('95550000')->assertStatus(429);
        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->at('10.1.2.1')->ask('95550000')->assertOk();

        // وأرقامٌ لا عميلَ لها: عشرةٌ من عنوانٍ واحد في الساعة، ثمّ لا يُفتح منه ولا رقمٌ مسجَّل
        for ($i = 1; $i <= 10; $i++) {
            $this->at('10.2.2.2')->ask('9600'.str_pad((string) $i, 4, '0', STR_PAD_LEFT))->assertStatus(422);
        }
        $this->at('10.2.2.2')->ask('97770000')->assertStatus(429);
        $this->at('10.2.2.2')->ask('91234567')->assertStatus(429);
        Carbon::setTestNow(now()->addMinutes(61));
        $this->at('10.2.2.2')->ask('91234567')->assertOk();

        // ورقمٌ لا عميلَ له يُحسب مرّةً مع غيره: خمسٌ كالمسجَّل لا ثلاث
        for ($i = 1; $i <= 5; $i++) {
            $this->at('10.2.3.3')->ask('96009999')->assertStatus(422);
        }
        $this->at('10.2.3.3')->ask('96009999')->assertStatus(429);

        // وأرقامٌ مسجَّلة: عشرون من عنوانٍ واحد في الساعة، وستّون في اليوم — فلا تُفتح صفحاتُ قائمة
        $phones = array_map(fn (int $i) => (string) (92000000 + $i), range(1, 62));
        foreach ($phones as $i => $phone) {
            $this->buyer('عميل '.$i, $phone);
        }
        $opened = fn (array $list) => array_map(fn ($p) => $this->at('10.4.4.4')->ask($p)->getStatusCode(), $list);
        $this->assertSame([200], array_values(array_unique($opened(array_slice($phones, 0, 20)))));
        $this->assertSame([429], $opened([$phones[20]]));
        foreach ([20, 40] as $start) {
            Carbon::setTestNow(now()->addMinutes(61));
            $this->assertSame([200], array_values(array_unique($opened(array_slice($phones, $start, 20)))));
        }
        Carbon::setTestNow(now()->addMinutes(61));
        $this->assertSame([429], $opened([$phones[60]]));
        Carbon::setTestNow(now()->addDay());
        $this->assertSame([200], $opened([$phones[60]]));
        $this->at('127.0.0.1');

        // ولا يُكتب الرقمُ في العدّاد
        $keys = (new \ReflectionMethod(Access::class, 'keys'))->invoke(null, '96891234567', Request::create('/my'));
        $this->assertStringNotContainsString('91234567', implode(' ', $keys));

        foreach (['my.login', 'my.logout', 'my.data', 'my.points', 'my.paper', 'my.paper.sheet', 'my.paper.pdf', 'my.index', 'admin.customers.portal.stop', 'admin.customers.portal.allow'] as $name) {
            $this->assertNotEmpty(array_filter(Route::getRoutes()->getByName($name)->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $name);
        }
    }

    /* ═════════════ الإيقاف ═════════════ */

    public function test_a_stopped_customer_is_out_of_every_device_and_the_number_opens_nothing(): void
    {
        $first = $this->enter($this->maryam);
        $second = $this->enter($this->maryam);
        $this->assertSame(1, CustomerPortalLink::where('customer_id', $this->maryam->id)->whereNull('revoked_at')->count(), 'إذنٌ واحدٌ لأجهزتها');

        $this->stop($this->owner, $this->maryam)->assertOk()->assertJsonPath('state.stopped', true)->assertJsonPath('state.devices', 0);
        foreach ([$first, $second] as $cookie) {
            $this->myJson('/data', $cookie)->assertStatus(401);
            $this->my('', $cookie)->assertDontSee('مريم')->assertCookieExpired(Access::COOKIE);
        }
        $this->assertSame(0, CustomerPortalSession::whereNull('revoked_at')->count(), 'وتُغلق جلساتُها نفسُها لا إذنُها وحده');
        // ورقمُها لا يفتح، ولا يُقال لمن كتبه إنّها موقوفة
        $closed = $this->ask('91234567')->assertStatus(422);
        $this->assertSame($this->ask('90000001')->json('message'), $closed->json('message'));
        $this->assertNull($closed->getCookie(Access::COOKIE));
        // وإيقافٌ ثانٍ لا يكتب صفًّا ثانيًا
        $this->stop($this->owner, $this->maryam)->assertOk();
        $this->assertSame(1, CustomerPortalLink::where('customer_id', $this->maryam->id)->count());
        $this->assertSame([(int) $this->owner->id, Access::STOPPED], array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, (array) DB::table('customer_portal_links')->first(['revoked_by', 'revoked_why']))));

        // ورقمٌ جديدٌ لها لا يفتح وهي موقوفة، ثمّ يُسمح بها فيفتح — والقديمُ من أجهزتها لا يعود
        $this->maryam->update(['phone' => '95550000']);
        $this->ask('95550000')->assertStatus(422);
        $this->allow($this->owner, $this->maryam)->assertOk()->assertJsonPath('state.stopped', false);
        $this->my('', $this->enter($this->maryam, '95550000'))->assertSee('مريم');
        $this->myJson('/data', $first)->assertStatus(401);

        // ومن لم يدخل بعدُ يُوقَف كذلك
        $sara = $this->buyer('سارة', '92223333');
        $this->stop($this->owner, $sara)->assertOk()->assertJsonPath('state.stopped', true)->assertJsonPath('state.since', null);
        $this->ask('92223333')->assertStatus(422);
        $this->my('', $this->enter($this->maryam, '95550000'))->assertSee('مريم');
        $rows = $this->settings($this->owner)['links'];
        $this->assertSame([['مريم البلوشي', false], ['سارة', true]], array_map(fn ($r) => [$r['customer'], $r['stopped']], $rows));
        $this->assertNotNull($rows[0]['since']);
        $this->assertNull($rows[1]['since'], 'أُوقفت ولم تدخل');

        // وإذنٌ حيٌّ واحدٌ لكلّ عميل — تحرسه القاعدة
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('customer_portal_links')->insert(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'token_hash' => str_repeat('a', 64), 'nonce' => str_repeat('b', 64), 'phone_hash' => str_repeat('c', 64)]);
    }

    public function test_an_entry_a_moment_before_the_stop_closes_at_its_next_request(): void
    {
        // دخولٌ قرأ «غيرَ موقوف» ثمّ كُتب إذنُه بعد أن أوقفها الموظّف
        $this->stop($this->owner, $this->maryam)->assertOk();
        $link = CustomerPortalLink::create(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'token_hash' => str_repeat('a', 64), 'nonce' => str_repeat('b', 64), 'phone_hash' => Access::phoneHash('96891234567')]);
        CustomerPortalSession::create(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'link_id' => $link->id, 'token_hash' => Access::hash(str_repeat('c', 64)), 'expires_at' => now()->addDays(90)]);

        $this->myJson('/data', str_repeat('c', 64))->assertStatus(401);
        $this->assertNotNull($link->fresh()->revoked_at);
        $this->assertNotNull(CustomerPortalSession::value('revoked_at'));
        // ولا تظهر في الإعدادات مرّتين
        $this->assertCount(1, $this->settings($this->owner)['links']);
        $this->allow($this->owner, $this->maryam)->assertOk();
        $this->my('', $this->enter($this->maryam))->assertSee('مريم');
    }

    public function test_a_changed_number_or_a_deleted_customer_closes_everything_at_once(): void
    {
        $this->order($this->maryam, 'INV-P');
        $cookie = $this->enter($this->maryam);

        $this->maryam->update(['phone' => '95550000']);
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->assertSame(Access::REVOKED_PHONE, CustomerPortalLink::latest('id')->value('revoked_why'));
        // ورقمُها القديم لا يفتح صفحتَها
        $this->ask('91234567')->assertStatus(422);
        $cookie = $this->enter($this->maryam, '95550000');
        $this->my('', $cookie)->assertSee('مريم');
        // وتغيّر ولم يُطلب شيءٌ بينهما: الجديدُ يفتح من أوّل مرّة، والإذنُ القديم أُغلق
        $this->maryam->update(['phone' => '95550001']);
        $this->assertNull($this->card($this->owner, $this->maryam)['since']);
        $this->maryam->update(['phone' => '95550002']);
        $cookie = $this->enter($this->maryam, '95550002');
        $this->my('', $cookie)->assertOk()->assertSee('مريم');
        $this->maryam->update(['phone' => '95550000']);
        $cookie = $this->enter($this->maryam, '95550000');
        $this->my('', $cookie)->assertOk()->assertSee('مريم');

        $this->maryam->delete();
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->assertSame(Access::REVOKED_CUSTOMER, CustomerPortalLink::latest('id')->value('revoked_why'));
        $this->my('', $cookie)->assertDontSee('مريم');
        $this->ask('95550000')->assertStatus(422);
        $this->allow($this->owner, $this->maryam)->assertNotFound();

        // ورقمٌ كُتب بصيغةٍ أخرى ليس رقمًا تغيّر
        $sara = $this->buyer('سارة', '92223333');
        $cookie = $this->enter($sara);
        $sara->update(['phone' => '+968 9222 3333']);
        $this->myJson('/data', $cookie)->assertOk();
        // وسجلٌّ صار لمتجرٍ آخر لا يُقرأ من هنا
        $sara->forceFill(['business_id' => $this->neighbour->id])->save();
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->assertSame(Access::REVOKED_CUSTOMER, CustomerPortalLink::latest('id')->value('revoked_why'));
    }

    /* ═════════════ لا رمزَ ولا رابطَ لعميل ═════════════ */

    public function test_there_is_no_code_no_device_number_no_personal_link_and_no_password(): void
    {
        foreach (['admin.customers.portal.share', 'admin.customers.portal.reissue', 'admin.customers.portal.code', 'admin.customers.portal.device', 'admin.customers.portal.dismiss',
            'admin.customers.portal.waiting', 'pos.orders.portal', 'my.same', 'my.wait', 'my.again'] as $gone) {
            $this->assertNull(Route::getRoutes()->getByName($gone), $gone);
        }
        $this->myJson('/wait')->assertNotFound();
        $this->assertContains($this->visitor(null)->postJson('http://ribbon.abaadapp.om/my/again')->getStatusCode(), [404, 405]);
        // ولا نصَّ لها في صفحات العميل
        $code = collect([...glob(resource_path('views/portal/ribbon/*.php')), app_path('Support/RibbonPortal/Texts.php')])->map(fn ($f) => file_get_contents($f))->implode("\n");
        $this->assertDoesNotMatchRegularExpression('/رقم جهاز|بقيت خطوة|بانتظار اعتماد|device number|portal-wait/u', $code);

        // ولا أثرَ لها على ورقة: الفاتورةُ والإيصالُ وفاتورةُ العميل كما كانت
        $order = $this->order($this->maryam, 'INV-PAPER');
        $invoice = CustomerInvoices::issue(CustomerInvoices::create((int) $this->ribbon->id, $this->maryam, [], [['description' => 'تنسيق', 'quantity' => 1, 'unit_price' => 9]], $this->owner->id), $this->owner->id)->fresh();
        foreach ([
            PdfController::saleHtml((int) $this->ribbon->id, $order)['html'], PdfController::saleHtml((int) $this->ribbon->id, $order, thermal: true)['html'],
            PdfController::customerInvoiceHtml((int) $this->ribbon->id, $invoice),
        ] as $html) {
            $this->assertDoesNotMatchRegularExpression('#رمز الدخول|data-portal|ribbon\.abaadapp\.om/my#u', $html);
        }
        foreach (File::allFiles(resource_path('views/documents')) as $file) {
            $this->assertStringNotContainsStringIgnoringCase('portal', $file->getContents(), $file->getRelativePathname());
        }

        // وجهازٌ كان ينتظر الاعتمادَ قبل هذا لا يفتح شيئًا: يُطلب رقمُه كسواه
        DB::table('customer_portal_requests')->insert(['business_id' => $this->ribbon->id, 'phone_hash' => Access::phoneHash('96891234567'), 'token_hash' => Access::hash(str_repeat('e', 64)), 'expires_at' => now()->addMinutes(9), 'created_at' => now(), 'updated_at' => now()]);
        $this->my('', str_repeat('e', 64))->assertOk()->assertSee('portal-login', false)->assertDontSee('مريم')->assertCookieExpired(Access::COOKIE);
        // ومن دخل باعتمادٍ قبل هذا يبقى داخلًا
        $link = CustomerPortalLink::create(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'token_hash' => str_repeat('a', 64), 'nonce' => str_repeat('b', 64), 'phone_hash' => Access::phoneHash('96891234567'), 'last_used_at' => now()]);
        CustomerPortalSession::create(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'link_id' => $link->id, 'token_hash' => Access::hash(str_repeat('f', 64)), 'last_seen_at' => now(), 'expires_at' => now()->addDays(90)]);
        $this->my('', str_repeat('f', 64))->assertSee('مريم');
    }

    /* ═════════════ فاتورةُ غيره ═════════════ */

    public function test_no_one_reaches_anothers_invoice_by_its_number_or_id(): void
    {
        $fatima = $this->buyer('فاطمة', '92222222');
        $order = $this->order($fatima, 'INV-F');
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->ribbon->id, $fatima, [], [['description' => 'تنسيق', 'quantity' => 1, 'unit_price' => 9]], $this->owner->id), $this->owner->id);
        $nb = $this->order($this->buyer('جارة', '93333333', $this->neighbour), 'NB-1');
        $mine = $this->order($this->maryam, 'INV-M');
        $held = $this->order($this->maryam, 'HELD', ['is_held' => true]);
        $draft = CustomerInvoices::create($this->ribbon->id, $this->maryam, [], [['description' => 'مسودة', 'quantity' => 1, 'unit_price' => 5]], $this->owner->id);

        $cookie = $this->enter($this->maryam);
        foreach (['', '/sheet', '/pdf', '/pdf?download=1'] as $tail) {
            $this->my("/paper/order/{$mine->id}{$tail}", $cookie)->assertOk();
            foreach (["order/{$order->id}", "invoice/{$invoice->id}", "order/{$nb->id}", "order/{$held->id}", "invoice/{$draft->id}", 'order/999999', "invoice/{$mine->id}000"] as $not) {
                $this->my("/paper/{$not}{$tail}", $cookie)->assertNotFound();
            }
        }
        foreach (['/paper/order/INV-F', '/paper/order/0', '/paper/user/1', '/paper/order/1e3', '/paper/order/99999999999'] as $odd) {
            $this->my($odd, $cookie)->assertNotFound();
        }
        $this->assertSame([], $this->myJson('/data?q=INV-F', $cookie)->json('rows'));
        $this->assertSame([], $this->myJson('/data?customer_id='.$fatima->id.'&q=INV-F&business_id='.$this->neighbour->id, $cookie)->json('rows'));

        // ورابطُ التحقّق العامّ على ورق المتجر كما كان لا يُبنى منه بابٌ هنا
        $this->my('/paper/order/'.$mine->number, $cookie)->assertNotFound();
    }

    /* ═════════════ الجلسةُ والترويسات ═════════════ */

    public function test_the_session_is_its_own_and_nothing_is_stored_or_indexed(): void
    {
        $cookie = $this->ask('91234567')->getCookie(Access::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/my', $cookie->getPath());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain(), 'لمضيف البوابة وحده');

        $raw = (string) $cookie->getValue();
        // ولا يُحفظ رمزُ الجهاز ولا رقمُ الهاتف
        $kept = (string) json_encode([DB::table('customer_portal_sessions')->get(), DB::table('customer_portal_links')->get(), DB::table('activity_logs')->get()]);
        foreach ([$raw, '91234567'] as $not) {
            $this->assertStringNotContainsString($not, $kept);
        }
        foreach (['', '/data', '/points'] as $path) {
            $r = $this->my($path, $raw);
            $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'), $path);
            $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'), $path);
        }
        $this->assertStringNotContainsString('public', (string) $this->my('/logo', $raw)->assertOk()->headers->get('Cache-Control'));
        $page = $this->my('', $raw);
        $csp = (string) $page->headers->get('Content-Security-Policy');
        foreach (["default-src 'none'", "connect-src 'self'", "frame-ancestors 'self'"] as $rule) {
            $this->assertStringContainsString($rule, $csp);
        }
        $this->assertStringNotContainsString('unsafe-inline', explode('style-src', $csp)[0], 'لا نصَّ من غير الصفحة');
        $html = $page->getContent();
        $this->assertStringContainsString('<meta name="referrer" content="no-referrer">', $html);
        foreach (['googletagmanager', 'google-analytics', 'gtag(', 'fbq(', 'snap', 'http://', 'https://'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $html, 'لا أداةَ خارجيّةً ولا رابطَ خارجًا: '.$foreign);
        }
        // والصفحةُ بلا متجر: لا سلّةَ ولا أصنافَ ولا قائمة
        foreach (['/cart', '/shop', '/checkout', 'rb-head'] as $store) {
            $this->assertStringNotContainsString($store, $html);
        }

        // كعكةُ البوابة ليست دخولًا إلى اللوحة، ودخولُ اللوحة ليس دخولًا إلى البوابة
        $this->visitor($raw)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->staff($this->owner)->getJson('http://ribbon.abaadapp.om/my/data')->assertStatus(401);

        // وتنقضي، ويُخرج منها
        $this->visitor($raw)->post('http://ribbon.abaadapp.om/my/logout')->assertRedirect()->assertCookieExpired(Access::COOKIE);
        $this->myJson('/data', $raw)->assertStatus(401);
        $again = $this->enter($this->maryam);
        $this->myJson('/data', $again)->assertOk();
        Carbon::setTestNow(now()->addDays(Access::DAYS + 1));
        $this->myJson('/data', $again)->assertStatus(401);
        $this->myJson('/data', str_repeat('f', 64))->assertStatus(401);
    }

    public function test_the_cookie_belongs_to_the_portals_own_host(): void
    {
        // على https: `__Host-` بمسار الجذر وبلا نطاق — لا يكتبها المتصفّحُ لصفحةٍ على نطاقٍ شقيق
        $secure = Request::create('https://ribbon.abaadapp.om/my');
        $cookie = Access::cookie($secure, str_repeat('a', 64));
        $this->assertSame(['__Host-rb_my', '/', null, true, true, 'lax'], [$cookie->getName(), $cookie->getPath(), $cookie->getDomain(), $cookie->isSecure(), $cookie->isHttpOnly(), $cookie->getSameSite()]);

        // ومن دخل قبل أن يتغيّر الاسمُ يبقى داخلًا، وتُكتب له بالاسم الجديد
        $raw = $this->enter($this->maryam);
        $old = Request::create('https://ribbon.abaadapp.om/my', cookies: ['rb_my' => $raw]);
        $session = Access::session((int) $this->ribbon->id, $old);
        $this->assertNotNull($session);
        $this->assertSame($raw, Access::held($old, $session));
        $both = Request::create('https://ribbon.abaadapp.om/my', cookies: ['__Host-rb_my' => str_repeat('b', 64), 'rb_my' => $raw]);
        $this->assertSame($raw, Access::held($both, Access::session((int) $this->ribbon->id, $both)));
        // ولا يُقرأ متجرٌ آخر بها
        $this->assertNull(Access::session((int) $this->neighbour->id, $old));
    }

    /* ═════════════ متجرٌ واحد ═════════════ */

    public function test_it_exists_on_ribbons_domain_alone(): void
    {
        $cookie = $this->enter($this->maryam);
        foreach (['http://neighbour.abaadapp.om/my', 'http://app.abaadapp.om/my', 'http://abaadapp.om/my', 'http://www.abaadapp.om/my', 'http://ribbon.abaadapp.om.evil.com/my', 'http://shop.example.com/my', 'http://neighbour.abaadapp.om/my/data'] as $elsewhere) {
            $this->visitor($cookie)->get($elsewhere)->assertNotFound();
        }

        // والقفلُ يُغلق كلَّ باب
        config(['storefront.ribbon_customer_portal.enabled' => false]);
        $this->my('', $cookie)->assertNotFound();
        $this->myJson('/data', $cookie)->assertNotFound();
        $this->ask('91234567')->assertNotFound();
        $this->stop($this->owner, $this->maryam)->assertNotFound();
        $this->allow($this->owner, $this->maryam)->assertNotFound();
        $this->staff($this->owner)->get(route('admin.settings.portal.index'))->assertNotFound();
        config(['storefront.ribbon_customer_portal.enabled' => true]);
        $this->my('', $cookie)->assertOk();

        // واسمُ النطاق حمله متجرٌ آخر خطأً، أو المعرّفُ لغير صاحب النطاق: لا بوابة
        $this->ribbon->forceFill(['site_slug' => 'ribbon-shop'])->save();
        $this->my('', $cookie)->assertOk();
        $this->neighbour->forceFill(['site_slug' => 'ribbon'])->save();
        $this->my('', $cookie)->assertNotFound();
        $this->neighbour->forceFill(['site_slug' => 'neighbour'])->save();
        config(['storefront.ribbon_customer_portal.business' => (int) $this->neighbour->id]);
        $this->my('', $cookie)->assertNotFound();
        config(['storefront.ribbon_customer_portal.business' => [(int) $this->ribbon->id]]);
        $this->assertNull(Portal::businessId(), 'معرّفٌ واحدٌ لا قائمة');
    }

    public function test_the_shipped_setting_is_saud_alone_and_no_platform_switch_exists(): void
    {
        $shipped = (require base_path('config/storefront.php'))['ribbon_customer_portal'];
        $this->assertSame(5, $shipped['business']); // رقمٌ مقصود: متجرُ سعود في config/storefront.php
        $this->assertSame('ribbon', (require base_path('config/storefront.php'))['subdomain_businesses'][5]); // رقمٌ مقصود

        $read = fn (string $dir) => collect(File::allFiles(base_path($dir)))->map(fn ($f) => [$f->getRelativePathname(), $f->getContents()]);
        foreach ($read('app/Http/Controllers/SuperAdmin')->merge($read('resources/js/Pages/Platform')) as [$name, $code]) {
            $this->assertStringNotContainsStringIgnoringCase('RibbonPortal', $code, $name);
        }
        $this->assertStringNotContainsString('ribbon_customer_portal', (string) file_get_contents(app_path('Support/PlanFeatures.php')));
        // ولا تُسأل الواجهةُ ولا الاسم
        foreach ($read('app/Support/RibbonPortal') as [$name, $code]) {
            $this->assertDoesNotMatchRegularExpression('/SERVES_THEME|store_theme|->name\s*===|business_id\s*===\s*5/', $code, $name);
        }
    }

    public function test_another_shop_has_no_button_no_key_and_no_door(): void
    {
        $them = $this->staffer('مالك الجار', 'admin', null, $this->neighbour);
        $theirs = $this->buyer('عميل الجار', '94444444', $this->neighbour);

        foreach ([route('admin.customers.index') => 'portal', route('admin.customers.show', $theirs->id) => 'portalCustomer', route('admin.settings.index') => 'portalUrl'] as $screen => $key) {
            $this->assertArrayNotHasKey($key, $this->staff($them)->get($screen)->assertOk()->viewData('page')['props'], $screen);
        }
        foreach ([
            ['delete', route('admin.customers.portal.stop', $theirs->id)], ['post', route('admin.customers.portal.allow', $theirs->id)],
            ['delete', route('admin.customers.portal.stop', $this->maryam->id)], ['post', route('admin.customers.portal.allow', $this->maryam->id)], ['get', route('admin.customers.portal.poster')], ['get', route('admin.settings.portal.index')],
            ['post', route('admin.settings.portal.staff')], ['delete', route('admin.settings.portal.signOut')], ['delete', route('admin.settings.portal.link', 1)], ['post', route('admin.settings.portal.allow', 1)],
        ] as [$verb, $url]) {
            $this->staff($them)->json(strtoupper($verb), $url)->assertNotFound();
        }
        $this->assertSame([0, 0], [CustomerPortalLink::count(), CustomerPortalSession::count()]);

        // ولا اسمَ ولا عنوانَ لها في صفحاته — ولا في جدول المسارات الذي يحمله متصفّحُه
        foreach ([route('admin.customers.index'), route('admin.settings.index'), route('admin.customers.show', $theirs->id), route('pos.index')] as $screen) {
            $html = $this->staff($them)->get($screen)->getContent();
            $this->assertStringNotContainsString('.portal.', $html, $screen);
            $this->assertStringNotContainsString('ribbon.abaadapp.om', $html, $screen);
        }

        // ولا يكتب لنفسه إعدادَها فتُفتح
        Setting::create(['business_id' => $this->neighbour->id, 'key' => Portal::STAFF, 'value' => json_encode(['share' => [$them->id], 'manage' => [$them->id]])]);
        $this->assertFalse(Portal::mayShare($them));
        $this->assertFalse(Portal::mayManage($them));
    }

    /* ═════════════ من يعمل عليها ═════════════ */

    public function test_the_owner_and_whoever_they_named_see_it_and_the_rest_do_not(): void
    {
        $clerk = $this->staffer('موظفة', 'sales', ['customers', 'pos']);
        $till = $this->staffer('كاشير', 'cashier');
        $plain = $this->staffer('بائع', 'sales', ['customers', 'pos', 'orders', 'settings']);
        $this->grant([$clerk->id, $till->id, $this->staffer('غريب', 'sales', null, $this->neighbour)->id]);
        $this->assertSame(['share' => [$clerk->id, $till->id], 'manage' => []], Portal::staff((int) $this->ribbon->id), 'من موظّفي المتجر وحدهم');

        foreach ([[$this->owner, true], [$clerk, true], [$plain, false]] as [$user, $may]) {
            $index = $this->staff($user)->get(route('admin.customers.index'))->assertOk()->viewData('page')['props'];
            $show = $this->staff($user)->get(route('admin.customers.show', $this->maryam->id))->assertOk()->viewData('page')['props'];
            $this->assertSame($may, isset($index['portal']), $user->name);
            $this->assertSame($may, isset($show['portalCustomer']), $user->name);
            $this->staff($user)->get(route('admin.customers.portal.poster'))->assertStatus($may ? 200 : 403);
            $this->stop($user, $this->maryam)->assertStatus($user === $this->owner ? 200 : 403);
            $this->allow($user, $this->maryam)->assertStatus($user === $this->owner ? 200 : 403);
            if ($may) {
                $this->assertSame('https://ribbon.abaadapp.om/my', $index['portal']['url']);
                $this->assertSame('https://ribbon.abaadapp.om/my', $show['portalCustomer']['url'], 'رابطٌ واحدٌ لكلّ عميل');
            }
        }

        // ولا تفتح الفواتيرَ ولا النقاطَ ولا الإعدادات
        $this->assertFalse($clerk->allows('orders'));
        $this->staff($clerk)->get(route('admin.customerInvoices.index'))->assertForbidden();
        $this->staff($clerk)->postJson(route('admin.customers.points.adjust', $this->maryam->id), ['direction' => 'add', 'points' => 5, 'reason' => 'x'])->assertForbidden();
        $this->staff($clerk)->get(route('admin.settings.portal.index'))->assertForbidden();
        $this->assertSame(['customers', 'pos'], $clerk->fresh()->permissions, 'صلاحيّاتُه كما كانت');

    }

    public function test_only_a_manager_stops_and_allows_a_customer_and_it_is_logged(): void
    {
        $clerk = $this->staffer('موظفة', 'sales', ['customers']);
        $manager = $this->staffer('مديرة', 'sales', ['customers']);
        $this->grant([$clerk->id], [$manager->id]);

        $this->assertNull($this->card($clerk, $this->maryam)['since']);
        $cookie = $this->enter($this->maryam);
        $this->assertNull($this->card($clerk, $this->maryam)['since'], 'دخل ولم تُرسم صفحتُه بعد');
        $this->my('', $cookie)->assertOk();
        $card = $this->card($clerk, $this->maryam);
        $this->assertSame(['2026-11-29 10:00', '2026-11-29 10:00', 1, false, null, '91234567'], [$card['since'], $card['used'], $card['devices'], $card['stopped'], $card['manage'], $card['phone']]);
        foreach (['stop', 'allow'] as $act) {
            $this->{$act}($clerk, $this->maryam)->assertForbidden();
        }
        $this->myJson('/data', $cookie)->assertOk();

        foreach ([$manager, $this->owner] as $user) {
            $cookie = $this->enter($this->maryam);
            $this->assertNotNull($this->card($user, $this->maryam)['manage'], $user->name);
            DB::table('activity_logs')->delete();
            $stopped = $this->stop($user, $this->maryam)->assertOk()->assertJsonPath('state.since', null)->assertJsonPath('state.stopped', true);
            $this->assertStringContainsString('no-store', (string) $stopped->headers->get('Cache-Control'));
            $this->myJson('/data', $cookie)->assertStatus(401);
            $this->assertTrue($this->card($user, $this->maryam)['stopped']);
            $this->allow($user, $this->maryam)->assertOk()->assertJsonPath('state.stopped', false);
            $log = DB::table('activity_logs')->where('subject_id', $this->maryam->id)->orderBy('id')->get();
            $this->assertSame(['أوقف دخول العميل إلى بوابته — مريم البلوشي', 'سمح بدخول العميل إلى بوابته — مريم البلوشي'], $log->pluck('description')->all());
            $this->assertSame([(int) $user->id], $log->pluck('user_id')->map(fn ($i) => (int) $i)->unique()->values()->all());
        }

        // وعميلُ متجرٍ آخر ليس هنا
        $theirs = $this->buyer('جار', '97777777', $this->neighbour);
        $this->stop($this->owner, $theirs)->assertNotFound();
        $this->allow($this->owner, $theirs)->assertNotFound();
        $this->assertNull($this->card($this->owner, $this->buyer('بلا رقم', null))['phone']);
    }

    public function test_it_is_tied_to_no_message_channel(): void
    {
        // رقمُ واتساب المتجر محفوظٌ وربطُه كما هو — ولا أثرَ له في البوابة
        MarketingSettings::save((int) $this->ribbon->id, 'website', ['store_whatsapp' => '90001122']);
        Queue::fake();
        Mail::fake();
        Notification::fake();
        $tables = array_values(array_filter(DB::connection()->getSchemaBuilder()->getTableListing(schemaQualified: false), fn ($t) => preg_match('/whatsapp|notification|message/', $t)));
        $this->assertContains('whatsapp_messages', $tables);
        $count = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $before = $count();

        $answers = [
            $this->ask('91234567')->assertOk(),
            $this->ask('90000001')->assertStatus(422),
            $this->staff($this->owner)->get(route('admin.customers.portal.poster'))->assertOk(),
            $this->my()->assertOk(),
            $this->my('', $this->enter($this->maryam))->assertOk(),
            $this->stop($this->owner, $this->maryam)->assertOk(),
            $this->allow($this->owner, $this->maryam)->assertOk(),
        ];
        foreach ($answers as $i => $answer) {
            $this->assertDoesNotMatchRegularExpression('/whatsapp|wa\\.me|واتساب|90001122|mailto:|sms:|tel:/iu', $answer->getContent(), 'الردّ '.$i);
        }
        $this->assertDoesNotMatchRegularExpression('/href="(https?:)?\\/\\//', $this->my()->getContent());

        $this->assertSame($before, $count(), 'لا صفَّ في جداول المراسلة');
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Http::assertNothingSent();

        // ولا ذكرَ لواتساب في كودها — سوى قاعدةِ النظام في قراءة رقم الهاتف، وهي حسابُ نصٍّ لا ربط
        $files = [
            ...glob(app_path('Support/RibbonPortal/*.php')), ...glob(resource_path('views/portal/ribbon/*.php')), ...glob(app_path('Models/CustomerPortal*.php')),
            app_path('Http/Controllers/Admin/RibbonPortalController.php'), app_path('Http/Controllers/Store/RibbonPortalController.php'), app_path('Http/Middleware/RibbonPortalGate.php'),
            ...glob(resource_path('js/Components/RibbonPortal*.tsx')), resource_path('js/lib/ribbon-portal.ts'), resource_path('js/Pages/Admin/Settings/CustomerPortal.tsx'),
        ];
        $this->assertGreaterThan(17, count($files));
        foreach ($files as $file) {
            $code = str_replace(['use App\\Support\\WhatsAppPhone;', '(`WhatsAppPhone`)', 'WhatsAppPhone::normalize('], '', (string) file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression('/whatsapp|wa\\.me|واتساب|mailto:|sms:/iu', $code, basename($file));
        }
    }

    public function test_the_till_gives_the_one_link_after_a_registered_customers_sale(): void
    {
        $till = $this->staffer('كاشير', 'cashier');
        $sell = fn (User $by, array $over = []) => $this->staff($by)->withSession(['current_branch' => $this->branch->id])->postJson(route('pos.checkout'), $over + [
            'items' => [['id' => $this->bouquet->id, 'name' => 'باقة', 'qty' => 1]], 'payment_method' => 'نقدي', 'client_uuid' => uniqid('p', true),
        ])->assertOk()->json();

        $cookie = $this->enter($this->maryam);
        $plain = $sell($till, ['customer_id' => $this->maryam->id]);
        $this->assertArrayNotHasKey('portal', $plain, 'كاشيرٌ لم يُختر');

        $this->grant([$till->id]);
        $this->assertArrayNotHasKey('portal', $sell($till), 'بيعةٌ بلا عميل');
        $sold = $sell($till, ['customer_id' => $this->maryam->id]);
        $this->assertSame(['url' => 'https://ribbon.abaadapp.om/my'], $sold['portal'], 'الرابطُ الواحد لا رابطُ عميل');
        // والبيعةُ ظهرت في صفحة صاحبتها وحدها — بلا فعلٍ من أحد
        $seen = $this->myJson('/data', $cookie)->getContent();
        foreach ([$plain['invoice'], $sold['invoice']] as $number) {
            $this->assertStringContainsString($number, $seen);
        }
        $this->assertStringNotContainsString($sold['invoice'], $this->myJson('/data', $this->enter($this->buyer('فاطمة', '92222222')))->getContent());
    }

    public function test_the_owner_runs_it_from_settings_and_a_manager_stops_but_does_not_grant(): void
    {
        $manager = $this->staffer('مديرة البوابة', 'sales', ['settings', 'customers']);
        // ومعه قسمُ الإعدادات: فيُردّ بصلاحيّة البوابة لا بقسمه
        $clerk = $this->staffer('موظف', 'sales', ['settings', 'customers']);

        $this->staff($this->owner)->post(route('admin.settings.portal.staff'), ['share' => [$clerk->id], 'manage' => [$manager->id]])->assertSessionHasNoErrors();
        $this->assertTrue(Portal::mayManage($manager->fresh()));
        $this->assertTrue(Portal::mayShare($manager->fresh()), 'ومن يدير يشارك');
        $this->assertFalse(Portal::mayManage($clerk->fresh()));
        $this->staff($manager)->post(route('admin.settings.portal.staff'), ['share' => [], 'manage' => []])->assertForbidden();

        $cookie = $this->enter($this->maryam);
        $this->assertSame([], $this->settings($manager)['links'], 'من لم تُرسم صفحتُه لا يُقرأ «دخل»');
        $this->my('', $cookie)->assertOk();
        $props = $this->settings($manager);
        $row = $props['links'][0];
        $this->assertSame(['مريم البلوشي', 1, false], [$row['customer'], $row['devices'], $row['stopped']]);
        $this->assertFalse($props['mayGrant']);
        $this->assertArrayNotHasKey('token_hash', $row);
        $this->assertSame(route('admin.settings.portal.index'), $this->staff($manager)->get(route('admin.settings.index'))->viewData('page')['props']['portalUrl']);

        // يوقف ويسمح من صفّه، ولا يُسمح لمن ليس موقوفًا ولا يُوقَف الموقوف
        $this->staff($manager)->post($row['url'])->assertNotFound();
        $this->staff($clerk)->get(route('admin.settings.portal.index'))->assertForbidden();
        $this->staff($clerk)->delete($row['url'])->assertForbidden();
        $this->staff($clerk)->post($row['url'])->assertForbidden();
        $this->staff($manager)->delete($row['url'])->assertSessionHasNoErrors();
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->assertSame([true, 0], [$this->settings($manager)['links'][0]['stopped'], $this->settings($manager)['links'][0]['devices']]);
        $this->staff($manager)->delete($row['url'])->assertNotFound();
        $this->staff($manager)->post($row['url'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->settings($manager)['links']);
        $this->my('', $this->enter($this->maryam))->assertSee('مريم');

        // «إخراج الجميع»: كلُّ أجهزة المتجر، ولا يُمسّ متجرٌ آخر — ويدخلون بأرقامهم من جديد
        $cookies = array_map(fn ($i) => $this->enter($this->buyer('عميل '.$i, '9111000'.$i)), [1, 2]);
        $other = CustomerPortalLink::create(['business_id' => $this->neighbour->id, 'customer_id' => $this->buyer('جار', '96666666', $this->neighbour)->id, 'token_hash' => str_repeat('d', 64), 'nonce' => str_repeat('e', 64), 'phone_hash' => str_repeat('f', 64), 'last_used_at' => now()]);
        $this->assertNotContains('جار', array_column($this->settings($manager)['links'], 'customer'));
        $this->assertCount(1, $this->settings($manager)['links']);
        $theirs = CustomerPortalSession::create(['business_id' => $this->neighbour->id, 'customer_id' => $other->customer_id, 'link_id' => $other->id, 'token_hash' => str_repeat('1', 64), 'expires_at' => now()->addDay()]);
        $this->staff($clerk)->delete(route('admin.settings.portal.signOut'))->assertForbidden();
        $this->staff($manager)->delete(route('admin.settings.portal.signOut'))->assertSessionHasNoErrors();
        foreach ($cookies as $c) {
            $this->myJson('/data', $c)->assertStatus(401);
        }
        $this->assertNull($theirs->fresh()->revoked_at, 'ولا يُمسّ متجرٌ آخر');
        $this->staff($manager)->delete(route('admin.settings.portal.link', $other->id))->assertNotFound();
        $this->my('', $this->enter($this->maryam))->assertSee('مريم');

        // ورمزُ QR ورقةٌ تُطبع بالرابط العامّ وحده
        $poster = $this->staff($this->owner)->get(route('admin.customers.portal.poster'))->assertOk()->getContent();
        $this->assertStringContainsString('<svg viewBox="0 0 320 320"', $poster, 'بلا viewBox يُقصّ الرمزُ حين يُصغَّر فلا يُمسح');
        $this->assertStringContainsString('ribbon.abaadapp.om/my', $poster);
        $this->assertStringContainsString('امسح الرمز واكتب رقم هاتفك', $poster);
        $this->assertDoesNotMatchRegularExpression('/رابطك الشخصي|فاتورتك\.|رقم جهازك|device number/u', $poster);
        $this->assertStringNotContainsString('#', explode('ribbon.abaadapp.om/my', $poster)[1][0] ?? '');
    }
}
