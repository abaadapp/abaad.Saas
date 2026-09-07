<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مصروفٌ يقول «مدفوع» ولا قيدَ له لا يُكتب أصلًا.
 *
 * كان الترحيل خارج معاملةٍ وفشلُه يُبتلع ويُقيَّد في سجلّ النشاط. فيبقى في
 * القاعدة مصروفٌ مدفوع، وصفُّ حركةٍ يقول إنّ مالًا خرج، **ولا قيدَ في
 * الدفتر**: تقرأ شاشةُ المصروفات ثلاثمئة، ويقرأ ميزانُ المراجعة صفرًا،
 * ويُقرأ ربحُ الشهر أعلى ممّا هو.
 *
 * ولا يظهر ذلك إلّا لمن يطابق الدفترَ بالشاشة — وهو آخرُ من يُطابق.
 */
class APaidExpenseCannotOutrunItsEntryTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* --------------------------- الطريقُ المعتاد --------------------------- */

    /** مصروفٌ مدفوع يُكتب ومعه صفُّ حركةٍ وقيدٌ متوازن */
    public function test_a_paid_expense_writes_its_row_its_transaction_and_its_entry(): void
    {
        $this->actingAs($this->owner)->post('/admin/expenses', [
            'type' => 'كهرباء وماء', 'amount' => 300, 'method' => 'نقدي', 'status' => 'مدفوع',
        ])->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();

        $this->assertSame(1, Transaction::where('business_id', $this->business->id)->count());
        $this->assertNotNull($expense->transaction_id);
        $this->assertSame(300.0, Ledger::balance($this->business->id, 'utilities'));
        $this->assertSame(-300.0, Ledger::balance($this->business->id, 'cash'));
    }

    /** وغيرُ المدفوع لا يُرحَّل: التزامٌ لم يخرج له مالٌ بعد */
    public function test_an_unpaid_expense_writes_no_entry(): void
    {
        $this->actingAs($this->owner)->post('/admin/expenses', [
            'type' => 'كهرباء وماء', 'amount' => 300, 'method' => 'نقدي', 'status' => 'غير مدفوع',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, JournalEntry::where('business_id', $this->business->id)->count());
        $this->assertSame(0, Transaction::where('business_id', $this->business->id)->count());
    }

    /* ------------------------ وحين يتعذّر الترحيل ------------------------ */

    /**
     * يسقط الثلاثةُ معًا: لا مصروف، ولا صفَّ حركة، ولا قيد.
     *
     * والعطبُ يُصنع بحذف حساب الصندوق: `Ledger::post` تردّ استثناءً حين لا
     * تجد حسابًا تُرحّل إليه — وهو أقربُ ما يقع فعلًا حين يعبث تاجرٌ بشجرة
     * حساباته.
     */
    public function test_when_the_ledger_refuses_nothing_at_all_is_written(): void
    {
        $this->breakTheChart();

        $this->actingAs($this->owner)->post('/admin/expenses', [
            'type' => 'كهرباء وماء', 'amount' => 300, 'method' => 'نقدي', 'status' => 'مدفوع',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Expense::count());
        $this->assertSame(0, Transaction::where('business_id', $this->business->id)->count());
        $this->assertSame(0, JournalEntry::where('business_id', $this->business->id)->count());
    }

    /**
     * و«سدّد» كذلك: يبقى المصروف «غير مدفوع» ولا يُكتب صفُّ حركة.
     *
     * وهو الأخطر: هنا كان الوسمُ يقع أوّلًا ثمّ يُحاوَل الترحيل — فيبقى
     * «مدفوع» أبدًا مهما فشل ما بعده.
     */
    public function test_marking_paid_rolls_back_when_the_entry_cannot_be_written(): void
    {
        $this->actingAs($this->owner)->post('/admin/expenses', [
            'type' => 'كهرباء وماء', 'amount' => 300, 'method' => 'نقدي', 'status' => 'غير مدفوع',
        ]);

        $expense = Expense::firstOrFail();
        $this->breakTheChart();

        $this->actingAs($this->owner)->post('/admin/expenses/'.$expense->id.'/paid')
            ->assertSessionHasErrors('pay');

        $this->assertSame('غير مدفوع', $expense->fresh()->status);
        $this->assertNull($expense->fresh()->transaction_id);
        $this->assertSame(0, Transaction::where('business_id', $this->business->id)->count());
        $this->assertSame(0, JournalEntry::where('business_id', $this->business->id)->count());
    }

    /**
     * وسببُ الفشل يُقيَّد — خارج المعاملة الساقطة.
     *
     * `Activity::log` داخلها يسقط معها، فيضيع الخبرُ الذي من أجله كُتب.
     */
    public function test_the_reason_survives_the_rollback(): void
    {
        $this->breakTheChart();

        $this->actingAs($this->owner)->post('/admin/expenses', [
            'type' => 'كهرباء وماء', 'amount' => 300, 'method' => 'نقدي', 'status' => 'مدفوع',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'business_id' => $this->business->id, 'subject_type' => 'expense',
        ]);
    }

    /**
     * شجرةٌ لا تُرحَّل إليها.
     *
     * والحذفُ وحدَه لا يكفي: `Ledger::account` تعيد بناء الحسابات النظاميّة
     * حين لا تجد المطلوب. فيُقفل الحسابُ ويبقى قائمًا — وهو ما يقع فعلًا
     * حين يُقفل التاجر حسابًا في شجرته.
     */
    private function breakTheChart(): void
    {
        Account::where('business_id', $this->business->id)
            ->where('system_key', 'cash')->update(['active' => false]);
    }
}
