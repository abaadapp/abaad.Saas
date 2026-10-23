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
use App\Support\StockLosses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ما تركه الحذفُ القديم في القواعد يُعرض كما هو — ولا يُصحَّح من تلقاء نفسه.
 *
 * ═══ ثلاثُ بقايا لا تُفترض سليمة ═══
 *
 *   - **محذوفٌ قيدُه حيّ:** حُذف قبل أن يصير الحذفُ يعكس القيد، أو حذفه
 *     الكودُ القديم وقيدُه على حركته أو على المخزون. الشاشةُ تقرأ صفرًا
 *     والدفترُ مبلغًا. كان المحوُ القديم يعكسه بيوم المحو — قيدٌ في شهرٍ لم
 *     يختره أحد. والآن يُعرض «ما زال في الدفتر»، واستعادتُه تُعيد الصفَّ إلى
 *     ما في الدفتر بلا قيدٍ جديد ولا سؤال.
 *   - **مدفوعٌ لم يُقيَّد قطّ:** استعادتُه أوّلُ قيدٍ له. وكان يُكتب بتاريخ
 *     صرفه ولو في شهرٍ مقفل — فصار يمرّ بقاعدة الفترات كأيّ تصحيح.
 *   - **وسؤالٌ عن قيدٍ لن يُكتب** يطلب سببًا لا يُحفظ — فلا يُسأل.
 */
