<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPortalLink;
use App\Models\CustomerPortalSession;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Document\PaperSize;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\MarketingSettings;
use App\Support\PublicDocument;
use App\Support\RibbonPortal\Access;
use App\Support\RibbonPortal\Code;
use App\Support\RibbonPortal\Portal;
use App\Support\SalesChannel;
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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\RibbonPortalShop;
use Tests\TestCase;

/**
 * بوابةُ RIBBON لا تُفتح إلّا لصاحبها — ولا تُعرف إلّا في متجرها.
 *
 *   - رابطٌ واحدٌ للجميع: رقمُ الهاتف ورمزٌ من فاتورته معًا، ولا يكفي أحدُهما، ولا يقول الردُّ أيُّهما أصاب.
 *   - والرمزُ على ورقة المشتري وحدها: لا على سند التسليم، ولا في صفحة رمز QR، ولا لطلبٍ كتبه زائرُ الموقع.
 *   - ويكفّ عن الطبع متى دخل صاحبُه، ويقف بالإلغاء، وتُغلق الجلساتُ بتغيّر الرقم وحذف العميل ساعتَها.
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

    /** ورقةٌ كما تُطبع — أو نصُّها بلا مسافات ليُبحث فيه */
    private function paper(string $html): string
    {
        return (string) preg_replace('/\s+/u', '', strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html)));
    }

    private function invoiceOf(Customer $customer, ?int $by = null, bool $issue = true): CustomerInvoice
    {
        $draft = CustomerInvoices::create((int) $customer->business_id, $customer, [], [['description' => 'تنسيق', 'quantity' => 1, 'unit_price' => 9]], $by);

        return $issue ? CustomerInvoices::issue($draft, $by)->fresh() : $draft->fresh();
    }

    /* ═════════════ الرقمُ وحدَه والرمزُ وحدَه ═════════════ */

    public function test_one_public_link_asks_for_the_phone_at_once_and_shows_nothing(): void
    {
        $this->order($this->maryam, 'INV-1');
        $page = $this->my()->assertOk()->getContent();

        // خانةُ الهاتف ظاهرةٌ ساعةَ الفتح، والرمزُ بعدها — ولا طلبَ لرابطٍ من موظّف
        $this->assertMatchesRegularExpression('/<form[^>]*data-testid="portal-login"(?![^>]*hidden)/', $page);
        $this->assertMatchesRegularExpression('/data-testid="portal-step-phone"(?![^>]*hidden)[^>]*>/', $page);
        $this->assertMatchesRegularExpression('/data-testid="portal-step-code" hidden/', $page);
        foreach (['يرجى طلب رابطك', 'رابطك الشخصي', 'personal link', 'مريم', 'INV-1', '91234567'] as $not) {
            $this->assertStringNotContainsString($not, $page, $not);
        }
        $en = $this->visitor(null)->withCookie('rb_my_lang', 'en')->get('http://ribbon.abaadapp.om/my')->getContent();
        $this->assertStringContainsString('Your phone number registered with us', $en);
        $this->assertStringNotContainsString('personal link', $en);
        // ولا بابَ لإنشاء حسابٍ ولا كلمةَ مرور
        $this->assertDoesNotMatchRegularExpression('/type="password"|register|signup|إنشاء حساب/iu', $page);

        $this->myJson('/data')->assertStatus(401);
        $this->myJson('/points')->assertStatus(401);
        $this->my('/paper/order/1')->assertRedirect();
        $this->my('/paper/order/1/sheet')->assertForbidden();
        $this->my('/paper/order/1/pdf')->assertForbidden();
    }

    public function test_a_phone_alone_or_a_code_alone_opens_nothing_and_every_refusal_reads_the_same(): void
    {
        $this->order($this->maryam, 'INV-1');
        $fatima = $this->buyer('فاطمة', '92222222');
        $this->buyer('جارة', '99999999', $this->neighbour);
        $right = $this->code($this->maryam);

        $answers = [];
        foreach ([
            ['91234567', ''], ['91234567', '000000000'], ['91234567', 'abc'], ['91234567', '12345'],     // رقمٌ مسجَّل بلا رمزه
            ['90000001', $right], ['90000001', '000000000'], ['99999999', $right],                        // غيرُ مسجَّل، ورقمُ عميلِ متجرٍ آخر
            ['92222222', $right], ['91234567', $this->code($fatima)],                                      // رمزُ أحدهما برقم الآخر
            ['', $right], ['مريم', $right], ["91234567' OR '1'='1", $right],
        ] as [$phone, $code]) {
            RateLimiter::clear('ribbon-portal:ip:127.0.0.1');
            Carbon::setTestNow(now()->addMinutes(20));
            $r = $this->attempt($phone, (string) $code)->assertStatus(422);
            $this->assertNull($r->getCookie(Access::COOKIE));
            $answers[] = $r->getContent();
        }
        $this->assertCount(1, array_unique($answers), 'لا يُعرف من الردّ أيُّهما أصاب، ولا أرقمٌ مسجَّلٌ أم لا');
        $this->assertSame(0, CustomerPortalSession::count());
        $this->assertSame(0, CustomerPortalLink::count(), 'ومحاولةٌ لا تكتب صفًّا');
        $this->assertSame(2, Customer::where('business_id', $this->ribbon->id)->count(), 'ولا حسابَ يُنشأ');
    }

    public function test_another_shops_customer_and_a_twin_number_are_kept_apart(): void
    {
        $twin = $this->buyer('مريم الجار', '91234567', $this->neighbour);
        $this->order($twin, 'NEIGHBOUR-9');
        $mine = $this->order($this->maryam, 'INV-MINE');
        // ورمزٌ لورقةِ متجرٍ آخر لا يوجد أصلًا
        $this->assertNull(Code::of($this->order($twin, 'NEIGHBOUR-10')));
        $this->attempt('91234567', $this->code($twin))->assertStatus(422);

        $cookie = $this->enter($this->maryam);
        $seen = $this->myJson('/data', $cookie)->getContent();
        $this->assertStringContainsString('INV-MINE', $seen);
        $this->assertStringNotContainsString('NEIGHBOUR-9', $seen);

        // وسجلّان برقمٍ واحد في المتجر نفسه لا يُدمجان: لكلٍّ رمزُ ورقته
        $same = $this->buyer('مريم الثانية', null, null, ['contact_phone' => '91234567']);
        $second = $this->order($same, 'INV-SECOND');
        $other = $this->attempt('91234567', (string) Code::of($second))->assertOk()->getCookie(Access::COOKIE)->getValue();
        $this->assertStringContainsString('INV-SECOND', $this->myJson('/data', $other)->getContent());
        $this->assertStringNotContainsString('INV-MINE', $this->myJson('/data', $other)->getContent());
        $this->my("/paper/order/{$mine->id}", $other)->assertNotFound();
        $this->assertSame(2, Customer::where('business_id', $this->ribbon->id)->count());
    }

    public function test_guessing_is_stopped_per_number_and_per_address(): void
    {
        $right = $this->code($this->maryam);
        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('91234567', '10000000'.$i)->assertStatus(422);
        }
        // والسادسةُ تُردّ ولو صحّت، وبأيّ صيغةٍ كُتب الرقم — حتّى تنقضي المدّة
        $this->assertGreaterThan(0, $this->attempt('91234567', $right)->assertStatus(429)->json('wait'));
        $this->attempt('+968 9123 4567', $right)->assertStatus(429);
        $this->assertSame(0, CustomerPortalSession::count());
        // ولا يُكتب الرقمُ في العدّاد
        $keys = (new \ReflectionMethod(Access::class, 'keys'))->invoke(null, '91234567', '', Request::create('/my'));
        $this->assertStringNotContainsString('91234567', implode(' ', $keys));

        Carbon::setTestNow(now()->addMinutes(16));
        $this->attempt('91234567', $right)->assertOk();

        // وعشرٌ خاطئةٌ في اليوم تُغلق يومَه، ولو تباعدت
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::clear('ribbon-portal:ip:127.0.0.1');
            Carbon::setTestNow(now()->addMinutes(16));
            $this->attempt('95550000', '20000000'.$i)->assertStatus(422);
        }
        RateLimiter::clear('ribbon-portal:ip:127.0.0.1');
        Carbon::setTestNow(now()->addMinutes(16));
        $this->attempt('95550000', '123456789')->assertStatus(429);

        // وعنوانٌ يجرّب أرقامًا كثيرة يُردّ كذلك — فلا تُعدّ الأرقامُ منه
        RateLimiter::clear('ribbon-portal:ip:127.0.0.1');
        for ($i = 1; $i <= 20; $i++) {
            $this->attempt('9600'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), '123456789')->assertStatus(422);
        }
        $this->attempt('91234567', $right)->assertStatus(429);

        foreach (['my.login', 'my.data', 'my.points', 'my.paper', 'my.paper.sheet', 'my.paper.pdf', 'my.index'] as $name) {
            $this->assertNotEmpty(array_filter(Route::getRoutes()->getByName($name)->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $name);
        }
    }

    /* ═════════════ رمزُ الفاتورة ═════════════ */

    public function test_the_code_is_on_the_buyers_own_papers_and_opens_the_account_once_for_the_device(): void
    {
        $order = $this->order($this->maryam, 'INV-A');
        $invoice = $this->invoiceOf($this->maryam, $this->owner->id);
        // فاتورةُ A4 وإيصالُ الصندوق وفاتورةُ العميل: ثلاثُ أوراقٍ بثلاثة قوالب
        DocumentTemplates::save((int) $this->ribbon->id, 'sale', ['paper' => PaperSize::A4]);
        $this->assertSame(PaperSize::A4, PdfController::saleHtml((int) $this->ribbon->id, $order)['paper']);

        foreach ([[$order, PdfController::saleHtml((int) $this->ribbon->id, $order)['html']], [$order, PdfController::saleHtml((int) $this->ribbon->id, $order, thermal: true)['html']], [$invoice, PdfController::customerInvoiceHtml((int) $this->ribbon->id, $invoice)]] as $i => [$document, $html]) {
            $code = (string) Code::of($document);
            $this->assertMatchesRegularExpression('/^\d{9}$/', $code);
            $this->assertStringContainsString('رمزالدخول'.$code, $this->paper($html), "الورقة {$i}");
            $this->assertStringContainsString('ribbon.abaadapp.om/my', $this->paper($html), "الورقة {$i}");
            $this->assertStringContainsString(Code::spaced($code), $html);
        }
        $this->assertNotSame(Code::of($order), Code::of($invoice), 'لكلّ ورقةٍ رمزُها');
        $this->assertSame(Code::of($order), Code::of($order->fresh()), 'وتُعاد طباعتُها به');

        // ويُدخل بأيٍّ منهما، وتُحفظ الجلسةُ على الجهاز
        $cookie = (string) $this->attempt('91234567', (string) Code::of($invoice))->assertOk()->getCookie(Access::COOKIE)->getValue();
        $this->my('', $cookie)->assertSee('مريم البلوشي');
        Carbon::setTestNow(now()->addDays(Access::DAYS - 1));
        $this->myJson('/data', $cookie)->assertOk();
        Carbon::setTestNow(now()->addDays(Access::DAYS - 1));
        $this->myJson('/data', $cookie)->assertOk();

        // ولا رمزَ محفوظٌ في القاعدة ولا في السجلّ
        $stored = (string) json_encode([DB::table('customer_portal_links')->get(), DB::table('customer_portal_sessions')->get(), DB::table('activity_logs')->get(), DB::table('cache')->get()]);
        $this->assertStringNotContainsString((string) Code::of($order), $stored);
        $this->assertStringNotContainsString('91234567', (string) json_encode([DB::table('customer_portal_links')->get(), DB::table('customer_portal_sessions')->get()]));
        // ولا يُشتقّ ممّا فيها: يلزم مفتاحُ الخادم
        $was = Code::daily($this->maryam);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertNotSame($was, Code::daily($this->maryam));
    }

    public function test_the_delivery_note_and_the_public_qr_page_never_carry_it(): void
    {
        $order = $this->order($this->maryam, 'INV-GIFT', ['recipient_name' => 'نورة', 'recipient_phone' => '95556666', 'fulfillment_type' => 'delivery']);
        $code = (string) Code::of($order);
        $bid = (int) $this->ribbon->id;

        $papers = [
            'سند A4' => DocumentRenderer::generic($bid, 'delivery', DocumentPaper::forDelivery($order), null, $order),
            'سند حراريّ' => DocumentRenderer::deliveryStrip($bid, $order, 80),
            'صفحة رمز QR' => $this->visitor(null)->get('http://localhost/i/'.PublicDocument::token($order))->assertOk()->getContent(),
        ];
        foreach ($papers as $name => $html) {
            $this->assertStringNotContainsString($code, $this->paper($html), $name);
            $this->assertStringNotContainsString('data-portal-code', $html, $name);
        }
        // ورمزُ QR نفسُه — على الفاتورة والسند معًا — لا يفتح شيئًا
        $this->assertStringContainsString((string) PublicDocument::token($order), $papers['سند A4']);
        $this->attempt('91234567', (string) PublicDocument::token($order))->assertStatus(422);
        $this->attempt('91234567', '', (string) PublicDocument::token($order))->assertStatus(422);
    }

    public function test_an_order_placed_by_a_visitor_under_someones_number_carries_no_code(): void
    {
        // طلبُ الموقع يُنسب إلى صاحب الرقم المكتوب بلا تحقّق — فلا يخرج منه رمزٌ ولا يُقبل
        $web = $this->order($this->maryam, 'WEB-ATTACK', ['channel' => SalesChannel::WEBSITE, 'user_id' => null, 'employee_name' => 'الموقع الإلكتروني']);
        $bot = $this->invoiceOf($this->maryam, null);
        foreach ([$web, $bot] as $document) {
            $this->assertNull(Code::of($document));
        }
        $this->assertStringNotContainsString('data-portal-code', PdfController::saleHtml((int) $this->ribbon->id, $web, thermal: true)['html']);
        $this->assertStringNotContainsString('data-portal-code', PdfController::customerInvoiceHtml((int) $this->ribbon->id, $bot));

        // ولو اشتُقّ بالقاعدة نفسِها رُدّ
        $make = new \ReflectionMethod(Code::class, 'make');
        foreach (["order|{$web->id}", "invoice|{$bot->id}"] as $what) {
            $this->attempt('91234567', $make->invoke(null, $this->maryam, $what, 0))->assertStatus(422);
        }

        // وكذلك سلّةٌ معلّقة ومسودّةٌ وبيعةٌ بلا عميل، وعميلٌ بلا رقم
        $held = $this->order($this->maryam, 'HELD', ['is_held' => true]);
        $draft = $this->invoiceOf($this->maryam, $this->owner->id, false);
        $walkIn = $this->order($this->maryam, 'WALK', ['customer_id' => null]);
        foreach ([$held, $draft, $walkIn, $this->order($this->buyer('بلا رقم', null), 'NOPHONE')] as $document) {
            $this->assertNull(Code::of($document), (string) $document->number);
        }
        foreach (["order|{$held->id}", "invoice|{$draft->id}"] as $what) {
            RateLimiter::clear('ribbon-portal:ip:127.0.0.1');
            $this->attempt('91234567', $make->invoke(null, $this->maryam, $what, 0))->assertStatus(422);
        }
        // وطلبٌ قديمٌ بلا قناةٍ كتبه موظّف: له رمزُه
        $this->assertNotNull(Code::of($this->order($this->maryam, 'OLD', ['channel' => null])));
        $this->assertNull(Code::of($this->order($this->maryam, 'OLD-2', ['channel' => null, 'user_id' => null])));
    }

    public function test_once_the_owner_is_in_a_purchase_under_their_number_opens_nothing(): void
    {
        $first = $this->order($this->maryam, 'INV-FIRST');
        $cookie = (string) $this->attempt('91234567', (string) Code::of($first))->assertOk()->getCookie(Access::COOKIE)->getValue();

        // غريبٌ اشترى في المحلّ برقمها بعد أن دخلت: إيصالُه بلا رمز
        Carbon::setTestNow(now()->addDay());
        $stranger = $this->order($this->maryam, 'INV-STRANGER');
        $this->assertNull(Code::of($stranger));
        $this->assertStringNotContainsString('data-portal-code', PdfController::saleHtml((int) $this->ribbon->id, $stranger, thermal: true)['html']);
        $make = new \ReflectionMethod(Code::class, 'make');
        $this->attempt('91234567', $make->invoke(null, $this->maryam, "order|{$stranger->id}", 0))->assertStatus(422);

        // وهي تدخل بجهازها الثاني بفاتورتها الأولى، وترى البيعةَ التي كُتبت برقمها
        $this->attempt('91234567', (string) Code::of($first))->assertOk();
        $this->assertStringContainsString('INV-STRANGER', $this->myJson('/data', $cookie)->getContent());

        // ومتى خرجت من أجهزتها كلِّها عاد الرمزُ إلى فواتيرها
        foreach (CustomerPortalSession::all() as $session) {
            Access::logout($session);
        }
        $this->assertNotNull(Code::of($stranger->fresh()));
    }

    public function test_revoking_signs_out_and_stops_every_code_printed_before_it(): void
    {
        $order = $this->order($this->maryam, 'INV-LEAK');
        $leaked = (string) Code::of($order);
        $cookie = (string) $this->attempt('91234567', $leaked)->assertOk()->getCookie(Access::COOKIE)->getValue();

        Access::revoke($this->maryam, $this->owner);
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->my('', $cookie)->assertDontSee('مريم')->assertCookieExpired(Access::COOKIE);
        $this->attempt('91234567', $leaked)->assertStatus(422);

        // والورقةُ نفسُها تُعاد طباعتُها برمزٍ جديدٍ يعمل
        $again = (string) Code::of($order->fresh());
        $this->assertNotSame($leaked, $again);
        // وفاتورتُها التالية تحمل رمزًا: جلساتُها أُغلقت كلُّها فلا تُحسب
        Carbon::setTestNow(now()->addHour());
        $this->assertSame(0, CustomerPortalSession::whereNull('revoked_at')->count());
        $this->assertNotNull(Code::of($this->order($this->maryam, 'INV-NEXT')));
        $this->attempt('91234567', $again)->assertOk();

        // ومن لم يدخل بعدُ تُوقَف رموزُه كذلك — فاتورةٌ وقعت في يدِ غيره
        $sara = $this->buyer('سارة', '92223333');
        $paper = (string) Code::of($this->order($sara, 'INV-SARA'));
        $daily = $this->code($sara);
        Access::revoke($sara, $this->owner);
        $this->attempt('92223333', $paper)->assertStatus(422);
        $this->attempt('92223333', $daily)->assertStatus(422);
        $this->attempt('92223333', $this->code($sara))->assertOk();

        // وإذنٌ حيٌّ واحدٌ لكلّ عميل — تحرسه القاعدة، وجهازان لا يكتبان إذنين
        $this->enter($sara);
        $this->assertSame(1, CustomerPortalLink::where('customer_id', $sara->id)->whereNull('revoked_at')->count());
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('customer_portal_links')->insert(['business_id' => $this->ribbon->id, 'customer_id' => $sara->id, 'token_hash' => str_repeat('a', 64), 'nonce' => str_repeat('b', 64), 'phone_hash' => str_repeat('c', 64)]);
    }

    public function test_a_code_read_by_staff_stops_at_the_end_of_tomorrow(): void
    {
        $code = $this->code($this->maryam);
        Carbon::setTestNow('2026-11-30 23:59:00');
        $this->attempt('91234567', $code)->assertOk();
        Carbon::setTestNow('2026-12-01 00:01:00');
        $this->attempt('91234567', $code)->assertStatus(422);
    }

    public function test_a_changed_number_or_a_deleted_customer_closes_everything_at_once(): void
    {
        $order = $this->order($this->maryam, 'INV-P');
        $cookie = (string) $this->attempt('91234567', (string) Code::of($order))->getCookie(Access::COOKIE)->getValue();

        $this->maryam->update(['phone' => '95550000']);
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->assertSame(Access::REVOKED_PHONE, CustomerPortalLink::latest('id')->value('revoked_why'));
        // ورقمُها القديم لا يفتح شيئًا ولو معه ورقتُها
        $this->attempt('91234567', (string) Code::of($order))->assertStatus(422);
        $cookie = (string) $this->attempt('95550000', (string) Code::of($order->fresh()))->assertOk()->getCookie(Access::COOKIE)->getValue();

        $this->maryam->delete();
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->my('', $cookie)->assertDontSee('مريم');
        $this->attempt('95550000', $this->codeOf($order))->assertStatus(422);

        // ورقمٌ كُتب بصيغةٍ أخرى ليس رقمًا تغيّر
        $sara = $this->buyer('سارة', '92223333');
        $cookie = $this->enter($sara);
        $sara->update(['phone' => '+968 9222 3333']);
        $this->myJson('/data', $cookie)->assertOk();
    }

    private function codeOf(Order $order): string
    {
        return (new \ReflectionMethod(Code::class, 'make'))->invoke(null, Customer::withTrashed()->find($order->customer_id), "order|{$order->id}", 0);
    }

    public function test_a_personal_link_issued_before_the_one_link_still_works_until_it_is_revoked(): void
    {
        // كما كُتب قبل التعديل: رابطٌ أصدره موظّفٌ باسمه
        $link = new CustomerPortalLink(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'nonce' => bin2hex(random_bytes(32)), 'created_by' => $this->owner->id,
            'phone_hash' => (new \ReflectionMethod(Access::class, 'phoneHash'))->invoke(null, '96891234567')]);
        $key = (new \ReflectionMethod(Access::class, 'token'))->invoke(null, $link);
        $link->token_hash = hash('sha256', $key);
        $link->save();

        $this->attempt('90000001', '', $key)->assertStatus(422);
        $cookie = (string) $this->attempt('91234567', '', $key)->assertOk()->getCookie(Access::COOKIE)->getValue();
        $this->my('', $cookie)->assertSee('مريم');
        Access::revoke($this->maryam, $this->owner);
        $this->attempt('91234567', '', $key)->assertStatus(422);
        $this->myJson('/data', $cookie)->assertStatus(401);

        // وإذنٌ كتبه الدخولُ نفسُه لا رابطَ له ولو عُرف سرُّه
        $this->enter($this->maryam);
        $own = CustomerPortalLink::whereNull('revoked_at')->firstOrFail();
        $this->attempt('91234567', '', (new \ReflectionMethod(Access::class, 'token'))->invoke(null, $own))->assertStatus(422);
        // ولا بابَ يُصدر رابطًا شخصيًّا بعد اليوم
        foreach (['admin.customers.portal.share', 'admin.customers.portal.reissue', 'pos.orders.portal', 'my.same'] as $gone) {
            $this->assertNull(Route::getRoutes()->getByName($gone), $gone);
        }
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
        $response = $this->attempt('91234567', $this->code($this->maryam));
        $cookie = $response->getCookie(Access::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/my', $cookie->getPath());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain(), 'لمضيف البوابة وحده');
        $this->assertTrue(Access::cookie(Request::create('https://ribbon.abaadapp.om/my'), str_repeat('a', 64))->isSecure());
        $this->assertStringNotContainsString($cookie->getValue(), json_encode(DB::table('customer_portal_sessions')->get()));

        $raw = (string) $cookie->getValue();
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
        Carbon::setTestNow(now()->addDays(Access::DAYS + 1));
        $this->myJson('/data', $again)->assertStatus(401);
        $this->myJson('/data', str_repeat('f', 64))->assertStatus(401);
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
        $this->staff($this->owner)->postJson(route('admin.customers.portal.code', $this->maryam->id))->assertNotFound();
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
            ['post', route('admin.customers.portal.code', $theirs->id)], ['delete', route('admin.customers.portal.revoke', $theirs->id)],
            ['post', route('admin.customers.portal.code', $this->maryam->id)], ['get', route('admin.customers.portal.poster')], ['get', route('admin.settings.portal.index')],
            ['post', route('admin.settings.portal.staff')], ['delete', route('admin.settings.portal.revokeAll')], ['delete', route('admin.settings.portal.revoke', 1)],
        ] as [$verb, $url]) {
            $this->staff($them)->json(strtoupper($verb), $url)->assertNotFound();
        }
        $this->assertSame(0, CustomerPortalLink::count());

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
            $this->staff($user)->postJson(route('admin.customers.portal.code', $this->maryam->id))->assertStatus($may ? 200 : 403);
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
        $this->staff($till)->postJson(route('admin.customers.portal.code', $this->maryam->id))->assertForbidden();
        $this->assertSame(['customers', 'pos'], $clerk->fresh()->permissions, 'صلاحيّاتُه كما كانت');

        // ورمزُ الدخول يُعرض لمن طُلب له، ويُكتب من عرضه — بلا الرمز
        $answer = $this->staff($clerk)->postJson(route('admin.customers.portal.code', $this->maryam->id))->assertOk();
        $this->assertSame(['code' => Code::spaced(Code::daily($this->maryam))], $answer->json());
        $this->assertStringContainsString('no-store', (string) $answer->headers->get('Cache-Control'));
        $log = DB::table('activity_logs')->where('subject_id', $this->maryam->id)->where('description', 'like', '%رمز دخول%')->get();
        $this->assertNotEmpty($log);
        $this->assertStringNotContainsString(Code::daily($this->maryam), (string) json_encode($log));
        // ولا رمزَ في الصفحة قبل أن يُطلب
        $this->assertStringNotContainsString(Code::daily($this->maryam), (string) preg_replace('/\s/', '', $this->staff($clerk)->get(route('admin.customers.show', $this->maryam->id))->getContent()));

        // وعميلٌ بلا رقم: يُقال. وعميلُ متجرٍ آخر ليس هنا
        $this->staff($clerk)->postJson(route('admin.customers.portal.code', $this->buyer('بلا رقم', null)->id))->assertStatus(422);
        $this->staff($this->owner)->postJson(route('admin.customers.portal.code', $this->buyer('جار', '97777777', $this->neighbour)->id))->assertNotFound();
    }

    public function test_only_a_manager_revokes_a_customers_entry(): void
    {
        $clerk = $this->staffer('موظفة', 'sales', ['customers']);
        $manager = $this->staffer('مديرة', 'sales', ['customers']);
        $this->grant([$clerk->id], [$manager->id]);
        $state = fn (User $by) => $this->staff($by)->get(route('admin.customers.show', $this->maryam->id))->viewData('page')['props']['portalCustomer'];

        $this->assertNull($state($clerk)['since']);
        $cookie = $this->enter($this->maryam);
        $this->assertSame([1, null], [$state($clerk)['devices'], $state($clerk)['urls']['revoke']]);
        $this->staff($clerk)->deleteJson(route('admin.customers.portal.revoke', $this->maryam->id))->assertForbidden();
        $this->myJson('/data', $cookie)->assertOk();

        foreach ([$manager, $this->owner] as $user) {
            $cookie = $this->enter($this->maryam);
            $this->assertNotNull($state($user)['urls']['revoke'], $user->name);
            $this->staff($user)->deleteJson(route('admin.customers.portal.revoke', $this->maryam->id))->assertOk()->assertJsonPath('state.since', null)->assertJsonPath('state.devices', 0);
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
            $this->staff($this->owner)->postJson(route('admin.customers.portal.code', $this->maryam->id))->assertOk(),
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

        // ولا ذكرَ لواتساب في كودها — سوى قاعدةِ النظام في قراءة رقم الهاتف، وهي حسابُ نصٍّ لا ربط، ووصفِ ما لا يُقبل رمزُه
        $files = [
            ...glob(app_path('Support/RibbonPortal/*.php')), ...glob(resource_path('views/portal/ribbon/*.php')), ...glob(app_path('Models/CustomerPortal*.php')),
            app_path('Http/Controllers/Admin/RibbonPortalController.php'), app_path('Http/Controllers/Store/RibbonPortalController.php'), app_path('Http/Middleware/RibbonPortalGate.php'),
            ...glob(resource_path('js/Components/RibbonPortal*.tsx')), resource_path('js/lib/ribbon-portal.ts'), resource_path('js/Pages/Admin/Settings/CustomerPortal.tsx'),
        ];
        $this->assertGreaterThan(19, count($files));
        foreach ($files as $file) {
            $code = str_replace(['use App\\Support\\WhatsAppPhone;', '(`WhatsAppPhone`)', 'WhatsAppPhone::normalize(', 'طلبُ واتساب الآليّ'], '', (string) file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression('/whatsapp|wa\\.me|واتساب|mailto:|sms:/iu', $code, basename($file));
        }
    }

    public function test_the_till_offers_the_one_link_after_a_sale_to_a_registered_customer_only(): void
    {
        $till = $this->staffer('كاشير', 'cashier');
        $sell = fn (User $by, array $over = []) => $this->staff($by)->withSession(['current_branch' => $this->branch->id])->postJson(route('pos.checkout'), $over + [
            'items' => [['id' => $this->bouquet->id, 'name' => 'باقة', 'qty' => 1]], 'payment_method' => 'نقدي', 'client_uuid' => uniqid('p', true),
        ])->assertOk()->json();

        $this->assertArrayNotHasKey('portal', $sell($till, ['customer_id' => $this->maryam->id]), 'كاشيرٌ لم يُختر');
        $this->grant([$till->id]);
        $this->assertArrayNotHasKey('portal', $sell($till), 'بيعةٌ بلا عميل');
        $sold = $sell($till, ['customer_id' => $this->maryam->id]);
        $this->assertSame('https://ribbon.abaadapp.om/my', $sold['portal'], 'الرابطُ الواحد لا رابطُ عميل');

        // وإيصالُها يحمل رمزَها، وتدخل به — وبيعةٌ بلا عميلٍ لا رمزَ لها
        $order = Order::where('number', $sold['invoice'])->firstOrFail();
        $this->attempt('91234567', (string) Code::of($order))->assertOk();
        $this->assertNull(Code::of(Order::where('number', $sell($till)['invoice'])->firstOrFail()));
    }

    public function test_another_shops_papers_are_drawn_without_it(): void
    {
        $theirs = $this->buyer('عميل الجار', '94444444', $this->neighbour);
        $order = $this->order($theirs, 'NB-PAPER');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertNull(Code::printed($order));
        $this->assertSame([], DB::getQueryLog(), 'ولا استعلامَ لها عند سواه');
        DB::disableQueryLog();
        $this->assertStringNotContainsString('data-portal-code', PdfController::saleHtml((int) $this->neighbour->id, $order, thermal: true)['html']);
        $this->assertStringNotContainsString('ribbon.abaadapp.om', PdfController::saleHtml((int) $this->neighbour->id, $order)['html']);

        // والقفلُ يُطفئه عند صاحبه كذلك
        config(['storefront.ribbon_customer_portal.enabled' => false]);
        $this->assertNull(Code::of($this->order($this->maryam, 'OFF')));
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
        $props = $this->staff($manager)->get(route('admin.settings.portal.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame('مريم البلوشي', $props['links'][0]['customer']);
        $this->assertFalse($props['mayGrant']);
        $this->assertArrayNotHasKey('token_hash', $props['links'][0]);
        $this->assertSame(1, $props['links'][0]['devices']);
        $this->assertSame(route('admin.settings.portal.index'), $this->staff($manager)->get(route('admin.settings.index'))->viewData('page')['props']['portalUrl']);

        $this->staff($manager)->delete(route('admin.settings.portal.revoke', $props['links'][0]['id']))->assertSessionHasNoErrors();
        $this->myJson('/data', $cookie)->assertStatus(401);

        foreach (['91110001', '91110002'] as $i => $phone) {
            $this->enter($this->buyer('عميل '.$i, $phone));
        }
        $other = CustomerPortalLink::create(['business_id' => $this->neighbour->id, 'customer_id' => $this->buyer('جار', '96666666', $this->neighbour)->id, 'token_hash' => str_repeat('d', 64), 'nonce' => str_repeat('e', 64), 'phone_hash' => str_repeat('f', 64)]);
        $this->staff($manager)->delete(route('admin.settings.portal.revokeAll'));
        $this->assertSame(0, CustomerPortalLink::where('business_id', $this->ribbon->id)->whereNull('revoked_at')->count());
        $this->assertNull($other->fresh()->revoked_at, 'ولا يُمسّ متجرٌ آخر');
        $this->staff($manager)->delete(route('admin.settings.portal.revoke', $other->id))->assertNotFound();

        // ورمزُ QR ورقةٌ تُطبع بالرابط العامّ وحده
        $poster = $this->staff($this->owner)->get(route('admin.customers.portal.poster'))->assertOk()->getContent();
        $this->assertStringContainsString('<svg viewBox="0 0 320 320"', $poster, 'بلا viewBox يُقصّ الرمزُ حين يُصغَّر فلا يُمسح');
        $this->assertStringContainsString('ribbon.abaadapp.om/my', $poster);
        $this->assertStringContainsString('اكتب رقم هاتفك ورمز الدخول من فاتورتك', $poster);
        $this->assertStringNotContainsString('رابطك الشخصي', $poster);
        $this->assertStringNotContainsString('#', explode('ribbon.abaadapp.om/my', $poster)[1][0] ?? '');
    }
}
