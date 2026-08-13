<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * الدفتر لا يقبل ما لا يتوازن.
 *
 * هذا أهمّ اختبارٍ في المحاسبة: قيدٌ مختلّ يمرّ اليوم لا يُكتشف إلا في ميزان
 * المراجعة بعد شهور، ولا يُعرف حينها أيّ قيدٍ أفسده ولا كم أفسد. فالحارس
 * يقف عند الكتابة لا عند القراءة، ويُفحص هنا من كل بابٍ يُكتب منه.
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->business->id);
    }

    /* ---------------------------- شجرة الحسابات ---------------------------- */

    public function test_a_new_store_gets_a_usable_chart(): void
    {
        $accounts = Account::where('business_id', $this->business->id)->get();

        $this->assertGreaterThan(15, $accounts->count(), 'الشجرة أفقر من أن تُستعمل');

        foreach (['cash', 'bank', 'sales', 'cogs', 'payable', 'receivable'] as $key) {
            $this->assertNotNull(
                Ledger::account($this->business->id, $key),
                "الحساب النظامي «{$key}» غائب — والترحيل التلقائي يعتمد عليه"
            );
        }
    }

    public function test_the_chart_is_seeded_once_not_duplicated(): void
    {
        $before = Account::where('business_id', $this->business->id)->count();

        $this->assertSame(0, Ledger::seedChart($this->business->id), 'أُعيد بناء شجرةٍ قائمة');
        $this->assertSame($before, Account::where('business_id', $this->business->id)->count());
    }

    public function test_a_contra_account_keeps_its_own_side(): void
    {
        /*
         * مجمّع الإهلاك أصلٌ طبيعته دائنة، ومردودات المبيعات إيرادٌ طبيعته
         * مدينة. اشتقاق الطبيعة من النوع يقلب إشارتهما في ميزان المراجعة.
         */
        $this->assertSame('credit', Ledger::account($this->business->id, 'accumulated_depreciation')->normal_side);
        $this->assertSame('debit', Ledger::account($this->business->id, 'sales_returns')->normal_side);
    }

    /* ------------------------------ التوازن ------------------------------ */

    public function test_a_balanced_entry_posts(): void
    {
        $entry = Ledger::post($this->business->id, 'بيع نقدي', [
            ['account' => 'cash', 'debit' => 100],
            ['account' => 'sales', 'credit' => 100],
        ]);

        $this->assertTrue($entry->posted);
        $this->assertTrue($entry->isBalanced());
        $this->assertCount(2, $entry->lines);
    }

    public function test_an_unbalanced_entry_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        Ledger::post($this->business->id, 'قيد مختلّ', [
            ['account' => 'cash', 'debit' => 100],
            ['account' => 'sales', 'credit' => 90],
        ]);
    }

    public function test_a_refused_entry_leaves_nothing_behind(): void
    {
        /*
         * أهمّ ما في الملفّ بعد التوازن نفسه: الرفض يجب أن يكون تامًّا.
         * قيدٌ يُكتب رأسُه ثم يُرفض يترك في الدفتر رأسًا بلا مبلغ — يظهر في
         * القوائم ولا يعني شيئًا، ولا يُعرف ما كان يُراد به.
         */
        try {
            Ledger::post($this->business->id, 'قيد مختلّ', [
                ['account' => 'cash', 'debit' => 100],
                ['account' => 'sales', 'credit' => 90],
            ]);
        } catch (RuntimeException) {
            // متوقَّع
        }

        $this->assertSame(0, JournalEntry::where('business_id', $this->business->id)->count(), 'بقي رأس قيدٍ مرفوض');
    }

    public function test_a_line_cannot_be_both_sides_at_once(): void
    {
        $this->expectException(RuntimeException::class);

        // يمرّ من فحص التوازن (١٠٠ = ١٠٠) ويُفسد دفتر الأستاذ
        Ledger::post($this->business->id, 'سطر بطرفين', [
            ['account' => 'cash', 'debit' => 100, 'credit' => 100],
            ['account' => 'sales', 'credit' => 100],
            ['account' => 'cogs', 'debit' => 100],
        ]);
    }

    public function test_a_single_line_is_not_an_entry(): void
    {
        $this->expectException(RuntimeException::class);

        Ledger::post($this->business->id, 'سطر واحد', [
            ['account' => 'cash', 'debit' => 100],
        ]);
    }

    public function test_posting_to_a_parent_account_is_refused(): void
    {
        /*
         * الترحيل إلى أبٍ وإلى ابنه معًا يُضاعف المبلغ في أي تقرير يجمع
         * الشجرة: يُقرأ مرّةً في الابن ومرّةً في الأب.
         */
        $this->expectException(RuntimeException::class);

        $root = Account::where('business_id', $this->business->id)->where('code', '1')->first();

        Ledger::post($this->business->id, 'ترحيل إلى أب', [
            ['account' => $root, 'debit' => 50],
            ['account' => 'sales', 'credit' => 50],
        ]);
    }

    public function test_a_closed_account_refuses_new_entries(): void
    {
        $this->expectException(RuntimeException::class);

        $cash = Ledger::account($this->business->id, 'cash');
        $cash->update(['active' => false]);

        Ledger::post($this->business->id, 'إلى حسابٍ مغلق', [
            ['account' => $cash, 'debit' => 10],
            ['account' => 'sales', 'credit' => 10],
        ]);
    }

    public function test_a_missing_account_names_itself_in_the_error(): void
    {
        $this->expectExceptionMessageMatches('/nonexistent/');

        Ledger::post($this->business->id, 'حساب مجهول', [
            ['account' => 'nonexistent', 'debit' => 10],
            ['account' => 'sales', 'credit' => 10],
        ]);
    }

    /* -------------------------- ميزان المراجعة -------------------------- */

    public function test_the_trial_balance_always_balances(): void
    {
        Ledger::post($this->business->id, 'بيع', [
            ['account' => 'cash', 'debit' => 250],
            ['account' => 'sales', 'credit' => 250],
        ]);
        Ledger::post($this->business->id, 'شراء', [
            ['account' => 'inventory', 'debit' => 80],
            ['account' => 'payable', 'credit' => 80],
        ]);

        $tb = Ledger::trialBalance($this->business->id);

        $this->assertTrue($tb['balanced'], 'الميزان لا يتوازن — قيدٌ كُتب من غير باب الدفتر');
        $this->assertSame($tb['total_debit'], $tb['total_credit']);
        $this->assertSame(330.0, $tb['total_debit']);
    }

    public function test_revenue_reads_positive_not_negative(): void
    {
        // الفرق المجرّد يجعل كل إيرادٍ سالبًا، فيقرأ التاجر مبيعاته خسارةً
        Ledger::post($this->business->id, 'بيع', [
            ['account' => 'cash', 'debit' => 100],
            ['account' => 'sales', 'credit' => 100],
        ]);

        $sales = collect(Ledger::trialBalance($this->business->id)['accounts'])->firstWhere('code', '4100');

        $this->assertSame(100.0, $sales['balance']);
        $this->assertSame(100.0, Ledger::account($this->business->id, 'sales')->balance());
    }

    public function test_the_number_is_sequential_not_random(): void
    {
        $a = Ledger::post($this->business->id, 'أول', [
            ['account' => 'cash', 'debit' => 1], ['account' => 'sales', 'credit' => 1],
        ]);
        $b = Ledger::post($this->business->id, 'ثانٍ', [
            ['account' => 'cash', 'debit' => 1], ['account' => 'sales', 'credit' => 1],
        ]);

        $this->assertSame('JV-000001', $a->number);
        $this->assertSame('JV-000002', $b->number);
    }

    public function test_a_dated_trial_balance_ignores_what_came_after(): void
    {
        Ledger::post($this->business->id, 'قديم', [
            ['account' => 'cash', 'debit' => 40], ['account' => 'sales', 'credit' => 40],
        ], now()->subMonth());

        Ledger::post($this->business->id, 'حديث', [
            ['account' => 'cash', 'debit' => 60], ['account' => 'sales', 'credit' => 60],
        ], now());

        $tb = Ledger::trialBalance($this->business->id, now()->subWeek());

        $this->assertSame(40.0, $tb['total_debit'], 'تسرّب قيدٌ لاحقٌ إلى ميزانٍ بتاريخٍ سابق');
        $this->assertTrue($tb['balanced']);
    }
}
