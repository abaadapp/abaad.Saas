<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseBranchAllocation;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Document\Pdf\Driver as PdfDriver;
use App\Support\Ledger;
use App\Support\Pdf;
use App\Support\Profitability;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * صافي الربح — للنشاط كلِّه ولكلّ فرع، والمصروفُ يُعدّ مرّةً واحدة.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) النشاطُ كلُّه: المبيعات − الضريبة − تكلفة البضاعة − المصروفات المدفوعة،
 *    وهو `Demo::reportSummary` نفسُه رقمًا برقم.
 * ٢) الفرع: ما بيع فيه، ومصروفُه المباشر وحصّتُه من الموزَّع — والعامُّ غيرُ
 *    الموزّع يُقال إلى جانبه ولا يُطرح منه.
 * ٣) الموزَّع صفٌّ واحد وقيدٌ واحد ودفعةٌ واحدة: لا يصير الألفُ ألفين.
 * ٤) لا يمسّ تاجرٌ فرعَ غيره: لا يُسند إليه مصروفًا، ولا يوزّع عليه، ولا يقرأ
 *    تقريره.
 */
class NetProfitIsReadForTheShopAndEachBranchTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $muscat;

    private Branch $sohar;

    protected function setUp(): void
    {
        parent::setUp();
        // الأربعاء في منتصف الشهر — لا يقع اختبارٌ على منتصف ليلٍ ولا على أوّل شهر
        Carbon::setTestNow('2027-03-17 12:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner, $this->muscat, $this->sohar] = $this->merchant('ورد مسقط', 'o@abaad.om');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------ أدوات ------------------------------ */

    /** @return array{0: Business, 1: User, 2: Branch, 3: Branch} */
    private function merchant(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'محل ورد', 'status' => 'نشط']);
        Ledger::seedChart($shop->id);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => $email,
            'password' => 'secret', 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [
            $shop, $owner,
            Branch::create(['business_id' => $shop->id, 'name' => 'مسقط']),
            Branch::create(['business_id' => $shop->id, 'name' => 'صحار']),
        ];
    }

    /** بيعةٌ بتكلفتها يوم البيع — `cost` لقطةُ البند لا بطاقةُ الصنف */
    private function sell(?Branch $branch, float $total, float $tax, float $cost, ?string $at = null, ?Business $shop = null, int $qty = 1): Order
    {
        $shop ??= $this->shop;
        $product = Product::create([
            'business_id' => $shop->id, 'name' => 'باقة '.Order::count(), 'price' => $total,
            'cost' => $cost / $qty, 'quantity' => 100, 'alert_qty' => 1, 'active' => true,
        ]);

        $order = Order::create([
            'business_id' => $shop->id, 'branch_id' => $branch?->id,
            'customer_name' => 'زبون', 'employee_name' => 'المالك',
            'number' => 'INV-'.(Order::count() + 1), 'status' => 'مكتمل',
            'payment_status' => 'مدفوع', 'is_held' => false, 'payment_method' => 'نقدي',
            'subtotal' => $total - $tax, 'discount' => 0, 'tax' => $tax, 'total' => $total,
            'ordered_at' => $at ? Carbon::parse($at) : now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => $product->name,
            'price' => $total / $qty, 'quantity' => $qty, 'cost' => $cost / $qty, 'total' => $total,
        ]);

        return $order;
    }

    /** مصروفٌ مباشر بلا شاشة — كما يكتبه أيّ بابٍ في النظام */
    private function spend(float $amount, ?Branch $branch = null, string $status = Expense::PAID, string $at = '2027-03-10', ?Business $shop = null): Expense
    {
        $expense = Expense::create([
            'business_id' => ($shop ?? $this->shop)->id, 'branch_id' => $branch?->id,
            'type' => 'إيجار', 'amount' => $amount, 'status' => $status, 'spent_at' => $at,
        ]);

        // والمدفوعُ يُرحَّل يومَ يُدفع — كما يفعل `ExpenseController::postToLedger`
        if ($status === Expense::PAID) {
            Books::recordExpense($expense);
        }

        return $expense;
    }

    /** مصروفٌ موزَّع — صفٌّ واحد وحصصُه */
    private function split(float $amount, array $shares, string $status = Expense::PAID): Expense
    {
        $e = $this->spend($amount, null, $status);
        foreach ($shares as [$branch, $value]) {
            ExpenseBranchAllocation::create(['expense_id' => $e->id, 'branch_id' => $branch->id, 'amount' => $value]);
        }

        return $e;
    }

    /** خصائصُ صفحة التقرير كما تصل الشاشة */
    private function page(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)
            ->get(route('admin.reports.profit', $query + ['range' => 'month']))
            ->assertOk()->viewData('page')['props'];
    }

    /* ═══════════════ ١ · النشاط كلُّه ═══════════════ */

    public function test_the_whole_business_is_sales_less_tax_less_cost_less_paid_expenses(): void
    {
        $this->sell($this->muscat, 1000, 50, 600);
        $this->spend(200);
        // غيرُ المدفوع لا يُخصم
        $this->spend(999, status: Expense::UNPAID);

        $s = $this->page()['summary'];

        $this->assertSame(1000.0, $s['sales']);
        $this->assertSame(50.0, $s['tax']);
        $this->assertSame(950.0, $s['net_revenue']);
        $this->assertSame(600.0, $s['cogs']);
        $this->assertSame(350.0, $s['gross_profit']);
        $this->assertSame(200.0, $s['expenses']);
        $this->assertSame(150.0, $s['net_profit']);
        $this->assertSame(15.8, $s['margin']);

        $this->actingAs($this->owner);
        $this->assertSame(Demo::reportSummary('month')['profit'], $s['net_profit'], 'افترق صافي الربح عن ملخّص المبيعات');
    }

    public function test_the_whole_business_matches_the_sales_summary_in_every_range(): void
    {
        $this->sell($this->muscat, 1000, 50, 600, '2027-03-17 09:00:00');
        $this->sell($this->sohar, 400, 0, 100, '2027-03-15 09:00:00');
        $this->sell($this->muscat, 300, 10, 90, '2027-02-11 09:00:00');
        $this->sell(null, 80, 0, 20, '2026-05-03 09:00:00');
        $this->spend(120, at: '2027-03-16');
        $this->spend(70, $this->muscat, at: '2027-02-20');
        $this->split(90, [[$this->muscat, 50], [$this->sohar, 40]]);

        $this->actingAs($this->owner);

        foreach (['today', 'week', 'month', 'year', 'all'] as $range) {
            $mine = Profitability::summary($this->shop->id, Demo::rangeStart($range));

            $this->assertSame(Demo::reportSummary($range)['profit'], $mine['net_profit'], "افترقا في {$range}");
        }
    }

    public function test_a_loss_is_a_loss(): void
    {
        $this->sell($this->muscat, 500, 0, 400);
        $this->spend(200);

        $s = $this->page()['summary'];

        $this->assertSame(-100.0, $s['net_profit']);
        $this->assertSame(-20.0, $s['margin']);
    }

    /* ═══════════════ ٢ · الفرع ═══════════════ */

    public function test_a_branch_carries_its_own_sales_cost_and_direct_expense_only(): void
    {
        $this->sell($this->muscat, 1000, 50, 600);
        $this->spend(200, $this->muscat);
        $this->sell($this->sohar, 700, 30, 100);
        $this->spend(90, $this->sohar);

        $p = $this->page(['branch_id' => $this->muscat->id]);
        $s = $p['summary'];

        $this->assertSame('branch', $p['scope']['kind']);
        $this->assertSame(1000.0, $s['sales']);
        $this->assertSame(950.0, $s['net_revenue']);
        $this->assertSame(350.0, $s['gross_profit']);
        $this->assertSame(200.0, $s['expenses']);
        $this->assertSame(150.0, $s['net_profit']);
        $this->assertSame(0.0, $p['unallocated']['amount']);
    }

    public function test_a_split_expense_counts_once_for_the_business_and_by_share_for_each_branch(): void
    {
        $res = $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 1000, 'status' => Expense::PAID, 'spent_at' => '2027-03-10',
            'scope' => 'split',
            'allocations' => [
                ['branch_id' => $this->muscat->id, 'amount' => 600],
                ['branch_id' => $this->sohar->id, 'amount' => 400],
            ],
        ]);
        $res->assertRedirect()->assertSessionHasNoErrors();

        // صفٌّ واحد، وحركةٌ واحدة، وقيدٌ حيٌّ واحد بألف — لا ألفين ولا ثلاثة قيود
        $this->assertSame(1, Expense::count());
        $this->assertSame(1, Transaction::count());
        $this->assertSame(1000.0, (float) Transaction::sole()->amount);
        $this->assertNull(Transaction::sole()->branch_id, 'الموزَّعُ دفعةٌ واحدة للنشاط');
        $live = JournalEntry::whereNull('reversed_at')->whereNull('reverses_id')->get();
        $this->assertCount(1, $live);
        $this->assertSame(1000.0, (float) $live[0]->lines()->sum('debit'));
        $this->assertNull($live[0]->branch_id);
        $this->assertSame(2, ExpenseBranchAllocation::count());

        $this->assertSame(1000.0, $this->page()['summary']['expenses']);
        $this->assertSame(600.0, $this->page(['branch_id' => $this->muscat->id])['summary']['expenses']);
        $this->assertSame(400.0, $this->page(['branch_id' => $this->sohar->id])['summary']['expenses']);
    }

    public function test_unallocated_overhead_is_named_beside_a_branch_and_not_taken_from_it(): void
    {
        $this->sell($this->muscat, 1000, 0, 400);
        $this->spend(1000);

        $branch = $this->page(['branch_id' => $this->muscat->id]);

        $this->assertSame(0.0, $branch['summary']['expenses']);
        $this->assertSame(600.0, $branch['summary']['net_profit']);
        $this->assertSame(['amount' => 1000.0, 'count' => 1], $branch['unallocated']);
        $this->assertSame(1000.0, $branch['summary']['unallocated']);

        $whole = $this->page();
        $this->assertSame(1000.0, $whole['summary']['expenses']);
        $this->assertSame(-400.0, $whole['summary']['net_profit']);
        $this->assertNull($whole['unallocated']);
        $this->assertNull($whole['summary']['unallocated'], 'للنشاط كلِّه هي من مصروفاته — لا تُقال');
    }

    public function test_mixed_expenses_are_attributed_without_double_counting(): void
    {
        $this->spend(100, $this->muscat);
        $this->spend(200, $this->sohar);
        $this->split(400, [[$this->muscat, 300], [$this->sohar, 100]]);
        $this->spend(500);

        $this->assertSame(400.0, $this->page(['branch_id' => $this->muscat->id])['summary']['expenses']);
        $this->assertSame(300.0, $this->page(['branch_id' => $this->sohar->id])['summary']['expenses']);
        $this->assertSame(1200.0, $this->page()['summary']['expenses']);
        $this->assertSame(500.0, $this->page(['branch_id' => $this->sohar->id])['unallocated']['amount']);
    }

    public function test_an_unpaid_expense_keeps_its_scope_but_takes_nothing_until_paid(): void
    {
        $direct = $this->spend(300, $this->muscat, Expense::UNPAID);
        $this->split(200, [[$this->muscat, 150], [$this->sohar, 50]], Expense::UNPAID);

        $this->assertSame(0.0, $this->page()['summary']['expenses']);
        $this->assertSame(0.0, $this->page(['branch_id' => $this->muscat->id])['summary']['expenses']);
        $this->assertSame(0.0, $this->page(['branch_id' => $this->muscat->id])['unallocated']['amount']);

        $this->actingAs($this->owner)->post(route('admin.expenses.paid', $direct->id))->assertRedirect();

        // يومُ السداد يومُ الخروج — اليوم، وفي الشهر نفسه
        $this->assertSame(300.0, $this->page(['branch_id' => $this->muscat->id])['summary']['expenses']);
        $this->assertSame($this->muscat->id, JournalEntry::where('sourceable_id', $direct->id)->value('branch_id'));
    }

    public function test_a_deleted_expense_and_its_shares_leave_the_profit(): void
    {
        $e = $this->split(400, [[$this->muscat, 300], [$this->sohar, 100]]);
        // كما يحذفه `ExpenseController::destroy`: قيدُه يُعكس ثمّ يُحذف صفُّه
        Books::unpostExpense($e);
        $e->delete();

        $this->assertSame(0.0, $this->page()['summary']['expenses']);
        $this->assertSame(0.0, $this->page(['branch_id' => $this->muscat->id])['summary']['expenses']);
    }

    public function test_an_expense_from_before_branches_is_business_wide(): void
    {
        // كما في القاعدة قبل هذه النسخة: لا فرعَ ولا توزيع
        $old = $this->spend(250);
        $this->assertNull($old->fresh()->branch_id);

        $this->assertSame(250.0, $this->page()['summary']['expenses']);
        $this->assertSame(['amount' => 250.0, 'count' => 1], $this->page(['branch_id' => $this->sohar->id])['unallocated']);
    }

    public function test_the_cost_of_a_sale_is_its_cost_on_the_day_in_a_branch_too(): void
    {
        $order = $this->sell($this->muscat, 100, 0, 6);
        // تبدّلت تكلفةُ الصنف بعد البيع
        Product::whereKey($order->items()->value('product_id'))->update(['cost' => 9]);

        $this->assertSame(6.0, $this->page(['branch_id' => $this->muscat->id])['summary']['cogs']);
        $this->assertSame(6.0, $this->page()['summary']['cogs']);
    }

    /* ═══════════════ ٣ · الصفوف والمنحنى والمقارنة ═══════════════ */

    public function test_the_rows_add_up_to_the_summary_for_the_business_and_a_branch(): void
    {
        $this->sell($this->muscat, 1000, 50, 600, '2027-03-02 10:00:00');
        $this->sell($this->sohar, 400, 20, 100, '2027-03-15 18:30:00');
        $this->sell($this->muscat, 300, 0, 50, '2027-01-20 08:00:00');
        $this->spend(200, $this->muscat, at: '2027-03-05');
        $this->spend(80, at: '2027-03-17');
        $this->split(300, [[$this->muscat, 100], [$this->sohar, 200]]);
        $this->spend(60, $this->sohar, at: '2027-01-02');

        foreach (['today', 'week', 'month', 'year', 'all'] as $range) {
            foreach ([null, $this->muscat->id, $this->sohar->id] as $branch) {
                $p = $this->page(array_filter(['range' => $range, 'branch_id' => $branch]));

                foreach (['sales', 'tax', 'cogs', 'expenses', 'net_profit', 'net_revenue', 'gross_profit'] as $key) {
                    $this->assertEqualsWithDelta(
                        $p['summary'][$key],
                        array_sum(array_column($p['rows'], $key)),
                        0.0005,
                        "{$key} في {$range} / ".($branch ?? 'الكل'),
                    );
                }

                $this->assertSame(array_column($p['rows'], 'net_profit'), $p['series']['net_profit']);
            }
        }
    }

    public function test_the_buckets_follow_the_range(): void
    {
        $this->sell($this->muscat, 100, 0, 10, '2026-11-03 09:00:00');

        $count = fn (string $range) => count($this->page(['range' => $range])['rows']);

        $this->assertSame(24, $count('today'));
        $this->assertSame(7, $count('week'));
        $this->assertSame(31, $count('month'));
        $this->assertSame(12, $count('year'));
        // من نوفمبر ٢٠٢٦ إلى مارس ٢٠٢٧ — من أوّل حركة
        $this->assertSame(5, $count('all'));

        // والأسبوعُ يبدأ الأحد
        $this->assertSame('2027-03-14', $this->page(['range' => 'week'])['rows'][0]['key']);
    }

    public function test_the_previous_period_is_compared_in_the_same_scope(): void
    {
        $this->sell($this->muscat, 1000, 0, 400);
        $this->sell($this->muscat, 600, 0, 300, '2027-02-10 10:00:00');
        $this->sell($this->sohar, 9000, 0, 1, '2027-02-10 10:00:00');
        $this->spend(100, $this->muscat, at: '2027-02-12');

        $cmp = collect($this->page(['branch_id' => $this->muscat->id])['comparison'])->keyBy('key');

        $this->assertSame(600.0, $cmp['net_profit']['current']);
        $this->assertSame(200.0, $cmp['net_profit']['previous']);
        $this->assertSame(400.0, $cmp['net_profit']['diff']);
        // والهامشُ بنقاطٍ مئويّة: ٦٠٪ مقابل ٣٣٫٣٪
        $this->assertSame(26.7, $cmp['margin']['diff']);

        $this->assertNull($this->page(['range' => 'all'])['comparison'], 'لا فترةَ سابقةً لكلّ الفترات');
    }

    /* ═══════════════ ٤ · كتابة النطاق ═══════════════ */

    public function test_a_direct_branch_expense_carries_its_branch_into_the_books(): void
    {
        $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 300, 'status' => Expense::PAID, 'spent_at' => '2027-03-10',
            'scope' => 'branch', 'branch_id' => $this->sohar->id,
        ])->assertSessionHasNoErrors();

        $e = Expense::sole();
        $this->assertSame($this->sohar->id, (int) $e->branch_id);
        $this->assertSame($this->sohar->id, (int) Transaction::sole()->branch_id);
        $this->assertSame($this->sohar->id, (int) JournalEntry::sole()->branch_id);
        $this->assertSame(300.0, (float) JournalEntry::sole()->lines()->sum('debit'), 'تغيّرت قيمةُ القيد');
    }

    public function test_a_new_expense_is_business_wide_even_with_a_branch_open_in_the_session(): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->muscat->id])
            ->post(route('admin.expenses.store'), ['type' => 'إيجار', 'amount' => 300, 'spent_at' => '2027-03-10'])
            ->assertSessionHasNoErrors();

        $this->assertNull(Expense::sole()->branch_id);
        $this->assertSame(0, ExpenseBranchAllocation::count());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badSplits(): array
    {
        return [
            'أقلّ من المصروف' => [[[0, 600], [1, 300]]],
            'أكثر من المصروف' => [[[0, 600], [1, 500]]],
            'سالب' => [[[0, 1200], [1, -200]]],
            'صفر' => [[[0, 1000], [1, 0]]],
            'فرعٌ مكرّر' => [[[0, 500], [0, 500]]],
            'بلا فروع' => [[]],
        ];
    }

    /** @dataProvider badSplits */
    #[DataProvider('badSplits')]
    public function test_a_split_that_does_not_add_up_is_refused_and_nothing_is_written(array $shares): void
    {
        $branches = [$this->muscat, $this->sohar];

        $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 1000, 'spent_at' => '2027-03-10', 'scope' => 'split',
            'allocations' => array_map(fn ($s) => ['branch_id' => $branches[$s[0]]->id, 'amount' => $s[1]], $shares),
        ])->assertSessionHasErrors();

        $this->assertSame(0, Expense::count());
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, JournalEntry::count());
    }

    /** والفرعُ المكرّر يُقال في صفّه — لا رفضًا عامًّا من قيد القاعدة */
    public function test_a_repeated_branch_is_named_on_its_row(): void
    {
        $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 1000, 'spent_at' => '2027-03-10', 'scope' => 'split',
            'allocations' => [['branch_id' => $this->muscat->id, 'amount' => 500], ['branch_id' => $this->muscat->id, 'amount' => 500]],
        ])->assertSessionHasErrors('allocations.1.branch_id');

        $this->assertSame(0, Expense::count());
    }

    public function test_a_split_is_compared_in_thousandths_not_in_floats(): void
    {
        $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => '0.3', 'spent_at' => '2027-03-10', 'scope' => 'split',
            'allocations' => [
                ['branch_id' => $this->muscat->id, 'amount' => '0.1'],
                ['branch_id' => $this->sohar->id, 'amount' => '0.2'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, ExpenseBranchAllocation::count());
    }

    public function test_rescoping_moves_the_books_by_reversal_and_counts_the_money_once(): void
    {
        $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 1000, 'spent_at' => '2027-03-10',
        ])->assertSessionHasNoErrors();
        $e = Expense::sole();

        $scope = fn (array $body) => $this->actingAs($this->owner)
            ->put(route('admin.expenses.scope', $e->id), $body)->assertSessionHasNoErrors();

        $live = fn () => JournalEntry::whereNull('reversed_at')->whereNull('reverses_id')->get();

        // النشاط ← فرع: القيدُ يُعكس ويُرحَّل بفرعه، والمالُ ألفٌ واحد
        $scope(['scope' => 'branch', 'branch_id' => $this->muscat->id]);
        $this->assertCount(1, $live());
        $this->assertSame($this->muscat->id, (int) $live()[0]->branch_id);
        $this->assertSame($this->muscat->id, (int) Transaction::sole()->branch_id);
        $this->assertSame(0.0, round((float) DB::table('journal_lines')->sum('debit') - (float) DB::table('journal_lines')->sum('credit'), 3));

        // فرع ← موزَّع: لا فرعَ يبقى، والحصصُ وحدها
        $scope(['scope' => 'split', 'allocations' => [
            ['branch_id' => $this->muscat->id, 'amount' => 700], ['branch_id' => $this->sohar->id, 'amount' => 300],
        ]]);
        $this->assertNull($e->fresh()->branch_id);
        $this->assertSame(2, ExpenseBranchAllocation::count());
        $this->assertNull($live()[0]->branch_id);

        // موزَّع ← موزَّع: لا يبقى توزيعٌ قديم
        $scope(['scope' => 'split', 'allocations' => [['branch_id' => $this->sohar->id, 'amount' => 1000]]]);
        $this->assertSame([$this->sohar->id], ExpenseBranchAllocation::pluck('branch_id')->map(fn ($v) => (int) $v)->all());

        // موزَّع ← النشاط: لا حصص
        $scope(['scope' => 'business']);
        $this->assertSame(0, ExpenseBranchAllocation::count());

        $this->assertCount(1, $live(), 'قيدٌ حيٌّ واحد بعد كلّ هذا');
        $this->assertSame(1000.0, (float) $live()[0]->lines()->sum('debit'));
        $this->assertSame(1000.0, $this->page()['summary']['expenses']);
        $this->assertSame(1, Expense::count());
        $this->assertSame(1000.0, (float) $e->fresh()->amount);
    }

    public function test_a_finance_movement_expense_keeps_the_movements_branch(): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->sohar->id])
            ->post(route('admin.finance.store'), [
                'kind' => 'expense', 'amount' => 45, 'side' => Books::CASH, 'expense_type' => 'صيانة',
                'occurred_at' => '2027-03-10',
            ])->assertSessionHasNoErrors();

        $this->assertSame($this->sohar->id, (int) Transaction::sole()->branch_id);
        $this->assertSame($this->sohar->id, (int) Expense::sole()->branch_id);
        $this->assertSame($this->sohar->id, (int) JournalEntry::sole()->branch_id);
        $this->assertSame(1, Expense::count());
        $this->assertSame(45.0, $this->page(['branch_id' => $this->sohar->id])['summary']['expenses']);
    }

    /* ═══════════════ ٥ · بين تاجرين ═══════════════ */

    public function test_another_merchants_branch_is_out_of_reach_everywhere(): void
    {
        [$other, $otherOwner, $theirMuscat] = $this->merchant('ورد صلالة', 'b@abaad.om');
        $this->sell($theirMuscat, 5000, 0, 1000, shop: $other);
        $theirs = Expense::create(['business_id' => $other->id, 'type' => 'إيجار', 'amount' => 900, 'spent_at' => '2027-03-10']);
        ExpenseBranchAllocation::create(['expense_id' => $theirs->id, 'branch_id' => $theirMuscat->id, 'amount' => 900]);

        // لا يُسند مصروفٌ إلى فرعه، ولا يُوزَّع عليه
        $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 100, 'spent_at' => '2027-03-10', 'scope' => 'branch', 'branch_id' => $theirMuscat->id,
        ])->assertSessionHasErrors('branch_id');
        $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 100, 'spent_at' => '2027-03-10', 'scope' => 'split',
            'allocations' => [['branch_id' => $this->muscat->id, 'amount' => 50], ['branch_id' => $theirMuscat->id, 'amount' => 50]],
        ])->assertSessionHasErrors('allocations.1.branch_id');
        $this->assertSame(0, Expense::where('business_id', $this->shop->id)->count());

        // ولا يُقرأ تقريرُ فرعه — لا فارغًا ولا باسمه
        $this->actingAs($this->owner)->get(route('admin.reports.profit', ['branch_id' => $theirMuscat->id]))->assertNotFound();
        $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'profit', 'branch_id' => $theirMuscat->id]))->assertNotFound();

        // ولا يُعاد نطاقُ مصروفه، ولا يُرى توزيعُه
        $this->actingAs($this->owner)->put(route('admin.expenses.scope', $theirs->id), ['scope' => 'business'])->assertNotFound();
        $this->assertSame(1, ExpenseBranchAllocation::where('expense_id', $theirs->id)->count());

        $rows = $this->actingAs($this->owner)->get(route('admin.expenses.index'))->viewData('page')['props']['expenses'];
        $this->assertSame([], $rows);
        $this->assertNotContains($theirMuscat->id, array_column($this->page()['options']['branches'], 'value'));

        // وأرقامُه لا تدخل أرقامه
        $this->assertSame(0.0, $this->page()['summary']['sales']);
        $this->assertSame(0.0, $this->page()['summary']['expenses']);
    }

    /* ═══════════════ ٦ · الفهرس والملفّات ═══════════════ */

    public function test_the_report_sits_under_financial_reports(): void
    {
        $entry = collect(Reports::forUser($this->owner))->firstWhere('key', 'profit');

        $this->assertNotNull($entry);
        $this->assertSame('financial', $entry['category']);
        $this->assertSame(route('admin.reports.profit'), $entry['href']);
    }

    public function test_the_xlsx_and_csv_carry_the_branch_and_its_unallocated_overhead(): void
    {
        $this->sell($this->muscat, 1000, 50, 600);
        $this->spend(200, $this->muscat);
        $this->spend(700);
        $this->sell($this->sohar, 8888, 0, 1);

        $query = ['report' => 'profit', 'range' => 'month', 'branch_id' => $this->muscat->id];

        $res = $this->actingAs($this->owner)->get(route('admin.reports.export.xlsx', $query))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $xlsx = collect(IOFactory::load($path)->getActiveSheet()->toArray())
            ->map(fn ($r) => implode('|', array_map(fn ($c) => (string) $c, $r)))->implode("\n");
        @unlink($path);

        $csv = $this->actingAs($this->owner)->get(route('admin.reports.export.csv', $query))->assertOk()->streamedContent();

        foreach (['xlsx' => $xlsx, 'csv' => $csv] as $kind => $text) {
            $this->assertStringContainsString('فرع مسقط', $text, "{$kind}: لا يقول أيَّ فرعٍ يقيس");
            $this->assertStringContainsString('المصروفات العامة غير الموزعة', $text, "{$kind}: سكت عن العامّ غير الموزّع");
            $this->assertStringContainsString('صافي الربح', $text);
            $this->assertStringContainsString('150', $text);
            $this->assertStringNotContainsString('8888', $text, "{$kind}: تسرّبت مبيعاتُ فرعٍ آخر");
        }

        // والنشاطُ كلُّه لا يذكر «غير الموزّعة»: هي من مصروفاته
        $whole = $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'profit', 'range' => 'month']))->streamedContent();
        $this->assertStringContainsString('النشاط بالكامل', $whole);
        $this->assertStringNotContainsString('المصروفات العامة غير الموزعة', $whole);
    }

    public function test_the_pdf_carries_the_same_scope(): void
    {
        $this->sell($this->muscat, 1000, 50, 600);
        $this->spend(700);

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
                ->get(route('admin.reports.export.pdf', ['report' => 'profit', 'range' => 'month', 'branch_id' => $this->muscat->id]))
                ->assertOk();
        } finally {
            Pdf::swap($was);
        }

        $this->assertStringContainsString('فرع مسقط', $fake->html);
        $this->assertStringContainsString('المصروفات العامة غير الموزعة', $fake->html);
        $this->assertStringContainsString('تكلفة البضاعة المباعة', $fake->html);
    }

    /* ═══════════════ ٧ · الهجرة ═══════════════ */

    public function test_the_migration_rolls_back_and_forth_and_keeps_expenses(): void
    {
        $e = $this->spend(321);
        $migration = require database_path('migrations/2026_10_01_100000_an_expense_belongs_to_a_branch_or_is_split_across_branches.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('expenses', 'branch_id'));
        $this->assertFalse(Schema::hasTable('expense_branch_allocations'));
        $this->assertSame(321.0, (float) DB::table('expenses')->where('id', $e->id)->value('amount'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('expenses', 'branch_id'));
        $this->assertTrue(Schema::hasTable('expense_branch_allocations'));
        $this->assertNull(DB::table('expenses')->where('id', $e->id)->value('branch_id'), 'لا يُخمَّن فرعٌ لما مضى');
    }
}
