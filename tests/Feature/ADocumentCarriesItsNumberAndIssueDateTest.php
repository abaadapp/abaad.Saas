<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Support\Document\Pdf\Driver;
use App\Support\Pdf;
use App\Support\PlatformDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AbaadSubscriptionContract;
use Tests\TestCase;

/**
 * رقمُ المستند وتاريخُ إصداره — يكتبهما الإصدارُ وحده، ويُقرآن واحدًا في كلّ مكان.
 *
 * ═══ ما يُحرس ═══
 *
 *  • لكلّ مستندٍ يصدر رقمٌ فريد (AB-شهرُه-معرّفُه) وتاريخُ يومه بتوقيت مسقط
 *    على الخادم — ولا يكتبهما المدير ولو أرسلهما.
 *  • ويثبتان بعد أيّامٍ وبعد التوقيع: المستندُ القديم يُفتح بتاريخ صدوره لا باليوم.
 *  • ويُقرآن واحدًا في ورقة PDF قبل التوقيع وبعده، وفي صفحة التوقيع، وفي شاشة
 *    المدير، وفي الورقة المحفوظة.
 *  • ومعاينةُ القالب رقمُها توضيحيٌّ موسوم «معاينة» وتاريخُها اليوم — ولا تحجز رقمًا.
 *  • والتجديدُ مستندٌ جديد برقمه وتاريخه، يذكر الذي يجدّده.
 *  • والموقَّعُ قبلُ تبقى ورقتُه المحفوظةُ وبصمتُه كما هما.
 *
 * والإصدارُ المتزامن: `TwoHandsIssuingAtOnceNeverShareADocumentNumberTest`.
 */
class ADocumentCarriesItsNumberAndIssueDateTest extends TestCase
{
    use AbaadSubscriptionContract;
    use RefreshDatabase;

    private User $super;

    private Business $shop;

    private PlatformDocumentTemplate $contract;

    private ?Driver $engineWas = null;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-24 10:00:00', PlatformDocuments::TIMEZONE));

        $pro = Plan::create([
            'name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 10000,
        ]);
        $this->shop = Business::create([
            'name' => 'زهور الريان', 'owner_name' => 'سالم الهنائي', 'phone' => '91234567', 'city' => 'مسقط',
            'status' => 'نشط', 'plan_id' => $pro->id, 'starts_at' => '2026-01-01', 'ends_at' => '2026-08-31',
        ]);
        $this->super = User::create([
            'name' => 'مدير المنصة', 'email' => 'super@number.test',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->contract = $this->subscriptionContract();
        foreach (['legal_name' => 'مشاريع الذهلي الجديدة', 'legal_trade_name' => 'أبعاد'] as $key => $value) {
            Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
        }

        // محرّكُ ورقٍ يُبقي ما رُسم نصًّا — فيُقرأ ما في الورقة، والمحفوظُ هو ما رُسم
        $this->engineWas = Pdf::swap(new class implements Driver
        {
            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                return new Response($html, 200, ['Content-Type' => 'application/pdf']);
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                return new Response($html, 200);
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 0.0;
            }
        });
    }

    protected function tearDown(): void
    {
        Pdf::swap($this->engineWas);
        parent::tearDown();
    }

    private function issue(array $data = []): PlatformDocument
    {
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), $this->confirmed($data + [
            'template_id' => $this->contract->id, 'business_id' => $this->shop->id, 'cycle' => 'yearly',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        auth()->logout();

        return PlatformDocument::latest('id')->firstOrFail();
    }

    private function sign(PlatformDocument $doc): void
    {
        $img = imagecreatetruecolor(600, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 20, 150, 480, 40, imagecolorallocate($img, 15, 15, 15));
        ob_start();
        imagepng($img);
        $png = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());

        auth()->logout();
        $res = $this->postJson(route('documents.sign.submit', $doc->token), [
            'client' => ['cr' => '1234567', 'civil' => '98765432'], 'agree' => '1', 'signature' => $png,
        ])->assertOk()->assertJson(['ok' => true]);

        // ونسختُه على جهازه — كما يحفظها متصفّحُه
        if ($cookie = $res->getCookie(PlatformDocument::COPY_COOKIE)) {
            $this->withCookie(PlatformDocument::COPY_COOKIE, $cookie->getValue());
        }
    }

