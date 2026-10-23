<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\BooksPeriod;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * بعد كلّ حذفٍ واستعادةٍ وتغييرِ نطاقٍ وسداد — الدفترُ وشاشةُ المصروفات ودفترُ الصندوق رقمٌ واحد.
 *
 * كلُّ اختبارٍ آخر يسأل عن عمليّةٍ واحدة. وهذا يسأل عن الحاصل بعد سلسلةٍ
 * منها عبر شهرين، كما يفعل تاجرٌ في أسبوعه: لا مصروفَ يُعدّ مرّتين، ولا
 * قيدَ حيٌّ لمصروفٍ محذوف، ولا حركةَ صندوقٍ بلا مصروف، والميزانُ متوازن.
 *
 * والشهرُ يطابق شهرَه ما دام التصحيحُ في شهر قيده أو بتاريخه الأصليّ.
 * و«في الفترة الحاليّة» وحده يُفرّق بينهما عمدًا: يبقى المصروفُ في شهره
 * في الدفتر ويُطرح من الجاري — والمجموعُ واحد.
 */
class TheLedgerAgreesWithTheExpensesScreenAfterEveryCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'صحار']);

        $this->actingAs(User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]));

        // سبتمبر مفتوحٌ معلوم — وإلّا لم يُتح التاريخُ الأصليّ
        Setting::create(['business_id' => $this->business->id, 'key' => BooksPeriod::SETTING, 'value' => '2026-08-31']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function spend(string $on, float $amount, array $over = []): Expense
    {
        $this->post(route('admin.expenses.store'), array_merge([
            'type' => 'إيجار', 'amount' => $amount, 'spent_at' => $on, 'method' => 'نقدي',
        ], $over))->assertSessionHasNoErrors();

        return Expense::latest('id')->firstOrFail();
    }

    private function why(string $mode): array
    {
        return ['correction' => $mode, 'correction_reason' => 'مكرّر'];
    }

    /** مصروفاتُ الدفتر لشهرٍ أو لكلّ الزمن — ما تقرؤه اللوحةُ والتقارير */
    private function ledger(?string $ym = null): float
    {
        $start = $ym ? Carbon::parse($ym.'-01') : null;

        return Ledger::operatingExpenses($this->business->id, $start, $start?->copy()->addMonth());
    }

    /** وما تقرؤه شاشةُ المصروفات وتقاريرُها — المدفوعُ الحيّ بتاريخ صرفه */
    private function screen(?string $ym = null): float
    {
        return round((float) Expense::where('business_id', $this->business->id)->paid()
            ->when($ym, fn ($q) => $q->where('spent_at', '>=', $ym.'-01')
                ->where('spent_at', '<', Carbon::parse($ym.'-01')->addMonth()->toDateString()))
            ->sum('amount'), 3);
    }

    private function cashBook(): float
    {
        return round((float) Transaction::where('business_id', $this->business->id)->sum('amount'), 3);
    }

    public function test_a_week_of_corrections_across_two_months_leaves_one_number_everywhere(): void
    {
        $a = $this->spend('2026-09-12', 400);
        $b = $this->spend('2026-09-28', 150);
        $c = $this->spend('2026-10-05', 300);
        $bill = $this->spend('2026-10-06', 90, ['type' => 'كهرباء', 'status' => 'غير مدفوع', 'due_date' => '2026-11-01']);
        $this->post(route('admin.finance.store'), [
            'kind' => 'expense', 'amount' => 45, 'side' => Books::CASH, 'expense_type' => 'صيانة', 'occurred_at' => '2026-10-07',
        ])->assertSessionHasNoErrors();
        $movement = Expense::latest('id')->firstOrFail();

        // سبتمبريٌّ يُحذف بتاريخه، وآخرُ في الجاري ثمّ يُستعاد
        $this->delete(route('admin.expenses.destroy', $a->id), $this->why(BooksPeriod::ORIGINAL))->assertSessionHasNoErrors();
        $this->delete(route('admin.expenses.destroy', $b->id), $this->why(BooksPeriod::CURRENT))->assertSessionHasNoErrors();
        $this->post(route('admin.expenses.restore', $b->id))->assertSessionHasNoErrors();

        // أكتوبريٌّ يُنقل إلى فرع، ثمّ يُحذف ويُستعاد، والفاتورةُ تُسدَّد مرّتين
        $sohar = Branch::where('business_id', $this->business->id)->where('name', 'صحار')->value('id');
        $this->put(route('admin.expenses.scope', $c->id), ['scope' => 'branch', 'branch_id' => $sohar])->assertSessionHasNoErrors();
        $this->delete(route('admin.expenses.destroy', $c->id))->assertSessionHasNoErrors();
        $this->post(route('admin.expenses.restore', $c->id))->assertSessionHasNoErrors();
        $this->post(route('admin.expenses.paid', $bill->id))->assertSessionHasNoErrors();
        $this->post(route('admin.expenses.paid', $bill->id))->assertSessionHasNoErrors();

        // ومصروفُ شاشة المالية لا يُحذف من هنا
        $this->delete(route('admin.expenses.destroy', $movement->id))->assertSessionHasErrors('expense');

        // كلَّ الزمن: 150 + 300 + 90 + 45
        $this->assertSame(585.0, $this->screen());
        $this->assertSame($this->screen(), $this->ledger(), 'الدفترُ وشاشةُ المصروفات رقمان');
        $this->assertSame($this->screen(), $this->cashBook(), 'دفترُ الصندوق وشاشةُ المصروفات رقمان');

        // وشهرًا شهرًا — لم يُصحَّح شيءٌ هنا «في الفترة الحاليّة» إلّا ما استُعيد
        $this->assertSame($this->screen('2026-09'), $this->ledger('2026-09'));
        $this->assertSame($this->screen('2026-10'), $this->ledger('2026-10'));

        // ولا قيدَ حيٌّ لمصروفٍ محذوف، ولا مصروفَ حيٌّ مدفوعٌ بلا قيدٍ واحدٍ بالضبط
        foreach (Expense::withTrashed()->where('business_id', $this->business->id)->get() as $e) {
            $live = Books::liveEntriesFor($e)->count() + (Books::movementEntryIsLive($e) ? 1 : 0);
            $this->assertSame($e->trashed() || ! $e->isPaid() ? 0 : 1, $live, 'المصروف '.$e->reference);
        }

        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
        $this->assertSame(0, JournalEntry::where('business_id', $this->business->id)->whereNotNull('reverses_id')
            ->whereNotIn('reverses_id', JournalEntry::where('business_id', $this->business->id)->pluck('id'))->count());
    }

    public function test_correcting_in_the_current_period_moves_the_month_but_not_the_total(): void
    {
        $this->spend('2026-10-02', 300);
        $old = $this->spend('2026-09-15', 70);

        $this->delete(route('admin.expenses.destroy', $old->id), $this->why(BooksPeriod::CURRENT))->assertSessionHasNoErrors();

        // الدفترُ يُبقي سبتمبرَ كما قُرئ ويطرح من أكتوبر، والشاشةُ تُخرجه من سبتمبر
        $this->assertSame(70.0, $this->ledger('2026-09'));
        $this->assertSame(0.0, $this->screen('2026-09'));
        $this->assertSame(230.0, $this->ledger('2026-10'));
        $this->assertSame(300.0, $this->screen('2026-10'));

        // والمجموعُ واحد
        $this->assertSame($this->screen(), $this->ledger());
        $this->assertSame($this->screen(), $this->cashBook());
    }
}
