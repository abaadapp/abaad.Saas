<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseBranchAllocation;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Bank;
use App\Support\Boutiques;
use App\Support\ExpenseScope;
use App\Support\Ledger;
use App\Support\Profitability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ورقةُ البوتيك دَينٌ من يوم صدورها — ومصروفٌ في شهر بيعها، مرّةً واحدة،
 * يقرؤه ربحُ المتجر وربحُ الفرع بالرقم نفسِه.
 *
 * كان مصروفُها لا يدخل الدفترَ حتّى يُسدَّد، فيُقيَّد بيوم السداد: ورقةُ
 * أكتوبر تُدفع في نوفمبر، فيُقرأ ربحُ أكتوبر أعلى بحصّة البوتيك كلِّها وربحُ
 * نوفمبر أقلّ بها، والميزانيةُ لا تعرف أنّ على المحلّ شيئًا. ولا يصحّ الشهرُ
 * إلّا بأن يُكتب «مدفوع» على مالٍ لم يخرج. وتقريرُ الفروع يقرأ المصروفاتِ
 * المدفوعة من جدولها، فلو قُيّدت الورقةُ في الدفتر وحده لافترق ربحُ الفرع عن
 * ربح متجره. وجدها تدقيقُ ماليّة RIBBON (2026-10-25) قبل أوّل ورقةٍ
 * لمستحقّات بوتيكاته (258.000).
 */
