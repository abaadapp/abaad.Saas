<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Bank;
use App\Support\Boutiques;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ورقةُ البوتيك دَينٌ من يوم صدورها — ومصروفٌ في شهر بيعها، مرّةً واحدة.
 *
 * كان مصروفُها لا يدخل الدفترَ حتّى يُسدَّد، فيُقيَّد بيوم السداد: ورقةُ
 * أكتوبر تُدفع في نوفمبر، فيُقرأ ربحُ أكتوبر أعلى بحصّة البوتيك كلِّها وربحُ
 * نوفمبر أقلّ بها، والميزانيةُ لا تعرف أنّ على المحلّ شيئًا. ولا يصحّ الشهرُ
 * إلّا بأن يُكتب «مدفوع» على مالٍ لم يخرج. وجدها تدقيقُ ماليّة RIBBON
 * (2026-10-25) قبل أوّل ورقةٍ لمستحقّات بوتيكاته (258.000).
 */
class ABoutiqueSheetIsOwedFromTheDayItIsIssuedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

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

        $this->shop = Business::create(['name' => 'متجر الورقة', 'type' => 'محل ورد', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create([
            'business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'sheet@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);

        $this->post(route('admin.finance.banks.store'), [
            'bank_name' => 'بنك مسقط', 'opening_balance' => 1000, 'opening_date' => '2027-01-01',
        ])->assertSessionHasNoErrors();

        $this->boutique = Boutique::create([
            'business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true,
        ]);
        $this->theirs = Product::create([
            'business_id' => $this->shop->id, 'name' => 'عطر لمى', 'price' => 50, 'cost' => 30,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true,
            'boutique_id' => $this->boutique->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** بيعتان لبضاعة البوتيك (١٠٠) في العاشر من فبراير، ثمّ ورقتُه في الثاني من مارس — صافيها ٨٠ */
    private function issue(): Expense
    {
        $this->postJson('/pos/checkout', [
            'items' => [['id' => $this->theirs->id, 'name' => 'عطر لمى', 'qty' => 2, 'price' => 50]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        Carbon::setTestNow('2027-03-02 09:00:00');
        $this->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-02'])->assertSessionHasNoErrors();

        return Expense::findOrFail(BoutiqueSettlement::where('business_id', $this->shop->id)->firstOrFail()->expense_id);
    }

    private function accrual(): ?JournalEntry
    {
        return JournalEntry::where('business_id', $this->shop->id)->where('source', Boutiques::ACCRUAL)->first();
    }

    /** مصروفاتُ الشهر التشغيليّة كما تقرؤها اللوحة */
    private function expensesIn(string $month): float
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();

        return Ledger::operatingExpenses($this->shop->id, $start, $start->copy()->endOfMonth());
    }

    private function owed(): float
    {
        return Ledger::balance($this->shop->id, 'boutique_payable');
    }

    private function bank(): float
    {
        return round(Bank::leaf($this->shop->id)->balance(), 3);
    }

    public function test_issuing_books_the_share_in_the_month_it_was_sold_and_owes_it(): void
    {
        $expense = $this->issue();

        $entry = $this->accrual();
        $this->assertNotNull($entry, 'الورقةُ صدرت ولم يُكتب لها في الدفتر شيء');
        $this->assertSame('2027-02-10', $entry->entry_date->toDateString(), 'حصّةُ البوتيك تُقيَّد في شهر بيعها');
        $this->assertSame(BoutiqueSettlement::class, $entry->sourceable_type);

        $this->assertSame(80.0, $this->expensesIn('2027-02'));
        $this->assertSame(0.0, $this->expensesIn('2027-03'));
        $this->assertSame(80.0, $this->owed(), 'على المحلّ للبوتيك ٨٠ ولا تعرفها الميزانيّة');

        // ولا يُعدّ مدفوعًا: لا مالَ خرج
        $this->assertSame(Expense::UNPAID, $expense->fresh()->status);
        $this->assertSame([1000.0, 100.0], [$this->bank(), Ledger::balance($this->shop->id, 'cash')]);
    }

    public function test_paying_clears_the_debt_from_where_the_money_left_and_expenses_nothing_twice(): void
    {
        $expense = $this->issue();

        Carbon::setTestNow('2027-03-05 11:00:00');
        $this->post(route('admin.expenses.paid', $expense->id), ['method' => 'تحويل بنكي'])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->owed());
        $this->assertSame(920.0, $this->bank());
        $this->assertSame(100.0, Ledger::balance($this->shop->id, 'cash'), 'نقص الدرجُ مالًا لم يخرج منه');

        // المصروفُ في فبراير وحده — السدادُ لا يكتبه ثانيةً في مارس
        $this->assertSame(80.0, $this->expensesIn('2027-02'));
        $this->assertSame(0.0, $this->expensesIn('2027-03'));

        $paid = JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $expense->id)->sole();
        $this->assertSame('2027-03-05', $paid->entry_date->toDateString(), 'البنكُ ينقص يومَ خرج المال لا في الماضي');

        $fresh = $expense->fresh();
        $this->assertSame(Expense::PAID, $fresh->status);
        $this->assertSame('2027-02-10', Carbon::parse($fresh->spent_at)->toDateString(), 'يومُ الورقة يبقى يومَ مصروفها');
    }

    public function test_paying_twice_clears_once(): void
    {
        $expense = $this->issue();

        $this->post(route('admin.expenses.paid', $expense->id), ['method' => 'تحويل بنكي']);
        $this->post(route('admin.expenses.paid', $expense->id), ['method' => 'نقدي']);

        $this->assertSame(1, JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $expense->id)->count());
        $this->assertSame([0.0, 920.0, 100.0], [$this->owed(), $this->bank(), Ledger::balance($this->shop->id, 'cash')]);
    }

    /**
     * ورقةٌ صدرت قبل هذا لا قيدَ لها يومَ صدورها — تُسدَّد على طريقتها الأولى.
     *
     * لو أُطفئ عنها دَينٌ لم يُكتب لصار 2160 مدينًا بلا سبب، ولَما خرجت حصّةُ
     * البوتيك من الربح أبدًا.
     */
    public function test_a_sheet_issued_before_is_paid_the_old_way(): void
    {
        $expense = $this->issue();
        $entry = $this->accrual();
        DB::table('journal_lines')->where('journal_entry_id', $entry->id)->delete();
        DB::table('journal_entries')->where('id', $entry->id)->delete();
        $this->assertNull(Boutiques::accrual($expense), 'تهيئة: ورقةٌ بلا قيدِ صدور');

        Carbon::setTestNow('2027-03-05 11:00:00');
        $this->post(route('admin.expenses.paid', $expense->id), ['method' => 'تحويل بنكي'])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->owed());
        $this->assertSame(920.0, $this->bank());
        $this->assertSame(80.0, $this->expensesIn('2027-03'), 'الورقةُ القديمة مصروفُها يومَ سدادها كما كان');
        $this->assertSame('2027-03-05', Carbon::parse($expense->fresh()->spent_at)->toDateString());
    }

    /** شهرٌ مقفل لا يُكتب فيه — والورقةُ تُقيَّد في أوّل يومٍ مفتوح */
    public function test_a_closed_month_is_not_written_into(): void
    {
        Setting::create(['business_id' => $this->shop->id, 'key' => 'books_closed_through', 'value' => '2027-02-28']);

        $this->issue();

        $this->assertSame('2027-03-02', $this->accrual()->entry_date->toDateString());
        $this->assertSame(0.0, $this->expensesIn('2027-02'));
        $this->assertSame(80.0, $this->owed());
    }

    /** والحسابُ حسابُ نوع المصروف نفسُه — ما اختاره التاجرُ لـ«تسوية بوتيكات» */
    public function test_the_share_goes_to_the_account_of_its_expense_type(): void
    {
        $this->issue();

        $debited = $this->accrual()->lines()->where('debit', '>', 0)->value('account_id');
        $this->assertSame('other_expenses', Account::find($debited)->system_key);
    }
}
