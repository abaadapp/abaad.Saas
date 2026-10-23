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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * حذفُ المصروف واستعادتُه ومحوُه — كلٌّ خطوةٌ واحدة، والدليلُ المحاسبيّ لا يضيع.
 *
 * كان الحذفُ أربعَ خطواتٍ بلا معاملة: حذفُ حركة المالية، ثمّ عكسُ القيد،
 * ثمّ حذفُ المصروف. فإن سقطت خطوةٌ بقيت التي قبلها: مالٌ يختفي من شاشة
 * المالية ويبقى في الدفتر، أو مصروفٌ ظاهرٌ لا يعدّه الدفتر. والاستعادةُ
 * كذلك، وضغطتان عليها كانتا تُرحّلان قيدين. والمحوُ النهائيّ — يدويًّا أو
 * بالأمر المجدول بعد ٩٠ يومًا — كان يمحو المصروفَ وحركتَه ومرفقَه ويترك
 * في الدفتر قيدًا وعكسَه يشيران إلى لا شيء.
 *
 * وفي الشهر الجاري كلُّه: الفترةُ لا تُسأل هنا — انظر
 * `ACorrectionIsRecordedInAPeriodItMayTouchTest`.
 */
class AnExpenseIsDeletedAndRestoredInOneStepTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function spend(array $over = []): Expense
    {
        $this->post(route('admin.expenses.store'), array_merge([
            'type' => 'إيجار', 'amount' => 300, 'spent_at' => '2026-10-05', 'method' => 'نقدي',
        ], $over))->assertSessionHasNoErrors();

        return Expense::latest('id')->firstOrFail();
    }

    private function entries(Expense $e)
    {
        return JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $e->id)->orderBy('id')->get();
    }

    private function live(Expense $e): int
    {
        return Books::liveEntriesFor($e)->count();
    }

    private function opex(): float
    {
        return Ledger::operatingExpenses($this->business->id);
    }

    /** يُسقط الاستعلامَ الذي يطابق النصّ بعد تنفيذه — داخل المعاملة، فيُلغيها */
    private function failAfter(string $needle): void
    {
        DB::listen(function (QueryExecuted $q) use ($needle) {
            if (str_contains($q->sql, $needle)) {
                throw new RuntimeException('عطبٌ مقصود بعد: '.$needle);
            }
        });
    }

    /* ═══════════════ ١ · الحذف ═══════════════ */

    public function test_deleting_a_paid_expense_reverses_its_entry_hides_its_movement_and_keeps_both_entries(): void
    {
        $e = $this->spend();

        $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($e);
        $this->assertSoftDeleted(Transaction::withTrashed()->findOrFail($e->transaction_id));
        $this->assertCount(2, $this->entries($e), 'مُحي قيدٌ بدل أن يُعكس');
        $this->assertSame(0, $this->live($e));
        $this->assertSame(0.0, $this->opex());
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    public function test_deleting_an_unpaid_bill_writes_nothing_in_the_ledger(): void
    {
        $e = $this->spend(['status' => 'غير مدفوع', 'due_date' => '2026-11-01']);

        $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($e);
        $this->assertSame(0, JournalEntry::where('business_id', $this->business->id)->count());
    }

    public function test_a_second_delete_finds_nothing_and_reverses_nothing_twice(): void
    {
        $e = $this->spend();

        $this->delete(route('admin.expenses.destroy', $e->id));
        $this->delete(route('admin.expenses.destroy', $e->id))->assertNotFound();

        $this->assertCount(2, $this->entries($e), 'عُكس القيدُ مرّتين');
        $this->assertSame(0.0, $this->opex());
    }

    /**
     * عطبٌ بعد كلّ خطوة — ولا يبقى منها شيء.
     *
     * بعد حذف الحركة، وبعد كتابة العكس، وبعد ختم الأصل معكوسًا، وبعد حذف
     * المصروف: المعاملةُ تُلغى كلُّها في كلّ حالة.
     */
    public function test_a_failure_after_any_step_of_the_delete_leaves_everything_as_it_was(): void
    {
        foreach ([
            'حذف الحركة' => 'update "transactions" set "deleted_at"',
            'كتابة العكس' => 'insert into "journal_lines"',
            'ختم الأصل' => 'set "reversed_at"',
            'حذف المصروف' => 'update "expenses" set "deleted_at"',
        ] as $step => $needle) {
            $e = $this->spend();
            $this->failAfter($needle);

            $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHas('toast');

            Event::forget(QueryExecuted::class);

            $fresh = Expense::withTrashed()->findOrFail($e->id);
            $this->assertFalse($fresh->trashed(), "{$step}: حُذف المصروف رغم العطب");
            $this->assertFalse(Transaction::withTrashed()->findOrFail($e->transaction_id)->trashed(), "{$step}: اختفت الحركة");
            $this->assertCount(1, $this->entries($e), "{$step}: بقي عكسٌ من معاملةٍ ساقطة");
            $this->assertSame(1, $this->live($e), "{$step}: الأصلُ مختومٌ معكوسًا بلا عكس");

            $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHasNoErrors();
        }

        $this->assertSame(0.0, $this->opex());
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /* ═══════════════ ٢ · الاستعادة ═══════════════ */

    public function test_restoring_brings_back_the_row_its_movement_and_one_live_entry(): void
    {
        $e = $this->spend();
        $reference = $e->transaction->reference;

        $this->delete(route('admin.expenses.destroy', $e->id));
        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted($e);
        $this->assertSame($reference, $e->fresh()->transaction->reference);
        $this->assertSame(1, $this->live($e));
        $this->assertSame(300.0, $this->opex());
    }

    public function test_a_second_restore_finds_nothing_and_posts_nothing_twice(): void
    {
        $e = $this->spend();

        $this->delete(route('admin.expenses.destroy', $e->id));
        $this->post(route('admin.expenses.restore', $e->id));
        $this->post(route('admin.expenses.restore', $e->id))->assertNotFound();

        $this->assertSame(1, $this->live($e), 'رُحّل القيدُ مرّتين');
        $this->assertSame(300.0, $this->opex());
    }

    /** حذفٌ ثمّ استعادةٌ ثلاث مرّات — والأرصدةُ كما بدأت، والسجلُّ كلُّه محفوظ */
    public function test_deleting_and_restoring_again_and_again_changes_no_balance(): void
    {
        $e = $this->spend();
        $before = Ledger::trialBalance($this->business->id);

        foreach (range(1, 3) as $round) {
            $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHasNoErrors();
            $this->assertSame(0.0, $this->opex(), "الجولة {$round}: بقي المصروفُ بعد حذفه");

            $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasNoErrors();
            $this->assertSame(300.0, $this->opex(), "الجولة {$round}: لم يعد المصروف مرّةً واحدة");
        }

        $after = Ledger::trialBalance($this->business->id);
        $balances = fn ($tb) => collect($tb['accounts'])->mapWithKeys(fn ($r) => [(string) $r['code'] => $r['balance']])->all();

        $this->assertTrue($after['balanced']);
        $this->assertSame($balances($before), $balances($after), 'تغيّر رصيدٌ بعد حذفٍ واستعادةٍ متكرّرين');
        $this->assertCount(7, $this->entries($e), 'ضاع قيدٌ من السجلّ: أصلٌ وثلاثةُ عكوسٍ وثلاثُ إعادات');
    }

    public function test_a_failure_while_restoring_leaves_the_expense_deleted_and_the_books_untouched(): void
    {
        $e = $this->spend();
        $this->delete(route('admin.expenses.destroy', $e->id));
        $count = JournalEntry::count();

        $this->failAfter('insert into "journal_lines"');
        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHas('toast');
        Event::forget(QueryExecuted::class);

        $this->assertSoftDeleted($e);
        $this->assertTrue(Transaction::withTrashed()->findOrFail($e->transaction_id)->trashed(), 'عادت الحركةُ بلا مصروفها');
        $this->assertSame($count, JournalEntry::count());
        $this->assertSame(0.0, $this->opex());
    }

    /* ═══════════════ ٣ · المحو النهائيّ: الدليلُ يبقى ═══════════════ */

    public function test_a_paid_expense_is_never_erased_and_keeps_its_movement_and_invoice(): void
    {
        Storage::fake('local');
        $e = $this->spend();
        $e->update(['attachment' => 'expenses/'.$this->business->id.'/rent.pdf', 'attachment_name' => 'rent.pdf']);
        Storage::disk('local')->put('expenses/'.$this->business->id.'/rent.pdf', 'pdf');

        $this->delete(route('admin.expenses.destroy', $e->id));
        $this->delete(route('admin.expenses.purge', $e->id))->assertSessionHas('toast');

        $this->assertNotNull(Expense::withTrashed()->find($e->id), 'مُحي مصروفٌ له قيد');
        $this->assertNotNull(Transaction::withTrashed()->find($e->transaction_id), 'مُحيت حركتُه');
        Storage::disk('local')->assertExists('expenses/'.$this->business->id.'/rent.pdf');
        $this->assertCount(2, $this->entries($e));
    }

    public function test_an_unpaid_bill_that_never_reached_the_ledger_is_still_erased(): void
    {
        $e = $this->spend(['status' => 'غير مدفوع', 'due_date' => '2026-11-01']);

        $this->delete(route('admin.expenses.destroy', $e->id));
        $this->delete(route('admin.expenses.purge', $e->id))->assertSessionHasNoErrors();

        $this->assertNull(Expense::withTrashed()->find($e->id));
    }

    public function test_the_nightly_purge_keeps_posted_expenses_and_erases_the_rest(): void
    {
        $paid = $this->spend();
        $bill = $this->spend(['status' => 'غير مدفوع', 'due_date' => '2026-11-01']);

        foreach ([$paid, $bill] as $e) {
            $this->delete(route('admin.expenses.destroy', $e->id));
            Expense::withTrashed()->whereKey($e->id)->update(['deleted_at' => now()->subDays(TrashController::WINDOW_DAYS + 5)]);
        }

        $this->artisan('trash:purge')->assertSuccessful();

        $this->assertNotNull(Expense::withTrashed()->find($paid->id), 'محا الأمرُ المجدولُ مصروفًا له قيد');
        $this->assertNull(Expense::withTrashed()->find($bill->id));

        // ويبقى ظاهرًا في «المحذوفات» بعد انقضاء المهلة — لا صفًّا لا يُرى ولا يُمسّ
        $row = collect(TrashController::panelData()['expenses'])->firstWhere('id', $paid->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['kept']);
        $this->assertNull($row['daysLeft']);
    }

    /* ═══════════════ ٤ · مصروفٌ وُلد من حركةٍ في المالية ═══════════════ */

    public function test_an_expense_born_of_a_finance_movement_is_not_half_deleted_from_here(): void
    {
        $this->post(route('admin.finance.store'), [
            'kind' => 'expense', 'amount' => 45, 'side' => Books::CASH, 'expense_type' => 'صيانة',
            'occurred_at' => '2026-10-10',
        ])->assertSessionHasNoErrors();
        $e = Expense::sole();
        $opex = $this->opex();

        $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHasErrors('expense');

        $this->assertNotSoftDeleted($e);
        $this->assertFalse(Transaction::withTrashed()->findOrFail($e->transaction_id)->trashed());
        $this->assertSame($opex, $this->opex());
    }

    /* ═══════════════ ٥ · بين تاجرين ═══════════════ */

    public function test_another_shops_expense_cannot_be_deleted_restored_or_erased_from_here(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Expense::create([
            'business_id' => $other->id, 'type' => 'إيجار', 'amount' => 900, 'spent_at' => '2026-10-05', 'status' => 'مدفوع',
        ]);

        $this->delete(route('admin.expenses.destroy', $theirs->id), ['correction' => 'current', 'correction_reason' => 'x'])->assertNotFound();
        $theirs->delete();
        $this->post(route('admin.expenses.restore', $theirs->id), ['correction' => 'current', 'correction_reason' => 'x'])->assertNotFound();
        $this->delete(route('admin.expenses.purge', $theirs->id))->assertNotFound();

        $this->assertNotNull(Expense::withTrashed()->find($theirs->id));
    }
}