class ABoutiqueSheetIsOwedFromTheDayItIsIssuedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $main;

    private Branch $bausher;

    private Boutique $boutique;

    private Product $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = $this->makeShop('متجر الورقة', 'sheet@abaad.om');
        $this->owner = User::where('business_id', $this->shop->id)->firstOrFail();
        $this->main = Branch::where('business_id', $this->shop->id)->orderBy('id')->firstOrFail();
        $this->bausher = Branch::create(['business_id' => $this->shop->id, 'name' => 'بوشر']);
        $this->actingAs($this->owner);

        $this->post(route('admin.finance.banks.store'), [
            'bank_name' => 'بنك مسقط', 'opening_balance' => 1000, 'opening_date' => '2027-01-01',
        ])->assertSessionHasNoErrors();

        [$this->boutique, $this->theirs] = $this->boutiqueOf($this->shop);

        // والقطعُ على الفرعين — يبيع كلٌّ ممّا عنده
        foreach ([$this->main, $this->bausher] as $branch) {
            BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $branch->id, 'product_id' => $this->theirs->id, 'quantity' => 50]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeShop(string $name, string $email): Business
    {
        $shop = Business::create(['name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create([
            'business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return $shop;
    }

    /** @return array{0: Boutique, 1: Product} */
    private function boutiqueOf(Business $shop): array
    {
        $boutique = Boutique::create(['business_id' => $shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $product = Product::create([
            'business_id' => $shop->id, 'name' => 'عطر لمى', 'price' => 50, 'cost' => 30,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true,
            'boutique_id' => $boutique->id,
        ]);

        return [$boutique, $product];
    }

    /** بيعةٌ لبضاعة البوتيك (٥٠ للقطعة، عمولة ٢٠٪) في فرعٍ بعينه */
    private function sell(int $qty = 2, ?Branch $at = null): void
    {
        $this->withSession(['current_branch' => ($at ?? $this->main)->id])->postJson('/pos/checkout', [
            'items' => [['id' => $this->theirs->id, 'name' => 'عطر لمى', 'qty' => $qty, 'price' => 50]],
            'payment_method' => 'نقدي',
        ])->assertOk();
    }

    /** يُصدر ورقةَ فبراير في لحظةٍ بعينها ويردّ مصروفَها */
    private function issue(string $at = '2027-03-02 09:00:00'): Expense
    {
        Carbon::setTestNow($at);
        $this->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-02'])->assertSessionHasNoErrors();

        return Expense::findOrFail(BoutiqueSettlement::where('business_id', $this->shop->id)->latest('id')->firstOrFail()->expense_id);
    }

    private function pay(Expense $expense, string $method = 'تحويل بنكي', string $at = '2027-03-05 11:00:00'): void
    {
        Carbon::setTestNow($at);
        $this->post(route('admin.expenses.paid', $expense->id), ['method' => $method])->assertSessionHasNoErrors();
    }

    private function accrual(): ?JournalEntry
    {
        return JournalEntry::where('business_id', $this->shop->id)->where('source', Boutiques::ACCRUAL)->first();
    }

    /** أرقامُ الشهر كما يقرؤها تقريرُ صافي الربح — للمتجر كلِّه أو لفرع */
    private function profit(string $month, ?Branch $branch = null): array
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();

        return Profitability::summary($this->shop->id, $start, $start->copy()->addMonthNoOverflow(), $branch?->id);
    }

    private function owed(): float
    {
        return Ledger::balance($this->shop->id, 'boutique_payable');
    }

    private function bank(): float
    {
        return round(Bank::leaf($this->shop->id)->balance(), 3);
    }

    private function cash(): float
    {
        return Ledger::balance($this->shop->id, 'cash');
    }

    /* ═══════════ ١ و٢ — شهرُ القيد شهرُ البيع ═══════════ */

    public function test_issued_in_the_month_of_the_sale_it_is_that_months_expense_and_a_debt(): void
    {
        $this->sell();
        $expense = $this->issue('2027-02-12 09:00:00');

        $this->assertSame('2027-02-10', $this->accrual()->entry_date->toDateString());
        $this->assertSame(80.0, $this->profit('2027-02')['expenses']);
        $this->assertSame(80.0, $this->profit('2027-02', $this->main)['expenses'], 'الفرعُ لا ينتظر السداد');
        $this->assertSame(80.0, $this->owed());

        // ولا يُعدّ مدفوعًا ولا يخرج مالٌ
        $this->assertSame(Expense::UNPAID, $expense->fresh()->status);
        $this->assertSame([1000.0, 100.0], [$this->bank(), $this->cash()]);
    }

    public function test_issued_in_a_later_month_it_stays_in_the_month_of_the_sale(): void
    {
        $this->sell();
        $this->issue();

        $entry = $this->accrual();
        $this->assertSame('2027-02-10', $entry->entry_date->toDateString());
        $this->assertSame(BoutiqueSettlement::class, $entry->sourceable_type);
        $this->assertSame([80.0, 0.0], [$this->profit('2027-02')['expenses'], $this->profit('2027-03')['expenses']]);
        $this->assertSame([80.0, 0.0], [$this->profit('2027-02', $this->main)['expenses'], $this->profit('2027-03', $this->main)['expenses']]);
    }

    /* ═══════════ ٣ و٥ و١٢ — السدادُ في شهرٍ آخر ═══════════ */

    public function test_paid_in_another_month_the_debt_and_the_bank_go_down_and_the_profit_stays(): void
    {
        $this->sell();
        $expense = $this->issue();
        $receivable = Ledger::balance($this->shop->id, 'receivable');

        $this->pay($expense);

        $this->assertSame(0.0, $this->owed());
        $this->assertSame(920.0, $this->bank());
        $this->assertSame(100.0, $this->cash(), 'نقص الدرجُ مالًا لم يخرج منه');
        $this->assertSame($receivable, Ledger::balance($this->shop->id, 'receivable'), 'السدادُ لا يمسّ ذمم العملاء');

        // المصروفُ في فبراير وحده — للمتجر وللفرع
        $this->assertSame([80.0, 0.0], [$this->profit('2027-02')['expenses'], $this->profit('2027-03')['expenses']]);
        $this->assertSame([80.0, 0.0], [$this->profit('2027-02', $this->main)['expenses'], $this->profit('2027-03', $this->main)['expenses']]);
        $this->assertSame(80.0, Ledger::balance($this->shop->id, 'other_expenses'), 'كُتب المصروفُ مرّتين');

        // والسدادُ بيومه، وبرقم الورقة
        $paid = JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $expense->id)->sole();
        $this->assertSame('2027-03-05', $paid->entry_date->toDateString(), 'البنكُ ينقص يومَ خرج المال لا في الماضي');
        $this->assertStringContainsString((string) $expense->reference, (string) $paid->description);

        $fresh = $expense->fresh();
        $this->assertSame(Expense::PAID, $fresh->status);
        $this->assertSame('2027-02-10', Carbon::parse($fresh->spent_at)->toDateString(), 'يومُ الورقة يبقى يومَ مصروفها');
    }

    public function test_paid_from_the_drawer_the_cash_goes_down_and_the_bank_stays(): void
    {
        $this->sell();
        $this->pay($this->issue(), 'نقدي');

        $this->assertSame([0.0, 1000.0, 20.0], [$this->owed(), $this->bank(), $this->cash()]);
    }

    /* ═══════════ ٤ — الفرعُ والمتجرُ ربحٌ واحد ═══════════ */

    public function test_the_branch_and_the_shop_read_the_same_profit_before_and_after_paying(): void
    {
        $this->sell();
        $expense = $this->issue();

        $this->assertSame(20.0, $this->profit('2027-02')['net_profit'], '١٠٠ إيراد − ٨٠ حصّة البوتيك');
        $this->assertSame($this->profit('2027-02')['net_profit'], $this->profit('2027-02', $this->main)['net_profit']);

        $this->pay($expense);

        $this->assertSame(20.0, $this->profit('2027-02')['net_profit']);
        $this->assertSame(20.0, $this->profit('2027-02', $this->main)['net_profit']);
        $this->assertSame([0.0, 0.0], [$this->profit('2027-03')['net_profit'], $this->profit('2027-03', $this->main)['net_profit']]);
    }

    /* ═══════════ ٦ — لا يُسدَّد الدَّينُ مرّتين ═══════════ */

    public function test_paying_twice_clears_once(): void
    {
        $this->sell();
        $expense = $this->issue();

        $this->pay($expense);
        $this->post(route('admin.expenses.paid', $expense->id), ['method' => 'نقدي']);

        $this->assertSame(1, JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $expense->id)->count());
        $this->assertSame([0.0, 920.0, 100.0], [$this->owed(), $this->bank(), $this->cash()]);
    }

    /* ═══════════ ٨ — ورقةٌ على فرعين ═══════════ */

    public function test_a_sheet_over_two_branches_is_split_by_what_each_sold(): void
    {
        $this->sell(2, $this->main);      // ١٠٠ ← للبوتيك ٨٠
        $this->sell(1, $this->bausher);   // ٥٠ ← للبوتيك ٤٠
        $expense = $this->issue();

        $this->assertSame(120.0, (float) $expense->amount);
        $this->assertSame(ExpenseScope::SPLIT, ExpenseScope::of($expense->fresh()));
        $this->assertEqualsCanonicalizing(
            [[$this->main->id, 80.0], [$this->bausher->id, 40.0]],
            ExpenseBranchAllocation::where('expense_id', $expense->id)->get()->map(fn ($a) => [(int) $a->branch_id, (float) $a->amount])->all(),
        );
        $this->assertNull($this->accrual()->branch_id, 'الموزَّعُ قيدٌ واحد للنشاط');

        $this->assertSame(80.0, $this->profit('2027-02', $this->main)['expenses']);
        $this->assertSame(40.0, $this->profit('2027-02', $this->bausher)['expenses']);
        $this->assertSame(
            $this->profit('2027-02')['net_profit'],
            round($this->profit('2027-02', $this->main)['net_profit'] + $this->profit('2027-02', $this->bausher)['net_profit'], 3),
            'مجموعُ الفرعين ربحُ المتجر',
        );
    }

    public function test_a_sheet_from_one_branch_is_that_branchs_and_so_is_its_entry(): void
    {
        $this->sell(1, $this->bausher);
        $expense = $this->issue()->fresh();

        $this->assertSame((int) $this->bausher->id, (int) $expense->branch_id);
        $this->assertSame((int) $this->bausher->id, (int) $this->accrual()->branch_id);
        $this->assertSame([0.0, 40.0], [$this->profit('2027-02', $this->main)['expenses'], $this->profit('2027-02', $this->bausher)['expenses']]);
    }

    /** بندٌ طلبُه بلا فرع: لا يُنسب إلى فرعٍ لم يبعه — يبقى عامًّا ويُقال */
    public function test_a_line_with_no_branch_keeps_the_sheet_general_and_disclosed(): void
    {
        $this->sell();
        DB::table('orders')->where('business_id', $this->shop->id)->update(['branch_id' => null]);
        $expense = $this->issue()->fresh();

        $this->assertSame(ExpenseScope::BUSINESS, ExpenseScope::of($expense));
        $this->assertSame(0.0, $this->profit('2027-02', $this->main)['expenses']);
        $this->assertSame([80.0, 1], [$this->profit('2027-02', $this->main)['unallocated'], $this->profit('2027-02', $this->main)['unallocated_count']]);
    }

    /* ═══════════ ٩ — الموزَّعُ والعامُّ في مكانيهما ═══════════ */

    public function test_split_and_general_expenses_keep_their_places_beside_the_sheet(): void
    {
        $this->sell(2, $this->main);
        $this->sell(1, $this->bausher);
        $this->issue();

        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 60, 'status' => Expense::PAID, 'method' => 'نقدي', 'spent_at' => '2027-02-20',
            'scope' => 'split', 'allocations' => [['branch_id' => $this->main->id, 'amount' => 40], ['branch_id' => $this->bausher->id, 'amount' => 20]],
        ])->assertSessionHasNoErrors();
        $this->post(route('admin.expenses.store'), [
            'type' => 'تسويق', 'amount' => 30, 'status' => Expense::PAID, 'method' => 'نقدي', 'spent_at' => '2027-02-21',
        ])->assertSessionHasNoErrors();

        $main = $this->profit('2027-02', $this->main);
        $bausher = $this->profit('2027-02', $this->bausher);

        $this->assertSame([120.0, 60.0], [$main['expenses'], $bausher['expenses']]);
        $this->assertSame([30.0, 1], [$main['unallocated'], $main['unallocated_count']], 'العامُّ يُقال ولا يُطرح');
        $this->assertSame(210.0, $this->profit('2027-02')['expenses']);
        $this->assertSame($this->profit('2027-02')['expenses'], round($main['expenses'] + $bausher['expenses'] + $main['unallocated'], 3));
    }

    /* ═══════════ ١٠ — الفترةُ المقفلة ═══════════ */

    public function test_a_closed_month_is_not_written_into_and_the_sale_dates_are_kept(): void
    {
        Setting::create(['business_id' => $this->shop->id, 'key' => 'books_closed_through', 'value' => '2027-02-28']);
        $this->sell();
        $expense = $this->issue();

        $this->assertSame('2027-03-02', $this->accrual()->entry_date->toDateString(), 'أوّلُ يومٍ مفتوح');
        $this->assertSame([0.0, 80.0], [$this->profit('2027-02')['expenses'], $this->profit('2027-03')['expenses']]);
        $this->assertSame([0.0, 80.0], [$this->profit('2027-02', $this->main)['expenses'], $this->profit('2027-03', $this->main)['expenses']]);
        $this->assertSame(80.0, $this->owed());

        // وأيّامُ البيع باقيةٌ في الورقة ومصروفها — ويومُ القيد هو يومُ المصروف
        $sheet = BoutiqueSettlement::where('business_id', $this->shop->id)->sole();
        $this->assertSame('2027-02-10', Carbon::parse($sheet->covered_to)->toDateString());
        $this->assertSame('2027-02', $sheet->period);
        $this->assertSame('2027-02-10', Carbon::parse($expense->due_date)->toDateString());
        $this->assertSame('2027-03-02', Carbon::parse($expense->spent_at)->toDateString());
    }

    /* ═══════════ ١١ — ورقةٌ صدرت قبل الإصلاح ═══════════ */

    /**
     * لا قيدَ لها يومَ صدورها ولا فرع — تُسدَّد على طريقتها الأولى.
     *
     * لو أُطفئ عنها دَينٌ لم يُكتب لصار 2160 مدينًا بلا سبب، ولَما خرجت حصّةُ
     * البوتيك من الربح أبدًا.
     */
    public function test_a_sheet_issued_before_is_paid_the_old_way(): void
    {
        $this->sell();
        $expense = $this->issue();
        $entry = $this->accrual();
        DB::table('journal_lines')->where('journal_entry_id', $entry->id)->delete();
        DB::table('journal_entries')->where('id', $entry->id)->delete();
        DB::table('expenses')->where('id', $expense->id)->update(['branch_id' => null]);
        $this->assertNull(Boutiques::accrual($expense), 'تهيئة: ورقةٌ بلا قيدِ صدور');

        // قبل سدادها لا يعدّها أحد — كما كانت
        $this->assertSame([0.0, 0.0], [$this->profit('2027-02')['expenses'], $this->profit('2027-02', $this->main)['unallocated']]);

        $this->pay($expense);

        $this->assertSame([0.0, 920.0], [$this->owed(), $this->bank()]);
        $this->assertSame(80.0, $this->profit('2027-03')['expenses'], 'الورقةُ القديمة مصروفُها يومَ سدادها كما كان');
        $this->assertSame(80.0, $this->profit('2027-03', $this->main)['unallocated']);
        $this->assertSame('2027-03-05', Carbon::parse($expense->fresh()->spent_at)->toDateString());
    }

    /* ═══════════ ١٣ — كلُّ متجرٍ في متجره ═══════════ */

    public function test_another_shops_sheet_stays_in_its_shop(): void
    {
        $this->sell();
        $ours = $this->issue();

        // متجرٌ ثانٍ يبيع ويُصدر ورقتَه
        Carbon::setTestNow('2027-02-10 10:00:00');
        $other = $this->makeShop('متجرٌ آخر', 'other@abaad.om');
        $otherOwner = User::where('business_id', $other->id)->firstOrFail();
        [$theirBoutique, $theirProduct] = $this->boutiqueOf($other);
        $this->actingAs($otherOwner)->withSession(['current_branch' => null])->postJson('/pos/checkout', [
            'items' => [['id' => $theirProduct->id, 'name' => 'عطر لمى', 'qty' => 4, 'price' => 50]],
            'payment_method' => 'نقدي',
        ])->assertOk();
        Carbon::setTestNow('2027-03-02 09:00:00');
        $this->actingAs($otherOwner)->post(route('admin.boutiques.settle', $theirBoutique->id), ['period' => '2027-02'])->assertSessionHasNoErrors();
        $theirs = Expense::where('business_id', $other->id)->sole();

        $this->assertSame([(int) $ours->id], Boutiques::accruedExpenseIds($this->shop->id)->pluck('expense_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(80.0, $this->profit('2027-02')['expenses']);
        $this->assertSame(80.0, $this->profit('2027-02', $this->main)['expenses']);
        $this->assertSame(80.0, $this->owed());

        // ولا تُسدَّد ورقتُه ولا يُغيَّر نطاقُها من متجرنا
        $this->actingAs($this->owner)->post(route('admin.expenses.paid', $theirs->id), ['method' => 'نقدي'])->assertNotFound();
        $this->actingAs($this->owner)->put(route('admin.expenses.scope', $theirs->id), ['scope' => 'business'])->assertNotFound();
        $this->assertSame(Expense::UNPAID, $theirs->fresh()->status);
    }

    /* ═══════════ ١٤ — القديمُ لا يُمسّ ═══════════ */

    public function test_issuing_and_paying_never_touch_an_earlier_entry(): void
    {
        $this->sell();
        $before = $this->ledgerRows();
        $entries = JournalEntry::where('business_id', $this->shop->id)->count();

        $this->pay($this->issue());

        $this->assertSame($before, array_slice($this->ledgerRows(), 0, count($before)), 'تغيّر قيدٌ قديم');
        $this->assertSame($entries + 2, JournalEntry::where('business_id', $this->shop->id)->count(), 'قيدُ الإصدار وقيدُ السداد وحدهما');
    }

    /** @return list<array{0: int, 1: int, 2: string, 3: string, 4: string, 5: ?string}> */
    private function ledgerRows(): array
    {
        return DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.business_id', $this->shop->id)->orderBy('e.id')->orderBy('l.id')
            ->get(['e.id', 'l.account_id', 'l.debit', 'l.credit', 'e.entry_date', 'e.reversed_at'])
            ->map(fn ($r) => [(int) $r->id, (int) $r->account_id, (string) $r->debit, (string) $r->credit, substr((string) $r->entry_date, 0, 10), $r->reversed_at])
            ->all();
    }

    /* ═══════════ وما يحرس الاتّفاقَ بين الدفتر والفروع ═══════════ */

    public function test_the_scope_of_an_accrued_sheet_cannot_be_changed_by_hand(): void
    {
        $this->sell();
        $expense = $this->issue();

        $this->put(route('admin.expenses.scope', $expense->id), ['scope' => 'business'])->assertSessionHasErrors('scope');

        $this->assertSame((int) $this->main->id, (int) $expense->fresh()->branch_id);
        $this->assertSame(80.0, $this->profit('2027-02', $this->main)['expenses']);
    }

    /** والحسابُ حسابُ نوع المصروف نفسُه — ما اختاره التاجرُ لـ«تسوية بوتيكات» */
    public function test_the_share_goes_to_the_account_of_its_expense_type(): void
    {
        $this->sell();
        $this->issue();

        $debited = $this->accrual()->lines()->where('debit', '>', 0)->value('account_id');
        $this->assertSame('other_expenses', Account::find($debited)->system_key);
    }
}
