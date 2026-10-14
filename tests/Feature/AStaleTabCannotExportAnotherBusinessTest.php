<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Books;
use App\Support\Document\Pdf\Driver as PdfDriver;
use App\Support\Pdf;
use App\Support\PublicDocument;
use App\Support\SalesChannel;
use App\Support\TenantExportGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * تبويبٌ قديم لا يُخرج ملفَّ نشاطٍ آخر — ولا ملفَّه هو بجلسةٍ ليست له.
 *
 * نشاطان بعلاماتٍ لا تلتبس: «Activity Alpha» و«Activity Beta»، لكلٍّ بريدُه
 * وهاتفُه وشعارُه وصنفُه وزبونُه وطلبٌ بالرقم نفسِه `INV-000204`. والسؤال في
 * كلّ اختبار: هل يحمل الملفُّ علاماتِ نشاط الجلسة وحدها — ترويسةً ومحتوى —
 * وهل يقف الطلبُ حين تبدّلت الجلسةُ منذ رُسمت الصفحة التي طلبته؟
 *
 * ورقمُ النشاط في الرابط توقُّعٌ لا اختيار: لا يُقرأ به نشاطٌ أبدًا.
 */
class AStaleTabCannotExportAnotherBusinessTest extends TestCase
{
    use RefreshDatabase;

    private const STALE = 'تغيّرت جلسة النشاط منذ فتح هذه الصفحة. حدّث الصفحة ثم أعد التصدير.';

    /** @var array<string, array{business: Business, admin: User, branch: Branch, markers: list<string>}> */
    private array $shop = [];

    private User $platform;

    /** ما كان سيُطبع لو بلغ الطلبُ محرّكَ PDF */
    private object $printer;

    private ?PdfDriver $was = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        foreach (['alpha' => 'Alpha', 'beta' => 'Beta'] as $key => $name) {
            $this->shop[$key] = $this->makeShop($key, $name);
        }

        $this->platform = User::create([
            'name' => 'مدير المنصة', 'email' => 'root@abaad.test', 'password' => 'secret',
            'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->printer = new class implements PdfDriver
        {
            public string $html = '';

            public int $calls = 0;

            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                $this->html = $html;
                $this->calls++;

                return response('%PDF fake '.$name, 200, ['Content-Type' => 'application/pdf']);
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                $this->html = $html;
                $this->calls++;

                return response('%PDF fake', 200, ['Content-Type' => 'application/pdf']);
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 100.0;
            }
        };
        $this->was = Pdf::swap($this->printer);
    }

    protected function tearDown(): void
    {
        Pdf::swap($this->was);
        parent::tearDown();
    }

    /** نشاطٌ بعلاماتٍ في كلّ ما يُطبع: الاسم والبريد والهاتف والشعار والصنف والزبون */
    private function makeShop(string $key, string $name): array
    {
        $business = Business::create([
            'name' => "Activity {$name}", 'type' => 'عام', 'status' => 'نشط',
            'email' => "{$key}@example.test", 'phone' => $key === 'alpha' ? '+968 9111 0001' : '+968 9222 0002',
            'logo' => "/storage/logos/{$key}-logo-marker.png",
        ]);
        $branch = Branch::create(['business_id' => $business->id, 'name' => "{$name} Branch"]);
        $admin = User::create([
            'business_id' => $business->id, 'name' => "{$name} Owner", 'email' => "{$key}-owner@example.test",
            'password' => 'secret', 'role' => 'admin', 'status' => 'نشط',
        ]);

        $product = Product::create([
            'business_id' => $business->id, 'name' => strtoupper($key).'-PRODUCT-MARKER',
            'price' => 10, 'cost' => 4, 'quantity' => 100, 'alert_qty' => 1, 'active' => true,
        ]);
        Customer::create(['business_id' => $business->id, 'name' => strtoupper($key).'-CUSTOMER-MARKER', 'phone' => $key === 'alpha' ? '91110001' : '92220002']);

        // والرقمُ نفسُه في النشاطين — أرقامُ الطلبات لكلّ نشاطٍ وحده
        $order = Order::create([
            'business_id' => $business->id, 'branch_id' => $branch->id,
            'customer_name' => strtoupper($key).'-CUSTOMER-MARKER', 'employee_name' => "{$name} Owner", 'user_id' => $admin->id,
            'number' => 'INV-000204', 'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'is_held' => false,
            'payment_method' => 'نقدي', 'channel' => SalesChannel::POS,
            'subtotal' => 20, 'discount' => 0, 'tax' => 0, 'total' => 20, 'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => $product->name,
            'price' => 10, 'quantity' => 2, 'cost' => 4, 'total' => 20,
        ]);
        Books::recordSale($order);

        return [
            'business' => $business, 'admin' => $admin, 'branch' => $branch, 'order' => $order,
            'markers' => [
                "Activity {$name}", "{$key}@example.test", $key === 'alpha' ? '9111 0001' : '9222 0002',
                "{$key}-logo-marker", strtoupper($key).'-PRODUCT-MARKER',
            ],
        ];
    }

