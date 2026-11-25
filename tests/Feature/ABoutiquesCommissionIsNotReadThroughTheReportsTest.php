<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Boutiques;
use App\Support\Document\Pdf\Driver;
use App\Support\Ledger;
use App\Support\Pdf;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * «التقارير» بلا «المصروفات» تقرأ المبيعات — ولا تقرأ عمولةَ بوتيكٍ ولا نسبتَه ولا صافيَه.
 *
 * كان تقريرُ المبيعات بنطاق بوتيكٍ يُرسل العمولةَ والصافي لكلّ من يملك «التقارير»: في
 * الشاشة والتغذية وCSV وXLSX وPDF. وسجلُّ النشاط يكتب «تسوية بوتيك … بمبلغ …» فيقرؤه
 * من يملك «الإعدادات» أو «الموظفين». والقراءةُ (`BoutiqueAccess::read`) هي «المصروفات».
 *
 * والأرقامُ هنا مميّزةٌ لا تقع صدفةً، ويُفتح بها كلُّ بابٍ يفتحه الحساب: فبابٌ جديدٌ يُخرجها
 * يُسقط الاختبار وإن لم يُذكر اسمُه.
 */
class ABoutiquesCommissionIsNotReadThroughTheReportsTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2027-02';

    /** نسبةُ 17.53٪ على 3 × 8.17 = 24.51: عمولةُ 4.297 وصافي 20.213، ومع المكوّن 26.643 */
    private const SECRETS = ['17.53', '17,53', '4.297', '4.2966', '20.213', '20.2134', '26.643', 'شرط سري', 'مرجع سري'];

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Product $piece;

    private Order $order;

    private BoutiqueSettlement $sheet;

    private Customer $customer;

    private ?Driver $pdf = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        $this->shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true, 'boutique_components_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = $this->user('owner', 'admin', null);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'عميل', 'phone' => '92222222']);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'لمى', 'commission_rate' => 17.53, 'active' => true, 'notes' => 'شرط سري', 'returns_terms' => 'all', 'returns_terms_ref' => 'مرجع سري']);
        $this->piece = $this->product('قطعة', 8.17, $this->lama->id);
        $component = $this->product('مكوّن', 6.43, $this->lama->id);
        $gift = $this->product('باقة', 31);
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $gift->id, 'component_product_id' => $component->id, 'quantity' => 1, 'wastage_percent' => 0]);
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->piece->id, 'name' => 'قطعة', 'qty' => 3, 'price' => 8.17], ['id' => $gift->id, 'name' => 'باقة', 'qty' => 1, 'price' => 31]],
            'payment_method' => 'نقدي', 'client_uuid' => 'sale',
        ])->assertOk();
        $this->order = Order::where('business_id', $this->shop->id)->sole();
        // مصروفٌ آخر: مجموعُ المصروفات في التقارير العاديّة ليس صافيَ البوتيك
        $this->actingAs($this->owner)->post(route('admin.expenses.store'), ['type' => 'إيجار', 'amount' => 77.111, 'method' => 'نقدي', 'status' => 'مدفوع', 'spent_at' => '2027-02-10'])->assertSessionHasNoErrors();
        $this->sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $this->assertEquals([24.51, 4.297, 20.213], [(float) $this->sheet->gross, (float) $this->sheet->commission, (float) $this->sheet->net], 'الأرقامُ المميّزة تغيّرت — الاختبارُ لا يقيس شيئًا');

        // ورقةُ PDF تُلتقط نصًّا: mPDF يكتب الأرقامَ رموزَ خطٍّ لا تُقرأ في الملفّ
        $this->pdf = Pdf::swap(new class implements Driver
        {
            public static string $html = '';

            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                self::$html .= $html.$name.$runningHeader.$context;

                return response('%PDF');
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                self::$html .= $html.$name;

                return response('%PDF');
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 1.0;
            }
        });
    }

    protected function tearDown(): void
    {
        Pdf::swap($this->pdf);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $mail, string $role, ?array $permissions): User
    {
        return User::create(['business_id' => $this->shop->id, 'name' => $mail, 'email' => $mail.'@abaad.om', 'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions]);
    }

    private function product(string $name, float $price, ?int $boutique = null): Product
    {
        return Product::create(['business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $boutique]);
    }

    /** نصُّ الجواب كيفما خرج: صفحةً أو JSON أو CSV أو XLSX مفكوكًا أو PDF بما رُسم منه */
    private function body(TestResponse $res): string
    {
        $base = $res->baseResponse;
        $body = match (true) {
            $base instanceof StreamedResponse => $res->streamedContent(),
            $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
            default => (string) $res->getContent(),
        };
        if (str_starts_with($body, 'PK')) {
            $file = tempnam(sys_get_temp_dir(), 'x');
            file_put_contents($file, $body);
            $zip = new ZipArchive;
            $body = '';
            if ($zip->open($file) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $body .= $zip->getFromIndex($i);
                }
                $zip->close();
            }
            unlink($file);
        }
        $driver = Pdf::driver();

        return $body."\n".html_entity_decode($body)."\n".$driver::$html;
    }

    private function fetch(User $user, string $name, array $params = []): TestResponse
    {
        $driver = Pdf::driver();
        $driver::$html = '';

        return $this->actingAs($user)->get($this->exportRoute($name, $params, (int) $this->shop->id));
    }

    /** أبوابُ تقرير المبيعات الخمسة — بنطاق البوتيك */
    private const DOORS = ['admin.reports.sales', 'admin.reports.feed', 'admin.export.reports', 'admin.reports.xlsx', 'admin.reports.pdf'];

    private function scope(): array
    {
        return ['boutique' => (string) $this->lama->id, 'period' => 'month', 'month' => self::PERIOD];
    }

    /** @return array<string, User> */
    private function withoutReading(): array
    {
        return [
            'التقارير وحدها' => $this->user('r1', 'sales', ['reports']),
            'كحساب كاشير مخصّص له التقارير والإعدادات واعتماد السند' => $this->user('r2', 'cashier', ['customers', 'dashboard', 'orders', 'pos', 'preparation', 'products', 'purchases', 'reports', 'settings', Permissions::INVOICE_APPROVE]),
            'كلُّ قسمٍ إلّا المصروفات والمالية' => $this->user('r3', 'sales', array_values(array_diff(Permissions::SECTIONS, ['expenses', 'finance']))),
            'مفتاحا الإصدار والسداد بلا المصروفات' => $this->user('r4', 'sales', ['dashboard', 'products', 'reports', Permissions::INVOICE_APPROVE, Permissions::INVOICE_PAY]),
        ];
    }

    /* ═══════════ تقريرُ المبيعات ═══════════ */

    public function test_the_sales_report_carries_the_boutiques_sales_and_neither_its_commission_nor_its_net(): void
    {
        foreach ($this->withoutReading() as $who => $user) {
            $props = $this->fetch($user, 'admin.reports.sales', $this->scope())->assertOk()->viewData('page')['props'];
            $this->assertSame(['gross', 'invoices', 'lines', 'orders', 'quantity'], collect($props['boutiqueTotals'])->keys()->sort()->values()->all(), $who);
            $this->assertEquals([24.51, 3.0, 1], [$props['boutiqueTotals']['gross'], $props['boutiqueTotals']['quantity'], $props['boutiqueTotals']['orders']], "{$who}: التقريرُ العاديّ ناقص");
            $this->assertSame('لمى', $props['boutique']['name'], $who);

            $feed = $this->fetch($user, 'admin.reports.feed', $this->scope())->assertOk()->json('boutiqueTotals');
            $this->assertArrayNotHasKey('commission', $feed, $who);
            $this->assertArrayNotHasKey('net', $feed, $who);

            foreach (self::DOORS as $door) {
                $body = $this->body($this->fetch($user, $door, $this->scope())->assertOk());
                foreach ([...self::SECRETS, 'عمولة المتجر', 'المستحق للبوتيك'] as $secret) {
                    if ($door === 'admin.reports.sales' && in_array($secret, ['عمولة المتجر', 'المستحق للبوتيك'], true)) {
                        continue;      // الصفحةُ تحمل قاموسَ الترجمة كلَّه — والبطاقةُ تُفحص في الواجهة
                    }
                    $this->assertStringNotContainsString($secret, $body, "{$who} — {$door}: «{$secret}»");
                }
                $this->assertTrue(str_contains($body, '24.51'), "{$who} — {$door}: مبيعُ البوتيك غائبٌ — حُجب أكثرُ ممّا ينبغي");
            }
        }
    }

    public function test_who_reads_the_money_reads_it_in_every_form_as_before(): void
    {
        $readers = [
            'المالك' => $this->owner, 'المدير' => $this->user('m', 'manager', null), 'المحاسب' => $this->user('a', 'accountant', null),
            'مخصّص: تقارير ومصروفات' => $this->user('c', 'sales', ['reports', 'expenses']),
        ];
        foreach ($readers as $who => $user) {
            $props = $this->fetch($user, 'admin.reports.sales', $this->scope())->assertOk()->viewData('page')['props'];
            $this->assertEquals([24.51, 4.297, 20.213], [$props['boutiqueTotals']['gross'], $props['boutiqueTotals']['commission'], $props['boutiqueTotals']['net']], $who);
            $this->assertEquals([4.297, 20.213], [$this->fetch($user, 'admin.reports.feed', $this->scope())->json('boutiqueTotals.commission'), $this->fetch($user, 'admin.reports.feed', $this->scope())->json('boutiqueTotals.net')], $who);
            foreach (['admin.export.reports', 'admin.reports.xlsx', 'admin.reports.pdf'] as $door) {
                $body = $this->body($this->fetch($user, $door, $this->scope())->assertOk());
                foreach (['عمولة المتجر', 'المستحق للبوتيك', '4.297', '20.213'] as $shown) {
                    $this->assertStringContainsString($shown, $body, "{$who} — {$door}");
                }
            }
        }
    }

    /** «منتجات المتجر» و«كل المبيعات» ليس فيهما مالُ بوتيك — لا يتغيّران على أحد */
    public function test_the_whole_report_and_the_shops_own_scope_are_the_same_for_every_reader(): void
    {
        $reader = $this->user('only', 'sales', ['reports']);
        foreach ([['range' => 'month'], ['range' => 'month', 'boutique' => Boutiques::OWN]] as $params) {
            $mine = $this->fetch($reader, 'admin.reports.sales', $params)->assertOk()->viewData('page')['props'];
            $owners = $this->fetch($this->owner, 'admin.reports.sales', $params)->assertOk()->viewData('page')['props'];
            foreach (['summary', 'boutiqueTotals', 'salesSeries', 'topSellingProducts', 'paymentDistribution', 'boutiques'] as $key) {
                $this->assertEquals($owners[$key], $mine[$key], $key);
            }
        }
        $this->assertStringContainsString('الربح', $this->body($this->fetch($reader, 'admin.export.reports', ['range' => 'month'])), 'التقريرُ العاديّ لم يبق عاديًّا');
        $this->fetch($reader, 'admin.reports.profit')->assertOk();
    }

    /* ═══════════ سجلُّ النشاط ═══════════ */

    public function test_the_activity_log_says_a_sheet_was_issued_without_its_amount(): void
    {
        $clerk = $this->user('clerk', 'manager', null);
        $this->actingAs($clerk)->put(route('admin.boutiques.update', $this->lama->id), ['name' => 'لمى', 'commission_rate' => 17.53, 'active' => true, 'returns_terms' => 'sellable', 'returns_terms_ref' => 'مرجع سري'])->assertSessionHasNoErrors();
        ActivityLog::where('subject_type', 'boutique_settlement')->update(['user_id' => $clerk->id, 'user_name' => 'clerk']);
        $stored = ActivityLog::where('business_id', $this->shop->id)->orderBy('id')->pluck('description', 'id')->all();
        $this->assertContains('تسوية بوتيك لمى عن 2027-02 بمبلغ 20.213', $stored);
        $this->assertContains('شرط مرتجع البوتيك لمى: sellable — مرجع سري', $stored);

        $reader = $this->user('log', 'sales', array_values(array_diff(Permissions::SECTIONS, ['expenses', 'finance'])));
        $seen = function (User $u) use ($clerk): array {
            $texts = fn (array $rows, string $key) => array_column($rows, $key);

            return [
                'تقرير النشاط' => $texts($this->fetch($u, 'admin.reports.activity', ['range' => 'all'])->assertOk()->viewData('page')['props']['rows'], 'description'),
                'سجلّ النشاط' => $texts($this->fetch($u, 'admin.activity.index')->assertOk()->viewData('page')['props']['logs'], 'description'),
                'صفحة الموظف' => [json_encode($this->fetch($u, 'admin.employees.show', [$clerk->id])->assertOk()->viewData('page')['props'], JSON_UNESCAPED_UNICODE)],
                'أحدث الأنشطة' => $this->actingAs($u) ? array_column(\App\Support\Demo::activities(50), 'text') : [],
            ];
        };
        foreach ($seen($reader) as $where => $texts) {
            $text = implode("\n", $texts);
            $this->assertStringContainsString('تسوية بوتيك لمى عن 2027-02', $text, "{$where}: السطرُ نفسُه غاب");
            foreach (['20.213', 'بمبلغ', 'مرجع سري'] as $secret) {
                $this->assertStringNotContainsString($secret, $text, $where);
            }
        }
        foreach (['csv', 'xlsx', 'pdf'] as $as) {
            $body = $this->body($this->fetch($reader, 'admin.reports.export.'.$as, ['report' => 'activity', 'range' => 'all'])->assertOk());
            $this->assertStringNotContainsString('20.213', $body, $as);
            $this->assertStringNotContainsString('مرجع سري', $body, $as);
            $this->assertStringContainsString('تسوية بوتيك لمى عن 2027-02', $body, $as);
        }

        // ولا يُستخرج المبلغُ بالبحث عنه
        $find = fn (User $u, string $q) => $this->fetch($u, 'admin.activity.index', ['q' => $q])->assertOk()->viewData('page')['props']['logs'];
        $this->assertSame([], $find($reader, '20.213'));
        $this->assertSame([], $find($reader, 'مرجع سري'));
        $this->assertNotSame([], $find($reader, 'clerk'), 'البحثُ باسم الموظف بقي');
        $this->assertNotSame([], $find($this->owner, '20.213'));

        // ومن يقرأ المال يقرأ السجلَّ كما كُتب، والمكتوبُ لم يُمسّ
        foreach ($seen($this->owner) as $where => $texts) {
            $this->assertStringContainsString('بمبلغ 20.213', implode("\n", $texts), $where);
        }
        $this->assertSame($stored, ActivityLog::whereIn('id', array_keys($stored))->orderBy('id')->pluck('description', 'id')->all());
    }

    /* ═══════════ كلُّ باب ═══════════ */

    /**
     * كلُّ بابِ قراءةٍ يفتحه الحساب: لا رقمَ سرّيٍّ في جوابه، بنطاق البوتيك وبغيره.
     *
     * و«المالية» خارجَه عمدًا: من مُنحها يقرأ الدفترَ والمبالغَ المستحقّة، وهي صلاحيّتُه.
     */
    public function test_no_door_an_account_without_the_expenses_opens_carries_a_boutiques_money(): void
    {
        $accounts = $this->withoutReading() + [
            'مبيعات بدوره' => $this->user('s', 'sales', null), 'مخزون بدوره' => $this->user('i', 'inventory', null),
            'توصيل بدوره' => $this->user('d', 'delivery', null), 'كاشير بدوره' => $this->user('k', 'cashier', null),
            'منتجات ومبيعات' => $this->user('p', 'cashier', ['customers', 'orders', 'pos', 'preparation', 'products']),
        ];
        foreach ($accounts as $who => $user) {
            $opened = 0;
            foreach (Route::getRoutes() as $route) {
                $name = (string) $route->getName();
                if (! in_array('GET', $route->methods(), true) || ! preg_match('/^(admin|pos)\./', $name)) {
                    continue;
                }
                preg_match_all('/\{(\w+)\??\}/', $route->uri(), $m);
                $params = [];
                foreach ($m[1] as $key) {
                    $params[$key] = match (true) {
                        $key === 'report' => 'sales', $key === 'number' => $this->order->number, $key === 'order' => $this->order->id,
                        str_contains($name, 'boutique') => $this->lama->id, str_contains($name, 'products') => $this->piece->id,
                        str_contains($name, 'customer') => $this->customer->id, str_contains($name, 'employees') => $this->owner->id, default => 1,
                    };
                }
                foreach ([$this->scope(), ['q' => 'لمى', 'range' => 'all']] as $extra) {
                    $res = $this->fetch($user, $name, $params + $extra);
                    if ($res->baseResponse->getStatusCode() !== 200) {
                        continue;
                    }
                    $opened++;
                    $body = $this->body($res);
                    foreach (self::SECRETS as $secret) {
                        $this->assertStringNotContainsString($secret, $body, "{$who} — {$name}: «{$secret}»");
                    }
                }
            }
            $this->assertGreaterThan(4, $opened, "{$who}: لم يُفتح له باب — الاختبارُ لا يقيس شيئًا");
        }
    }

    /** وأجوبةُ البيع والتعليق لا تحمل نسبةَ البوتيك إلى الصندوق */
    public function test_the_tills_answers_carry_no_rate(): void
    {
        $cashier = $this->user('till', 'cashier', null);
        $item = ['id' => $this->piece->id, 'name' => 'قطعة', 'qty' => 3, 'price' => 8.17];
        foreach ([
            $this->actingAs($cashier)->postJson('/pos/checkout', ['items' => [$item], 'payment_method' => 'نقدي', 'client_uuid' => 'u2']),
            $this->actingAs($cashier)->postJson(route('pos.hold'), ['items' => [$item], 'kind' => 'hold']),
        ] as $res) {
            $res->assertOk();
            foreach (self::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, (string) $res->getContent());
            }
        }
    }

    /** والصلاحيّاتُ كما مُنحت: لا حسابَ تغيّر مفتاحُه */
    public function test_no_account_is_granted_or_loses_a_permission(): void
    {
        $this->withoutReading();
        $before = User::where('business_id', $this->shop->id)->orderBy('id')->get()->map(fn ($u) => [$u->id, $u->role, $u->permissions])->all();
        foreach (User::where('business_id', $this->shop->id)->get() as $u) {
            $this->fetch($u, 'admin.reports.sales', $this->scope());
        }
        $this->assertSame($before, User::where('business_id', $this->shop->id)->orderBy('id')->get()->map(fn ($u) => [$u->id, $u->role, $u->permissions])->all());
    }
}
