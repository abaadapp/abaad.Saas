<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Support\Bank;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الورقةُ تتبع البنكَ الذي دخله المال.
 *
 * ═══ العطب ═══
 *
 * لكلّ حسابٍ بنكيّ ورقتُه في الشجرة — هكذا كُتب `leafFor` عمدًا، و
 * `BankAccount::balance()` تقرأ ورقةَ حسابها هي. لكنّ **كلّ** ترحيلٍ بنكيّ
 * في النظام يقول `'bank'`، والمفتاح النظاميّ يقصد ورقةً واحدة (1200)
 * يملكها أوّلُ حسابٍ وُجد. فالتصميمُ كان نصفَين لا يلتقيان.
 *
 * وأثرُه في متجرٍ حيّ: يفتح التاجر «المالية» فيُولَد له — من القراءة —
 * حسابٌ بلا اسمٍ يملك ورقة «البنك». ثمّ يضيف حسابه الحقيقيّ «بنك مسقط»
 * فيأخذ ورقةً أختًا. فيبيع بالبطاقة شهرًا كاملًا، ويقرأ في شاشة المالية
 * «بنك مسقط: ٢٠٠٠» لا يتحرّك، و«حساب بنكي: ١٤٧» لا يعرف ما هو.
 *
 * ═══ والإصلاح في السطر الذي يقرأ الحساب ═══
 *
 * لا في مواضع الترحيل: سبعةٌ منها تُنسى ثامنتُها. فـ`Ledger::post` وحدها
 * تترجم `'bank'` إلى ورقة الحساب الرئيسيّ، و`CustomerPayments` تمرّر ورقة
 * الحساب الذي سمّاه المستخدم بعينه.
 */
