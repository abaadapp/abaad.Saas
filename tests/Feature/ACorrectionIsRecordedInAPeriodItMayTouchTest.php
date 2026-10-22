<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\TrashController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\Books;
use App\Support\BooksPeriod;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تصحيحُ مصروفٍ من شهرٍ مضى — لا يُشوّه شهرين، ولا يُكتب في فترةٍ مقفلة.
 *
 * قيس على الإنتاج: مصروفان مكرَّران من سبتمبر حُذفا يومَ ١ أكتوبر، فبقيا
 * في سبتمبر وطُرحا من أكتوبر — وقرأ صاحبُ المتجر «إجمالي المصروفات −14.250».
 * والاستعادةُ كانت أسوأ: تُعيد القيدَ بتاريخ المصروف فيتضاعف سبتمبر.
 *
 * والقاعدةُ (`BooksPeriod`):
 *   - في شهر القيد نفسِه: يومُ التصحيح كما كان، بلا سؤال.
 *   - عبر حدّ شهر: اختيارٌ صريحٌ وسببٌ مكتوب — «في الفترة الحاليّة» دائمًا،
 *     و«بتاريخ القيد الأصليّ» لصاحب النشاط وحده في فترةٍ مفتوحةٍ معلومة.
 *   - ولا يُفترض أنّ شهرًا مضى مفتوحٌ ما لم يُحدَّد تاريخُ إقفال الدفاتر.
 *   - والمقفلُ — بإقفال الدفاتر أو بإقرارٍ قُدِّم — لا يُكتب فيه شيءٌ من هنا.
 */