    private function other(string $key): string
    {
        return $key === 'alpha' ? 'beta' : 'alpha';
    }

    /** رابطٌ كما بنته شاشةُ هذا النشاط */
    private function linkFor(string $key, string $route, mixed $params = []): string
    {
        return TenantExportGuard::url(route($route, $params), $this->shop[$key]['business']->id);
    }

    private function body(TestResponse $res): string
    {
        ob_start();
        $res->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    /** نصُّ ورقة Excel كلُّها — خلاياها سطرًا سطرًا */
    private function workbookText(TestResponse $res): string
    {
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xl');
        file_put_contents($path, $this->body($res));
        $text = '';
        foreach (IOFactory::load($path)->getAllSheets() as $sheet) {
            foreach ($sheet->toArray(null, false, false) as $row) {
                $text .= implode(' | ', array_map('strval', $row))."\n";
            }
        }
        @unlink($path);

        return $text;
    }

    private function reportActivities(int $businessId): int
    {
        return DB::table('activity_logs')->where('business_id', $businessId)->where('action', 'report')->count();
    }

    /** الوقوفُ كما يجب: لا ملفّ، ولا محرّك، ولا شيءَ من النشاطين، ولا سجلّ */
    private function assertRefused(TestResponse $res): void
    {
        $res->assertStatus(409);
        $html = (string) $res->getContent();
        $this->assertStringContainsString(self::STALE, $html);
        $this->assertStringContainsString('data-stale-export', $html);
        $this->assertSame(0, $this->printer->calls, 'بلغ الطلبُ محرّكَ PDF');
        $res->assertCookieMissing('download_token');

        foreach ($this->shop as $shop) {
            foreach ($shop['markers'] as $marker) {
                $this->assertStringNotContainsString($marker, $html);
            }
            $this->assertSame(0, $this->reportActivities($shop['business']->id), 'سُجّل تصديرٌ لم يقع');
        }
    }

    /* ═══════════════ ١ و٢ و١٠ و١١ و١٢ — كلُّ نشاطٍ بهويّته وبيانه وحدَه ═══════════════ */

    public function test_each_sales_pdf_carries_its_own_identity_and_data_only(): void
    {
        foreach (['alpha', 'beta'] as $key) {
            $this->printer->html = '';
            $this->actingAs($this->shop[$key]['admin'])
                ->get($this->linkFor($key, 'admin.reports.pdf', ['range' => 'all']))
                ->assertOk();

            foreach ($this->shop[$key]['markers'] as $marker) {
                $this->assertStringContainsString($marker, $this->printer->html, "{$key}: لا «{$marker}» في ورقته");
            }
            foreach ($this->shop[$this->other($key)]['markers'] as $marker) {
                $this->assertStringNotContainsString($marker, $this->printer->html, "{$key}: «{$marker}» من النشاط الآخر في ورقته");
            }
            $this->assertSame(1, $this->reportActivities($this->shop[$key]['business']->id));
        }
    }

    /* ═══════════════ ٣ و٤ — رابطُ تبويبٍ قديم تحت جلسة النشاط الآخر ═══════════════ */

    public function test_a_stale_link_is_refused_in_both_directions_and_the_fresh_one_works(): void
    {
        foreach (['alpha', 'beta'] as $key) {
            $stale = $this->linkFor($key, 'admin.reports.pdf', ['range' => 'all']);
            $now = $this->other($key);

            // التبويبُ رُسم لـ$key، والجلسةُ صارت للآخر
            $this->assertRefused($this->actingAs($this->shop[$now]['admin'])->get($stale.'&download_token=abc123XYZ'));

            // وبعد التحديث: رابطُ الجلسة الحاليّة يخرج — بنشاطها وحدَه
            $this->actingAs($this->shop[$now]['admin'])->get($this->linkFor($now, 'admin.reports.pdf', ['range' => 'all']))->assertOk();
            $this->assertStringContainsString($this->shop[$now]['business']->name, $this->printer->html);
            foreach ($this->shop[$key]['markers'] as $marker) {
                $this->assertStringNotContainsString($marker, $this->printer->html);
            }

            DB::table('activity_logs')->delete();
            $this->printer->calls = 0;
        }
    }

    /* ═══════════════ ٥ — رقمٌ في الرابط لا يختار نشاطًا ═══════════════ */

    public function test_a_foreign_unknown_or_malformed_expectation_is_refused_and_reads_nothing(): void
    {
        $alpha = $this->shop['alpha']['admin'];
        $beta = $this->shop['beta']['business']->id;

        $own = $this->shop['alpha']['business']->id;

        // وما يُقرأ رقمَ الجلسة لو حُوّل عددًا («01»، «1abc»، «1.0») يقف أيضًا: الرقمُ يُقارَن نصًّا من أرقامٍ وحدها
        foreach ([(string) $beta, '999999', 'abc', '-1', '0', '1e3', $beta.' ', '0'.$own, $own.'abc', $own.'.0', '+'.$own] as $expected) {
            $url = route('admin.reports.pdf', ['range' => 'all', TenantExportGuard::PARAM => $expected]);
            $this->assertRefused($this->actingAs($alpha)->get($url));
        }

        // والسببُ مسمًّى: رقمُ نشاطٍ آخر «stale»، وما ليس رقمًا «invalid»
        $reason = fn (string $v) => $this->actingAs($alpha)
            ->get(route('admin.reports.pdf', [TenantExportGuard::PARAM => $v]))->getContent();
        $this->assertStringContainsString('data-stale-export="stale"', (string) $reason((string) $beta));
        $this->assertStringContainsString('data-stale-export="invalid"', (string) $reason('abc'));
        $this->assertStringContainsString('data-stale-export="invalid"', (string) $reason($own.'abc'));

        // ولا يُقرأ النشاطُ المذكور: لا استعلامَ عن businesses بالرقم المُرسَل
        $queried = [];
        DB::listen(function ($q) use (&$queried, $beta) {
            if (str_contains($q->sql, 'businesses') && in_array($beta, $q->bindings, false)) {
                $queried[] = $q->sql;
            }
        });
        $this->actingAs($alpha)->get(route('admin.reports.xlsx', [TenantExportGuard::PARAM => $beta]))->assertStatus(409);
        $this->assertSame([], $queried, 'قُرئ النشاطُ المذكور في الرابط');
    }

    /* ═══════════════ ٦ — الرابطُ بلا توقُّع ═══════════════ */

    public function test_an_aggregate_export_without_expectation_asks_for_a_refresh_and_a_document_still_opens(): void
    {
        $alpha = $this->shop['alpha']['admin'];

        // ملفٌّ يجمع بيانَ النشاط: لا يخرج بلا توقُّع — رابطٌ لم تبنِه شاشةٌ حاليّة
        foreach (['admin.reports.pdf', 'admin.reports.xlsx', 'admin.export.products', 'admin.backup.latest.download'] as $name) {
            $res = $this->actingAs($alpha)->get(route($name));
            $res->assertStatus(409);
            $this->assertStringContainsString(self::STALE, (string) $res->getContent());
        }

        // ومستندٌ بمعرّفه: الرابطُ المحفوظ يبقى يعمل — ويُخرج مستندَ الجلسة نفسِها
        $this->actingAs($alpha)->get(route('admin.orders.pdf', 'INV-000204'))->assertOk();
        $this->assertStringContainsString('ALPHA-PRODUCT-MARKER', $this->printer->html);
        $this->assertStringNotContainsString('BETA-PRODUCT-MARKER', $this->printer->html);
    }

    public function test_a_document_number_shared_by_both_businesses_is_not_opened_from_a_stale_tab(): void
    {
        // تبويبُ طلب Alpha رقم INV-000204 — والجلسةُ صارت لـBeta التي عندها الرقمُ نفسُه
        $stale = $this->linkFor('alpha', 'admin.orders.pdf', 'INV-000204');
        $this->assertRefused($this->actingAs($this->shop['beta']['admin'])->get($stale));

        foreach (['admin.orders.receipt', 'admin.orders.deliveryNote', 'admin.orders.taxInvoice'] as $name) {
            $this->assertRefused($this->actingAs($this->shop['beta']['admin'])->get($this->linkFor('alpha', $name, 'INV-000204')));
        }

        // ورابطُ Beta نفسِها يفتح طلبَها هي
        $this->actingAs($this->shop['beta']['admin'])->get($this->linkFor('beta', 'admin.orders.pdf', 'INV-000204'))->assertOk();
        $this->assertStringContainsString('BETA-PRODUCT-MARKER', $this->printer->html);
        $this->assertStringNotContainsString('ALPHA-PRODUCT-MARKER', $this->printer->html);
    }

    /* ═══════════════ ٧ و٨ و٩ — Excel وCSV وعائلةُ التقارير ═══════════════ */

    public function test_excel_csv_and_report_downloads_carry_their_own_business_only(): void
    {
        foreach (['alpha', 'beta'] as $key) {
            $as = $this->actingAs($this->shop[$key]['admin']);
            $mine = strtoupper($key);
            $theirs = strtoupper($this->other($key));

            $xlsx = $this->workbookText($as->get($this->linkFor($key, 'admin.reports.xlsx', ['range' => 'all'])));
            $this->assertStringContainsString($this->shop[$key]['business']->name, $xlsx);
            $this->assertStringNotContainsString($this->shop[$this->other($key)]['business']->name, $xlsx);

            $products = $this->workbookText($as->get($this->linkFor($key, 'admin.products.xlsx')));
            $this->assertStringContainsString("{$mine}-PRODUCT-MARKER", $products);
            $this->assertStringNotContainsString("{$theirs}-PRODUCT-MARKER", $products);

            foreach ([['admin.export.products', 'PRODUCT'], ['admin.export.customers', 'CUSTOMER'], ['admin.export.orders', 'CUSTOMER']] as [$route, $what]) {
                $csv = $this->body($as->get($this->linkFor($key, $route, ['range' => 'all']))->assertOk());
                $this->assertStringContainsString("{$mine}-{$what}-MARKER", $csv, "{$route} لـ{$key}");
                $this->assertStringNotContainsString("{$theirs}-", $csv, "{$route} لـ{$key} يحمل من الآخر");
            }

            $report = $this->body($as->get($this->linkFor($key, 'admin.reports.export.csv', ['customers', 'range' => 'all']))->assertOk());
            $this->assertStringContainsString("{$mine}-CUSTOMER-MARKER", $report);
            $this->assertStringNotContainsString("{$theirs}-CUSTOMER-MARKER", $report);
        }

        // والتبويبُ القديم في كلّ صيغة — ولا يُكتب له «صدّر» في السجلّ
        DB::table('activity_logs')->delete();
        foreach ([['admin.reports.xlsx', []], ['admin.export.products', []], ['admin.reports.export.csv', ['customers']], ['admin.reports.export.xlsx', ['customers']], ['admin.products.export.pdf', []]] as [$route, $params]) {
            $this->assertRefused($this->actingAs($this->shop['beta']['admin'])->get($this->linkFor('alpha', $route, $params)));
        }
    }

    /* ═══════════════ ١٣ و١٤ — المُرشِّحاتُ ورمزُ التنزيل كما كانا ═══════════════ */

    public function test_filters_and_the_download_token_still_work_for_the_same_business(): void
    {
        $alpha = $this->shop['alpha'];

        $this->actingAs($alpha['admin'])
            ->withSession(['current_branch' => $alpha['branch']->id])
            ->get($this->linkFor('alpha', 'admin.reports.pdf', ['range' => 'all', 'channel' => SalesChannel::POS]))
            ->assertOk();
        $this->assertStringContainsString($alpha['branch']->name, $this->printer->html);
        $this->assertStringContainsString(SalesChannel::label(SalesChannel::POS), $this->printer->html);

        $res = $this->actingAs($alpha['admin'])->get($this->linkFor('alpha', 'admin.export.products').'&download_token=abc123XYZ');
        $res->assertOk()->assertCookie('download_token', 'abc123XYZ', false);
    }

    /* ═══════════════ ١٥ — مديرُ المنصّة يبدّل النشاط في تبويبٍ آخر ═══════════════ */

    public function test_a_stale_tab_from_an_impersonation_is_refused_after_switching_to_another_business(): void
    {
        $alphaId = $this->shop['alpha']['business']->id;
        $betaId = $this->shop['beta']['business']->id;

        $this->actingAs($this->platform)->post(route('super-admin.businesses.impersonate', $alphaId))->assertRedirect();

        // التبويبُ الأوّل: صفحةٌ رُسمت لـAlpha — ومنها يُبنى الرابط كما تبنيه الشاشة
        $props = $this->get(route('admin.reports.sales'))->assertOk()->viewData('page')['props'];
        $this->assertSame($alphaId, $props['auth']['user']['businessId']);
        $stale = TenantExportGuard::url(route('admin.reports.pdf', ['range' => 'all']), $props['auth']['user']['businessId']);

        // التبويبُ الآخر: يخرج من Alpha ويدخل Beta
        $this->post(route('impersonate.stop'))->assertRedirect();
        $this->post(route('super-admin.businesses.impersonate', $betaId))->assertRedirect();
        DB::table('activity_logs')->where('action', '!=', 'report')->delete();

        $this->assertRefused($this->get($stale));

        // وبعد التحديث: صفحةُ Beta ورابطُها
        $fresh = $this->get(route('admin.reports.sales'))->assertOk()->viewData('page')['props'];
        $this->assertSame($betaId, $fresh['auth']['user']['businessId']);
        $this->get(TenantExportGuard::url(route('admin.reports.pdf', ['range' => 'all']), $fresh['auth']['user']['businessId']))->assertOk();
        $this->assertStringContainsString('Activity Beta', $this->printer->html);
        $this->assertStringNotContainsString('Activity Alpha', $this->printer->html);
    }

    /* ═══════════════ ١٧ و١٨ — المنصّةُ والمستندُ العامّ خارجَ الحارس ═══════════════ */

    public function test_platform_exports_and_public_documents_are_untouched(): void
    {
        foreach (['super-admin.businesses.xlsx', 'super-admin.export.businesses', 'super-admin.reports.pdf'] as $name) {
            $this->actingAs($this->platform)->get(route($name))->assertOk();
            $this->actingAs($this->platform)->get(route($name, [TenantExportGuard::PARAM => 999999]))->assertOk();
        }

        auth()->logout();
        $url = (string) PublicDocument::url($this->shop['alpha']['order']);
        $this->get($url)->assertOk();
        $this->get($url.'?'.TenantExportGuard::PARAM.'='.$this->shop['beta']['business']->id)->assertOk();

        foreach (['paper.show', 'super-admin.reports.pdf', 'super-admin.businesses.xlsx'] as $name) {
            $this->assertNotContains('export.tenant', Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
    }

    /* ═══════════════ ٢٢ — جردُ الأبواب: كلُّ ما يُخرج ملفًّا مصنَّف ═══════════════ */

    public function test_every_file_route_of_a_business_is_guarded_or_exempted_with_a_reason(): void
    {
        $listed = [...TenantExportGuard::REQUIRED, ...TenantExportGuard::DOCUMENTS, ...array_keys(TenantExportGuard::EXEMPT)];
        $this->assertSame(count($listed), count(array_unique($listed)), 'مسارٌ مذكورٌ مرّتين');

        $forgotten = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! in_array('GET', $route->methods(), true) || ! preg_match('/^(admin|pos)\./', $name)) {
                continue;
            }
            $looksLikeFile = preg_match('/(pdf|Pdf|xlsx|csv|download|attachment|receiptFile|statement|Statement|deliveryNote|taxInvoice|receipt$|export\.)/', $name)
                || preg_match('#/(pdf|xlsx|csv|download|receipt|statement|delivery-note|tax-invoice)(/|$)#', $route->uri());

            if ($looksLikeFile && ! in_array($name, $listed, true)) {
                $forgotten[] = $name;
            }
        }
        $this->assertSame([], $forgotten, 'مسارُ ملفٍّ لا يعرفه TenantExportGuard — صنّفه');

        foreach ([...TenantExportGuard::REQUIRED, ...TenantExportGuard::DOCUMENTS] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "{$name} لا وجود له");
            $this->assertContains('export.tenant', $route->gatherMiddleware(), "{$name} بلا حارس");
        }

        foreach (TenantExportGuard::EXEMPT as $name => $why) {
            $this->assertNotSame('', trim($why), "استُثني {$name} بلا سبب");
        }
    }