class TheBankLeafFollowsTheBankThatTookTheMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة التراث', 'phone' => '90000001',
        ]);

        $this->actingAs($this->owner);
    }

    /** حسابٌ يُضاف من الشاشة نفسها ليأخذ ورقته كما يأخذها التاجر */
    private function add(string $label, float $opening = 0): BankAccount
    {
        $this->post(route('admin.finance.banks.store'), [
            'label' => $label, 'bank_name' => $label, 'opening_balance' => $opening,
        ])->assertSessionHasNoErrors();

        return BankAccount::where('business_id', $this->business->id)->latest('id')->firstOrFail();
    }

    /* ─────────────────── لا يُخترع حسابٌ عند القراءة ─────────────────── */

    /** فتحُ شاشة المالية لا يُولّد حسابًا بنكيًّا بلا اسم */
    public function test_reading_the_finance_screen_creates_no_nameless_account(): void
    {
        $this->get(route('admin.finance.index'))->assertOk();
        $this->get(route('admin.finance.index'))->assertOk();

        $this->assertSame(
            0,
            BankAccount::where('business_id', $this->business->id)->count(),
            'وُلد حسابٌ بنكيّ من قراءةٍ — فحُجبت الحالة الفارغة التي تدعو التاجر لإضافة حسابه'
        );
    }

    /** والشاشةُ تعرض حالتَها الفارغة: لا حسابات ولا رصيد */
    public function test_the_empty_state_is_reachable(): void
    {
        $this->get(route('admin.finance.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->has('accounts', 0)->where('summary.count', 0)->etc());
    }

    /** وكشفُ الحساب بلا حسابٍ يردّ إلى البابِ الذي يُنشئه — ولا يخترعه */
    public function test_the_statement_without_an_account_sends_you_to_the_door(): void
    {
        $this->get(route('admin.finance.statement'))
            ->assertRedirect(route('admin.finance.index'));

        $this->assertSame(0, BankAccount::where('business_id', $this->business->id)->count());
    }

    /** والاستيراد بلا حسابٍ يُردّ برسالةٍ تقول لماذا */
    public function test_clearing_a_statement_without_an_account_is_refused(): void
    {
        $this->from(route('admin.finance.index'))
            ->delete(route('admin.bank.clear'))
            ->assertRedirect(route('admin.finance.index'));

        $this->assertSame(0, BankAccount::where('business_id', $this->business->id)->count());
    }

    /* ─────────────────── الورقةُ تتبع الحسابَ المسمّى ─────────────────── */

    /** تحصيلٌ إلى حسابٍ بعينه يرفع رصيدَ ذلك الحساب — لا رصيدَ جاره */
    public function test_a_collection_named_to_an_account_moves_that_accounts_balance(): void
    {
        $first = $this->add('بنك مسقط', 100);
        $second = $this->add('بنك ظفار', 200);

        CustomerPayments::record($this->business->id, $this->customer, 50, [
            'method' => 'تحويل', 'bank_account_id' => $second->id,
        ], [], $this->owner->id);

        $this->assertSame(100.0, $first->fresh()->balance(), 'ارتفع رصيدُ حسابٍ لم يدخله ريال');
        $this->assertSame(250.0, $second->fresh()->balance(), 'دخل المالُ حسابًا ولم يظهر في رصيده');
    }

    /** وحسابان لا يجمعان مالَهما في ورقةٍ واحدة */
    public function test_two_accounts_do_not_share_one_leaf(): void
    {
        $first = $this->add('بنك مسقط');
        $second = $this->add('بنك ظفار');

        $this->assertNotSame(
            $first->fresh()->account_id,
            $second->fresh()->account_id,
            'حسابان على ورقةٍ واحدة يجمعان رصيدهما فلا يُعرف ما في كلٍّ منهما'
        );
    }

    /** وما لا يُنسب إلى حسابٍ يدخل الرئيسيّ — لا أوّل صفٍّ في الجدول */
    public function test_an_unnamed_collection_enters_the_primary_account(): void
    {
        $first = $this->add('بنك مسقط', 100);
        $second = $this->add('بنك ظفار', 200);
        $this->post(route('admin.finance.banks.primary', $second->id));

        CustomerPayments::record($this->business->id, $this->customer, 40, [
            'method' => 'بطاقة',
        ], [], $this->owner->id);

        $this->assertSame(100.0, $first->fresh()->balance());
        $this->assertSame(240.0, $second->fresh()->balance(), 'دخل المالُ البنكَ ولم يظهر في الحساب الرئيسيّ');
    }

    /** والحركةُ اليدوية من شاشة المالية تتبع القاعدة نفسها */
    public function test_a_manual_bank_movement_enters_the_primary_account(): void
    {
        $first = $this->add('بنك مسقط', 100);
        $second = $this->add('بنك ظفار', 200);
        $this->post(route('admin.finance.banks.primary', $second->id));

        $this->post(route('admin.finance.store'), [
            'kind' => 'owner_deposit', 'amount' => 60, 'side' => 'bank',
            'description' => 'إيداع المالك',
        ])->assertSessionHasNoErrors();

        $this->assertSame(100.0, $first->fresh()->balance());
        $this->assertSame(260.0, $second->fresh()->balance(), 'إيداعٌ في البنك لا يظهر في الحساب الرئيسيّ');
    }

    /** والنقدُ لا يمرّ بأيّ حسابٍ بنكيّ مهما تعدّدت */
    public function test_cash_touches_no_bank_leaf(): void
    {
        $only = $this->add('بنك مسقط', 100);

        CustomerPayments::record($this->business->id, $this->customer, 30, [
            'method' => 'نقدي',
        ], [], $this->owner->id);

        $this->assertSame(100.0, $only->fresh()->balance());
        $this->assertSame(30.0, Ledger::account($this->business->id, 'cash')->balance());
    }

    /* ─────────────────── ولا تسقط بيعةٌ حين تُغلق ورقة ─────────────────── */

    /**
     * ورقةٌ مغلقةٌ لا تُسقط الترحيل.
     *
     * التاجرُ يغلق ورقةً في شجرة الحسابات ولا يربط ذلك ببيعةٍ بالبطاقة عند
     * الكاشير. فيسقط الترحيل إلى الورقة النظامية: قيدٌ في مكانٍ غير دقيق
     * خيرٌ من بيعةٍ تُردّ في وجه الكاشير برسالةٍ لا يفهمها.
     */
    public function test_a_closed_leaf_falls_back_to_the_system_leaf_and_the_sale_stands(): void
    {
        $first = $this->add('بنك مسقط');
        $second = $this->add('بنك ظفار');
        $this->post(route('admin.finance.banks.primary', $second->id));

        Account::whereKey($second->fresh()->account_id)->update(['active' => false]);

        CustomerPayments::record($this->business->id, $this->customer, 25, [
            'method' => 'تحويل',
        ], [], $this->owner->id);

        $this->assertSame(
            25.0,
            Ledger::account($this->business->id, 'bank')->balance(),
            'سقط الترحيل حين أُغلقت ورقة الحساب الرئيسيّ — فتُردّ البيعة عند الكاشير'
        );
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /** ومتجرٌ بلا حسابٍ بنكيّ يُرحّل إلى ورقة «البنك» النظامية كما كان */
    public function test_a_shop_with_no_bank_account_still_posts(): void
    {
        CustomerPayments::record($this->business->id, $this->customer, 15, [
            'method' => 'بطاقة',
        ], [], $this->owner->id);

        $this->assertSame(15.0, Ledger::account($this->business->id, 'bank')->balance());
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /* ─────────────────── والميزانُ لا يختلّ ─────────────────── */

    /**
     * ورقةُ الحساب الثاني أختٌ لورقة «البنك» لا ابنة.
     *
     * الأوّلُ يأخذ الورقة النظامية نفسها — فلا ازدواج. والثاني يأخذ أختًا
     * لها تحت «الأصول»: لو صارت ابنةً لجُمع مالُها مرّتين، فيها وفي مجموع
     * أبناء 1200.
     */
    public function test_the_balance_sheet_counts_the_money_once(): void
    {
        $this->add('بنك مسقط', 0);
        $second = $this->add('بنك ظفار', 0);

        CustomerPayments::record($this->business->id, $this->customer, 70, [
            'method' => 'تحويل', 'bank_account_id' => $second->id,
        ], [], $this->owner->id);

        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
        $this->assertSame(70.0, $second->fresh()->balance());
        $this->assertSame(
            0.0,
            Ledger::account($this->business->id, 'bank')->balance(),
            'دخل المالُ ورقتين معًا فتضاعف البنك في الميزانية'
        );
        $system = Ledger::account($this->business->id, 'bank');
        $leaf = Account::findOrFail($second->fresh()->account_id);

        $this->assertNotNull($leaf->parent_id, 'ورقةُ الحساب خرجت من شجرة الأصول فغاب مالُها عن الميزانية');
        $this->assertSame($system->parent_id, $leaf->parent_id, 'ورقةُ الحساب صارت ابنةً لـ1200 فيُجمع مالُها مرّتين');
    }

    /* ─────────────────── ومجموعُ البنك رقمٌ واحد ─────────────────── */

    /** شاشةُ الحسابات وشاشةُ الملخّص تقولان الرقم نفسه */
    public function test_both_screens_read_one_bank_total(): void
    {
        $this->add('بنك مسقط', 100);
        $second = $this->add('بنك ظفار', 200);

        // حسابٌ أُوقف وفيه رصيد — وكانت شاشةٌ تعدّه والأخرى تُسقطه
        $this->put(route('admin.finance.banks.update', $second->id), [
            'label' => 'بنك ظفار', 'opening_balance' => 200, 'active' => false,
        ])->assertSessionHasNoErrors();

        $banks = $this->get(route('admin.finance.index'))->assertOk()
            ->viewData('page')['props']['summary']['balance'];
        $summary = $this->get(route('admin.finance.summary'))->assertOk()
            ->viewData('page')['props']['bank'];

        $this->assertSame(300.0, $banks, 'رصيدُ حسابٍ موقوفٍ سقط من مجموع الشاشة');
        $this->assertSame($banks, $summary, 'شاشتان ترسمان «مجموع الأرصدة» برقمين');
    }

    /** ومالٌ في ورقة «البنك» لا يملكها حساب يُعدّ — ولا يُعدّ مرّتين */
    public function test_bank_money_with_no_account_is_still_counted(): void
    {
        CustomerPayments::record($this->business->id, $this->customer, 55, [
            'method' => 'بطاقة',
        ], [], $this->owner->id);

        $this->assertSame(55.0, Bank::total($this->business->id), 'مالٌ في الدفتر لا تراه شاشة المالية');

        // ثمّ يسجّل التاجر حسابه: يرث الورقة نفسها فلا يُجمع المال مرّتين
        $account = $this->add('بنك مسقط', 0);

        $this->assertSame((int) $account->account_id, Ledger::account($this->business->id, 'bank')->id);
        $this->assertSame(55.0, Bank::total($this->business->id), 'عُدّ المالُ مرّتين: في الحساب وفي الورقة');
    }

    /* ─────────────────── وBank::leaf وحدها ─────────────────── */

    /** ولا تُنشئ `Bank::leaf` شيئًا ولا تخترع حسابًا */
    public function test_the_leaf_reader_creates_nothing(): void
    {
        $this->assertNull(Bank::leaf($this->business->id));
        $this->assertNull(Bank::current($this->business->id));
        $this->assertSame(0, BankAccount::where('business_id', $this->business->id)->count());
    }

    /** وحسابُ متجرٍ آخر لا تُردّ ورقتُه */
    public function test_another_shops_account_is_not_resolved(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        $theirs = BankAccount::create([
            'business_id' => $other->id, 'label' => 'حسابهم', 'is_primary' => true,
            'account_id' => Ledger::account($other->id, 'bank')->id,
        ]);

        $this->assertNull(Bank::leaf($this->business->id, $theirs->id));
    }
}