class ACorrectionIsRecordedInAPeriodItMayTouchTest extends TestCase
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

    private function spend(string $on, float $amount = 400): Expense
    {
        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => $amount, 'spent_at' => $on, 'method' => 'نقدي',
        ])->assertSessionHasNoErrors();

        return Expense::latest('id')->firstOrFail();
    }

    private function closeBooksThrough(?string $date, string $key = BooksPeriod::SETTING): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $date]);
    }

    /** المصروفاتُ التشغيليّة لشهرٍ — كما تقرؤها اللوحةُ والتقارير */
    private function month(string $ym): float
    {
        $start = Carbon::parse($ym.'-01');

        return Ledger::operatingExpenses($this->business->id, $start, $start->copy()->addMonth());
    }

    private function lastReversal(Expense $e): JournalEntry
    {
        return JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $e->id)
            ->whereNotNull('reverses_id')->latest('id')->firstOrFail();
    }

    /* ═══════════════ ١ · في الشهر نفسه: لا سؤال ═══════════════ */

    public function test_within_the_same_month_the_reversal_is_dated_the_day_it_happens(): void
    {
        $e = $this->spend('2026-10-03');

        $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHasNoErrors();

        $this->assertSame('2026-10-20', $this->lastReversal($e)->entry_date->toDateString());
        $this->assertSame(0.0, $this->month('2026-10'));
    }

    /* ═══════════════ ٢ · عبر حدّ شهر: اختيارٌ وسبب ═══════════════ */

    public function test_crossing_a_month_is_refused_without_a_choice_and_a_reason(): void
    {
        $e = $this->spend('2026-09-30');

        $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHasErrors('correction');
        $this->delete(route('admin.expenses.destroy', $e->id), ['correction' => 'current'])->assertSessionHasErrors('correction_reason');

        $this->assertNotSoftDeleted($e);
        $this->assertSame(400.0, $this->month('2026-09'));
        $this->assertSame(0.0, $this->month('2026-10'));
    }

    /**
     * «في الفترة الحاليّة»: الشهرُ السابق كما صدر، والتصحيحُ موسومٌ بتاريخيه وسببه ومن كتبه.
     */
    public function test_in_the_current_period_the_old_month_stays_as_reported_and_the_correction_says_so(): void
    {
        $e = $this->spend('2026-09-30');

        $this->delete(route('admin.expenses.destroy', $e->id), [
            'correction' => 'current', 'correction_reason' => 'إيجارٌ مكرّر',
        ])->assertSessionHasNoErrors();

        $reversal = $this->lastReversal($e);
        $this->assertSame('2026-10-20', $reversal->entry_date->toDateString());
        $this->assertSame($this->owner->id, (int) $reversal->created_by);
        $this->assertStringContainsString('2026-09-30', $reversal->description);
        $this->assertStringContainsString('إيجارٌ مكرّر', $reversal->description);
        $this->assertSame(400.0, $this->month('2026-09'));
        $this->assertSame(-400.0, $this->month('2026-10'));
    }

    /** ولا يُفترض أنّ سبتمبر مفتوحٌ ما لم يقل التاجرُ أين أقفل */
    public function test_the_original_date_is_refused_while_no_closing_date_is_set(): void
    {
        $e = $this->spend('2026-09-30');

        $this->delete(route('admin.expenses.destroy', $e->id), [
            'correction' => 'original', 'correction_reason' => 'إيجارٌ مكرّر',
        ])->assertSessionHasErrors('correction');

        $this->assertNotSoftDeleted($e);
    }

    /** وحين يُعلَم أنّه مفتوح: يُصحَّح سبتمبرُ نفسُه، وأكتوبرُ لا يمسّه شيء */
    public function test_an_open_month_is_corrected_on_its_own_date_by_the_owner(): void
    {
        $this->closeBooksThrough('2026-08-31');
        $e = $this->spend('2026-09-30');

        $this->delete(route('admin.expenses.destroy', $e->id), [
            'correction' => 'original', 'correction_reason' => 'إيجارٌ مكرّر',
        ])->assertSessionHasNoErrors();

        $reversal = $this->lastReversal($e);
        $this->assertSame('2026-09-30', $reversal->entry_date->toDateString());
        $this->assertSame('2026-10-20', $reversal->created_at->toDateString(), 'ضاع التاريخُ الفعليّ للتصحيح');
        $this->assertSame(0.0, $this->month('2026-09'));
        $this->assertSame(0.0, $this->month('2026-10'));
    }

    public function test_a_closed_month_is_never_written_into(): void
    {
        $this->closeBooksThrough('2026-09-30');
        $e = $this->spend('2026-09-30');

        $this->delete(route('admin.expenses.destroy', $e->id), [
            'correction' => 'original', 'correction_reason' => 'x',
        ])->assertSessionHasErrors('correction');
        $this->assertNotSoftDeleted($e);

        // والفترةُ الحاليّة تبقى طريقًا: يُصحَّح بلا لمس المقفل
        $this->delete(route('admin.expenses.destroy', $e->id), [
            'correction' => 'current', 'correction_reason' => 'x',
        ])->assertSessionHasNoErrors();
        $this->assertSame(400.0, $this->month('2026-09'));
    }

    /** والإقرارُ الضريبيُّ المقدَّم إقفالٌ كذلك — ولو بلا تاريخ إقفالٍ للدفاتر */
    public function test_a_filed_tax_return_closes_its_period_too(): void
    {
        $this->closeBooksThrough('2026-08-31');
        $this->closeBooksThrough('2026-09-30', 'vat_filed_through');
        $e = $this->spend('2026-09-30');

        $this->delete(route('admin.expenses.destroy', $e->id), [
            'correction' => 'original', 'correction_reason' => 'x',
        ])->assertSessionHasErrors('correction');

        $this->assertNotSoftDeleted($e);
    }

    /** والتأريخُ في فترةٍ سابقة لصاحب النشاط وحده — المحاسبُ يصحّح في الفترة الحاليّة */
    public function test_only_the_owner_dates_a_correction_in_a_past_period(): void
    {
        $this->closeBooksThrough('2026-08-31');
        $e = $this->spend('2026-09-30');
        $accountant = User::create([
            'business_id' => $this->business->id, 'name' => 'المحاسب', 'email' => 'a@abaad.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط',
        ]);

        $this->actingAs($accountant)->delete(route('admin.expenses.destroy', $e->id), [
            'correction' => 'original', 'correction_reason' => 'x',
        ])->assertSessionHasErrors('correction');
        $this->assertNotSoftDeleted($e);

        $this->actingAs($accountant)->delete(route('admin.expenses.destroy', $e->id), [
            'correction' => 'current', 'correction_reason' => 'x',
        ])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($e);
    }

    /* ═══════════════ ٣ · الاستعادة تقابل حذفَها ═══════════════ */

    /**
     * كانت الاستعادةُ تُعيد القيدَ بتاريخ المصروف: سبتمبر ٨٠٠ وأكتوبر −٤٠٠.
     * فصار القيدُ يعود بيوم حذفه، فيتقابلان في أكتوبر ويبقى سبتمبر ٤٠٠.
     */
    public function test_restoring_in_the_month_of_the_delete_cancels_it_there_and_leaves_the_old_month_whole(): void
    {
        $e = $this->spend('2026-09-30');
        $this->delete(route('admin.expenses.destroy', $e->id), ['correction' => 'current', 'correction_reason' => 'خطأ']);

        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasNoErrors();

        $this->assertSame(400.0, $this->month('2026-09'));
        $this->assertSame(0.0, $this->month('2026-10'));
        $this->assertSame('2026-10-20', Books::expenseEntryDate($e->fresh())->toDateString());
    }

    public function test_restoring_after_the_month_of_the_delete_asks_like_a_delete(): void
    {
        $this->closeBooksThrough('2026-08-31');
        $e = $this->spend('2026-09-30');
        $this->delete(route('admin.expenses.destroy', $e->id), ['correction' => 'current', 'correction_reason' => 'خطأ']);

        Carbon::setTestNow('2026-11-03 09:00:00');

        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasErrors('correction');
        $this->assertSoftDeleted($e);

        // بتاريخ الحذف: يتقابلان في أكتوبر، ونوفمبر لا يمسّه شيء
        $this->post(route('admin.expenses.restore', $e->id), [
            'correction' => 'original', 'correction_reason' => 'حُذف سهوًا',
        ])->assertSessionHasNoErrors();

        $this->assertSame(400.0, $this->month('2026-09'));
        $this->assertSame(0.0, $this->month('2026-10'));
        $this->assertSame(0.0, $this->month('2026-11'));
    }

    /* ═══════════════ ٤ · تغييرُ النطاق ═══════════════ */

    public function test_rescoping_an_old_expense_moves_it_on_one_date_and_never_counts_it_twice(): void
    {
        $e = $this->spend('2026-09-30');
        $branch = Branch::where('business_id', $this->business->id)->firstOrFail();

        $this->put(route('admin.expenses.scope', $e->id), ['scope' => 'branch', 'branch_id' => $branch->id])
            ->assertSessionHasErrors('correction');

        $this->put(route('admin.expenses.scope', $e->id), [
            'scope' => 'branch', 'branch_id' => $branch->id, 'correction' => 'current', 'correction_reason' => 'إيجار الفرع',
        ])->assertSessionHasNoErrors();

        // العكسُ والإعادةُ بيومٍ واحد: سبتمبر كما صدر، وأكتوبر صفر
        $this->assertSame(400.0, $this->month('2026-09'));
        $this->assertSame(0.0, $this->month('2026-10'));
        $this->assertSame(1, Books::liveEntriesFor($e)->count());
        $this->assertSame($branch->id, (int) Books::liveEntriesFor($e)->first()->branch_id);
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    public function test_rescoping_within_the_month_keeps_the_expense_on_its_own_day(): void
    {
        $e = $this->spend('2026-10-03');
        $branch = Branch::where('business_id', $this->business->id)->firstOrFail();

        $this->put(route('admin.expenses.scope', $e->id), ['scope' => 'branch', 'branch_id' => $branch->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-03', Books::expenseEntryDate($e->fresh())->toDateString());
        $this->assertSame(400.0, $this->month('2026-10'));
    }

    /* ═══════════════ ٥ · الشاشة تسأل قبل الضغط ═══════════════ */

    public function test_the_screens_know_which_rows_cross_a_month_and_what_may_be_chosen(): void
    {
        $this->closeBooksThrough('2026-08-31');
        $old = $this->spend('2026-09-30');
        $new = $this->spend('2026-10-03');

        $rows = collect($this->get(route('admin.expenses.index', ['period' => 'all']))
            ->viewData('page')['props']['expenses'])->keyBy('id');

        $this->assertTrue($rows[$old->id]['correction']['crosses']);
        $this->assertTrue($rows[$old->id]['correction']['original_allowed']);
        $this->assertSame('2026-09-30', $rows[$old->id]['correction']['entry_date']);
        $this->assertFalse($rows[$new->id]['correction']['crosses']);

        $this->delete(route('admin.expenses.destroy', $old->id), ['correction' => 'current', 'correction_reason' => 'x']);
        Carbon::setTestNow('2026-11-03 09:00:00');

        $trashed = collect(TrashController::panelData()['expenses'])->firstWhere('id', $old->id);
        $this->assertTrue($trashed['correction']['crosses'], 'الاستعادةُ تعبر حدَّ شهرٍ ولا تسأل');
        $this->assertSame('2026-10-20', $trashed['correction']['entry_date']);
    }

    /* ═══════════════ ٦ · تاريخ الإقفال نفسه ═══════════════ */

    public function test_the_closing_date_is_saved_and_cannot_close_a_day_that_has_not_ended(): void
    {
        $this->post(route('admin.settings.update'), ['books_closed_through' => '2026-10-20'])
            ->assertSessionHasErrors('books_closed_through');

        $this->post(route('admin.settings.update'), ['books_closed_through' => '2026-09-30'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-09-30', BooksPeriod::booksClosedThrough($this->business->id)?->toDateString());
    }

    public function test_one_shops_closing_date_does_not_close_another_shops_books(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        Setting::create(['business_id' => $other->id, 'key' => BooksPeriod::SETTING, 'value' => '2026-09-30']);

        $this->assertNull(BooksPeriod::booksClosedThrough($this->business->id));
        $this->closeBooksThrough('2026-08-31');
        $e = $this->spend('2026-09-30');

        $this->delete(route('admin.expenses.destroy', $e->id), [
            'correction' => 'original', 'correction_reason' => 'x',
        ])->assertSessionHasNoErrors();
    }
}
