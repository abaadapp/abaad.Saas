<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\TrashController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * مصروفٌ من شاشة المالية حُذف نصفَ حذفٍ قبل الإصلاح — لا يُعدّ مرّتين ولا يضيع دليلُه.
 *
 * ═══ ما بقي في القواعد ═══
 *
 * كان الحذفُ من شاشة المصروفات يُخفي مصروفًا وُلد من حركةٍ في المالية،
 * ويُخفي حركتَه، ولا يعكس شيئًا: قيدُه على الحركة لا عليه، و`unpostExpense`
 * لا يرى إلّا قيودَ المصروف. فبقي في المحذوفات مصروفٌ قيدُه حيّ.
 *
 * ثمّ يُستعاد: `recordExpense` لا يجد قيدًا على المصروف فيكتب واحدًا —
 * وقيدُ الحركة حيٌّ معه. المالُ نفسُه يخرج من الدرج مرّتين في الدفتر.
 * ويُمحى: لا قيدَ على المصروف فيُعدّ «لم يُقيَّد قطّ»، فتُمحى الحركةُ
 * ويبقى قيدُها حيًّا يشير إلى لا شيء.
 */
class AnExpenseHalfDeletedBeforeTheFixIsNotCountedTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

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

    /** مصروفٌ من شاشة المالية، ثمّ ما كان الحذفُ القديم يفعله به حرفيًّا */
    private function halfDeleted(): Expense
    {
        $this->post(route('admin.finance.store'), [
            'kind' => 'expense', 'amount' => 45, 'side' => Books::CASH, 'expense_type' => 'صيانة',
            'occurred_at' => '2026-10-10',
        ])->assertSessionHasNoErrors();

        $e = Expense::sole();

        $e->transaction()->delete();
        Books::unpostExpense($e);
        $e->delete();

        return $e;
    }

    private function opex(): float
    {
        return Ledger::operatingExpenses($this->business->id);
    }

    private function movementEntry(Expense $e): ?JournalEntry
    {
        return JournalEntry::find(Transaction::withTrashed()->whereKey($e->transaction_id)->value('journal_entry_id'));
    }

    public function test_restoring_it_brings_back_the_row_and_its_movement_and_posts_nothing_new(): void
    {
        $e = $this->halfDeleted();
        $this->assertSame(45.0, $this->opex(), 'الحالةُ الموروثة: قيدُ الحركة حيّ');

        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted($e);
        $this->assertFalse(Transaction::withTrashed()->findOrFail($e->transaction_id)->trashed());
        $this->assertSame(0, JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $e->id)->count(),
            'كُتب قيدٌ ثانٍ فوق قيد الحركة الحيّ');
        $this->assertNull($this->movementEntry($e)->reversed_at);
        $this->assertSame(45.0, $this->opex(), 'خرج المالُ نفسُه مرّتين في الدفتر');
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    public function test_it_is_never_erased_by_the_button_or_the_nightly_purge(): void
    {
        $e = $this->halfDeleted();

        $this->delete(route('admin.expenses.purge', $e->id));

        $this->assertNotNull(Expense::withTrashed()->find($e->id), 'مُحي مصروفٌ قيدُ حركته حيّ');
        $this->assertNotNull(Transaction::withTrashed()->find($e->transaction_id), 'مُحيت حركةٌ وبقي قيدُها يشير إلى لا شيء');

        Carbon::setTestNow(now()->addDays(TrashController::WINDOW_DAYS + 1));
        $this->artisan('trash:purge')->assertSuccessful();

        $this->assertNotNull(Expense::withTrashed()->find($e->id));
        $this->assertNotNull(Transaction::withTrashed()->find($e->transaction_id));
        $this->assertSame(45.0, $this->opex());

        // وما بقي يبقى ظاهرًا بعد المهلة — وإلّا صار دليلًا لا يصل إليه أحد
        $this->assertContains($e->id, collect(TrashController::panelData()['expenses'])->pluck('id')->all());
    }

    public function test_the_trash_shows_it_as_evidence_that_stays(): void
    {
        $e = $this->halfDeleted();

        $row = collect(TrashController::panelData()['expenses'])->firstWhere('id', $e->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['kept']);
        $this->assertNull($row['daysLeft']);
    }
}
