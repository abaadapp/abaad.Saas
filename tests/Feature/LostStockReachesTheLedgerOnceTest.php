<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\StockAdjustment;
use App\Support\Ledger;
use App\Support\StockLosses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الفاقدُ الذي كُتب مصروفًا يصل الأستاذ — ولا يصله مرّتين.
 *
 * ═══ العطبُ كما وقع ═══
 *
 * كانت تسويةُ الجرد تكتب صفَّ مصروفٍ ولا تُرحّل شيئًا. فيبقى المخزون في
 * الميزانية بقيمة بضاعةٍ ليست في الرفّ. وأُصلح البابُ في `StockLosses`،
 * وبقي ما كُتب قبله نصفَ قيد: على الإنتاج ثلاثةَ عشرَ صفًّا بـ٥٦٥٫٨٢٦ ر.ع.
 *
 * ═══ وأخطرُ ما في إصلاح الماضي ═══
 *
 * المضاعفة. أمرٌ يُنادى مرّتين — أو يُجدوَل — فيكتب القيدَ مرّتين، فيصير
 * الخطأُ ضعفَ الأوّل وأصعبَ كشفًا: الميزانُ يبقى متّزنًا والأرقامُ كاذبة.
 *
 * فالحارسُ الأثقل هنا `test_running_it_twice_writes_nothing_the_second_time`.
 */
class LostStockReachesTheLedgerOnceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->shop->id);
    }

    /* ═══════════════════ الاستدراك ═══════════════════ */

    /** فاقدٌ قديمٌ بلا قيد: يُرحَّل بمبلغه وتاريخه */
    public function test_an_old_loss_reaches_the_ledger(): void
    {
        $expense = $this->orphanLoss(12.5, '2026-08-31');

        $this->artisan('finance:post-missing-stock-losses')->assertSuccessful();

        $entry = JournalEntry::where('sourceable_type', Expense::class)
            ->where('sourceable_id', $expense->id)->first();

        $this->assertNotNull($entry, 'بقي الفاقدُ خارج الأستاذ');
        $this->assertSame(StockLosses::SOURCE, $entry->source);
        $this->assertSame('2026-08-31', $entry->entry_date->toDateString());
    }

    /** ومدينٌ مصروفًا ودائنٌ مخزونًا — لا العكس */
    public function test_the_entry_debits_expense_and_credits_inventory(): void
    {
        $expense = $this->orphanLoss(20, '2026-08-31');

        $this->artisan('finance:post-missing-stock-losses')->assertSuccessful();

        $entry = JournalEntry::where('sourceable_id', $expense->id)->firstOrFail();
        $lines = JournalLine::where('journal_entry_id', $entry->id)->get();

        $debit = $lines->firstWhere(fn ($l) => (float) $l->debit > 0);
        $credit = $lines->firstWhere(fn ($l) => (float) $l->credit > 0);

        $this->assertSame('other_expenses', $debit->account->system_key, 'الفاقدُ لم يُقيَّد مصروفًا');
        $this->assertSame('inventory', $credit->account->system_key, 'المخزونُ لم يُنقَص');
        $this->assertSame(20.0, round((float) $debit->debit, 3));
        $this->assertSame(20.0, round((float) $credit->credit, 3));
    }

    /**
     * ولا يُرحَّل مرّتين — وهذا أثقلُ ما يُحرَس.
     *
     * إصلاحُ الماضي يُخطئ مرّةً واحدةً فيصير الخطأُ ضعفَ الأوّل، والميزانُ
     * يبقى متّزنًا فلا يشكو أحد.
     */
    public function test_running_it_twice_writes_nothing_the_second_time(): void
    {
        $this->orphanLoss(30, '2026-08-31');

        $this->artisan('finance:post-missing-stock-losses')->assertSuccessful();
        $after = JournalEntry::count();

        $this->artisan('finance:post-missing-stock-losses')->assertSuccessful();

        $this->assertSame($after, JournalEntry::count(), 'رُحّل الفاقدُ مرّتين — والخطأُ صار ضعفَ الأوّل');
    }

    /** وما رُحّل مع وقوعه لا يُرحَّل ثانيةً */
    public function test_a_loss_already_posted_is_left_alone(): void
    {
        $expense = $this->orphanLoss(40, '2026-08-31');

        Ledger::post(
            $this->shop->id, 'فاقد', [
                ['account' => 'other_expenses', 'debit' => 40],
                ['account' => 'inventory', 'credit' => 40],
            ], now(), StockLosses::SOURCE, null, null, $expense,
        );

        $before = JournalEntry::count();
        $this->artisan('finance:post-missing-stock-losses')->assertSuccessful();

        $this->assertSame($before, JournalEntry::count());
    }

    /** و«عدٌّ بلا كتابة» لا يكتب */
    public function test_a_dry_run_writes_nothing(): void
    {
        $this->orphanLoss(15, '2026-08-31');

        $this->artisan('finance:post-missing-stock-losses', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, JournalEntry::count(), 'كتب «العدُّ بلا كتابة» قيدًا');
    }

    /** ومصروفٌ من نوعٍ آخر لا يُمَسّ — الإيجارُ ليس فاقدَ جرد */
    public function test_other_expenses_are_not_touched(): void
    {
        Expense::create([
            'business_id' => $this->shop->id, 'type' => 'إيجار', 'description' => 'إيجار',
            'amount' => 100, 'method' => 'نقدي', 'spent_at' => '2026-08-31',
        ]);

        $this->artisan('finance:post-missing-stock-losses')->assertSuccessful();

        $this->assertSame(0, JournalEntry::count(), 'رُحّل مصروفٌ ليس فاقدَ جرد');
    }

    /** ومتجرٌ آخر لا تُمَسّ دفاترُه حين يُطلب متجرٌ بعينه */
    public function test_naming_one_business_leaves_the_others_alone(): void
    {
        $other = Business::create(['name' => 'آخر', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $other->id, 'code' => 'OMR', 'name' => 'ريال',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($other->id);

        $mine = $this->orphanLoss(10, '2026-08-31');
        $theirs = $this->orphanLoss(10, '2026-08-31', $other->id);

        $this->artisan('finance:post-missing-stock-losses', ['--business' => $this->shop->id])
            ->assertSuccessful();

        $this->assertNotNull(JournalEntry::where('sourceable_id', $mine->id)->first());
        $this->assertNull(
            JournalEntry::where('sourceable_id', $theirs->id)->first(),
            'مُسَّت دفاترُ متجرٍ لم يُطلب'
        );
    }

    /** والميزانُ يبقى متّزنًا بعد الاستدراك */
    public function test_the_books_stay_balanced_afterwards(): void
    {
        $this->orphanLoss(33.333, '2026-08-31');
        $this->orphanLoss(7.007, '2026-09-01');

        $this->artisan('finance:post-missing-stock-losses')->assertSuccessful();

        $debit = round((float) JournalLine::sum('debit'), 3);
        $credit = round((float) JournalLine::sum('credit'), 3);

        $this->assertSame($debit, $credit, 'اختلّ الميزانُ بعد الاستدراك');
        $this->assertSame(40.34, $debit);
    }

    /* ═══════════════════ أدوات ═══════════════════ */

    /** صفُّ فاقدٍ بلا قيدٍ يقابله — كما خلّفه العطب */
    private function orphanLoss(float $amount, string $when, ?int $businessId = null): Expense
    {
        return Expense::create([
            'business_id' => $businessId ?? $this->shop->id,
            'type' => StockAdjustment::STOCKTAKE_LOSS,
            'description' => 'فاقد جرد — الفرع الرئيسي',
            'amount' => $amount,
            'method' => 'قيد داخلي',
            'spent_at' => $when,
        ]);
    }
}
