<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Books;
use App\Support\CostsAndLosses;
use App\Support\Document\Pdf\Driver as PdfDriver;
use App\Support\Ledger;
use App\Support\Pdf;
use App\Support\Profitability;
use App\Support\ReportData;
use App\Support\StockLosses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * التكاليف والخسائر — من دفتر الأستاذ وحده، وكلُّ حدثٍ مرّةً واحدة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - المصدرُ القيودُ المرحَّلة على حسابات «مصروف»: لا جدولُ المصروفات ولا
 *     المسيرة ولا سنداتُ المورّدين ولا الأصول فوقها.
 *   - الراتبُ يوم الاعتماد لا يوم الصرف، والهالكُ بقيده لا بصفّ مصروفه،
 *     ومشترياتُ المخزون وسدادُ المورّد والمسحوباتُ والتحويلُ وشراءُ الأصل
 *     وضريبةُ المدخلات خارجه، والإهلاكُ المرحَّل وحده داخله.
 *   - العكسُ يُلغي أصلَه، ومسوّدةٌ لا تُعدّ، وما لا يُعرف مفتاحُه «أخرى».
 *   - الفئةُ مجموعُ حساباتها، والإجماليُّ مجموعُ الفئات، والصفُّ مجموعُ سطوره،
 *     والملفّاتُ أرقامُ الشاشة.
 *   - المتجرُ والفرعُ والصلاحيةُ في الشاشة والتفصيل والملفّات، والقراءةُ لا تكتب.
 *   - و«المصروفات» و«صافي الربح» على حالهما.
 */
class CostsAndLossesAreReadFromTheLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const SEPT = ['from' => '2026-09-01', 'to' => '2026-09-30'];

    private Business $shop;

    private Business $other;

    private Branch $muscat;

    private Branch $sohar;

    private User $owner;

    private User $theirOwner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 10:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner, $this->muscat, $this->sohar] = $this->business('متجري', 'o@abaad.om');
        [$this->other, $this->theirOwner] = $this->business('الجار', 'x@other.om');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: User, 2: Branch, 3: Branch} */
    private function business(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        $a = Branch::create(['business_id' => $shop->id, 'name' => 'مسقط']);
        $b = Branch::create(['business_id' => $shop->id, 'name' => 'صحار']);
        Ledger::seedChart($shop->id);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$shop, $owner, $a, $b];
    }

    private function entry(array $lines, string $date, string $source = 'يدوي', ?Branch $branch = null, $sourceable = null, ?Business $shop = null): JournalEntry
    {
        return Ledger::post(($shop ?? $this->shop)->id, 'قيد اختبار', $lines, Carbon::parse($date), $source, $branch?->id, null, $sourceable);
    }

    private function scope(array $filters = [], ?User $user = null, ?Business $shop = null): array
    {
        return CostsAndLosses::scope(($shop ?? $this->shop)->id, $filters + self::SEPT, $user);
    }

    private function report(array $filters = [], ?User $user = null, ?Business $shop = null): array
    {
        $shop ??= $this->shop;

        return CostsAndLosses::report($shop->id, $this->scope($filters, $user, $shop));
    }

    private function category(array $report, string $key): array
    {
        return collect($report['categories'])->firstWhere('key', $key);
    }

    private function page(array $query = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)->get(route('admin.reports.costs', $query + self::SEPT));
    }

    private function sale(float $cost, string $date, ?Branch $branch = null): Order
    {
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 20, 'cost' => $cost, 'quantity' => 50]);
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => ($branch ?? $this->muscat)->id,
            'number' => 'INV-'.uniqid(), 'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'is_held' => false,
            'payment_method' => 'نقدي', 'subtotal' => 20, 'discount' => 0, 'tax' => 0, 'total' => 20,
            'ordered_at' => Carbon::parse($date),
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'name' => 'باقة',
            'price' => 20, 'quantity' => 1, 'cost' => $cost, 'total' => 20]);
        Books::recordSale($order);

        return $order;
    }

    private function expense(float $amount, string $type, string $date, ?Branch $branch = null): Expense
    {
        $expense = Expense::create([
            'business_id' => $this->shop->id, 'branch_id' => $branch?->id, 'type' => $type,
            'description' => $type, 'amount' => $amount, 'method' => 'نقدي', 'status' => Expense::PAID,
            'spent_at' => $date,
        ]);
        Books::recordExpense($expense);

        return $expense;
    }

    /* ═══════════ ما يُعدّ مرّةً واحدة ═══════════ */

    public function test_a_posted_expense_appears_once_in_its_category(): void
    {
        $this->expense(30, 'إيجار', '2026-09-05', $this->muscat);

        $r = $this->report();
        $this->assertSame(30.0, $this->category($r, CostsAndLosses::OPERATING)['current']);
        $this->assertSame(30.0, $r['summary']['total']);
        $this->assertSame('5300', $this->category($r, CostsAndLosses::OPERATING)['rows'][0]['code']);
    }

    public function test_cogs_from_a_sale_appears_exactly_once_and_revenue_never(): void
    {
        $this->sale(7.5, '2026-09-06');

        $r = $this->report();
        $this->assertSame(7.5, $r['summary']['cost_of_sales']);
        $this->assertSame(7.5, $r['summary']['total'], 'الإيرادُ أو المخزونُ دخلا المجموع');
    }

    public function test_payroll_is_a_cost_when_approved_and_not_again_when_paid(): void
    {
        // كما يكتبهما `PayrollRunController::approve` و`PayrollPaymentController::store`
        $this->entry([
            ['account' => 'salaries', 'debit' => 500, 'memo' => 'سارة'],
            ['account' => 'other_income', 'credit' => 20],
            ['account' => 'salaries_payable', 'credit' => 480],
        ], '2026-09-30', 'رواتب');
        $this->entry([
            ['account' => 'salaries_payable', 'debit' => 480],
            ['account' => 'cash', 'credit' => 480],
        ], '2026-09-30', 'صرف رواتب');

        $r = $this->report();
        $this->assertSame(500.0, $this->category($r, CostsAndLosses::EMPLOYEE)['current'], 'الراتبُ الإجماليّ مرّةً واحدة');
        $this->assertSame(500.0, $r['summary']['operating']);
        $this->assertSame(500.0, $r['summary']['total'], 'الصرفُ عُدّ تكلفةً ثانية');
    }

    public function test_supplier_inventory_invoice_and_its_payment_are_not_costs_until_sold(): void
    {
        // كما يكتبه `SupplierInvoices::approve` — مخزونٌ وضريبةُ مدخلات وذمّة
        $this->entry([
            ['account' => 'inventory', 'debit' => 100],
            ['account' => 'tax_input', 'debit' => 5],
            ['account' => 'payable', 'credit' => 105],
        ], '2026-09-03', 'سند مورّد');
        $this->entry([['account' => 'payable', 'debit' => 105], ['account' => 'bank', 'credit' => 105]], '2026-09-10', 'سداد مورّد');

        $this->assertSame(0.0, $this->report()['summary']['total']);

        // والبضاعةُ تصير تكلفةً حين تُباع
        $this->sale(12, '2026-09-12');
        $this->assertSame(12.0, $this->report()['summary']['total']);
    }

    public function test_a_supplier_bill_that_posts_to_an_expense_account_is_read_through_the_ledger(): void
    {
        $this->entry([['account' => 'utilities', 'debit' => 40], ['account' => 'payable', 'credit' => 40]], '2026-09-08', 'سند مورّد');

        $r = $this->report();
        $this->assertSame(40.0, $this->category($r, CostsAndLosses::OPERATING)['current']);
    }

    public function test_a_stock_loss_is_counted_once_though_it_writes_an_expense_row_too(): void
    {
        DB::transaction(fn () => StockLosses::record($this->shop->id, 9, true, 'تلف ورد', Carbon::parse('2026-09-09'), $this->muscat->id));

        $this->assertSame(1, Expense::where('business_id', $this->shop->id)->count(), 'الوصفةُ لم تكتب صفَّ المصروف');

        $r = $this->report();
        $this->assertSame(9.0, $r['summary']['losses']);
        $this->assertSame(9.0, $this->category($r, CostsAndLosses::INVENTORY_LOSSES)['current']);
        $this->assertSame(0.0, $r['summary']['other'], 'الهالكُ عُدّ في «الأخرى» أيضًا');
        $this->assertSame(9.0, $r['summary']['total']);

        // وزيادةُ الجرد تُنقصه — صافي الهالك
        DB::transaction(fn () => StockLosses::record($this->shop->id, 4, false, 'زيادة عدّ', Carbon::parse('2026-09-10'), $this->muscat->id));
        $this->assertSame(5.0, $this->report()['summary']['losses']);
    }

    public function test_drawings_transfers_asset_purchase_and_input_tax_are_not_costs(): void
    {
        $this->entry([['account' => 'drawings', 'debit' => 50], ['account' => 'cash', 'credit' => 50]], '2026-09-04');
        $this->entry([['account' => 'bank', 'debit' => 70], ['account' => 'cash', 'credit' => 70]], '2026-09-04');
        $asset = FixedAsset::create(['business_id' => $this->shop->id, 'name' => 'ثلاجة', 'cost' => 1200,
            'salvage_value' => 0, 'life_months' => 12, 'purchased_at' => '2026-01-01', 'status' => 'نشط', 'accumulated' => 0]);
        $this->entry([['account' => 'fixed_assets', 'debit' => 1200], ['account' => 'cash', 'credit' => 1200]], '2026-09-04', 'أصل ثابت', null, $asset);
        $this->entry([['account' => 'tax_input', 'debit' => 3], ['account' => 'cash', 'credit' => 3]], '2026-09-04');

        $this->assertSame(0.0, $this->report()['summary']['total']);
    }

    public function test_only_posted_depreciation_counts_never_what_the_asset_could_calculate(): void
    {
        $asset = FixedAsset::create(['business_id' => $this->shop->id, 'name' => 'ثلاجة', 'cost' => 1200,
            'salvage_value' => 0, 'life_months' => 12, 'purchased_at' => '2026-01-01', 'status' => 'نشط', 'accumulated' => 0]);
        $this->assertGreaterThan(0, $asset->dueThrough(Carbon::parse('2026-09-30')), 'الأصلُ لا مستحقَّ له — الاختبارُ لا يقيس شيئًا');

        $this->assertSame(0.0, $this->report()['summary']['total'], 'إهلاكٌ محسوبٌ غيرُ مرحَّل ظهر');

        // كما يكتبه `FixedAssetController::depreciate`
        $this->entry([['account' => 'depreciation', 'debit' => 100, 'memo' => 'ثلاجة'], ['account' => 'accumulated_depreciation', 'credit' => 100]], '2026-09-30', 'إهلاك');

        $r = $this->report();
        $this->assertSame(100.0, $this->category($r, CostsAndLosses::DEPRECIATION)['current']);
        $this->assertSame(100.0, $r['summary']['operating']);
    }

    public function test_an_unposted_draft_is_not_counted(): void
    {
        $entry = JournalEntry::create(['business_id' => $this->shop->id, 'number' => 'JV-DRAFT', 'entry_date' => '2026-09-05',
            'description' => 'مسوّدة', 'source' => 'يدوي', 'posted' => false]);
        JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => Ledger::account($this->shop->id, 'rent')->id, 'debit' => 80, 'credit' => 0]);
        JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => Ledger::account($this->shop->id, 'cash')->id, 'debit' => 0, 'credit' => 80]);

        $this->assertSame(0.0, $this->report()['summary']['total']);
    }

    public function test_a_reversal_cancels_its_entry_and_a_later_reversal_reads_negative(): void
    {
        $same = $this->expense(25, 'إيجار', '2026-09-05');
        Books::unpostExpense($same);   // العكسُ بتاريخ اليوم — في المدّة نفسِها

        $r = $this->report();
        $this->assertSame(0.0, $r['summary']['total']);
        $this->assertSame(0.0, $this->category($r, CostsAndLosses::OPERATING)['rows'][0]['current']);

        // مصروفُ أغسطس يُعكس في سبتمبر: أغسطس كما كان، وسبتمبر سالب
        $august = $this->expense(40, 'كهرباء وماء', '2026-08-20');
        Books::unpostExpense($august);

        $r = $this->report();
        $this->assertSame(-40.0, $r['summary']['total']);
        $row = collect($this->category($r, CostsAndLosses::OPERATING)['rows'])->firstWhere('code', '5500');
        // والنسبةُ من سابقٍ موجب تُقال ولو انقلب الحاليّ سالبًا
        $this->assertSame([-40.0, 40.0, -80.0, -200.0], [$row['current'], $row['previous'], $row['delta'], $row['change_pct']]);
        $this->assertNull($row['share'], 'حصّةٌ من إجماليٍّ غير موجب');

        // وعكسُ الهالك يبقى في فئته — مصدرُ أصله لا نصُّ العكس
        DB::transaction(fn () => StockLosses::record($this->shop->id, 6, true, 'تلف', Carbon::parse('2026-09-09')));
        $loss = JournalEntry::where('source', StockLosses::SOURCE)->sole();
        app()->setLocale('en');
        Ledger::reverse($loss, Carbon::parse('2026-09-11'));
        app()->setLocale('ar');
        $this->assertSame(0.0, $this->report()['summary']['losses']);
        $this->assertSame(0.0, $this->report()['summary']['other']);
    }

    public function test_an_expense_account_without_a_known_key_falls_into_other(): void
    {
        $root = Account::where('business_id', $this->shop->id)->where('code', '5')->sole();
        $mine = Account::create(['business_id' => $this->shop->id, 'parent_id' => $root->id, 'code' => '5950',
            'name' => 'عمولات المنصّات', 'type' => 'مصروف', 'normal_side' => 'debit']);
        $this->entry([['account' => $mine, 'debit' => 12], ['account' => 'cash', 'credit' => 12]], '2026-09-07');

        $r = $this->report();
        $this->assertSame(12.0, $r['summary']['other']);
        $this->assertSame('عمولات المنصّات', $this->category($r, CostsAndLosses::OTHER)['rows'][0]['account']);
    }

    /* ═══════════ استبعادُ الأصل ═══════════ */

    /** أصلٌ يُشترى ويُهلَك ويُستبعد من أبوابه هو — لا قيودٌ تُكتب باليد */
    private function assetSoldAtALoss(): FixedAsset
    {
        $this->actingAs($this->owner)->post(route('admin.finance.assets.store'), [
            'name' => 'ثلاجة عرض', 'purchased_at' => '2026-09-01', 'cost' => 1200, 'life_months' => 12, 'paid_from' => 'cash',
        ])->assertSessionHasNoErrors();
        $asset = FixedAsset::where('business_id', $this->shop->id)->sole();

        $this->actingAs($this->owner)->post(route('admin.finance.assets.depreciate'), ['month' => '2026-09'])->assertSessionHasNoErrors();
        // ١٢٠٠ − ١٠٠ إهلاكًا = ١١٠٠ دفتريًّا، بيعت بـ٥٠٠ ⇒ خسارةٌ مرحَّلة ٦٠٠
        $this->actingAs($this->owner)->post(route('admin.finance.assets.dispose', $asset->id), [
            'disposed_at' => '2026-09-30', 'amount' => 500, 'received_in' => 'cash',
        ])->assertSessionHasNoErrors();

        return $asset->fresh();
    }

    public function test_a_posted_disposal_loss_is_a_loss_and_depreciation_and_purchase_stay_apart(): void
    {
        $this->assetSoldAtALoss();

        $r = $this->report();
        $this->assertSame(600.0, $this->category($r, CostsAndLosses::ASSET_DISPOSAL_LOSSES)['current']);
        $this->assertSame(100.0, $this->category($r, CostsAndLosses::DEPRECIATION)['current'], 'الإهلاكُ عُدّ خسارةَ استبعاد');
        $this->assertSame([600.0, 100.0, 0.0, 700.0], [$r['summary']['losses'], $r['summary']['operating'], $r['summary']['other'], $r['summary']['total']]);

        // والمبلغُ المرحَّل نفسُه — لا قيمةٌ تُحسب من الأصل
        $posted = (float) JournalLine::whereHas('entry', fn ($q) => $q->where('sourceable_type', FixedAsset::class))
            ->whereHas('account', fn ($q) => $q->where('system_key', 'other_expenses'))->sum('debit');
        $this->assertSame(600.0, $posted);

        $drill = CostsAndLosses::drill($this->shop->id, $this->scope(['category' => CostsAndLosses::ASSET_DISPOSAL_LOSSES]), null);
        $this->assertSame([600.0, 1], [$drill['total'], $drill['count']]);
    }

    public function test_a_disposal_at_a_gain_adds_no_cost(): void
    {
        $this->actingAs($this->owner)->post(route('admin.finance.assets.store'), [
            'name' => 'مكيّف', 'purchased_at' => '2026-09-01', 'cost' => 100, 'life_months' => 12, 'paid_from' => 'cash',
        ]);
        $asset = FixedAsset::where('business_id', $this->shop->id)->sole();
        $this->actingAs($this->owner)->post(route('admin.finance.assets.dispose', $asset->id), [
            'disposed_at' => '2026-09-15', 'amount' => 150,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->report()['summary']['total'], 'ربحُ البيع إيرادٌ لا تكلفة');
    }

    public function test_depreciation_is_never_a_disposal_loss_even_on_an_asset_entry(): void
    {
        $asset = FixedAsset::create(['business_id' => $this->shop->id, 'name' => 'ثلاجة', 'cost' => 1200,
            'salvage_value' => 0, 'life_months' => 12, 'purchased_at' => '2026-01-01', 'status' => 'نشط', 'accumulated' => 0]);
        $this->entry([['account' => 'depreciation', 'debit' => 100], ['account' => 'accumulated_depreciation', 'credit' => 100]], '2026-09-30', 'إهلاك', null, $asset);

        $r = $this->report();
        $this->assertSame(100.0, $this->category($r, CostsAndLosses::DEPRECIATION)['current']);
        $this->assertSame(0.0, $r['summary']['losses']);
    }

    public function test_a_reversed_disposal_stays_in_its_category(): void
    {
        $asset = $this->assetSoldAtALoss();
        $entry = JournalEntry::where('sourceable_type', FixedAsset::class)->where('sourceable_id', $asset->id)
            ->whereHas('lines.account', fn ($q) => $q->where('system_key', 'other_expenses'))->sole();

        // والعكسُ يكتب مصدرَه مترجَمًا — فيُقرأ بأصله في أيّ لغة
        app()->setLocale('en');
        Ledger::reverse($entry, Carbon::parse('2026-10-05'));
        app()->setLocale('ar');

        $this->assertSame(600.0, $this->report()['summary']['losses'], 'سبتمبر تغيّر بعكسٍ في أكتوبر');

        $october = $this->report(['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame(-600.0, $this->category($october, CostsAndLosses::ASSET_DISPOSAL_LOSSES)['current']);
        $this->assertSame([-600.0, 0.0], [$october['summary']['losses'], $october['summary']['other']]);

        // وفي مدّةٍ تجمعهما: صفر
        $both = $this->report(['from' => '2026-09-01', 'to' => '2026-10-31']);
        $this->assertSame(0.0, $this->category($both, CostsAndLosses::ASSET_DISPOSAL_LOSSES)['current']);
    }

    /* ═══════════ المطابقة ═══════════ */

    private function busyMonth(): void
    {
        $this->sale(10, '2026-09-02', $this->muscat);
        $this->sale(6, '2026-09-03', $this->sohar);
        $this->expense(30, 'إيجار', '2026-09-05', $this->muscat);
        $this->expense(15, 'كهرباء وماء', '2026-09-06', $this->sohar);
        $this->entry([['account' => 'salaries', 'debit' => 400], ['account' => 'salaries_payable', 'credit' => 400]], '2026-09-30', 'رواتب');
        $this->entry([['account' => 'depreciation', 'debit' => 50], ['account' => 'accumulated_depreciation', 'credit' => 50]], '2026-09-30', 'إهلاك');
        DB::transaction(fn () => StockLosses::record($this->shop->id, 8, true, 'تلف', Carbon::parse('2026-09-09'), $this->muscat->id));
        $this->entry([['account' => 'other_expenses', 'debit' => 3], ['account' => 'cash', 'credit' => 3]], '2026-09-11');
        // والشهرُ السابق
        $this->expense(20, 'إيجار', '2026-08-15', $this->muscat);
    }

    public function test_cards_categories_and_accounts_reconcile_to_the_total(): void
    {
        $this->busyMonth();
        $r = $this->report();

        $categories = collect($r['categories']);
        $this->assertSame($r['summary']['total'], round($categories->sum('current'), 3));
        foreach ($r['categories'] as $c) {
            $this->assertSame($c['current'], round(array_sum(array_column($c['rows'], 'current')), 3), $c['key']);
        }

        $s = $r['summary'];
        $this->assertSame([16.0, 495.0, 8.0, 3.0, 522.0], [$s['cost_of_sales'], $s['operating'], $s['losses'], $s['other'], $s['total']]);
        $this->assertSame($s['total'], round($s['cost_of_sales'] + $s['operating'] + $s['losses'] + $s['other'], 3));
    }

    public function test_every_row_equals_the_sum_of_its_drill_down(): void
    {
        $this->busyMonth();
        $scope = $this->scope();
        $r = CostsAndLosses::report($this->shop->id, $scope);

        foreach ($r['categories'] as $c) {
            $drill = CostsAndLosses::drill($this->shop->id, array_merge($scope, ['category' => $c['key']]), null);
            $this->assertSame($c['current'], $drill['total'], 'فئة '.$c['key']);

            foreach ($c['rows'] as $row) {
                $drill = CostsAndLosses::drill($this->shop->id, array_merge($scope, ['category' => $c['key']]), $row['account_id']);
                $this->assertSame($row['current'], $drill['total'], $c['key'].' '.$row['code']);
                $this->assertSame($row['current'], round(array_sum(array_column($drill['lines'], 'net')), 3));
            }
        }
    }

    public function test_the_drill_down_is_paginated_on_the_server(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->entry([['account' => 'rent', 'debit' => 1], ['account' => 'cash', 'credit' => 1]], '2026-09-05');
        }

        $scope = $this->scope(['category' => CostsAndLosses::OPERATING]);
        $first = CostsAndLosses::drill($this->shop->id, $scope, null, 1);
        $second = CostsAndLosses::drill($this->shop->id, $scope, null, 2);

        $this->assertSame([55, 2, 50, 5], [$first['count'], $first['last_page'], count($first['lines']), count($second['lines'])]);
        $this->assertSame(55.0, $first['total'], 'المجموعُ مجموعُ الصفحة لا الكلّ');
    }

    /* ═══════════ المرشّحات والمقارنة ═══════════ */

    public function test_the_date_range_includes_both_ends_and_nothing_outside(): void
    {
        foreach (['2026-08-31' => 1, '2026-09-01' => 10, '2026-09-30' => 100, '2026-10-01' => 1000] as $date => $amount) {
            $this->entry([['account' => 'rent', 'debit' => $amount], ['account' => 'cash', 'credit' => $amount]], $date);
        }

        $this->assertSame(110.0, $this->report()['summary']['total']);
    }

    public function test_the_previous_period_is_as_long_and_ends_the_day_before(): void
    {
        $this->assertSame(['from' => '2026-08-02', 'to' => '2026-08-31'], array_intersect_key(CostsAndLosses::previous($this->scope()), ['from' => 1, 'to' => 1]));

        $ten = CostsAndLosses::previous($this->scope(['from' => '2026-09-11', 'to' => '2026-09-20']));
        $this->assertSame(['2026-09-01', '2026-09-10'], [$ten['from'], $ten['to']]);

        $this->entry([['account' => 'rent', 'debit' => 5], ['account' => 'cash', 'credit' => 5]], '2026-09-10');
        $this->entry([['account' => 'rent', 'debit' => 9], ['account' => 'cash', 'credit' => 9]], '2026-09-15');
        $r = $this->report(['from' => '2026-09-11', 'to' => '2026-09-20']);
        $this->assertSame([9.0, 5.0], [$r['summary']['total'], $r['previousSummary']['total']]);
        $this->assertSame(80.0, $r['comparison'][0]['change_pct']);
    }

    public function test_a_zero_previous_period_shows_no_percentage(): void
    {
        $this->expense(30, 'إيجار', '2026-09-05');
        $r = $this->report();

        $this->assertNull($r['comparison'][0]['change_pct']);
        $this->assertNull($this->category($r, CostsAndLosses::OPERATING)['change_pct']);
        // والصفحةُ تحمل `null` لا ما لا يُكتب — وJSON لا يحمل لانهايةً أصلًا
        $props = $this->page()->assertOk()->viewData('page')['props'];
        $this->assertNull($props['comparison'][0]['change_pct']);
        $this->assertStringNotContainsString('INF', json_encode($props, JSON_THROW_ON_ERROR));
    }

    public function test_the_category_filter_narrows_cards_rows_and_drill_down_alike(): void
    {
        $this->busyMonth();
        $r = $this->report(['category' => CostsAndLosses::EMPLOYEE]);

        $this->assertSame([400.0, 400.0, 0.0], [$r['summary']['total'], $r['summary']['operating'], $r['summary']['cost_of_sales']]);
        $this->assertSame(400.0, CostsAndLosses::drill($this->shop->id, $this->scope(['category' => CostsAndLosses::EMPLOYEE]), null)['total']);
        // وفئةٌ لا تُعرف تُهمل لا تُفرغ التقرير
        $this->assertSame(522.0, $this->report(['category' => 'nonsense'])['summary']['total']);
    }

    public function test_a_branch_reads_its_own_entries_and_not_the_business_wide_ones(): void
    {
        $this->busyMonth();

        $muscat = $this->report(['branch_id' => $this->muscat->id]);
        // بيعُه ١٠ وإيجارُه ٣٠ وهالكُه ٨ — لا الرواتبُ ولا الإهلاكُ ولا صحار
        $this->assertSame([10.0, 30.0, 8.0, 48.0], [$muscat['summary']['cost_of_sales'], $muscat['summary']['operating'], $muscat['summary']['losses'], $muscat['summary']['total']]);
        $this->assertSame(21.0, $this->report(['branch_id' => $this->sohar->id])['summary']['total']);

        $props = $this->page(['branch_id' => $this->muscat->id])->assertOk()->viewData('page')['props'];
        $this->assertEquals(48.0, $props['summary']['total']);
        $this->assertSame((string) $this->muscat->id, $props['filters']['branch_id']);
    }

    /* ═══════════ المتجر والفرع والصلاحية ═══════════ */

    public function test_another_business_is_never_read_nor_selectable(): void
    {
        $this->busyMonth();
        $this->entry([['account' => 'rent', 'debit' => 999], ['account' => 'cash', 'credit' => 999]], '2026-09-05', shop: $this->other);

        $this->assertSame(522.0, $this->report()['summary']['total']);
        $this->assertSame(999.0, $this->report(shop: $this->other)['summary']['total']);

        // صفحةُ الجار لا تقرأ متجري، ولا يُختار متجرٌ من الرابط
        $props = $this->page(['business_id' => $this->shop->id], $this->theirOwner)->assertOk()->viewData('page')['props'];
        $this->assertEquals(999.0, $props['summary']['total']);

        // وفرعُ متجرٍ آخر ⇒ ٤٠٤ في الصفحة والتفصيل والملفّ
        $theirBranch = Branch::where('business_id', $this->other->id)->first();
        $this->page(['branch_id' => $theirBranch->id])->assertNotFound();
        $this->actingAs($this->owner)->get(route('admin.reports.costs.lines', self::SEPT + ['branch_id' => $theirBranch->id]))->assertNotFound();
        $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'costs', 'branch_id' => $theirBranch->id] + self::SEPT))->assertNotFound();
    }

    public function test_the_drill_down_never_opens_another_businesss_account(): void
    {
        $this->entry([['account' => 'rent', 'debit' => 999], ['account' => 'cash', 'credit' => 999]], '2026-09-05', shop: $this->other);
        $theirs = Ledger::account($this->other->id, 'rent');

        $this->actingAs($this->owner)
            ->getJson(route('admin.reports.costs.lines', self::SEPT + ['account_id' => $theirs->id, 'category' => 'operating']))
            ->assertNotFound();

        $mine = $this->actingAs($this->owner)->getJson(route('admin.reports.costs.lines', self::SEPT))->assertOk()->json();
        $this->assertSame(0, $mine['count']);
    }

    public function test_the_export_carries_only_its_own_business(): void
    {
        $this->expense(30, 'إيجار', '2026-09-05');
        $root = Account::where('business_id', $this->other->id)->where('code', '5')->sole();
        $secret = Account::create(['business_id' => $this->other->id, 'parent_id' => $root->id, 'code' => '5951',
            'name' => 'حسابٌ سرّيٌّ للجار', 'type' => 'مصروف', 'normal_side' => 'debit']);
        $this->entry([['account' => $secret, 'debit' => 777], ['account' => 'cash', 'credit' => 777]], '2026-09-05', shop: $this->other);

        $csv = $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'costs'] + self::SEPT))->streamedContent();
        $this->assertStringNotContainsString('حسابٌ سرّيٌّ للجار', $csv);
        $this->assertStringNotContainsString('777', $csv);
    }

    public function test_a_user_without_the_finance_section_is_refused_and_the_accountant_reads_it(): void
    {
        $clerk = User::create(['business_id' => $this->shop->id, 'name' => 'موظف', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'reports']]);
        $this->page([], $clerk)->assertForbidden();
        $this->actingAs($clerk)->get(route('admin.reports.costs.lines', self::SEPT))->assertForbidden();
        $this->actingAs($clerk)->get(route('admin.reports.export.csv', ['report' => 'costs'] + self::SEPT))->assertForbidden();

        $accountant = User::create(['business_id' => $this->shop->id, 'name' => 'محاسب', 'email' => 'a@abaad.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط']);
        $this->page([], $accountant)->assertOk();
    }

    public function test_a_user_bound_to_one_branch_reads_it_alone_everywhere(): void
    {
        $this->busyMonth();
        $bound = User::create(['business_id' => $this->shop->id, 'name' => 'محاسب مسقط', 'email' => 'm@abaad.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط']);
        $bound->branches()->attach($this->muscat->id);

        // بلا فرع: فروعُه وحدها — لا صحار ولا قيود النشاط العامّة
        $props = $this->page([], $bound)->assertOk()->viewData('page')['props'];
        $this->assertEquals(48.0, $props['summary']['total']);
        $this->assertSame([(string) $this->muscat->id], array_column($props['options']['branches'], 'value'));

        $this->page(['branch_id' => $this->sohar->id], $bound)->assertForbidden();
        $this->actingAs($bound)->get(route('admin.reports.costs.lines', self::SEPT + ['branch_id' => $this->sohar->id]))->assertForbidden();
        $this->actingAs($bound)->get(route('admin.reports.export.csv', ['report' => 'costs', 'branch_id' => $this->sohar->id] + self::SEPT))->assertForbidden();

        $drill = $this->actingAs($bound)->getJson(route('admin.reports.costs.lines', self::SEPT))->assertOk()->json();
        $this->assertEquals(48.0, $drill['total']);
        $this->assertSame(['مسقط'], array_values(array_unique(array_column($drill['lines'], 'branch'))));

        $csv = $this->actingAs($bound)->get(route('admin.reports.export.csv', ['report' => 'costs'] + self::SEPT))->streamedContent();
        $this->assertStringNotContainsString('الرواتب والأجور', $csv);
    }

    /* ═══════════ الملفّات ═══════════ */

    public function test_the_spreadsheet_and_csv_carry_the_screen_totals(): void
    {
        $this->busyMonth();
        $filters = ['branch_id' => $this->muscat->id];
        $screen = $this->page($filters)->assertOk()->viewData('page')['props'];

        $csv = $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'costs'] + $filters + self::SEPT))->streamedContent();
        $this->assertStringContainsString('فرع مسقط', $csv);
        foreach ($screen['rows'] as $row) {
            $this->assertStringContainsString($row['account'], $csv);
        }
        $this->assertStringContainsString(number_format($screen['summary']['total'], 3), $csv);

        $file = tempnam(sys_get_temp_dir(), 'costs').'.xlsx';
        file_put_contents($file, $this->actingAs($this->owner)
            ->get(route('admin.reports.export.xlsx', ['report' => 'costs'] + $filters + self::SEPT))->streamedContent());
        $sheet = IOFactory::load($file)->getActiveSheet()->toArray();
        @unlink($file);

        $money = collect($sheet)->filter(fn ($r) => in_array($r[2] ?? null, array_column($screen['rows'], 'account'), true))
            ->sum(fn ($r) => (float) $r[3]);
        $this->assertSame($screen['summary']['total'], round($money, 3), 'صفوفُ الورقة لا تجمع إجماليَّ الشاشة');
    }

    public function test_the_pdf_carries_the_same_filtered_totals(): void
    {
        $this->busyMonth();
        $fake = new class implements PdfDriver
        {
            public string $html = '';

            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                $this->html = $html;

                return response('PDF');
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                return response('PDF');
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 1.0;
            }
        };

        $was = Pdf::swap($fake);
        try {
            $this->actingAs($this->owner)
                ->get(route('admin.reports.export.pdf', ['report' => 'costs', 'category' => 'employee'] + self::SEPT))
                ->assertOk();
        } finally {
            Pdf::swap($was);
        }

        $this->assertStringContainsString('2026-09-01 → 2026-09-30', $fake->html);
        $this->assertStringContainsString('الرواتب والأجور', $fake->html);
        $this->assertStringContainsString('400.000', $fake->html);
        $this->assertStringNotContainsString('الإيجار', $fake->html, 'مرشّحُ الفئة لم يصل الملفّ');
    }

    /* ═══════════ ما لم يتغيّر ═══════════ */

    public function test_the_expenses_report_and_net_profit_keep_reading_their_own_sources(): void
    {
        $this->expense(30, 'إيجار', '2026-09-05');
        DB::transaction(fn () => StockLosses::record($this->shop->id, 9, true, 'تلف', Carbon::parse('2026-09-09')));
        $this->entry([['account' => 'salaries', 'debit' => 400], ['account' => 'salaries_payable', 'credit' => 400]], '2026-09-30', 'رواتب');

        // «المصروفات» صفوفُ جدولها — المصروفُ وصفُّ الهالك، لا الرواتب
        $expenses = ReportData::expenses($this->shop->id, ['range' => 'month']);
        $this->assertSame([39.0, 2], [$expenses['summary']['total'], $expenses['summary']['count']]);

        // و«صافي الربح» يقرأ المصروفاتِ المدفوعة من جدولها كما كان
        $this->assertSame(39.0, Profitability::summary($this->shop->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01'))['expenses']);

        // والتقريرُ الجديد من الدفتر: المصروفُ والهالكُ والرواتب
        $this->assertSame(439.0, $this->report()['summary']['total']);
    }

    public function test_paid_expenses_outside_the_ledger_and_unpaid_ones_are_told_not_added(): void
    {
        Expense::create(['business_id' => $this->shop->id, 'type' => 'إيجار', 'description' => 'قديم', 'amount' => 70,
            'method' => 'نقدي', 'status' => Expense::PAID, 'spent_at' => '2026-09-03']);
        Expense::create(['business_id' => $this->shop->id, 'type' => 'إيجار', 'description' => 'مستحقّ', 'amount' => 45,
            'method' => 'نقدي', 'status' => Expense::UNPAID, 'spent_at' => '2026-09-04']);
        $this->expense(30, 'إيجار', '2026-09-05');

        $rec = CostsAndLosses::reconciliation($this->shop->id, $this->scope());
        $this->assertSame([1, 70.0, 1, 45.0], [$rec['unposted_count'], $rec['unposted_amount'], $rec['unpaid_count'], $rec['unpaid_amount']]);
        $this->assertSame(30.0, $this->report()['summary']['total'], 'صفٌّ بلا قيدٍ أُضيف إلى الدفتر');

        $this->assertSame(1, $this->page()->assertOk()->viewData('page')['props']['reconciliation']['unposted_count']);
    }

    /* ═══════════ اللغة والقراءة ═══════════ */

    public function test_the_page_speaks_each_language_without_leaking_the_other(): void
    {
        $this->busyMonth();

        $ar = $this->page()->assertOk()->viewData('page')['props'];
        $this->assertSame('تكلفة المبيعات', $this->category($ar, CostsAndLosses::COST_OF_SALES)['label']);
        $this->assertSame('تكلفة البضاعة المباعة', $this->category($ar, CostsAndLosses::COST_OF_SALES)['rows'][0]['account']);

        $this->owner->update(['locale' => 'en']);
        $en = $this->page()->assertOk()->viewData('page')['props'];
        $labels = array_column($en['categories'], 'label');
        $this->assertSame(['Cost of sales', 'Employee costs', 'Operating expenses', 'Depreciation', 'Inventory losses', 'Asset disposal losses', 'Other expenses & losses'], $labels);
        foreach ($labels as $label) {
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $label);
        }
        foreach ($en['rows'] as $row) {
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $row['account'].$row['category_label'], 'اسمُ حسابٍ نظاميّ بلا ترجمة');
        }
    }

    public function test_reading_the_report_writes_nothing(): void
    {
        $this->busyMonth();
        $asset = FixedAsset::create(['business_id' => $this->shop->id, 'name' => 'ثلاجة', 'cost' => 1200,
            'salvage_value' => 0, 'life_months' => 12, 'purchased_at' => '2026-01-01', 'status' => 'نشط', 'accumulated' => 0]);

        $count = fn () => [JournalEntry::count(), JournalLine::count(), Expense::count(), Account::count(), DB::table('fixed_assets')->value('depreciated_through')];
        $before = $count();

        $this->page()->assertOk();
        $this->actingAs($this->owner)->getJson(route('admin.reports.costs.lines', self::SEPT))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'costs'] + self::SEPT))->assertOk()->streamedContent();

        $this->assertSame($before, $count());
        $this->assertNull($asset->fresh()->depreciated_through);
    }
}
