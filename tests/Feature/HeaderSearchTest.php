<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\GoodsReceiptNote;
use App\Models\JobTitle;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ReportData;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * صندوق البحث في الشريط العلوي — دليلُ الشاشات، ورموزُ الأوراق.
 *
 * كان يعرف أربعة أشياء: المنتج والطلب والعميل والمورّد — كلُّها تُطلب
 * بالاسم. ومن معه ورقةٌ في يده لا اسمَ فيها بل رمز — «GRN-000042»،
 * «TRX-000412»، «JV-000007» — لا يجد لها بابًا. ومن يريد شاشةً يعرف اسمها
 * ولا يعرف تحت أيّ قسمٍ دُفنت كان يفتح الأقسام واحدًا واحدًا.
 *
 * وهذا الملفّ يحرس الوعدين: أنّ ما يُعرض في الدليل يُفتح، وأنّ ما يُطلب
 * برمزه يُوجَد ويقود إلى صفٍّ لا إلى أوّل قائمة.
 */
class HeaderSearchTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّدي']);
    }

    /** نتائج البحث مسطّحةً: نصُّ كل صفٍّ ووجهتُه */
    private function search(string $q, ?User $as = null): array
    {
        $res = $this->actingAs($as ?? $this->owner)
            ->getJson(route('admin.search', ['q' => $q]));

        $res->assertOk();

        $out = [];
        foreach ($res->json('groups') ?? [] as $group) {
            foreach ($group['items'] as $item) {
                $out[] = ['group' => $group['title'], 'label' => $item['label'], 'url' => $item['url']];
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    private function labels(string $q, ?User $as = null): array
    {
        return array_column($this->search($q, $as), 'label');
    }

    /* ------------------------- رمز الإيصال ------------------------- */

    /**
     * الفاتورة تُوجَد برقمها — ولو أُلغيت.
     *
     * أكثرُ ما يُبحث عن رقم فاتورةٍ يقع عند الإرجاع والاعتراض، أي على فاتورةٍ
     * أُلغيت. وكان البحث يمرّ بـ`sold()` فيستثنيها، فيكتب التاجر رقمها ويُقال
     * له «لا نتائج» — ويظنّ أنّ بيعه ضاع من النظام. وشاشةُ المبيعات تعرضها
     * وتفتح صفحتها.
     */
    public function test_a_cancelled_receipt_is_found_by_its_code(): void
    {
        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-000042',
            'customer_name' => 'خالد', 'status' => Order::CANCELLED,
            'total' => 12, 'ordered_at' => now(), 'is_held' => false,
        ]);

        $hit = collect($this->search('INV-000042'))->firstWhere('label', 'INV-000042');

        $this->assertNotNull($hit, 'فاتورةٌ ملغاة لا تُوجَد برقمها');
        $this->assertSame(route('admin.orders.show', 'INV-000042'), $hit['url']);
    }

    /** والمعلّق يبقى خارجها: سلّةٌ لم تُبَع بعد ولا رقمَ لها يُطلب */
    public function test_a_held_basket_is_not_a_receipt(): void
    {
        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-000099',
            'status' => 'قيد التنفيذ', 'total' => 5, 'ordered_at' => now(), 'is_held' => true,
        ]);

        $this->assertNotContains('INV-000099', $this->labels('INV-000099'));
    }

    /* ------------------------ رموز السندات ------------------------ */

    /** كلُّ ورقةٍ لها رمزٌ تُطلب به، ووجهةٌ تُرشَّح بالرمز نفسه */
    public function test_every_document_is_found_by_its_code(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'ورد',
            'price' => 10, 'cost' => 5, 'quantity' => 5, 'alert_qty' => 1, 'active' => true,
        ]);

        PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id, 'number' => 'PO-000011',
            'status' => 'مُرسل', 'total' => 60, 'ordered_at' => now(),
        ]);

        SupplierInvoice::create([
            'business_id' => $this->business->id, 'supplier_id' => $this->supplier->id,
            'supplier_ref' => 'SR-000022', 'issued_at' => now()->toDateString(),
            'subtotal' => 60, 'tax' => 3, 'total' => 63,
        ]);

        GoodsReceiptNote::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id, 'number' => 'GRN-000033',
            'received_at' => now()->toDateString(), 'receiver' => 'أمين المخزن',
        ]);

        StockAdjustment::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'product_id' => $product->id, 'number' => 'SA-000044',
            'quantity_delta' => -1, 'cost_at_time' => 5, 'reason' => 'تالف',
            'adjusted_at' => now()->toDateString(),
        ]);

        JournalEntry::create([
            'business_id' => $this->business->id, 'number' => 'JV-000055',
            'entry_date' => now()->toDateString(), 'description' => 'قيد افتتاحي',
        ]);

        Expense::create([
            'business_id' => $this->business->id, 'reference' => 'EXP-000066',
            'type' => 'إيجار', 'amount' => 250, 'spent_at' => now()->subDay(),
        ]);

        $expected = [
            'PO-000011' => route('admin.purchases.orders', ['q' => 'PO-000011']),
            'SR-000022' => route('admin.purchases.invoices', ['q' => 'SR-000022']),
            'GRN-000033' => route('admin.inventory.receipts', ['q' => 'GRN-000033']),
            'SA-000044' => route('admin.inventory.adjustments', ['q' => 'SA-000044']),
            'JV-000055' => route('admin.finance.journal', ['q' => 'JV-000055']),
            'EXP-000066' => route('admin.expenses.index', ['month' => '', 'q' => 'EXP-000066']),
        ];

        foreach ($expected as $code => $url) {
            $hit = collect($this->search($code))->firstWhere('label', $code);

            $this->assertNotNull($hit, "لم يُوجَد السند {$code} برمزه");
            $this->assertSame($url, $hit['url'], "وجهةُ {$code} لا تُرشَّح برمزه");
        }
    }

    /**
     * ووجهةُ المصروف تفتح كلّ الشهور لا الشهر الجاري وحده.
     *
     * شاشة المصروفات تبدأ من شهرها، فرابطٌ برمزٍ من الشهر الماضي كان يقع على
     * الشهر الجاري ويعرض جدولًا فارغًا — نتيجةُ بحثٍ تقود إلى «لا شيء».
     */
    public function test_an_old_expense_opens_on_a_screen_that_shows_it(): void
    {
        Expense::create([
            'business_id' => $this->business->id, 'reference' => 'EXP-000077',
            'type' => 'إيجار', 'description' => 'إيجار قديم', 'amount' => 300,
            'spent_at' => now()->subMonths(3),
        ]);

        $hit = collect($this->search('EXP-000077'))->firstWhere('label', 'EXP-000077');
        $this->assertNotNull($hit);

        $this->actingAs($this->owner)->get($hit['url'])
            ->assertOk()
            ->assertSee('EXP-000077');
    }

    /* ----------------------- رمز المعاملة ----------------------- */

    /** المعاملة اليدوية تُوجَد بمرجعها، ووجهتُها تعرض صفَّها وحده */
    public function test_a_transaction_is_found_by_its_reference_and_lands_on_its_row(): void
    {
        Transaction::create([
            'business_id' => $this->business->id, 'reference' => 'TRX-000412',
            'description' => 'إيجار المحل', 'method' => 'نقدي', 'type' => 'مصروف',
            'amount' => 200, 'occurred_at' => now()->subMonths(4),
        ]);
        Transaction::create([
            'business_id' => $this->business->id, 'reference' => 'TRX-000413',
            'description' => 'كهرباء', 'method' => 'نقدي', 'type' => 'مصروف',
            'amount' => 30, 'occurred_at' => now()->subMonths(4),
        ]);

        $hit = collect($this->search('TRX-000412'))->firstWhere('label', 'TRX-000412');

        $this->assertNotNull($hit, 'معاملةٌ لا تُوجَد بمرجعها');
        $this->assertSame(
            route('admin.reports.finance', ['range' => 'all', 'q' => 'TRX-000412']),
            $hit['url'],
        );

        // والوجهةُ ترشّح فعلًا: مُرشِّحٌ لا يُرشِّح يقود إلى دفترٍ كامل
        $rows = ReportData::finance($this->business->id, ['range' => 'all', 'q' => 'TRX-000412'])['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('TRX-000412', $rows[0]['reference']);
    }

    /** والبيان مثلها: من يتذكّر «إيجار المحل» ولا يتذكّر رمزه يجدها به */
    public function test_the_finance_filter_reads_the_description_too(): void
    {
        Transaction::create([
            'business_id' => $this->business->id, 'reference' => 'TRX-000500',
            'description' => 'إيجار المحل', 'method' => 'نقدي', 'type' => 'مصروف',
            'amount' => 200, 'occurred_at' => now(),
        ]);

        $rows = ReportData::finance($this->business->id, ['range' => 'all', 'q' => 'إيجار'])['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('TRX-000500', $rows[0]['reference']);
    }

    /**
     * ومعاملةُ البيع لا تُعرض مرّتين.
     *
     * نقطةُ البيع تكتب لكل فاتورةٍ معاملةَ دخلٍ مرجعُها رقمُ الفاتورة نفسه،
     * فالبحث عن رقمٍ واحد كان سيردّ صفَّين برمزٍ واحد — والفاتورة أنفعُ
     * الوجهتين: فيها الأصناف والعميل وزرّ الطباعة.
     */
    public function test_a_sale_shows_once_not_twice(): void
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-000700',
            'status' => 'مكتمل', 'total' => 40, 'ordered_at' => now(), 'is_held' => false,
        ]);
        Transaction::create([
            'business_id' => $this->business->id, 'order_id' => $order->id,
            'reference' => 'INV-000700', 'description' => 'مبيعات نقطة البيع',
            'method' => 'نقدي', 'type' => 'دخل', 'amount' => 40, 'occurred_at' => now(),
        ]);

        $hits = array_filter($this->labels('INV-000700'), fn ($l) => $l === 'INV-000700');

        $this->assertCount(1, $hits, 'الفاتورة ومعاملتُها صفّان برمزٍ واحد');
    }

    /* --------------------- الحدود والصلاحيات --------------------- */

    /**
     * ولا يُسأل عن الأوراق إلا حين يحتمل الطلبُ أن يكون رمزًا.
     *
     * الرموز كلُّها بادئةٌ ورقم، فالبحث عن اسمٍ لا يستحقّ سبعة استعلامات على
     * سبعة جداول لا يمكن أن يحمل أيٌّ منها ذلك الاسم في خانة رمزه — وهذا
     * يجري مع كلّ ضغطة حرفٍ في الشريط.
     */
    public function test_a_query_without_digits_asks_no_document_table(): void
    {
        GoodsReceiptNote::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id, 'number' => 'GRN-ورد',
            'received_at' => now()->toDateString(), 'receiver' => 'أمين',
        ]);

        $groups = array_unique(array_column($this->search('ورد'), 'group'));

        $this->assertNotContains('السندات والمعاملات', $groups);
    }

    /**
     * والبحث لا يتجاوز صلاحيات صاحبه.
     *
     * رمزٌ يُقرأ من مربّع البحث ثمّ يصطدم بـ403 عند الضغط يعني أنّ البيانات
     * وصلت قبل الباب المغلق.
     */
    public function test_codes_stop_at_the_sections_their_reader_owns(): void
    {
        PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id, 'number' => 'PO-000088',
            'status' => 'مُرسل', 'total' => 10, 'ordered_at' => now(),
        ]);

        $seller = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'sales@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->assertContains('PO-000088', $this->labels('PO-000088'));
        $this->assertNotContains('PO-000088', $this->labels('PO-000088', $seller));
    }

    /** ولا رموزَ من متجرٍ آخر */
    public function test_codes_do_not_cross_between_shops(): void
    {
        $other = Business::create(['name' => 'متجرهم', 'type' => 'عام', 'status' => 'نشط']);

        JournalEntry::create([
            'business_id' => $other->id, 'number' => 'JV-000909',
            'entry_date' => now()->toDateString(), 'description' => 'قيدهم',
        ]);

        $this->assertNotContains('JV-000909', $this->labels('JV-000909'));
    }

    /* ------------------------- دليل الصفحات ------------------------- */

    /**
     * كلُّ صفحةٍ في الدليل تُفتح.
     *
     * الدليل يُبنى من مصادره — القائمة الجانبية، وتبويبات الأقسام، وبطاقات
     * الإعدادات — فاسمُ مسارٍ يُحذف من الخادم لا يُخطئ عند البناء: يسقط في
     * المتصفّح لحظةَ تُفتح القائمة، فتبيضّ الصفحة كلُّها عند من نقر حقل
     * البحث. والعطبُ لا يظهر لمن كتب الحذف لأنّه لا ينقر ذلك الحقل.
     */
    public function test_every_page_the_directory_offers_has_a_route(): void
    {
        $known = collect(Route::getRoutes())->map(fn ($r) => $r->getName())->filter()->flip();
        $dead = [];

        $sources = [
            'resources/js/lib/nav.ts' => "/\\broute:\\s*'([a-z][a-zA-Z0-9_.-]*\\.[a-zA-Z0-9_.-]+)'/",
            'resources/js/Components/SectionTabs.tsx' => "/\\brouteName:\\s*'([a-z][a-zA-Z0-9_.-]*\\.[a-zA-Z0-9_.-]+)'/",
            'resources/js/lib/pages.ts' => "/route\\(\\s*'([a-z][a-zA-Z0-9_.-]*\\.[a-zA-Z0-9_.-]+)'/",
        ];

        foreach ($sources as $file => $pattern) {
            $code = file_get_contents(base_path($file));
            preg_match_all($pattern, $code, $m);

            $this->assertNotEmpty($m[1], "لم يُقرأ أيّ مسارٍ من {$file} — تبدّلت صيغته والحارس صار أعمى");

            foreach ($m[1] as $name) {
                if (! $known->has($name)) {
                    $dead[] = "{$file} → {$name}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($dead)), 'دليلُ الصفحات يَعِد بأبوابٍ لا وجود لها');
    }

    /**
     * وفهرسُ التقارير يصل الواجهةَ مبنيًّا من مصدره الوحيد.
     *
     * الدليل يقرؤه ليعرض التقارير السبعة عشر. ونسخُه إلى الواجهة كان سيصنع
     * فهرسين يفترقان عند أوّل تقريرٍ يُضاف — انظر HandleInertiaRequests.
     */
    public function test_the_directory_is_handed_the_report_index(): void
    {
        $props = $this->actingAs($this->owner)->get(route('admin.dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNotEmpty($props['reportPages']);
        $this->assertSame(
            ['title', 'href'],
            array_keys($props['reportPages'][0]),
            'فهرس التقارير يحمل أكثر ممّا يحتاجه الدليل',
        );
        $this->assertContains(
            route('admin.reports.finance'),
            array_column($props['reportPages'], 'href'),
        );
    }

    /** ولا يُرسَل الفهرس لمن لا لوحةَ تاجرٍ له */
    public function test_a_platform_admin_gets_no_merchant_report_index(): void
    {
        $root = User::create([
            'name' => 'المشغّل', 'email' => 'root@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $props = $this->actingAs($root)->get(route('super-admin.dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNull($props['reportPages']);
    }

    /** وحسابٌ لا يملك التقارير لا يرى منها شيئًا في دليله */
    public function test_the_report_index_stops_at_what_its_reader_owns(): void
    {
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'cash@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->assertSame([], Reports::forUser($cashier));
        $this->assertNotSame([], Reports::forUser($this->owner));
    }
}
