<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\StockLosses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * هالكُ المخزون يظهر في المصروفات ولا يُقيَّد منها — لا يُحذف نصفَ حذف، ولا يُستعاد نقدًا خرج.
 *
 * ═══ صفٌّ يعرض قيدًا كُتب في مكانٍ آخر ═══
 *
 * `StockLosses::record` يكتب القيدَ على حركة المخزون (مدين مصروفاتٌ أخرى،
 * دائن المخزون)، ثمّ صفَّ مصروفٍ بوسيلة «قيد داخلي» ليُرى في الشاشة. وما
 * كُتب قبل ذلك رحّله `PostMissingStockLosses` على المصروف نفسِه بالحسابين
 * نفسَيهما.
 *
 * والاستعادةُ تبني قيدَ المصروف من نوعه ووسيلته (`recordExpense`) —
 * و«قيد داخلي» ليس وسيلةً يعرفها، فيقع على **الصندوق**. فالأوّلُ يُستعاد
 * فيُعدّ هالكُه مرّتين ويُطرح من الدرج مالٌ لم يخرج، والثاني يُستعاد
 * فيعود الهالكُ مدفوعًا من الصندوق لا منقوصًا من المخزون.
 */
class AStockLossIsNotPostedAgainFromTheExpensesScreenTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Ledger::ensureSystemAccounts($this->business->id);

        $this->actingAs(User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** هالكٌ كما يكتبه الجردُ اليوم — قيدُه على حركة المخزون */
    private function loss(): Expense
    {
        DB::transaction(fn () => StockLosses::record($this->business->id, 20, true, 'تالف في المخزن', now()));

        return Expense::sole();
    }

    /** وهالكٌ قديمٌ رحّله أمرُ الاستدراك على المصروف نفسِه */
    private function backfilledLoss(): Expense
    {
        $e = Expense::create([
            'business_id' => $this->business->id, 'type' => StockAdjustment::STOCKTAKE_LOSS, 'description' => 'فاقد قديم',
            'amount' => 20, 'method' => 'قيد داخلي', 'spent_at' => '2026-10-02',
        ]);
        Ledger::post($this->business->id, 'فاقد قديم', [
            ['account' => 'other_expenses', 'debit' => 20],
            ['account' => 'inventory', 'credit' => 20],
        ], Carbon::parse('2026-10-02'), StockLosses::SOURCE, null, null, $e);

        return $e;
    }

    private function books(): array
    {
        $bid = $this->business->id;

        return [
            'opex' => Ledger::operatingExpenses($bid),
            'cash' => Ledger::balance($bid, 'cash'),
            'inventory' => Ledger::balance($bid, 'inventory'),
        ];
    }

    public function test_it_is_not_half_deleted_from_the_expenses_screen(): void
    {
        $e = $this->loss();
        $before = $this->books();

        $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHasErrors('expense');

        $this->assertNotSoftDeleted($e);
        $this->assertSame($before, $this->books());
    }

    /** وقيدُه على حركة المخزون حيّ — فيعود صفُّه وحده، ولا يُكتب له شيء */
    public function test_one_deleted_before_the_fix_is_not_restored_out_of_the_cash_drawer(): void
    {
        $e = $this->loss();
        $before = $this->books();
        $e->delete();

        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted($e);
        $this->assertSame(0, JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $e->id)->count(),
            'كُتب للهالك قيدٌ ثانٍ من الصندوق');
        $this->assertSame($before, $this->books());
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /** وما عُكس قيدُه على المصروف نفسِه لا يُعاد من هنا — لا سبيلَ لبنائه إلّا على الصندوق */
    public function test_a_backfilled_one_is_not_reposted_against_cash(): void
    {
        $e = $this->backfilledLoss();
        Books::unpostExpense($e);
        $e->delete();
        $before = $this->books();

        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasErrors('expense');

        $this->assertSoftDeleted($e);
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cash'), 'عاد الهالكُ مدفوعًا من الصندوق');
        $this->assertSame($before, $this->books());
    }

    public function test_it_is_never_erased_while_it_stands_for_a_ledger_entry(): void
    {
        $e = $this->loss();
        $e->delete();

        $this->delete(route('admin.expenses.purge', $e->id));

        $this->assertNotNull(Expense::withTrashed()->find($e->id));
    }
}
