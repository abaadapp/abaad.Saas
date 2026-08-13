<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المصروف وقيده في الدفتر شيءٌ واحد، والمدفوع وحده مصروف.
 *
 * كان حذف مصروفٍ يُخفيه من شاشته ويترك سطره في دفتر المالية: تقرأ
 * المصروفات فترى صفرًا، وتقرأ المالية فترى ٣٠٠ — رقمان متناقضان عن الشيء
 * نفسه، والقيد اليتيم يدخل المطابقة البنكية كأنّ مبلغًا خرج.
 *
 * وكانت فاتورةٌ بحالة «غير مدفوع» تُخصم من الربح وتُقيَّد كأنّ المال خرج:
 * ربحٌ أقلّ ممّا هو، ونقدٌ أقلّ ممّا في الدرج. والحالة تُعرض ولا تُغيّر شيئًا.
 *
 * وتاريخ الاستحقاق عمودٌ يُعرض ويُرشَّح به ولا سبيل لإدخاله — يقول «—» إلى
 * الأبد.
 */
class ExpenseLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function record(array $over = []): Expense
    {
        $this->post(route('admin.expenses.store'), array_merge([
            'type' => 'إيجار', 'amount' => 300,
        ], $over))->assertSessionHasNoErrors();

        return Expense::latest('id')->first();
    }

    /* -------------------- القيد يتبع مصروفه -------------------- */

    public function test_deleting_an_expense_takes_its_ledger_entry_with_it(): void
    {
        $e = $this->record();
        $this->assertSame(1, Transaction::where('type', 'مصروف')->count());

        $this->delete(route('admin.expenses.destroy', $e->id));

        $this->assertSame(0, Transaction::where('type', 'مصروف')->count(), 'بقي قيدٌ يتيم في الدفتر');
        $this->assertSame(0.0, Demo::reportSummary('all')['expenses']);
    }

    public function test_restoring_it_brings_the_entry_back_with_the_same_reference(): void
    {
        $e = $this->record();
        $reference = $e->transaction->reference;

        $this->delete(route('admin.expenses.destroy', $e->id));
        $this->post(route('admin.expenses.restore', $e->id));

        $this->assertSame(1, Transaction::where('type', 'مصروف')->count());
        $this->assertSame($reference, $e->fresh()->transaction->reference, 'عاد بمرجعٍ جديد فانكسر تسلسل TRX');
        $this->assertSame(300.0, Demo::reportSummary('all')['expenses']);
    }

    public function test_purging_it_leaves_nothing_behind(): void
    {
        $e = $this->record();

        $this->delete(route('admin.expenses.destroy', $e->id));
        $this->delete(route('admin.expenses.purge', $e->id));

        $this->assertSame(0, Transaction::withTrashed()->where('type', 'مصروف')->count());
    }

    /* -------------------- المدفوع وحده مصروف -------------------- */

    public function test_an_unpaid_bill_is_not_money_out_yet(): void
    {
        $this->record(['type' => 'كهرباء', 'amount' => 90, 'status' => 'غير مدفوع']);

        $this->assertSame(0.0, Demo::reportSummary('all')['expenses'], 'خُصمت فاتورةٌ لم تُدفع من الربح');
        $this->assertSame(0, Transaction::where('type', 'مصروف')->count(), 'قُيّد خروج مالٍ لم يخرج');
    }

    public function test_paying_it_writes_the_entry_then(): void
    {
        $e = $this->record(['type' => 'كهرباء', 'amount' => 90, 'status' => 'غير مدفوع']);

        $this->post(route('admin.expenses.paid', $e->id))->assertSessionHasNoErrors();

        $this->assertSame(Expense::PAID, $e->fresh()->status);
        $this->assertSame(90.0, Demo::reportSummary('all')['expenses']);
        $this->assertSame(1, Transaction::where('type', 'مصروف')->count());
    }

    public function test_paying_twice_does_not_double_the_entry(): void
    {
        $e = $this->record(['status' => 'غير مدفوع']);

        $this->post(route('admin.expenses.paid', $e->id));
        $this->post(route('admin.expenses.paid', $e->id));

        $this->assertSame(1, Transaction::where('type', 'مصروف')->count());
    }

    public function test_the_screen_shows_what_is_owed_separately(): void
    {
        // رقمٌ خرج من حسابٍ بلا أن يظهر في آخر يضيع
        $this->record(['amount' => 300]);
        $this->record(['type' => 'كهرباء', 'amount' => 90, 'status' => 'غير مدفوع']);

        $props = $this->get(route('admin.expenses.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame(300.0, $props['totalAmount']);
        $this->assertSame(90.0, $props['unpaidAmount']);
        $this->assertSame(1, $props['unpaidCount']);
    }

    public function test_another_stores_bill_cannot_be_paid_from_here(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Expense::create([
            'business_id' => $other->id, 'type' => 'إيجار', 'amount' => 500,
            'status' => 'غير مدفوع', 'spent_at' => now()->toDateString(),
        ]);

        $this->post(route('admin.expenses.paid', $theirs->id))->assertNotFound();
        $this->assertSame('غير مدفوع', $theirs->fresh()->status);
    }

    /* -------------------- تاريخ الاستحقاق -------------------- */

    public function test_a_due_date_can_finally_be_entered(): void
    {
        $e = $this->record([
            'type' => 'إيجار', 'amount' => 300, 'status' => 'غير مدفوع', 'due_date' => '2026-09-01',
        ]);

        $this->assertSame('2026-09-01', $e->due_date->format('Y-m-d'), 'العمود يُعرض ولا يُملأ');
    }

    public function test_what_is_due_soon_and_what_is_late_are_counted(): void
    {
        $this->record(['type' => 'ماء', 'amount' => 20, 'status' => 'غير مدفوع', 'due_date' => now()->addDays(3)->toDateString()]);
        $this->record(['type' => 'إنترنت', 'amount' => 30, 'status' => 'غير مدفوع', 'due_date' => now()->subDays(5)->toDateString()]);
        $this->record(['type' => 'إيجار', 'amount' => 300, 'status' => 'غير مدفوع', 'due_date' => now()->addMonths(3)->toDateString()]);

        $props = $this->get(route('admin.expenses.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $props['dueSoonCount'], 'ما بعد الأسبوع ليس وشيكًا');
        $this->assertSame(1, $props['overdueCount']);
    }
}