    public function test_the_page_and_the_server_name_the_expectation_the_same_way(): void
    {
        $ts = (string) file_get_contents(resource_path('js/lib/exportLink.ts'));
        $this->assertStringContainsString("export const EXPECTED_BUSINESS_PARAM = '".TenantExportGuard::PARAM."';", $ts);
        // وعنوانُ الصفحة لا يورّث توقُّعًا قديمًا
        $this->assertStringContainsString('here.delete(EXPECTED_BUSINESS_PARAM);', $ts);
    }

    /**
     * ولا رابطَ في الواجهة يُبنى لمسارٍ محروسٍ دون أن يحمل نشاطَ صفحته.
     *
     * إمّا عبر `ExportMenu` (تضيفه بنفسها)، أو `DocumentPanel`/`DocumentPreview`
     * (`url=`، تضيفانه بنفسيهما)، أو ملفوفًا بـ`tenant(...)`/`expectBusiness(...)`.
     */
    public function test_every_frontend_link_to_a_guarded_route_carries_the_page_business(): void
    {
        $names = array_map('preg_quote', [...TenantExportGuard::REQUIRED, ...TenantExportGuard::DOCUMENTS]);
        $call = "route\\('(?:".implode('|', $names).")'";
        $bare = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));
        foreach ($files as $file) {
            if (! preg_match('/\.(tsx?|jsx?)$/', $file->getFilename())) {
                continue;
            }
            foreach (file($file->getPathname()) as $i => $line) {
                if (! preg_match('/'.$call.'/', $line)) {
                    continue;
                }
                $ok = preg_match('/\b(xlsx|pdf|csv|importXlsx|url)=\{route\(/', $line)
                    || preg_match('/\b(tenant|expectBusiness)\(route\(/', $line);
                if (! $ok) {
                    $bare[] = str_replace(resource_path('js').'/', '', $file->getPathname()).':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $bare, 'رابطٌ لمسارٍ محروسٍ لا يحمل نشاطَ صفحته');
    }
}
