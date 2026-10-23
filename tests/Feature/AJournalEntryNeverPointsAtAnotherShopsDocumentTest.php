<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OrderItem;
use App\Models\Transaction;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * قيدُ متجرٍ لا يُكتب على مستند متجرٍ آخر — ولو استُدعي الدفتر من كودٍ داخليّ.
 *
 * كان `Ledger::post` يكتب `sourceable_type` و`sourceable_id` كما وصلا: قيدٌ في
 * المتجر (ب) مصدرُه مصروفُ المتجر (أ) يُكتب بلا اعتراض — ثبت بالفحص المعزول
 * لبيانات PR #149. ومصدرُ القيد هو ما تقرأ منه الشاشاتُ «ما زال في الدفتر»
 * و«قيدُه حيّ»، فقيدُ الجار على مصروفك يُغيّر ما تراه عن مصروفك.
 *
 * فالآن: المرجعُ نموذجٌ محفوظٌ يحمل `business_id` متجرِ القيد نفسه، وإلّا رُفض
 * قبل أن يُكتب شيء. وما لا يُعرف متجرُه لا يُفترض آمنًا. والرسالةُ لا تقول
 * لمن المستند ولا أنّه موجود.
 */
class AJournalEntryNeverPointsAtAnotherShopsDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->theirs = Business::create(['name' => 'متجر الجار السرّيّ', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->mine->id);
        Ledger::seedChart($this->theirs->id);
    }

    private function entryOn(Business $on, $sourceable): JournalEntry
    {
        return Ledger::post($on->id, 'قيد', [
            ['account' => 'other_expenses', 'debit' => 5],
            ['account' => 'cash', 'credit' => 5],
        ], now(), 'يدوي', null, null, $sourceable);
    }

    private function expenseOf(Business $b): Expense
    {
        return Expense::create(['business_id' => $b->id, 'type' => 'إيجار', 'amount' => 5, 'method' => 'نقدي',
            'spent_at' => now()->toDateString(), 'status' => Expense::UNPAID, 'reference' => 'EXP-0001']);
    }

    private function assertRefusedWithoutTrace(callable $write): void
    {
        $entries = JournalEntry::count();
        $lines = JournalLine::count();
        $cash = Ledger::balance($this->mine->id, 'cash');

        try {
            $write();
            $this->fail('كُتب قيدٌ على مستندٍ لا يتبع متجرَه');
        } catch (RuntimeException $e) {
            // ولا يُكشف شيءٌ عن المستند ولا عن صاحبه
            $this->assertStringNotContainsString($this->theirs->name, $e->getMessage());
            $this->assertStringNotContainsString('EXP-0001', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/\d/', $e->getMessage(), 'الرسالة تذكر رقمًا');
        }

        $this->assertSame($entries, JournalEntry::count(), 'بقي رأسُ قيد');
        $this->assertSame($lines, JournalLine::count(), 'بقيت سطور');
        $this->assertSame($cash, Ledger::balance($this->mine->id, 'cash'), 'تغيّر الرصيد');
    }

    public function test_an_entry_on_the_shops_own_documents_is_written_as_before(): void
    {
        $expense = $this->expenseOf($this->mine);
        $entry = $this->entryOn($this->mine, $expense);
        $this->assertSame(Expense::class, $entry->sourceable_type);
        $this->assertSame($expense->id, (int) $entry->sourceable_id);

        $movement = Transaction::create(['business_id' => $this->mine->id, 'reference' => 'TRX-1', 'description' => 'x',
            'method' => 'نقدي', 'type' => 'مصروف', 'amount' => 5, 'occurred_at' => now()]);
        $this->assertSame($movement->id, (int) $this->entryOn($this->mine, $movement)->sourceable_id);

        $this->assertNull($this->entryOn($this->mine, null)->sourceable_id);

        // ونموذجٌ قُرئت بعضُ أعمدته يُسأل عن متجره من القاعدة
        $this->assertSame($expense->id, (int) $this->entryOn($this->mine, Expense::select('id')->find($expense->id))->sourceable_id);

        // والعكسُ يحمل مرجعَ أصله كما كان
        $this->assertSame($expense->id, (int) Ledger::reverse($entry)->sourceable_id);
    }

    public function test_another_shops_expense_is_refused(): void
    {
        $theirs = $this->expenseOf($this->theirs);
        $this->assertRefusedWithoutTrace(fn () => $this->entryOn($this->mine, $theirs));
    }

    public function test_another_shops_movement_is_refused(): void
    {
        $theirs = Transaction::create(['business_id' => $this->theirs->id, 'reference' => 'TRX-1', 'description' => 'x',
            'method' => 'نقدي', 'type' => 'مصروف', 'amount' => 5, 'occurred_at' => now()]);
        $this->assertRefusedWithoutTrace(fn () => $this->entryOn($this->mine, $theirs));
    }

    public function test_another_shops_boutique_sheet_is_refused(): void
    {
        $boutique = Boutique::create(['business_id' => $this->theirs->id, 'name' => 'بوتيك', 'commission_rate' => 10, 'active' => true]);
        $sheet = BoutiqueSettlement::create(['business_id' => $this->theirs->id, 'boutique_id' => $boutique->id, 'number' => 'BQ-1',
            'period' => '2027-01', 'gross' => 5, 'commission' => 0, 'net' => 5, 'lines_count' => 1,
            'kind' => BoutiqueSettlement::SETTLEMENT, 'issued_at' => now()]);
        $this->assertRefusedWithoutTrace(fn () => $this->entryOn($this->mine, $sheet));
    }

    /** وما لا يُعرف متجرُه لا يُفترض آمنًا: نموذجٌ بلا `business_id`، أو لم يُحفظ، أو ليس نموذجًا */
    public function test_a_reference_whose_shop_cannot_be_told_is_refused(): void
    {
        $this->assertRefusedWithoutTrace(fn () => $this->entryOn($this->mine, new OrderItem(['id' => 1])));
        $this->assertRefusedWithoutTrace(fn () => $this->entryOn($this->mine, new Expense(['business_id' => $this->mine->id])));
        $this->assertRefusedWithoutTrace(fn () => $this->entryOn($this->mine, new stdClass));
    }

    /** وسمٌ على النموذج لا يكفي: المتجرُ يُقرأ من الصفّ حين يغيب عمودُه عن النموذج */
    public function test_a_partly_read_model_of_another_shop_is_still_refused(): void
    {
        $theirs = $this->expenseOf($this->theirs);
        $this->assertRefusedWithoutTrace(fn () => $this->entryOn($this->mine, Expense::select('id')->find($theirs->id)));
    }
}
