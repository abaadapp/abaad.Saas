<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Bank;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الرصيدُ الافتتاحيُّ يدخل الدفتر — فلا تقول شاشتان رقمين.
 *
 * ═══ العطب ═══
 *
 * `BankAccount::balance()` كانت تجمع `opening_balance` من **خارج** الدفتر على
 * رصيد ورقته فيه. فشاشةُ «الحسابات البنكية» تقول ٦٠٥ والميزانيةُ تقول ١٠٥،
 * والفرقُ مالٌ حقيقيٌّ لا يحمله حسابٌ واحد في الشجرة. وقيس على الإنتاج قبل
 * كتابة هذا: ثلاثةُ حساباتٍ بنكيّة، كلُّها كذلك.
 *
 * وكان التعليقُ يشرح المنعَ بأنّ القيدَ «يظهر إيرادًا لا وجود له». وهو صوابٌ
 * في مقابلٍ خاطئ: المالُ الذي كان في البنك قبل أوّل يومٍ ليس دخلَ هذا الشهر،
 * هو حقُّ صاحبه في متجره. فمقابلُه حقوقُ الملكية (3150)، وقائمةُ الدخل لا
 * تتحرّك.
 *
 * ═══ وبابٌ واحد ═══
 *
 * `Bank::syncOpening` هي الموضع الوحيد الذي يكتب هذا القيد ويصحّحه: المتحكّم
 * يناديها عند الإنشاء وعند كلّ حفظ، والترحيلُ يناديها للحسابات القائمة،
 * ومتجرُ العرض يناديها بدل قيدٍ كان يكتبه بيده.
 */
class TheOpeningBalanceEntersTheLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function create(array $over = []): BankAccount
    {
        $this->actingAs($this->owner)
            ->post(route('admin.finance.banks.store'), $over + [
                'label' => 'بنك مسقط', 'bank_name' => 'بنك مسقط',
                'opening_balance' => '500', 'opening_date' => '2026-01-01', 'active' => true,
            ])->assertRedirect();

        return BankAccount::latest('id')->firstOrFail();
    }

    private function equity(): float
    {
        return Ledger::account($this->business->id, 'opening_balance_equity')->balance();
    }

    /* ───────────────────────── الشجرة ───────────────────────── */

    /** حسابُ الأرصدة الافتتاحيّة في الشجرة الافتراضيّة — حقوقُ ملكيةٍ دائنة */
    public function test_the_chart_carries_an_opening_equity_account(): void
    {
        $account = Ledger::account($this->business->id, 'opening_balance_equity');

        $this->assertNotNull($account, 'لا حسابَ يقابل الافتتاحيّ فيبقى خارج الدفتر');
        $this->assertSame('حقوق ملكية', $account->type);
        $this->assertSame('credit', $account->normal_side, 'مقابلُ الافتتاحيّ مدينٌ فيقلب الميزانية');
    }

    /** وشجرةٌ بُنيت قبل هذه النسخة تستدركه — لا تُترك بلا مقابل */
    public function test_an_older_chart_gains_it(): void
    {
        Account::where('business_id', $this->business->id)
            ->where('system_key', 'opening_balance_equity')->delete();

        Ledger::ensureSystemAccounts($this->business->id);

        $this->assertNotNull(Ledger::account($this->business->id, 'opening_balance_equity'));
    }

    /* ───────────────────────── الكتابة ───────────────────────── */

    /** حسابٌ يُنشأ برصيدٍ افتتاحيّ يُقيَّد — والشاشتان تقولان الرقم نفسه */
    public function test_creating_an_account_posts_its_opening(): void
    {
        $account = $this->create();

        $this->assertSame(500.0, $account->balance(), 'الشاشةُ تقرأ رقمًا لا يحمله الدفتر');
        $this->assertSame(500.0, $this->equity(), 'الافتتاحيُّ بلا مقابلٍ في حقوق الملكية');
        $this->assertSame(500.0, Bank::total($this->business->id));
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /** ولا يُجمع مرّتين: الصفُّ والدفترُ يقولان الشيء نفسه لا شيئين */
    public function test_the_opening_is_not_counted_twice(): void
    {
        $account = $this->create();
        $leaf = Account::findOrFail($account->account_id);

        $this->assertSame(500.0, $leaf->balance());
        $this->assertSame($leaf->balance(), $account->balance(), 'الصفُّ يُضاف إلى الدفتر فيُعدّ مرّتين');
    }

    /** وقائمةُ الدخل لا تتحرّك: هذا ليس إيرادًا */
    public function test_it_is_not_income(): void
    {
        $this->create();

        $this->assertSame(0.0, Ledger::account($this->business->id, 'sales')->balance());
        $this->assertSame(0.0, Ledger::account($this->business->id, 'other_income')->balance());
    }

    /** وتاريخُه تاريخُ الافتتاحيّ لا يومَ الإدخال */
    public function test_the_entry_carries_the_opening_date(): void
    {
        $this->create();

        $entry = JournalEntry::where('source', Bank::OPENING)->sole();

        $this->assertSame('2026-01-01', $entry->entry_date->toDateString());
    }

    /* ───────────────────────── التصحيح ───────────────────────── */

    private function save(BankAccount $account, array $over): void
    {
        $this->actingAs($this->owner)
            ->put(route('admin.finance.banks.update', $account->id), $over + [
                'label' => $account->label, 'bank_name' => $account->bank_name,
                'opening_balance' => (string) $account->opening_balance,
                'opening_date' => optional($account->opening_date)->format('Y-m-d'),
                'active' => true,
            ])->assertRedirect();
    }

    /** حفظُ الاسم وحده لا يكتب قيدًا ثانيًا */
    public function test_saving_without_changing_the_opening_writes_nothing(): void
    {
        $account = $this->create();

        $this->save($account, ['label' => 'بنك مسقط — الجاري']);

        $this->assertSame(1, JournalEntry::where('source', Bank::OPENING)->count(), 'حفظُ الاسم ضاعف القيد');
        $this->assertSame(500.0, $account->fresh()->balance());
    }

    /**
     * وتصحيحُ الرقم عكسٌ وكتابةٌ — لا تعديلُ سطرٍ مُرحَّل.
     *
     * من قرأ ميزانَ أمسِ يجب أن يجد ما قرأه، ويجد إلى جانبه أنّ شيئًا صُحّح.
     */
    public function test_correcting_the_opening_reverses_and_reposts(): void
    {
        $account = $this->create();

        $this->save($account, ['opening_balance' => '800']);

        $this->assertSame(800.0, $account->fresh()->balance());
        $this->assertSame(800.0, $this->equity());
        $this->assertSame(1, JournalEntry::where('source', Bank::OPENING)->whereNull('reversed_at')
            ->whereNull('reverses_id')->count(), 'قيدان حيّان لافتتاحيٍّ واحد');
        $this->assertSame(1, JournalEntry::where('source', Bank::OPENING)->whereNotNull('reversed_at')->count(),
            'القيدُ الأوّل مُحي بدل أن يُعكس');
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /** وتصفيرُه يُخرجه من الدفتر — بعكسٍ لا بحذف */
    public function test_zeroing_the_opening_unwinds_it(): void
    {
        $account = $this->create();

        $this->save($account, ['opening_balance' => '0']);

        $this->assertSame(0.0, $account->fresh()->balance());
        $this->assertSame(0.0, $this->equity());
        $this->assertSame(2, JournalEntry::where('source', 'like', '%'.Bank::OPENING.'%')->count(),
            'الأصلُ وعكسُه يبقيان معًا');
    }

    /** ورصيدٌ افتتاحيٌّ سالب — حسابٌ مكشوف — يُقيَّد بطرفيه مقلوبين */
    public function test_a_negative_opening_is_posted_the_other_way(): void
    {
        $account = $this->create(['opening_balance' => '-120']);

        $this->assertSame(-120.0, $account->balance());
        $this->assertSame(-120.0, $this->equity());
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /* ───────────────────────── الاستدراك ───────────────────────── */

    /** والترحيلُ يُدخل الحسابات القائمة — ولا يُدخلها مرّتين إن أُعيد */
    public function test_the_migration_posts_existing_accounts_once(): void
    {
        $account = BankAccount::create([
            'business_id' => $this->business->id, 'label' => 'قديم',
            'opening_balance' => 2000, 'opening_date' => '2025-06-01',
            'active' => true, 'is_primary' => true,
            'account_id' => Ledger::account($this->business->id, 'bank')->id,
        ]);

        $migration = require base_path('database/migrations/2026_09_19_090000_the_opening_balance_enters_the_ledger.php');
        $migration->up();
        $migration->up();

        $this->assertSame(2000.0, $account->fresh()->balance());
        $this->assertSame(2000.0, $this->equity(), 'الترحيلُ كتب القيد مرّتين');
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /**
     * وما دخل الدفتر بقيدٍ يدويّ لا يدخله الترحيلُ ثانيةً.
     *
     * شكلُ متجر العرض على الإنتاج: صفٌّ بافتتاحيّ، وقيدٌ مصدرُه «افتتاحي»
     * كُتب بيده مقابل رأس المال. ولو رُحّل له قيدٌ ثانٍ لصار في الدفتر ضعفُ
     * ما في البنك.
     */
    public function test_the_migration_skips_a_hand_written_opening(): void
    {
        $leaf = Ledger::account($this->business->id, 'bank');

        $account = BankAccount::create([
            'business_id' => $this->business->id, 'label' => 'العرض',
            'opening_balance' => 12500, 'active' => true, 'is_primary' => true,
            'account_id' => $leaf->id,
        ]);

        Ledger::post($this->business->id, 'رصيد افتتاحيّ — بنك مسقط', [
            ['account' => 'bank', 'debit' => 12500],
            ['account' => 'capital', 'credit' => 12500],
        ], null, 'افتتاحي');

        (require base_path('database/migrations/2026_09_19_090000_the_opening_balance_enters_the_ledger.php'))->up();

        $this->assertSame(12500.0, $account->fresh()->balance(), 'الافتتاحيُّ حُسب مرّتين في الدفتر');
        $this->assertSame(0.0, $this->equity());
    }

    /* ───────────────────────── الحرّاس ───────────────────────── */

    /**
     * والقيدُ يتبع ورقتَه إن تبدّلت.
     *
     * ورقةُ الحساب ليست ثابتة: ترحيلُ «الحساب بلا اسم» نقل ورقةً من صفٍّ إلى
     * صفّ. فلو قيس الاتفاقُ بالمبلغ وحده لبقي الافتتاحيُّ في ورقةٍ هجرها
     * صاحبُها.
     */
    public function test_the_entry_follows_a_moved_leaf(): void
    {
        $account = $this->create();
        $old = (int) $account->account_id;

        $sibling = Account::create([
            'business_id' => $this->business->id,
            'parent_id' => Account::findOrFail($old)->parent_id,
            'code' => '1220', 'name' => 'البنك: آخر', 'type' => 'أصل', 'normal_side' => 'debit',
        ]);
        $account->update(['account_id' => $sibling->id]);

        Bank::syncOpening($account->fresh());

        $this->assertSame(500.0, $account->fresh()->balance(), 'الافتتاحيُّ بقي في ورقةٍ هجرها صاحبُها');
        $this->assertSame(0.0, Account::findOrFail($old)->balance());
        $this->assertSame(500.0, $this->equity(), 'مقابلُ الافتتاحيّ تضاعف مع النقل');
    }

    /**
     * وورقةٌ هُجرت وافتتاحيٌّ صُفّر: يُفرَّغ القيدُ القديم من مكانه.
     *
     * وهذه وحدها تمسك قياسَ المكان: في كلّ الحالات الأخرى يكفي أنّ المبلغ
     * المقيس يُقرأ من سطور الورقة الحاليّة، فيخرج صفرًا حين تتبدّل فيُعاد
     * الترحيل. أمّا حين يكون المطلوبُ صفرًا أيضًا فالرقمان يتّفقان — ويبقى
     * في الدفتر قيدٌ يُدين ورقةً لا يملكها أحد ويُدين مقابله حقوقَ الملكية.
     */
    public function test_a_zeroed_opening_on_a_moved_leaf_is_unwound(): void
    {
        $account = $this->create();
        $old = (int) $account->account_id;

        $sibling = Account::create([
            'business_id' => $this->business->id,
            'parent_id' => Account::findOrFail($old)->parent_id,
            'code' => '1230', 'name' => 'البنك: ثالث', 'type' => 'أصل', 'normal_side' => 'debit',
        ]);
        $account->update(['account_id' => $sibling->id, 'opening_balance' => 0]);

        Bank::syncOpening($account->fresh());

        $this->assertSame(0.0, Account::findOrFail($old)->balance(), 'بقي القيدُ في ورقةٍ هجرها صاحبُها');
        $this->assertSame(0.0, $this->equity(), 'بقي مقابلُ افتتاحيٍّ لا وجود له');
        $this->assertSame(0, JournalEntry::where('source', Bank::OPENING)
            ->whereNull('reversed_at')->whereNull('reverses_id')->count());
    }

    /**
     * وورقةٌ لا يُرحَّل إليها لا يُمسّ قيدُها.
     *
     * لو عُكس القديمُ ثمّ سقطت كتابةُ الجديد لمُحي الافتتاحيُّ من الدفتر
     * صامتًا — لأنّ التاجر أغلق حسابًا في شجرته.
     */
    public function test_a_closed_leaf_leaves_the_entry_alone(): void
    {
        $account = $this->create();
        Account::whereKey($account->account_id)->update(['active' => false]);

        $account->update(['opening_balance' => 900]);
        Bank::syncOpening($account->fresh());

        $this->assertSame(500.0, Account::findOrFail($account->account_id)->balance(), 'مُحي الافتتاحيُّ صامتًا');
        $this->assertSame(500.0, $this->equity());
    }
}
