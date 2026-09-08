<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الحسابُ بلا اسمٍ يردّ الورقة — والرصيدُ يعود إلى بنكه.
 *
 * هذه تختبر الهجرة على **شكل الإنتاج نفسه**: حسابٌ فارغٌ يملك ورقة «البنك»
 * وعليها ثلاثةَ عشرَ سطرًا، وحسابٌ حقيقيٌّ رئيسيٌّ برصيدٍ افتتاحيّ وورقةٍ
 * أختٍ لم يدخلها ريال. فالتاجر يقرأ بنكَه ساكنًا ومالَه في صفٍّ لا يعرفه.
 */
class TheNamelessBankAccountGivesBackTheLeafTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_08_230000_the_nameless_bank_account_gives_back_the_leaf.php';

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->business->id);
    }

    private function migrate(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function systemLeaf(): Account
    {
        return Ledger::account($this->business->id, 'bank');
    }

    /** ورقةٌ أختٌ تحت «الأصول» — كما يصنعها `leafFor` */
    private function sibling(string $name, string $code): Account
    {
        return Account::create([
            'business_id' => $this->business->id,
            'parent_id' => $this->systemLeaf()->parent_id,
            'code' => $code, 'name' => $name, 'type' => 'أصل', 'normal_side' => 'debit',
        ]);
    }

    private function nameless(): BankAccount
    {
        return BankAccount::create([
            'business_id' => $this->business->id, 'is_primary' => false,
            'account_id' => $this->systemLeaf()->id,
        ]);
    }

    private function real(float $opening = 2000): BankAccount
    {
        return BankAccount::create([
            'business_id' => $this->business->id, 'label' => 'بنك مسقط',
            'bank_name' => 'بنك مسقط', 'account_name' => 'شركة ابعاد',
            'opening_balance' => $opening, 'active' => true, 'is_primary' => true,
            'account_id' => $this->sibling('البنك: بنك مسقط', '1220')->id,
        ]);
    }

    private function movement(float $amount): void
    {
        Ledger::post($this->business->id, 'إيداع', [
            ['account' => $this->systemLeaf(), 'debit' => $amount],
            ['account' => 'capital', 'credit' => $amount],
        ]);
    }

    /* ───────────────────────── شكلُ الإنتاج ───────────────────────── */

    /** المالُ يعود إلى البنك الذي كان يستقبله، والفارغُ يمضي */
    public function test_the_real_account_inherits_the_leaf_and_its_balance(): void
    {
        $nameless = $this->nameless();
        $real = $this->real(2000);
        $this->movement(147);

        // شكلُ الإنتاج: المالُ في الصفّ الذي لا اسمَ له، والبنكُ ساكنٌ عند افتتاحيّه
        $this->assertSame(147.0, $nameless->fresh()->balance(), 'الحالة قبل الهجرة ليست حالة الإنتاج');
        $this->assertSame(2000.0, $real->fresh()->balance(), 'الحالة قبل الهجرة ليست حالة الإنتاج');

        $this->migrate();

        $this->assertNull(BankAccount::find($nameless->id), 'بقي الصفُّ الفارغ يُعرض «حساب بنكي»');
        $this->assertSame(
            2147.0,
            $real->fresh()->balance(),
            'لم يعد المالُ إلى بنكه — يقرأ التاجر رصيدًا ساكنًا وماله في ورقةٍ أخرى'
        );
        $this->assertSame($this->systemLeaf()->id, $real->fresh()->account_id);
    }

    /** ولا يتحرّك سطرٌ واحدٌ من الدفتر */
    public function test_not_one_ledger_line_moves(): void
    {
        $this->nameless();
        $this->real();
        $this->movement(147);

        $before = DB::table('journal_lines')->orderBy('id')->get()->toArray();

        $this->migrate();

        $this->assertEquals($before, DB::table('journal_lines')->orderBy('id')->get()->toArray());
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /** والورقةُ الخالية تُرفع من الشجرة فلا يبقى فيها اسمٌ لا يعني شيئًا */
    public function test_the_empty_sibling_leaf_is_removed(): void
    {
        $this->nameless();
        $real = $this->real();
        $orphan = $real->account_id;

        $this->migrate();

        $this->assertNull(Account::find($orphan));
    }

    /* ───────────────────────── ومتى لا تمسّ ───────────────────────── */

    /** حاملُ الورقة إن كان مسمًّى فهو بنكُ التاجر — لا يُحذف */
    public function test_a_named_holder_is_left_alone(): void
    {
        $named = BankAccount::create([
            'business_id' => $this->business->id, 'label' => 'الجاري',
            'is_primary' => true, 'account_id' => $this->systemLeaf()->id,
        ]);
        $second = BankAccount::create([
            'business_id' => $this->business->id, 'label' => 'التوفير',
            'account_id' => $this->sibling('البنك: التوفير', '1220')->id,
        ]);

        $this->migrate();

        $this->assertNotNull(BankAccount::find($named->id));
        $this->assertSame($this->systemLeaf()->id, $named->fresh()->account_id);
        $this->assertSame($second->account_id, $second->fresh()->account_id);
    }

    /** ورصيدٌ افتتاحيٌّ على الفارغ يعني أنّ أحدًا كتبه — فليس فارغًا */
    public function test_an_opening_balance_makes_it_not_nameless(): void
    {
        $holder = BankAccount::create([
            'business_id' => $this->business->id, 'opening_balance' => 500,
            'account_id' => $this->systemLeaf()->id,
        ]);
        $this->real();

        $this->migrate();

        $this->assertNotNull(BankAccount::find($holder->id));
    }

    /** وتحصيلٌ نُسب إليه يجعله مستعمَلًا مهما خلا اسمُه */
    public function test_an_account_that_took_a_collection_is_left_alone(): void
    {
        $holder = $this->nameless();
        $this->real();

        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'عميل']);
        CustomerPayment::create([
            'business_id' => $this->business->id, 'customer_id' => $customer->id,
            'number' => 'RCPT-000001', 'amount' => 10, 'method' => 'تحويل',
            'bank_account_id' => $holder->id, 'occurred_at' => now()->toDateString(),
        ]);

        $this->migrate();

        $this->assertNotNull(BankAccount::find($holder->id), 'حُذف حسابٌ نُسب إليه تحصيل');
    }

    /** ومتجرٌ ليس له غيرُ الفارغ يبقى كما هو: حذفُه يترك سطورًا بلا مالك */
    public function test_the_only_account_is_kept_even_if_nameless(): void
    {
        $holder = $this->nameless();
        $this->movement(147);

        $this->migrate();

        $this->assertNotNull(BankAccount::find($holder->id));
        $this->assertSame($this->systemLeaf()->id, $holder->fresh()->account_id);
    }

    /** ووارثٌ على ورقته سطورٌ لا يُستبدل بها: مالٌ يضيع بحذفها */
    public function test_an_heir_whose_leaf_already_moved_is_left_alone(): void
    {
        $holder = $this->nameless();
        $real = $this->real();
        $this->movement(147);

        Ledger::post($this->business->id, 'إيداع لاحق', [
            ['account' => Account::findOrFail($real->account_id), 'debit' => 30],
            ['account' => 'capital', 'credit' => 30],
        ]);

        $this->migrate();

        $this->assertNotNull(BankAccount::find($holder->id));
        $this->assertNotNull(Account::find($real->account_id));
    }

    /** وتُشغَّل مرّتين فلا تفعل في الثانية شيئًا */
    public function test_running_it_twice_changes_nothing(): void
    {
        $this->nameless();
        $real = $this->real();
        $this->movement(147);

        $this->migrate();
        $after = $real->fresh()->balance();
        $count = BankAccount::where('business_id', $this->business->id)->count();

        $this->migrate();

        $this->assertSame($after, $real->fresh()->balance());
        $this->assertSame($count, BankAccount::where('business_id', $this->business->id)->count());
    }

    /** ومتجرُ الجار لا يُمسّ بإصلاح متجرٍ آخر */
    public function test_a_neighbours_shop_is_untouched(): void
    {
        $this->nameless();
        $this->real();
        $this->movement(147);

        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        $theirs = BankAccount::create([
            'business_id' => $other->id, 'label' => 'حسابهم', 'is_primary' => true,
            'account_id' => Ledger::account($other->id, 'bank')->id,
        ]);

        $this->migrate();

        $this->assertNotNull(BankAccount::find($theirs->id));
        $this->assertSame(Ledger::account($other->id, 'bank')->id, $theirs->fresh()->account_id);
    }
}
