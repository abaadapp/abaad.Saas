<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\Books;
use App\Support\BooksPeriod;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تصحيحُ المصروف يقول مَن كتبه ولماذا — كاملًا، ولا يُسقط ما وقع.
 *
 * ═══ ما وُجد في المراجعة ═══
 *
 *   - القيدُ المعاد بالاستعادة أو بتغيير النطاق بلا كاتبٍ ولا سبب: «مصروف:
 *     إيجار» وحسب — ولو كُتب بتاريخ شهرٍ مضى.
 *   - السببُ حتى 200 حرف بعد اسم الفعل والتاريخين، في عمودَين بـ255: يُقطع
 *     ذيلُه في القيد، ويُسقط سطرَ النشاط على PostgreSQL **بعد** أن تمّ
 *     الحذف — فيرى التاجرُ خطأً عن حذفٍ وقع، ولا يبقى في السجلّ مَن حذف.
 *   - و«تراجع» في الإشعار بعد عكسٍ وقع في شهرٍ مضى يُرسل الاستعادةَ بلا
 *     اختيارٍ ولا سبب، فيُردّ دائمًا.
 *   - ومحوٌ يسأل «أله قيد؟» قبل معاملته، فاستعادةٌ بينهما تُمحى معه.
 */
class ACorrectionSaysWhoMadeItAndWhyTest extends TestCase
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

        Setting::create(['business_id' => $this->business->id, 'key' => BooksPeriod::SETTING, 'value' => '2026-08-31']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function spend(string $on, array $over = []): Expense
    {
        $this->post(route('admin.expenses.store'), array_merge([
            'type' => 'إيجار', 'amount' => 400, 'spent_at' => $on, 'method' => 'نقدي',
        ], $over))->assertSessionHasNoErrors();

        return Expense::latest('id')->firstOrFail();
    }

    private function live(Expense $e): JournalEntry
    {
        return Books::liveEntriesFor($e)->sole();
    }

    public function test_the_longest_reason_survives_whole_in_the_entry_and_the_activity_log(): void
    {
        $e = $this->spend('2026-09-30');
        $reason = str_repeat('س', BooksPeriod::REASON_MAX - 3).'آخر';

        $this->delete(route('admin.expenses.destroy', $e->id), ['correction' => BooksPeriod::ORIGINAL, 'correction_reason' => $reason.'ز'])
            ->assertSessionHasErrors('correction_reason');
        $this->delete(route('admin.expenses.destroy', $e->id), ['correction' => BooksPeriod::ORIGINAL, 'correction_reason' => $reason])
            ->assertSessionHasNoErrors();

        $reversal = JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $e->id)->whereNotNull('reverses_id')->sole();
        $this->assertStringEndsWith($reason, $reversal->description, 'قُطع السببُ في القيد');

        $log = ActivityLog::where('subject_type', 'expense')->where('subject_id', $e->id)->where('action', 'deleted')->sole();
        $this->assertStringEndsWith($reason, $log->description);
        $this->assertLessThanOrEqual(255, mb_strlen($log->description), 'سطرُ النشاط أطولُ من عموده — يسقط على PostgreSQL');
    }

    /** ونوعُ مصروفٍ طويل لا يمحو السببَ من عكسه — يُقصَّر الصدرُ لا السبب */
    public function test_a_long_expense_type_does_not_push_the_reason_out_of_its_reversal(): void
    {
        $e = $this->spend('2026-09-30', ['type' => str_repeat('ن', 240)]);
        $this->assertLessThanOrEqual(255, mb_strlen(Books::liveEntriesFor($e)->sole()->description));
        // وسطرُ «سجّل مصروف <النوع>» كان يُسقط الحفظَ نفسَه على PostgreSQL (`22001`)
        $this->assertTrue(ActivityLog::where('action', 'created')->exists(), 'لم يُقيَّد تسجيلُ المصروف');

        $this->delete(route('admin.expenses.destroy', $e->id), ['correction' => BooksPeriod::CURRENT, 'correction_reason' => 'فاتورةٌ مكرّرة'])
            ->assertSessionHasNoErrors();

        $reversal = JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $e->id)->whereNotNull('reverses_id')->sole();
        $this->assertStringEndsWith('فاتورةٌ مكرّرة', $reversal->description, 'محا الوصفُ الطويل سببَ العكس');
        $this->assertLessThanOrEqual(255, mb_strlen($reversal->description));
        $this->assertStringEndsWith('فاتورةٌ مكرّرة', ActivityLog::where('subject_id', $e->id)->where('action', 'deleted')->sole()->description);
    }

    public function test_a_reposted_entry_says_who_restored_it_and_why(): void
    {
        $e = $this->spend('2026-10-05');
        $this->delete(route('admin.expenses.destroy', $e->id))->assertSessionHasNoErrors();

        $this->post(route('admin.expenses.restore', $e->id))->assertSessionHasNoErrors();

        $entry = $this->live($e);
        $this->assertSame($this->owner->id, (int) $entry->created_by, 'قيدٌ معادٌ بلا كاتب');
        $this->assertStringContainsString(__('استعادة المصروف'), $entry->description);
    }

    public function test_a_reposted_entry_across_a_month_carries_the_chosen_reason(): void
    {
        $e = $this->spend('2026-09-30');
        $this->delete(route('admin.expenses.destroy', $e->id), ['correction' => BooksPeriod::ORIGINAL, 'correction_reason' => 'مكرّر'])
            ->assertSessionHasNoErrors();

        $this->post(route('admin.expenses.restore', $e->id), ['correction' => BooksPeriod::CURRENT, 'correction_reason' => 'لم يكن مكرّرًا'])
            ->assertSessionHasNoErrors();

        $entry = $this->live($e);
        $this->assertSame('2026-10-20', $entry->entry_date->toDateString());
        $this->assertSame($this->owner->id, (int) $entry->created_by);
        $this->assertStringContainsString('لم يكن مكرّرًا', $entry->description);
    }

    public function test_undo_is_offered_only_while_the_reversal_stays_in_this_month(): void
    {
        $october = $this->spend('2026-10-05');
        $this->delete(route('admin.expenses.destroy', $october->id))->assertSessionHas('toast.undo');

        $current = $this->spend('2026-09-20');
        $this->delete(route('admin.expenses.destroy', $current->id), ['correction' => BooksPeriod::CURRENT, 'correction_reason' => 'مكرّر'])
            ->assertSessionHas('toast.undo');

        // عكسٌ وقع في سبتمبر: «تراجع» بلا اختيارٍ يُردّ دائمًا — فالاستعادةُ من المحذوفات
        $original = $this->spend('2026-09-30');
        $response = $this->delete(route('admin.expenses.destroy', $original->id), ['correction' => BooksPeriod::ORIGINAL, 'correction_reason' => 'مكرّر']);
        $response->assertSessionHasNoErrors();
        $this->assertNull(session('toast')['undo'] ?? null);
    }

    public function test_an_expense_restored_while_it_is_being_erased_is_not_erased(): void
    {
        $bill = $this->spend('2026-10-05', ['type' => 'كهرباء', 'amount' => 90, 'status' => 'غير مدفوع', 'due_date' => '2026-11-01']);
        $this->delete(route('admin.expenses.destroy', $bill->id))->assertSessionHasNoErrors();

        // تُستعاد بعد أن قرأها المحوُ محذوفةً، وقبل أن يمحوها
        $restored = false;
        DB::listen(function (QueryExecuted $q) use (&$restored, $bill) {
            if ($restored || ! str_starts_with($q->sql, 'select * from "expenses"')) {
                return;
            }
            $restored = true;
            DB::table('expenses')->where('id', $bill->id)->update(['deleted_at' => null]);
        });

        $this->delete(route('admin.expenses.purge', $bill->id))->assertSessionHas('toast.type', 'danger');

        $this->assertTrue($restored, 'لم يقع الترتيبُ المقصود');
        $this->assertNotSoftDeleted($bill);
    }
}