class ALegacyDeletionIsShownNotSilentlyCorrectedTest extends TestCase
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
        Ledger::ensureSystemAccounts($this->business->id);
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

    private function row(int $id): ?array
    {
        return collect(TrashController::panelData()['expenses'])->firstWhere('id', $id);
    }

    private function opex(): float
    {
        return Ledger::operatingExpenses($this->business->id);
    }

    /** مصروفٌ من سبتمبر حُذف بالطريقة التي سبقت عكسَ القيد: الصفُّ وحده */
    private function deletedWithoutReversal(): Expense
    {
        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 300, 'spent_at' => '2026-09-10', 'method' => 'نقدي',
        ])->assertSessionHasNoErrors();
        $e = Expense::latest('id')->firstOrFail();
        $e->transaction()->delete();
        $e->delete();

        return $e;
    }

    public function test_a_deleted_expense_whose_entry_is_still_live_is_shown_and_restored_without_a_new_entry(): void
    {
        $e = $this->deletedWithoutReversal();
        $this->assertSame(300.0, $this->opex(), 'الحالةُ الموروثة: محذوفٌ يعدّه الدفتر');

        $this->assertTrue($this->row($e->id)['stillCounted']);
        $this->assertFalse($this->row($e->id)['correction']['crosses'], 'سُئل عن قيدٍ لن يُكتب');

        // من شهرٍ مضى، ومع ذلك لا سؤال: لا يُكتب شيء
        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted($e);
        $this->assertSame(1, Books::liveEntriesFor($e)->count(), 'كُتب قيدٌ ثانٍ فوق قيدٍ حيّ');
        $this->assertSame(300.0, $this->opex());
    }

    /** وله عكسٌ قديمٌ في شهرٍ مضى وقيدٌ حيّ — لا يُكتب له شيء، فلا يُسأل عن سببٍ لا يُحفظ */
    public function test_an_old_reversal_in_a_past_month_does_not_ask_when_nothing_will_be_posted(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 300, 'spent_at' => '2026-09-10', 'method' => 'نقدي',
        ])->assertSessionHasNoErrors();
        $e = Expense::latest('id')->firstOrFail();
        $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHasNoErrors();
        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasNoErrors();
        $e->refresh()->delete();

        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->assertFalse($this->row($e->id)['correction']['crosses']);
        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasNoErrors();
        $this->assertSame(1, Books::liveEntriesFor($e)->count());
        $this->assertSame(300.0, $this->opex());
    }

    public function test_it_is_not_erased_and_not_reversed_by_the_nightly_purge(): void
    {
        $e = $this->deletedWithoutReversal();
        $entries = JournalEntry::count();

        Carbon::setTestNow(now()->addDays(TrashController::WINDOW_DAYS + 1));
        $this->artisan('trash:purge')->assertSuccessful();

        $this->assertNotNull(Expense::withTrashed()->find($e->id));
        $this->assertSame($entries, JournalEntry::count(), 'كتب الأمرُ المجدول عكسًا في شهرٍ لم يختره أحد');
    }

    public function test_a_normally_deleted_expense_is_not_flagged(): void
    {
        $this->post(route('admin.expenses.store'), ['type' => 'إيجار', 'amount' => 300, 'method' => 'نقدي'])->assertSessionHasNoErrors();
        $e = Expense::latest('id')->firstOrFail();

        $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHasNoErrors();

        $this->assertFalse($this->row($e->id)['stillCounted']);
    }

    public function test_a_trashed_stock_loss_still_counts_and_a_reversed_one_does_not(): void
    {
        DB::transaction(fn () => StockLosses::record($this->business->id, 20, true, 'تالف', now()));
        $live = Expense::latest('id')->firstOrFail();
        $live->delete();

        $reversed = Expense::create([
            'business_id' => $this->business->id, 'type' => 'فاقد جرد', 'amount' => 20,
            'method' => StockLosses::EXPENSE_METHOD, 'spent_at' => '2026-10-02',
        ]);
        Ledger::post($this->business->id, 'فاقد قديم', [
            ['account' => 'other_expenses', 'debit' => 20],
            ['account' => 'inventory', 'credit' => 20],
        ], Carbon::parse('2026-10-02'), StockLosses::SOURCE, null, null, $reversed);
        Books::unpostExpense($reversed);
        $reversed->delete();

        $this->assertTrue($this->row($live->id)['stillCounted']);
        $this->assertFalse($this->row($reversed->id)['stillCounted']);
    }

    /* ═══════════════ مدفوعٌ لم يُقيَّد قطّ ═══════════════ */

    private function neverPosted(string $on): Expense
    {
        $e = Expense::create([
            'business_id' => $this->business->id, 'type' => 'إيجار', 'amount' => 120,
            'method' => 'نقدي', 'spent_at' => $on, 'status' => Expense::PAID,
        ]);
        $e->delete();

        return $e;
    }

    public function test_one_never_posted_from_this_month_is_posted_on_its_day_without_a_question(): void
    {
        $e = $this->neverPosted('2026-10-04');

        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasNoErrors();

        $this->assertSame('2026-10-04', Books::liveEntriesFor($e)->sole()->entry_date->toDateString());
    }

    public function test_one_never_posted_from_a_past_month_asks_where_and_why(): void
    {
        $e = $this->neverPosted('2026-09-10');

        $row = $this->row($e->id);
        $this->assertTrue($row['correction']['crosses']);
        $this->assertSame('unposted', $row['correctionSubject']);

        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasErrors('correction');
        $this->assertSoftDeleted($e);

        $this->post(route('admin.expenses.restore', $e->id), ['correction' => BooksPeriod::CURRENT, 'correction_reason' => 'لم يُقيَّد'])
            ->assertSessionHasNoErrors();

        $entry = Books::liveEntriesFor($e)->sole();
        $this->assertSame('2026-10-20', $entry->entry_date->toDateString());
        $this->assertSame($this->owner->id, (int) $entry->created_by);
        $this->assertStringEndsWith('لم يُقيَّد', $entry->description);
    }

    public function test_one_never_posted_in_a_closed_period_is_never_written_there(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => BooksPeriod::SETTING, 'value' => '2026-09-30']);
        $e = $this->neverPosted('2026-09-10');

        $this->post(route('admin.expenses.restore', $e->id), ['correction' => BooksPeriod::ORIGINAL, 'correction_reason' => 'x'])
            ->assertSessionHasErrors('correction');
        $this->assertSame(0, JournalEntry::where('entry_date', '<=', '2026-09-30')->count(), 'كُتب في فترةٍ مقفلة');
    }
}