    private function expectedNumber(PlatformDocument $doc, string $month): string
    {
        return 'AB-'.$month.'-'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT);
    }

    /** ورقةُ المدير كما تُنزَّل — نصُّها */
    private function adminPaper(PlatformDocument $doc): string
    {
        return (string) $this->actingAs($this->super)->get(route('super-admin.documents.pdf', $doc->id))->assertOk()->getContent();
    }

    /** يقول المكانُ الرقمَ والتاريخَ معًا — ولا يقول تاريخًا غيره */
    private function assertCarries(string $where, string $html, string $number, string $issued, ?string $notToday = null): void
    {
        $this->assertStringContainsString($number, $html, $where.': الرقم');
        $this->assertStringContainsString('تاريخ الإصدار', $html, $where.': عنوان التاريخ');
        $this->assertStringContainsString($issued, $html, $where.': التاريخ');
        if ($notToday !== null) {
            $this->assertStringNotContainsString($notToday, $html, $where.': ظهر تاريخُ اليوم بدل تاريخ الإصدار');
        }
    }

    public function test_each_issued_document_gets_its_own_number_and_the_muscat_day_it_was_issued(): void
    {
        // ٠٠:٣٠ في مسقط هي ٢٠:٣٠ من أمسِ في UTC — فاليومُ والشهرُ يومُ مسقط وشهرُها
        $this->travelTo(Carbon::parse('2026-11-01 00:30:00', PlatformDocuments::TIMEZONE));
        $this->assertSame('2026-10-31', now()->utc()->toDateString());

        // ولا يكتبهما المديرُ ولو أرسلهما
        $first = $this->issue(['number' => 'HAND-0001', 'issued_on' => '2020/01/01', 'created_at' => '2020-01-01 00:00:00']);
        $second = $this->issue();

        $this->assertSame($this->expectedNumber($first, '202611'), $first->number);
        $this->assertSame($this->expectedNumber($second, '202611'), $second->number);
        $this->assertNotSame($first->number, $second->number);
        $this->assertSame('2026/11/01', PlatformDocuments::issuedOn($first));
        $this->assertSame('2026/11/01', PlatformDocuments::issuedOn($second));
    }

    public function test_the_number_and_date_stay_days_later_and_after_signing_and_never_become_today(): void
    {
        $doc = $this->issue();
        $number = $this->expectedNumber($doc, '202610');

        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', PlatformDocuments::TIMEZONE));
        $this->sign($doc);

        $this->travelTo(Carbon::parse('2026-12-15 16:00:00', PlatformDocuments::TIMEZONE));
        $doc = $doc->fresh();
        $this->assertSame($number, $doc->number);
        $this->assertSame('2026/10/24', PlatformDocuments::issuedOn($doc));

        $this->assertCarries('الورقة الموقَّعة', $this->adminPaper($doc), $number, '2026/10/24', '2026/12/15');
        $this->actingAs($this->super)->get(route('super-admin.documents.show', $doc->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('document.number', $number)->where('document.issued_on', '2026/10/24'));
    }

    public function test_the_paper_the_signing_page_the_admin_and_the_archive_say_the_same_number_and_date(): void
    {
        $doc = $this->issue();
        $number = $doc->number;
        $issued = PlatformDocuments::issuedOn($doc);
        $this->assertSame('2026/10/24', $issued);

        // قبل التوقيع: صفحةُ العميل، وورقةُ المدير وشاشتُه — والعميلُ لا ينزّل ورقةً قبل أن يوقّع
        $this->travelTo(Carbon::parse('2026-10-27 11:00:00', PlatformDocuments::TIMEZONE));
        $page = (string) $this->get(route('documents.sign', $doc->token))->assertOk()->getContent();
        $this->assertCarries('صفحة التوقيع', $page, $number, $issued, '2026/10/27');
        $this->assertCarries('ورقة المدير قبل التوقيع', $this->adminPaper($doc), $number, $issued, '2026/10/27');
        $this->actingAs($this->super)->get(route('super-admin.documents.show', $doc->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('document.number', $number)->where('document.issued_on', $issued));
        $this->actingAs($this->super)->get(route('super-admin.documents.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('documents.0.number', $number)->where('documents.0.issued_on', $issued));

        // وبعده: الورقةُ المحفوظة، وما ينزّله العميلُ والمديرُ منها، وصفحةُ نسخته
        $this->sign($doc);
        $doc = $doc->fresh();
        $this->assertNotNull($doc->pdf_path);
        $archived = (string) Storage::disk('local')->get($doc->pdf_path);
        $this->assertCarries('الورقة المحفوظة', $archived, $number, $issued, '2026/10/27');
        $this->assertSame($archived, (string) $this->get(route('documents.sign.pdf', $doc->token))->assertOk()->getContent(), 'ورقةُ العميل غيرُ المحفوظة');
        $this->assertCarries('نسخة العميل الموقَّعة', (string) $this->get(route('documents.sign', $doc->token))->assertOk()->getContent(), $number, $issued, '2026/10/27');
        $this->assertSame($archived, $this->adminPaper($doc));
        $this->assertTrue(PlatformDocuments::intact($doc));
    }

    public function test_previewing_a_template_shows_a_sample_number_and_today_and_reserves_no_number(): void
    {
        $before = $this->issue();
        $count = PlatformDocument::count();

        $this->travelTo(Carbon::parse('2026-10-25 08:00:00', PlatformDocuments::TIMEZONE));
        $this->actingAs($this->super);
        for ($i = 0; $i < 3; $i++) {
            $page = (string) $this->get(route('super-admin.documents.templates.preview', $this->contract->id))->assertOk()->getContent();
            $paper = (string) $this->get(route('super-admin.documents.templates.preview-pdf', $this->contract->id))->assertOk()->getContent();
        }

        foreach (['صفحة المعاينة' => $page, 'ورقة المعاينة' => $paper] as $where => $html) {
            $this->assertCarries($where, $html, 'AB-202610-0000', '2026/10/25');
            $this->assertStringContainsString('معاينة', $html, $where);
        }
        $this->assertSame($count, PlatformDocument::count(), 'المعاينةُ كتبت مستندًا');

        // والمستندُ التالي يأخذ الرقمَ الذي يلي السابقَ مباشرةً — المعاينةُ لم تحجز شيئًا
        $after = $this->issue();
        $this->assertSame($before->id + 1, $after->id);
        $this->assertSame($this->expectedNumber($after, '202610'), $after->number);
    }

    public function test_a_renewal_is_a_new_document_with_its_own_number_and_date_naming_the_one_it_renews(): void
    {
        $original = $this->issue();
        $this->sign($original);

        $this->travelTo(Carbon::parse('2026-12-03 10:00:00', PlatformDocuments::TIMEZONE));
        $renewal = $this->issue(['renews_document_id' => $original->id, 'business_id' => null]);

        $this->assertNotSame($original->id, $renewal->id);
        $this->assertSame($original->id, $renewal->renews_document_id);
        $this->assertSame($this->expectedNumber($renewal, '202612'), $renewal->number);
        $this->assertSame('2026/12/03', PlatformDocuments::issuedOn($renewal));
        // والأصلُ كما صدر
        $this->assertSame('2026/10/24', PlatformDocuments::issuedOn($original->fresh()));
        $this->assertSame($this->expectedNumber($original, '202610'), $original->fresh()->number);

        $page = (string) $this->get(route('documents.sign', $renewal->token))->assertOk()->getContent();
        $this->assertCarries('صفحة توقيع التجديد', $page, $renewal->number, '2026/12/03');
        $this->assertStringContainsString($original->number, $page, 'صفحةُ التجديد لا تذكر العقدَ الذي تجدّده');
        $paper = $this->adminPaper($renewal);
        $this->assertCarries('ورقة التجديد', $paper, $renewal->number, '2026/12/03');
        $this->assertStringContainsString('تجديد للعقد '.$original->number, $paper);
    }

    public function test_a_document_signed_before_keeps_its_paper_fingerprint_number_and_date(): void
    {
        $doc = $this->issue();
        $this->sign($doc);
        $doc = $doc->fresh();

        // ورقةٌ حُفظت قبل هذا التغيير — بعنوانها وتنسيقها يومئذ
        $old = '%PDF-old رقم المستند: '.$doc->number.' التاريخ: 2026-10-24';
        Storage::disk('local')->put($doc->pdf_path, $old);
        PlatformDocument::whereKey($doc->id)->update(['pdf_hash' => hash('sha256', $old)]);
        $sealed = $doc->fresh()->only(['number', 'created_at', 'content_hash', 'pdf_path', 'pdf_hash', 'signed_at']);

        $this->travelTo(Carbon::parse('2027-01-10 10:00:00', PlatformDocuments::TIMEZONE));
        $this->assertSame($old, $this->adminPaper($doc), 'الورقةُ المحفوظة رُسمت من جديد');

        $doc = $doc->fresh();
        $this->assertEquals($sealed, $doc->only(array_keys($sealed)));
        $this->assertTrue(PlatformDocuments::intact($doc), 'تغيّرت بصمةُ ما وُقِّع');
        $this->assertSame('2026/10/24', PlatformDocuments::issuedOn($doc));
    }
}
