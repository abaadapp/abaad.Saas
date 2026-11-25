<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Customer;
use App\Models\CustomerPortalLink;
use App\Models\CustomerPortalRequest;
use App\Models\CustomerPortalSession;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\MarketingSettings;
use App\Support\RibbonPortal\Access;
use App\Support\RibbonPortal\Devices;
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
 * بوابةُ RIBBON لا تُفتح إلّا لصاحبها — ولا تُعرف إلّا في متجرها.
 *
 *   - رابطٌ واحدٌ للجميع وخانةٌ واحدة: رقمُ الهاتف. ولا يُفتح به شيءٌ حتّى يعتمد موظّفٌ الجهازَ مرّة.
 *   - والردُّ واحدٌ لرقمٍ مسجَّلٍ وغيره، ولا حسابَ يُنشأ.
 *   - والموظّفُ يكتب الرقمَ الظاهر على جهاز العميل ولا يراه عنده: فلا يُعتمد جهازٌ لم يره أو يُقرأ له.
 *   - ويقول كيف تحقّق: العميلُ أمامه، أو اتّصل هو برقمه المسجَّل — ويُكتب مع اسمه.
 *   - والرقمُ ستُّ خاناتٍ لعشر دقائق، ويُطلب غيرُه من الجهاز نفسه.
 *   - ولا رمزَ على فاتورةٍ ولا إيصال، ولا رابطَ لعميلٍ بعينه، ولا كلمةَ مرور.
 *   - وتُغلق الجلساتُ بالإلغاء وتغيّر الرقم وحذف العميل ساعتَها.
 *   - ولا يبلغ أحدٌ فاتورةَ غيره برقمها ولا بمعرّفها.
 *   - ومتجرٌ آخر لا بابَ له ولا زرَّ ولا مفتاحَ في صفحاته — ولو كتب العنوانَ بيده.
 *   - والعملُ عليها لمن اختاره صاحبُ النشاط، ولا يفتح له شيئًا آخر، وإلغاءُ الدخول لمن يدير.
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

    private function approve(User $by, Customer $customer, string $digits, array $over = []): TestResponse
    {
        return $this->staff($by)->postJson(route('admin.customers.portal.device', $customer->id), $over + ['device' => $digits, 'how' => 'shop']);
    }

    /** رقمُ جهازٍ لا ينتظر به أحد */
    private function other(string ...$shown): string
    {
        return (string) collect(range(100000, 100999))->first(fn (int $n) => ! in_array((string) $n, $shown, true));
    }

    /* ═════════════ الرقمُ وحدَه لا يفتح شيئًا ═════════════ */

    public function test_one_public_link_asks_for_the_phone_alone_and_shows_nothing(): void
    {
        $this->order($this->maryam, 'INV-1');
        $page = $this->my()->assertOk()->getContent();

        // خانةٌ واحدةٌ ظاهرةٌ ساعةَ الفتح: رقمُ الهاتف — لا رمزَ ولا كلمةَ مرورٍ ولا حسابَ جديدًا
        $this->assertMatchesRegularExpression('/<form[^>]*data-testid="portal-login"(?![^>]*hidden)/', $page);
        $this->assertSame(1, preg_match_all('/<input\b/', $page));
        $this->assertStringContainsString('name="phone" type="tel"', $page);
        foreach (['يرجى طلب رابطك', 'رابطك الشخصي', 'رمز', 'فاتورتك من', 'personal link', 'code', 'مريم', 'INV-1', '91234567'] as $not) {
            $this->assertStringNotContainsString($not, (string) preg_replace('#<(style|script).*?</\1>#su', '', $page), $not);
        }
        $en = $this->visitor(null)->withCookie('rb_my_lang', 'en')->get('http://ribbon.abaadapp.om/my')->getContent();
        $this->assertStringContainsString('Your phone number registered with us', $en);
        $this->assertDoesNotMatchRegularExpression('/type="password"|register"|signup|sign up|إنشاء حساب|حساب جديد/iu', $page.$en);

        $this->myJson('/data')->assertStatus(401);
        $this->myJson('/points')->assertStatus(401);
        $this->my('/paper/order/1')->assertRedirect();
        $this->my('/paper/order/1/sheet')->assertForbidden();
        $this->my('/paper/order/1/pdf')->assertForbidden();
        $this->myJson('/wait')->assertOk()->assertExactJson(['state' => 'over']);
    }

    public function test_a_phone_number_opens_nothing_until_the_device_is_approved(): void
    {
        $order = $this->order($this->maryam, 'INV-1');
        $cookie = $this->device('91234567');

        // الجهازُ ينتظر: رقمُه على الشاشة، ولا حرفَ من الحساب — ولا أنّ الرقم مسجَّل
        $page = $this->my('', $cookie)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-testid="portal-number">[1-9]\d\d \d{3}</', $page);
        foreach (['مريم', 'INV-1', '91234567', 'باقة', 'portal-rows'] as $not) {
            $this->assertStringNotContainsString($not, $page, $not);
        }
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->myJson('/points', $cookie)->assertStatus(401);
        $this->my("/paper/order/{$order->id}", $cookie)->assertRedirect();
        $this->my("/paper/order/{$order->id}/sheet", $cookie)->assertForbidden();
        $this->my("/paper/order/{$order->id}/pdf", $cookie)->assertForbidden();
        $this->myJson('/wait', $cookie)->assertExactJson(['state' => 'wait']);
        $this->assertSame([0, 0], [CustomerPortalSession::count(), CustomerPortalLink::count()]);

        // ويُعتمد مرّةً فتُفتح وحدها، وتُحفظ على الجهاز بلا كتابة شيء
        $this->approve($this->owner, $this->maryam, $this->shown($cookie))->assertOk();
        $this->myJson('/wait', $cookie)->assertExactJson(['state' => 'in']);
        $home = $this->my('', $cookie)->assertSee('مريم البلوشي');
        $this->assertStringContainsString('INV-1', $home->getContent());
        // والكعكةُ نفسُها تُجدَّد بكلّ زيارة — وإلّا انقضت في المتصفّح وجلستُها حيّة
        $again = $home->getCookie(Access::COOKIE);
        $this->assertSame($cookie, $again->getValue());
        $this->assertSame(now()->addDays(Access::DAYS)->timestamp, $again->getExpiresTime());
        $this->assertSame(90, Access::DAYS, 'تسعون يومًا تتجدّد بالزيارة');
        foreach ([1, 2, 3] as $visit) {
            Carbon::setTestNow(now()->addDays(Access::DAYS - 1));
            $this->myJson('/data', $cookie)->assertOk();
        }
        $this->ask('91234567', $cookie)->assertOk();
        $this->my('', $cookie)->assertSee('مريم البلوشي');
    }

    public function test_every_number_is_answered_alike_and_no_account_is_made(): void
    {
        $this->order($this->maryam, 'INV-1');
        $this->buyer('جارة', '99999999', $this->neighbour);

        $answers = [];
        $pages = [];
        // مسجَّل، وغيرُ مسجَّل، ورقمُ عميلِ متجرٍ آخر
        foreach (['91234567', '90000001', '99999999'] as $phone) {
            $r = $this->ask($phone)->assertOk();
            $answers[] = [$r->getContent(), array_keys($r->headers->all()), strlen((string) $r->getCookie(Access::COOKIE)->getValue())];
            $page = $this->my('', (string) $r->getCookie(Access::COOKIE)->getValue())->getContent();
            $pages[] = preg_replace(['/nonce="[^"]+"/', '/(csrf-token" content|_token" value)="[^"]+"/', '/portal-number">[\d ]+/'], '', (string) $page);
        }
        $this->assertCount(1, array_unique($answers, SORT_REGULAR), 'لا يُعرف من الردّ أرقمٌ مسجَّلٌ أم لا');
        $this->assertCount(1, array_unique($pages), 'ولا من الصفحة');
        $this->assertSame(3, CustomerPortalRequest::count(), 'ولكلّ رقمٍ صفٌّ يُكتب — فلا يُعرف من زمن الردّ');

        // وما ليس رقمًا يُقال له، ولا صفَّ يُكتب
        foreach (['', 'مريم', '123', '<b>', str_repeat('9', 60)] as $odd) {
            $this->assertNull($this->ask($odd)->assertStatus(422)->getCookie(Access::COOKIE), $odd);
        }
        $this->assertSame(3, CustomerPortalRequest::count());
        // وما يشبه رقمًا وليس لأحدٍ ينتظر كسواه ولا يُفتح به شيء
        $this->assertSame([], Devices::of(Customer::make(['business_id' => $this->ribbon->id, 'phone' => "91234567' OR '1'='1"])));
        $this->assertNull($this->visitor(null)->postJson('http://ribbon.abaadapp.om/my/again')->assertStatus(410)->getCookie(Access::COOKIE));
        $this->assertSame(1, Customer::where('business_id', $this->ribbon->id)->count(), 'ولا حسابَ يُنشأ');
        $this->assertSame([0, 0], [CustomerPortalSession::count(), CustomerPortalLink::count()]);

        // ولا يُحفظ رقمٌ ولا رمزُ جهازٍ ولا رقمُ شاشته
        $row = (string) json_encode(DB::table('customer_portal_requests')->get());
        foreach (['91234567', '90000001', $this->device('92220000')] as $not) {
            $this->assertStringNotContainsString($not, $row);
        }

        // ورقمٌ لم يكن مسجَّلًا ثمّ أضافه الموظّفُ عميلًا: يُعتمد جهازُه الذي ينتظر
        $cookie = $this->device('95551111');
        $this->assertSame([], array_filter(Devices::waiting((int) $this->ribbon->id), fn ($w) => $w['phone'] === '95551111'));
        $new = $this->buyer('عميلة جديدة', '95551111');
        $this->approve($this->owner, $new, $this->shown($cookie))->assertOk();
        $this->my('', $cookie)->assertSee('عميلة جديدة');
    }

    public function test_the_staff_member_must_read_the_number_off_the_customers_own_screen(): void
    {
        $this->order($this->maryam, 'INV-1');
        // غريبٌ كتب رقمَها من بعيد، وهي في المحلّ تكتبه في جهازها
        $stranger = $this->device('91234567');
        $hers = $this->device('91234567');
        $this->assertNotSame($this->shown($stranger), $this->shown($hers), 'لا جهازان لرقمٍ واحدٍ برقمٍ واحد');

        // ولا رقمَ جهازٍ عند الموظّف في أيّ شاشة: يُقرأ من شاشتها
        $index = $this->staff($this->owner)->get(route('admin.customers.index'))->viewData('page')['props'];
        $show = $this->staff($this->owner)->get(route('admin.customers.show', $this->maryam->id))->viewData('page')['props'];
        $list = $this->staff($this->owner)->getJson(route('admin.customers.portal.waiting'))->assertOk();
        $this->assertSame(1, $index['portal']['waiting']);
        $this->assertCount(2, $show['portalCustomer']['waiting']);
        $this->assertSame(['مريم البلوشي', '91234567', false, 2], [$list->json('waiting.0.name'), $list->json('waiting.0.phone'), $list->json('waiting.0.twin'), count($list->json('waiting.0.devices'))]);
        foreach ([$this->shown($stranger), $this->shown($hers)] as $number) {
            foreach ([json_encode($index['portal']), json_encode($show['portalCustomer']), json_encode($list->json())] as $seen) {
                $this->assertDoesNotMatchRegularExpression('/"'.$number.'"/', (string) $seen);
            }
        }
        $this->assertStringContainsString('no-store', (string) $list->headers->get('Cache-Control'));

        // تُعتمد هي وحدها
        $this->approve($this->owner, $this->maryam, $this->shown($hers))->assertOk()->assertJsonCount(1, 'state.devices');
        $this->my('', $hers)->assertSee('مريم البلوشي');
        $this->my('', $stranger)->assertDontSee('مريم');
        $this->myJson('/data', $stranger)->assertStatus(401);
        $this->assertSame(1, CustomerPortalSession::count());

        // ورقمُ جهازٍ اعتُمد لا يُعتمد به ثانية ولا يعود ينتظر بعد الخروج منه
        $approved = (string) CustomerPortalRequest::whereNotNull('approved_at')->firstOrFail()->token_hash;
        $again = Devices::number(CustomerPortalRequest::where('token_hash', $approved)->firstOrFail());
        $this->approve($this->owner, $this->maryam, $again)->assertStatus(422);
        $this->visitor($hers)->post('http://ribbon.abaadapp.om/my/logout')->assertRedirect();
        $this->myJson('/wait', $hers)->assertExactJson(['state' => 'over']);
        $this->my('', $hers)->assertDontSee('portal-number', false);
        // ورقمُ جهازٍ لرقمٍ آخر لا يعتمده لها
        $this->approve($this->owner, $this->maryam, $this->shown($this->device('92220000')))->assertStatus(422);
        $this->assertSame(1, CustomerPortalSession::count());
    }

    public function test_the_number_is_six_digits_and_two_devices_with_the_same_one_are_not_told_apart(): void
    {
        $hash = fn (int $i) => hash('sha256', 'n-'.$i);
        $number = fn (int $i) => Devices::number(new CustomerPortalRequest(['token_hash' => $hash($i)]));
        $seen = $pair = [];
        for ($i = 0; $i < 20000 && $pair === []; $i++) {
            $n = $number($i);
            $this->assertMatchesRegularExpression('/^[1-9]\d{5}$/', $n);
            $pair = isset($seen[$n]) ? [$seen[$n], $i] : [];
            $seen[$n] = $i;
        }
        $this->assertGreaterThan(200, count($seen), 'لا يُصادَف رقمٌ مرّتين في مئتين');
        $this->assertCount(2, $pair);

        // جهازان برقمٍ واحد لرقم هاتفٍ واحد: لا يُعرف أيُّهما أمام الموظّف
        foreach ($pair as $i) {
            CustomerPortalRequest::create(['business_id' => $this->ribbon->id, 'phone_hash' => Devices::key('91234567'), 'token_hash' => $hash($i), 'expires_at' => now()->addMinutes(5)]);
        }
        $this->approve($this->owner, $this->maryam, $number($pair[0]))->assertStatus(422);
        $this->assertSame(0, CustomerPortalSession::count());
        // ويبقى أحدُهما فيُعتمد
        CustomerPortalRequest::where('token_hash', $hash($pair[0]))->delete();
        $this->approve($this->owner, $this->maryam, $number($pair[1]))->assertOk();
        $this->assertSame([$hash($pair[1])], CustomerPortalSession::pluck('token_hash')->all());
    }

    public function test_three_wrong_numbers_close_what_waits(): void
    {
        $cookie = $this->device('91234567');
        $right = $this->shown($cookie);
        $wrong = $this->other($right);

        foreach ([1, 2] as $try) {
            $this->approve($this->owner, $this->maryam, $wrong)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'ثلاثة أخطاء'));
        }
        $this->myJson('/wait', $cookie)->assertExactJson(['state' => 'wait']);
        $this->approve($this->owner, $this->maryam, $wrong)->assertStatus(422);
        $this->myJson('/wait', $cookie)->assertExactJson(['state' => 'over']);
        $this->approve($this->owner, $this->maryam, $right)->assertStatus(422);
        $this->my('', $cookie)->assertSee('انتهى رقم جهازك قبل اعتماده')->assertDontSee('portal-number', false);
        $this->assertSame([0, 0], [CustomerPortalSession::count(), CustomerPortalLink::count()]);

        // وخطآن ثمّ الصواب يُعتمد، وبأرقامٍ عربيّة
        $cookie = $this->device('91234567');
        $this->approve($this->owner, $this->maryam, $this->other($this->shown($cookie)))->assertStatus(422);
        $this->approve($this->owner, $this->maryam, strtr($this->shown($cookie), array_combine(range('0', '9'), mb_str_split('٠١٢٣٤٥٦٧٨٩'))))->assertOk();
        $spaced = $this->device('91234567');
        $this->approve($this->owner, $this->maryam, implode(' ', str_split($this->shown($spaced))))->assertOk();
        $this->approve($this->owner, $this->maryam, '')->assertStatus(422)->assertJsonPath('errors.device.0', fn ($m) => str_contains((string) $m, 'رقم الجهاز'));
    }

    public function test_the_number_lives_ten_minutes_and_the_device_asks_for_another(): void
    {
        $this->assertSame(10, Devices::MINUTES);
        $cookie = $this->device('91234567');
        $right = $this->shown($cookie);
        $this->assertStringContainsString('data-testid="portal-left">10:00<', $this->my('', $cookie)->getContent());
        // الجهازُ نفسُه والرقمُ نفسُه: رقمُه كما هو ولا تتجدّد مدّتُه، ولا طلبَ ثانيًا
        Carbon::setTestNow(now()->addMinutes(4));
        $this->assertNull($this->ask('+968 9123 4567', $cookie)->assertOk()->getCookie(Access::COOKIE));
        $this->assertNull($this->visitor($cookie)->postJson('http://ribbon.abaadapp.om/my/again')->assertOk()->getCookie(Access::COOKIE));
        $this->assertSame([$right, 1], [$this->shown($cookie), CustomerPortalRequest::count()]);
        $this->assertStringContainsString('data-testid="portal-left">6:00<', $this->my('', $cookie)->getContent());

        // ثمّ لا يُعتمد به شيء
        Carbon::setTestNow(now()->addMinutes(6)->subSecond());
        $this->myJson('/wait', $cookie)->assertExactJson(['state' => 'wait']);
        Carbon::setTestNow(now()->addSeconds(2));
        $this->myJson('/wait', $cookie)->assertExactJson(['state' => 'over']);
        $this->approve($this->owner, $this->maryam, $right)->assertStatus(422);
        $this->assertSame([], Devices::waiting((int) $this->ribbon->id));
        $over = $this->my('', $cookie)->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="portal-again"', $over);
        $this->assertStringNotContainsString('portal-number', $over);

        // ويطلب غيرَه بلا كتابة هاتفه: رقمٌ جديدٌ لرقمه هو، والقديمُ لا يعمل
        $next = (string) $this->visitor($cookie)->postJson('http://ribbon.abaadapp.om/my/again')->assertOk()->getCookie(Access::COOKIE)->getValue();
        $this->assertNotSame($cookie, $next);
        $this->assertNotSame($right, $this->shown($next));
        $this->approve($this->owner, $this->maryam, $right)->assertStatus(422);
        $this->approve($this->owner, $this->maryam, $this->shown($next))->assertOk();
        $this->my('', $next)->assertSee('مريم البلوشي');
        $this->myJson('/data', $cookie)->assertStatus(401);

        // والطلبُ الجديد محسوبٌ في الحدّ كالأوّل، ورقمٌ غيرُ مسجَّلٍ كسواه
        Carbon::setTestNow(now()->addMinutes(16));
        $stranger = $this->device('90000001');
        $again = fn (string $c) => $this->visitor($c)->postJson('http://ribbon.abaadapp.om/my/again');
        for ($i = 2; $i <= 5; $i++) {
            CustomerPortalRequest::query()->update(['closed_at' => now()]);
            $stranger = (string) $again($stranger)->assertOk()->getCookie(Access::COOKIE)->getValue();
        }
        CustomerPortalRequest::query()->update(['closed_at' => now()]);
        $this->assertGreaterThan(0, $again($stranger)->assertStatus(429)->json('wait'));

        // ومن اعتُمد ثمّ أُلغي، ومن مضى يومُه: لا رقمَ له بلا كتابة هاتفه
        Access::revoke($this->maryam, $this->owner);
        $again($next)->assertStatus(410);
        Carbon::setTestNow(now()->addDay());
        $again($stranger)->assertStatus(410);
        $this->my('', $stranger)->assertSee('portal-login', false)->assertCookieExpired(Access::COOKIE);
    }

    public function test_a_waiting_device_is_forgotten_by_either_side(): void
    {
        // «تغيير الرقم» يُنسيه، ورقمٌ آخر في الجهاز نفسه يُسقط الأوّل
        $cookie = $this->device('91234567');
        $right = $this->shown($cookie);
        $next = (string) $this->ask('92220000', $cookie)->assertOk()->getCookie(Access::COOKIE)->getValue();
        $this->assertNotSame($cookie, $next);
        $this->approve($this->owner, $this->maryam, $right)->assertStatus(422);
        $this->visitor($next)->post('http://ribbon.abaadapp.om/my/logout')->assertRedirect()->assertCookieExpired(Access::COOKIE);
        $this->myJson('/wait', $next)->assertExactJson(['state' => 'over']);

        // و«رفض» من الموظّف
        $cookie = $this->device('91234567');
        $this->staff($this->owner)->deleteJson(route('admin.customers.portal.dismiss', $this->maryam->id))->assertOk()->assertJsonPath('state.waiting', []);
        $this->myJson('/wait', $cookie)->assertExactJson(['state' => 'over']);

        // ولا ينتظر لرقمٍ واحدٍ أكثرُ من ثلاثة: الأقدمُ يسقط
        Carbon::setTestNow(now()->addMinutes(16));
        $many = array_map(fn () => $this->device('91234567'), range(1, 4));
        $this->assertSame(['over', 'wait', 'wait', 'wait'], array_map(fn ($c) => $this->myJson('/wait', $c)->json('state'), $many));

        // واعتُمد ولم يعد إليه صاحبُه في يومه: لا جلسةَ تبقى له
        $this->approve($this->owner, $this->maryam, $this->shown($many[3]))->assertOk();
        Carbon::setTestNow(now()->addHours(Access::UNSEEN_HOURS)->addMinute());
        $this->myJson('/data', $many[3])->assertStatus(401);
        $this->assertSame(24, Access::UNSEEN_HOURS);
    }

    public function test_staff_say_how_they_verified_and_a_remote_one_only_after_calling_the_registered_number(): void
    {
        $cookie = $this->device('91234567');
        $right = $this->shown($cookie);
        $try = fn (array $body) => $this->staff($this->owner)->postJson(route('admin.customers.portal.device', $this->maryam->id), ['device' => $right] + $body);

        // بلا قولٍ، أو بقولٍ لا يُعرف، أو عن بُعدٍ بلا إقرارٍ بالاتّصال: لا اعتماد
        foreach ([[], ['how' => ''], ['how' => 'message'], ['how' => 'call'], ['how' => 'call', 'called' => false], ['how' => 'call', 'called' => '0']] as $body) {
            $try($body)->assertStatus(422);
        }
        $this->assertStringContainsString('اتصل أنت بالرقم المسجّل', (string) $try(['how' => 'call'])->json('message'));
        $this->myJson('/wait', $cookie)->assertExactJson(['state' => 'wait']);
        $this->assertSame([0, 0], [CustomerPortalSession::count(), (int) CustomerPortalRequest::value('tries')], 'ولا يُحسب خطأً في الخانات');

        // والرقمُ الذي يُتّصل به من سجلّه لا ممّا كُتب في الجهاز
        $this->assertSame('91234567', $this->staff($this->owner)->get(route('admin.customers.show', $this->maryam->id))->viewData('page')['props']['portalCustomer']['phone']);

        $try(['how' => 'call', 'called' => true])->assertOk()
            ->assertJsonPath('state.devices.0.by', 'سعود')->assertJsonPath('state.devices.0.how', 'call')->assertJsonPath('state.devices.0.at', '2026-11-29 10:00');
        $this->my('', $cookie)->assertSee('مريم البلوشي');
        $this->assertStringContainsString('باتصال على رقمه المسجل — مريم البلوشي', (string) DB::table('activity_logs')->latest('id')->value('description'));

        // ومن في المحلّ: باسمِ من اعتمده، ولو ترك العمل
        $clerk = $this->staffer('منى', 'sales', ['customers']);
        $this->grant([$clerk->id]);
        $this->approve($clerk, $this->maryam, $this->shown($this->device('91234567')))->assertOk();
        $this->assertStringContainsString('وهو في المحل — مريم البلوشي', (string) DB::table('activity_logs')->latest('id')->value('description'));
        $clerk->delete();
        $rows = $this->staff($this->owner)->get(route('admin.settings.portal.index'))->viewData('page')['props']['links'];
        $this->assertSame([['منى', 'shop'], ['سعود', 'call']], array_map(fn ($d) => [$d['by'], $d['how']], $rows[0]['devices']));
    }

    public function test_another_shops_customer_and_a_twin_number_are_kept_apart(): void
    {
        $twin = $this->buyer('مريم الجار', '91234567', $this->neighbour);
        $this->order($twin, 'NEIGHBOUR-9');
        $mine = $this->order($this->maryam, 'INV-MINE');

        $cookie = $this->enter($this->maryam);
        $seen = $this->myJson('/data', $cookie)->getContent();
        $this->assertStringContainsString('INV-MINE', $seen);
        $this->assertStringNotContainsString('NEIGHBOUR-9', $seen);
        // وجهازٌ ينتظر هنا لا يُعتمد لعميلِ متجرٍ آخر بالرقم نفسه
        $waiting = $this->device('91234567');
        $this->assertNull(Devices::approve($twin, $this->shown($waiting), $this->owner, 'shop'));
        $this->assertSame([], Devices::waiting((int) $this->neighbour->id));

        // وسجلّان برقمٍ واحد في المتجر نفسه لا يُدمجان: يُقال للموظّف، ويُفتح لمن اعتُمد له
        $same = $this->buyer('مريم الثانية', null, null, ['contact_phone' => '91234567']);
        $this->order($same, 'INV-SECOND');
        $list = collect(Devices::waiting((int) $this->ribbon->id));
        $this->assertSame([true, true], $list->pluck('twin')->all());
        $this->approve($this->owner, $same, $this->shown($waiting))->assertOk();
        $this->assertStringContainsString('INV-SECOND', $this->myJson('/data', $waiting)->getContent());
        $this->assertStringNotContainsString('INV-MINE', $this->myJson('/data', $waiting)->getContent());
        $this->my("/paper/order/{$mine->id}", $waiting)->assertNotFound();
        $this->assertSame(2, Customer::where('business_id', $this->ribbon->id)->count());
    }

    public function test_requests_are_limited_per_number_and_per_address(): void
    {
        // خمسةٌ للرقم من عنوانٍ واحد في ربع ساعة، بأيّ صيغةٍ كُتب — مسجَّلًا أو غيره
        foreach (['91234567', '90000001'] as $phone) {
            for ($i = 1; $i <= 5; $i++) {
                $this->ask($phone)->assertOk();
            }
            $this->assertGreaterThan(0, $this->ask($phone)->assertStatus(429)->json('wait'));
            $this->ask('+968 '.$phone)->assertStatus(429);
        }
        // ومن عنوانٍ آخر يُقبل: غريبٌ لا يُغلق البابَ على صاحب الرقم
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7']);
        $this->ask('91234567')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        // ولا يُكتب الرقمُ في العدّاد
        $keys = (new \ReflectionMethod(Access::class, 'keys'))->invoke(null, Devices::key('91234567'), Request::create('/my'));
        $this->assertStringNotContainsString('91234567', implode(' ', $keys));
        Carbon::setTestNow(now()->addMinutes(16));
        $this->ask('91234567')->assertOk();

        // وعشرون للرقم في اليوم ولو تباعدت وتعدّدت عناوينُها
        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.'.$i]);
            $this->ask('95550000')->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.1']);
        $this->ask('95550000')->assertStatus(429);

        // وعنوانٌ يكتب أرقامًا كثيرة يُردّ
        $this->withServerVariables(['REMOTE_ADDR' => '10.2.2.2']);
        for ($i = 1; $i <= 40; $i++) {
            $this->ask('9600'.str_pad((string) $i, 4, '0', STR_PAD_LEFT))->assertOk();
        }
        $this->ask('97770000')->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);

        foreach (['my.login', 'my.again', 'my.wait', 'my.data', 'my.points', 'my.paper', 'my.paper.sheet', 'my.paper.pdf', 'my.index', 'admin.customers.portal.device', 'pos.orders.portal'] as $name) {
            $this->assertNotEmpty(array_filter(Route::getRoutes()->getByName($name)->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $name);
        }
    }

    /* ═════════════ الإلغاء ═════════════ */

    public function test_revoking_signs_out_every_device_and_closes_what_waits(): void
    {
        $first = $this->enter($this->maryam);
        $second = $this->enter($this->maryam);
        $waiting = $this->device('91234567');
        $right = $this->shown($waiting);
        $this->assertSame(1, CustomerPortalLink::where('customer_id', $this->maryam->id)->whereNull('revoked_at')->count(), 'إذنٌ واحدٌ لأجهزتها');

        Access::revoke($this->maryam, $this->owner);
        foreach ([$first, $second] as $cookie) {
            $this->myJson('/data', $cookie)->assertStatus(401);
            $this->my('', $cookie)->assertDontSee('مريم')->assertDontSee('انتهى رقم')->assertCookieExpired(Access::COOKIE);
        }
        $this->myJson('/wait', $waiting)->assertExactJson(['state' => 'over']);
        $this->approve($this->owner, $this->maryam, $right)->assertStatus(422);
        $this->assertSame(0, CustomerPortalSession::whereNull('revoked_at')->count(), 'وتُغلق جلساتُها نفسُها لا إذنُها وحده');

        // ويُعتمد جهازُها من جديد فتدخل، والقديمُ لا يعود
        $this->my('', $this->enter($this->maryam))->assertSee('مريم');
        $this->myJson('/data', $first)->assertStatus(401);

        // وإذنٌ حيٌّ واحدٌ لكلّ عميل — تحرسه القاعدة
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('customer_portal_links')->insert(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'token_hash' => str_repeat('a', 64), 'nonce' => str_repeat('b', 64), 'phone_hash' => str_repeat('c', 64)]);
    }

    public function test_a_changed_number_or_a_deleted_customer_closes_everything_at_once(): void
    {
        $this->order($this->maryam, 'INV-P');
        $cookie = $this->enter($this->maryam);
        $old = $this->device('91234567');

        $this->maryam->update(['phone' => '95550000']);
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->assertSame(Access::REVOKED_PHONE, CustomerPortalLink::latest('id')->value('revoked_why'));
        // وجهازٌ ينتظر برقمها القديم لا يُعتمد لها
        $this->approve($this->owner, $this->maryam, $this->shown($old))->assertStatus(422);
        $cookie = $this->enter($this->maryam, '95550000');
        $this->my('', $cookie)->assertSee('مريم');

        $waiting = $this->device('95550000');
        $shown = $this->shown($waiting);
        $this->maryam->delete();
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->my('', $cookie)->assertDontSee('مريم');
        $this->assertSame([], Devices::waiting((int) $this->ribbon->id));
        $this->approve($this->owner, $this->maryam, $shown)->assertNotFound();
        $this->myJson('/data', $waiting)->assertStatus(401);

        // ورقمٌ كُتب بصيغةٍ أخرى ليس رقمًا تغيّر
        $sara = $this->buyer('سارة', '92223333');
        $cookie = $this->enter($sara);
        $sara->update(['phone' => '+968 9222 3333']);
        $this->myJson('/data', $cookie)->assertOk();
    }

    /* ═════════════ لا رمزَ ولا رابطَ لعميل ═════════════ */

    public function test_there_is_no_invoice_code_no_personal_link_and_no_password(): void
    {
        foreach (['admin.customers.portal.share', 'admin.customers.portal.reissue', 'admin.customers.portal.code', 'my.same'] as $gone) {
            $this->assertNull(Route::getRoutes()->getByName($gone), $gone);
        }

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

        // ورابطٌ شخصيٌّ صدر من قبل لا يفتح شيئًا: يُطلب رقمُ الهاتف كسواه
        $link = new CustomerPortalLink(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'nonce' => bin2hex(random_bytes(32)), 'created_by' => $this->owner->id,
            'phone_hash' => Access::phoneHash('96891234567')]);
        $key = (new \ReflectionMethod(Access::class, 'token'))->invoke(null, $link);
        $link->token_hash = Access::hash($key);
        $link->save();
        $r = $this->visitor(null)->postJson('http://ribbon.abaadapp.om/my/session', ['phone' => '91234567', 'key' => $key, 'code' => '123456789'])->assertOk();
        $this->myJson('/data', (string) $r->getCookie(Access::COOKIE)->getValue())->assertStatus(401);
        // ومن صدر له رابطٌ ولم يدخل به لا يُقرأ «دخل»
        $this->assertNull($this->staff($this->owner)->get(route('admin.customers.show', $this->maryam->id))->viewData('page')['props']['portalCustomer']['since']);
        $this->assertSame([], $this->staff($this->owner)->get(route('admin.settings.portal.index'))->viewData('page')['props']['links']);

        // وجهازٌ دخل بالرابط من قبلُ يبقى داخلًا حتّى يُلغى
        $link->forceFill(['last_used_at' => now()])->save();
        CustomerPortalSession::create(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'link_id' => $link->id, 'token_hash' => Access::hash(str_repeat('a', 64)), 'last_seen_at' => now(), 'expires_at' => now()->addDays(90)]);
        $this->my('', str_repeat('a', 64))->assertSee('مريم');
        $this->assertSame([null, null], array_values(array_intersect_key($this->staff($this->owner)->get(route('admin.settings.portal.index'))->viewData('page')['props']['links'][0]['devices'][0], ['by' => 1, 'how' => 1])));
        Access::revoke($this->maryam, $this->owner);
        $this->myJson('/data', str_repeat('a', 64))->assertStatus(401);
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
        $this->approve($this->owner, $this->maryam, $this->shown($raw))->assertOk();
        $this->assertStringNotContainsString($raw, (string) json_encode([DB::table('customer_portal_sessions')->get(), DB::table('customer_portal_requests')->get(), DB::table('activity_logs')->get()]));
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

    public function test_a_sibling_domain_cannot_plant_a_device_in_the_customers_browser(): void
    {
        // على https: `__Host-` بمسار الجذر وبلا نطاق — لا يكتبها المتصفّحُ لصفحةٍ على نطاقٍ شقيق
        $secure = Request::create('https://ribbon.abaadapp.om/my');
        $cookie = Access::cookie($secure, str_repeat('a', 64));
        $this->assertSame(['__Host-rb_my', '/', null, true, true, 'lax'], [$cookie->getName(), $cookie->getPath(), $cookie->getDomain(), $cookie->isSecure(), $cookie->isHttpOnly(), $cookie->getSameSite()]);

        // وكعكةٌ بالاسم المجرَّد لا تُظهر جهازَ غيره في متصفّحه
        $raw = $this->device('91234567');
        $planted = Request::create('https://ribbon.abaadapp.om/my', cookies: ['rb_my' => $raw]);
        $this->assertNull(Devices::mine((int) $this->ribbon->id, $planted));
        $this->assertNotNull(Devices::mine((int) $this->ribbon->id, Request::create('https://ribbon.abaadapp.om/my', cookies: ['__Host-rb_my' => $raw])));

        // ومن دخل قبل أن يتغيّر الاسمُ يبقى داخلًا، وتُكتب له بالاسم الجديد
        Devices::approve($this->maryam, $this->shown($raw), $this->owner, 'shop');
        $session = Access::session((int) $this->ribbon->id, $planted);
        $this->assertNotNull($session);
        $this->assertSame($raw, Access::held($planted, $session));
        $both = Request::create('https://ribbon.abaadapp.om/my', cookies: ['__Host-rb_my' => str_repeat('b', 64), 'rb_my' => $raw]);
        $this->assertSame($raw, Access::held($both, Access::session((int) $this->ribbon->id, $both)));
        // ولا يُقرأ متجرٌ آخر بها
        $this->assertNull(Access::session((int) $this->neighbour->id, $planted));
        $this->assertNull(Devices::mine((int) $this->neighbour->id, Request::create('https://ribbon.abaadapp.om/my', cookies: ['__Host-rb_my' => $this->device('92220000')])));
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
        $this->myJson('/wait', $cookie)->assertNotFound();
        $this->staff($this->owner)->postJson(route('admin.customers.portal.device', $this->maryam->id), ['device' => '123'])->assertNotFound();
        $this->staff($this->owner)->getJson(route('admin.customers.portal.waiting'))->assertNotFound();
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
            ['post', route('admin.customers.portal.device', $theirs->id)], ['delete', route('admin.customers.portal.dismiss', $theirs->id)], ['delete', route('admin.customers.portal.revoke', $theirs->id)],
            ['post', route('admin.customers.portal.device', $this->maryam->id)], ['get', route('admin.customers.portal.waiting')], ['post', route('pos.orders.portal', $this->order($theirs, 'NB-SALE')->number)], ['get', route('admin.customers.portal.poster')], ['get', route('admin.settings.portal.index')],
            ['post', route('admin.settings.portal.staff')], ['delete', route('admin.settings.portal.revokeAll')], ['delete', route('admin.settings.portal.revoke', 1)],
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
            $cookie = $this->device('91234567');
            $right = $this->shown($cookie);
            $this->staff($user)->getJson(route('admin.customers.portal.waiting'))->assertStatus($may ? 200 : 403);
            $this->staff($user)->deleteJson(route('admin.customers.portal.dismiss', $this->buyer('أخرى', null)->id))->assertStatus($may ? 200 : 403);
            $this->approve($user, $this->maryam, $right)->assertStatus($may ? 200 : 403);
            $this->myJson('/data', $cookie)->assertStatus($may ? 200 : 401);
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
        $this->approve($till, $this->maryam, '123')->assertForbidden();
        $this->assertSame(['customers', 'pos'], $clerk->fresh()->permissions, 'صلاحيّاتُه كما كانت');

        // ويُكتب في السجلّ من اعتمد ولمن وأيَّ جهاز — بلا رقمه
        $cookie = (string) $this->visitor(null)->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1')
            ->postJson('http://ribbon.abaadapp.om/my/session', ['phone' => '91234567'])->getCookie(Access::COOKIE)->getValue();
        $right = $this->shown($cookie);
        $this->assertSame('iPhone · Safari', $this->staff($clerk)->getJson(route('admin.customers.portal.waiting'))->json('waiting.0.devices.0.device'));
        DB::table('activity_logs')->delete();
        $this->assertStringContainsString('no-store', (string) $this->approve($clerk, $this->maryam, $right)->assertOk()->headers->get('Cache-Control'));
        $log = DB::table('activity_logs')->where('subject_id', $this->maryam->id)->get();
        $this->assertCount(1, $log);
        $this->assertStringContainsString('اعتمد جهازًا لبوابة العميل (iPhone · Safari) وهو في المحل — مريم البلوشي', (string) $log[0]->description);
        $this->assertSame((int) $clerk->id, (int) $log[0]->user_id);
        $this->assertStringNotContainsString($right, (string) $log[0]->description);
        $this->assertSame([(int) $clerk->id, (int) $this->maryam->id], array_map('intval', (array) array_values((array) DB::table('customer_portal_requests')->latest('id')->first(['approved_by', 'customer_id']))));

        // وعميلٌ بلا رقم: يُقال. وعميلُ متجرٍ آخر ليس هنا
        $this->approve($clerk, $this->buyer('بلا رقم', null), '123')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'أضف رقم هاتف العميل'));
        $this->approve($this->owner, $this->buyer('جار', '97777777', $this->neighbour), '123')->assertNotFound();
        $this->staff($this->owner)->deleteJson(route('admin.customers.portal.dismiss', Customer::where('name', 'جار')->value('id')))->assertNotFound();
    }

    public function test_only_a_manager_revokes_a_customers_entry(): void
    {
        $clerk = $this->staffer('موظفة', 'sales', ['customers']);
        $manager = $this->staffer('مديرة', 'sales', ['customers']);
        $this->grant([$clerk->id], [$manager->id]);
        $state = fn (User $by) => $this->staff($by)->get(route('admin.customers.show', $this->maryam->id))->viewData('page')['props']['portalCustomer'];

        $this->assertNull($state($clerk)['since']);
        $cookie = $this->enter($this->maryam);
        $this->assertNull($state($clerk)['since'], 'اعتُمد ولم يفتحها بعد');
        $this->my('', $cookie)->assertOk();
        $this->assertNotNull($state($clerk)['since']);
        $this->assertSame([1, null], [count($state($clerk)['devices']), $state($clerk)['urls']['revoke']]);
        $this->staff($clerk)->deleteJson(route('admin.customers.portal.revoke', $this->maryam->id))->assertForbidden();
        $this->myJson('/data', $cookie)->assertOk();

        foreach ([$manager, $this->owner] as $user) {
            $cookie = $this->enter($this->maryam);
            $this->assertNotNull($state($user)['urls']['revoke'], $user->name);
            $this->staff($user)->deleteJson(route('admin.customers.portal.revoke', $this->maryam->id))->assertOk()->assertJsonPath('state.since', null)->assertJsonPath('state.devices', []);
            $this->myJson('/data', $cookie)->assertStatus(401);
        }
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
            $this->my('', $this->device('91234567'))->assertOk(),
            $this->staff($this->owner)->getJson(route('admin.customers.portal.waiting'))->assertOk(),
            $this->staff($this->owner)->get(route('admin.customers.portal.poster'))->assertOk(),
            $this->my()->assertOk(),
            $this->my('', $this->enter($this->maryam))->assertOk(),
            $this->staff($this->owner)->deleteJson(route('admin.customers.portal.revoke', $this->maryam->id))->assertOk(),
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
        $this->assertGreaterThan(19, count($files));
        foreach ($files as $file) {
            $code = str_replace(['use App\\Support\\WhatsAppPhone;', '(`WhatsAppPhone`)', 'WhatsAppPhone::normalize('], '', (string) file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression('/whatsapp|wa\\.me|واتساب|mailto:|sms:/iu', $code, basename($file));
        }
    }

    public function test_the_till_approves_the_device_of_this_sales_customer_alone(): void
    {
        $till = $this->staffer('كاشير', 'cashier');
        $sell = fn (User $by, array $over = []) => $this->staff($by)->withSession(['current_branch' => $this->branch->id])->postJson(route('pos.checkout'), $over + [
            'items' => [['id' => $this->bouquet->id, 'name' => 'باقة', 'qty' => 1]], 'payment_method' => 'نقدي', 'client_uuid' => uniqid('p', true),
        ])->assertOk()->json();
        $approve = fn (User $by, string $number, string $digits) => $this->staff($by)->withSession(['current_branch' => $this->branch->id])->postJson(route('pos.orders.portal', $number), ['device' => $digits, 'how' => 'shop']);

        $plain = $sell($till, ['customer_id' => $this->maryam->id]);
        $this->assertArrayNotHasKey('portal', $plain, 'كاشيرٌ لم يُختر');
        $cookie = $this->device('91234567');
        $approve($till, $plain['invoice'], $this->shown($cookie))->assertForbidden();
        $approve($till, $sell($till)['invoice'], $this->shown($cookie))->assertForbidden();

        $this->grant([$till->id]);
        $walkIn = $sell($till);
        $this->assertArrayNotHasKey('portal', $walkIn, 'بيعةٌ بلا عميل');
        $sold = $sell($till, ['customer_id' => $this->maryam->id]);
        $this->assertSame(['url' => 'https://ribbon.abaadapp.om/my', 'device' => route('pos.orders.portal', $sold['invoice'], false), 'phone' => '91234567'], $sold['portal'], 'الرابطُ الواحد لا رابطُ عميل');

        // عميلُ هذه البيعة وحده: جهازُ فاطمة لا يُعتمد ببيعة مريم، ولا ببيعةٍ بلا عميل، ولا ببيعةِ متجرٍ آخر
        $this->buyer('فاطمة', '92222222');
        $hers = $this->device('92222222');
        $approve($till, $sold['invoice'], $this->shown($hers))->assertStatus(422);
        $this->my('', $hers)->assertDontSee('فاطمة');
        $approve($till, $walkIn['invoice'], $this->shown($cookie))->assertNotFound();
        $approve($till, $this->order($this->buyer('جارة', '93333333', $this->neighbour), 'NB-1')->number, $this->shown($cookie))->assertNotFound();
        $approve($till, $this->order($this->maryam, 'HELD', ['is_held' => true])->number, $this->shown($cookie))->assertNotFound();

        // ورقمُ بيعةٍ تكرّر في متجرٍ آخر قبلها: بيعةُ هذا المتجر
        $this->order($this->buyer('جارة ثانية', '93333334', $this->neighbour), 'SAME-1');
        $this->order($this->maryam, 'SAME-1');
        $second = $this->device('91234567');
        $approve($till, 'SAME-1', $this->shown($second))->assertOk();
        $this->my('', $second)->assertSee('مريم البلوشي');

        $approve($till, $sold['invoice'], $this->shown($cookie))->assertOk()->assertExactJson(['ok' => true]);
        $this->my('', $cookie)->assertSee('مريم البلوشي');
        $this->assertStringContainsString($sold['invoice'], $this->myJson('/data', $cookie)->getContent());
    }

    public function test_the_owner_runs_it_from_settings_and_a_manager_revokes_but_does_not_grant(): void
    {
        $manager = $this->staffer('مديرة البوابة', 'sales', ['settings', 'customers']);
        $clerk = $this->staffer('موظف', 'sales', ['customers']);

        $this->staff($this->owner)->post(route('admin.settings.portal.staff'), ['share' => [$clerk->id], 'manage' => [$manager->id]])->assertSessionHasNoErrors();
        $this->assertTrue(Portal::mayManage($manager->fresh()));
        $this->assertTrue(Portal::mayShare($manager->fresh()), 'ومن يدير يشارك');
        $this->assertFalse(Portal::mayManage($clerk->fresh()));
        $this->staff($manager)->post(route('admin.settings.portal.staff'), ['share' => [], 'manage' => []])->assertForbidden();

        $cookie = $this->enter($this->maryam);
        $this->my('', $cookie)->assertOk();
        $props = $this->staff($manager)->get(route('admin.settings.portal.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame('مريم البلوشي', $props['links'][0]['customer']);
        $this->assertFalse($props['mayGrant']);
        $this->assertArrayNotHasKey('token_hash', $props['links'][0]);
        $this->assertCount(1, $props['links'][0]['devices']);
        $this->assertSame(route('admin.settings.portal.index'), $this->staff($manager)->get(route('admin.settings.index'))->viewData('page')['props']['portalUrl']);

        $this->staff($manager)->delete(route('admin.settings.portal.revoke', $props['links'][0]['id']))->assertSessionHasNoErrors();
        $this->myJson('/data', $cookie)->assertStatus(401);

        foreach (['91110001', '91110002'] as $i => $phone) {
            $this->enter($this->buyer('عميل '.$i, $phone));
        }
        $waiting = $this->device('91110003');
        $other = CustomerPortalLink::create(['business_id' => $this->neighbour->id, 'customer_id' => $this->buyer('جار', '96666666', $this->neighbour)->id, 'token_hash' => str_repeat('d', 64), 'nonce' => str_repeat('e', 64), 'phone_hash' => str_repeat('f', 64)]);
        $this->staff($manager)->delete(route('admin.settings.portal.revokeAll'));
        $this->assertSame(0, CustomerPortalLink::where('business_id', $this->ribbon->id)->whereNull('revoked_at')->count());
        $this->myJson('/wait', $waiting)->assertExactJson(['state' => 'over']);
        $this->assertNull($other->fresh()->revoked_at, 'ولا يُمسّ متجرٌ آخر');
        $this->staff($manager)->delete(route('admin.settings.portal.revoke', $other->id))->assertNotFound();

        // ورمزُ QR ورقةٌ تُطبع بالرابط العامّ وحده
        $poster = $this->staff($this->owner)->get(route('admin.customers.portal.poster'))->assertOk()->getContent();
        $this->assertStringContainsString('<svg viewBox="0 0 320 320"', $poster, 'بلا viewBox يُقصّ الرمزُ حين يُصغَّر فلا يُمسح');
        $this->assertStringContainsString('ribbon.abaadapp.om/my', $poster);
        $this->assertStringContainsString('امسح الرمز واكتب رقم هاتفك', $poster);
        $this->assertDoesNotMatchRegularExpression('/رابطك الشخصي|فاتورتك\./u', $poster);
        $this->assertStringNotContainsString('#', explode('ribbon.abaadapp.om/my', $poster)[1][0] ?? '');
    }
}
