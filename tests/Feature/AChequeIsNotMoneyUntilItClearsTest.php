<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Cheques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * الشيكُ ليس مالًا حتّى يُصرَف.
 *
 * ═══ العطب ═══
 *
 * «شيك» كان وسيلةَ تحصيلٍ كغيرها: يُسجَّل فيُرحَّل **مدين البنك / دائن ذمم
 * العملاء** لحظتَه. أي أنّ الدفتر يشهد بمالٍ في البنك يومَ استلام الورقة —
 * وقد تكون بتاريخٍ بعد شهرين، وقد ترتدّ.
 *
 * فيقرأ التاجر رصيدًا بنكيًّا لا وجود له، وعميلًا سدّد ولم يسدّد. **ولا شيء
 * يشكو**: القيدُ متوازن، والورقةُ مقفلة، والذمّةُ صفر. ولا يُكتشف حتّى يطابق
 * كشفَ حسابه — إن طابقه.
 *
 * ═══ وما تحرسه هذه الملفّات ═══
 *
 * أنّ المال يجلس في «شيكات تحت التحصيل» حتّى يُقرّ إنسانٌ أنّه رآه في كشفه،
 * وأنّ الارتداد يُعيد الذمّة على العميل في الدفتر **وفي الورقة معًا** — فلا
 * يقول أحدُهما «مدفوعة» والآخر «عليه دَين».
 */
class AChequeIsNotMoneyUntilItClearsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

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

        $this->bank = BankAccount::create([
            'business_id' => $this->business->id, 'bank_name' => 'بنك مسقط',
            'label' => 'الحساب الرئيسي', 'active' => true, 'is_primary' => true,
        ]);
    }

    private function issued(float $total = 100): CustomerInvoice
    {
        $invoice = CustomerInvoices::create(
            $this->business->id, $this->customer, [],
            [['description' => 'باقة', 'quantity' => 1, 'unit_price' => $total]],
            $this->owner->id,
        );

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    private function pay(CustomerInvoice $invoice, string $method = 'شيك', array $extra = []): CustomerPayment
    {
        return CustomerPayments::record(
            $this->business->id, $this->customer, (float) $invoice->total,
            array_merge([
                'method' => $method,
                'bank_account_id' => $this->bank->id,
                'occurred_at' => now()->toDateString(),
            ], $extra),
            [$invoice->id => (float) $invoice->total],
            $this->owner->id,
        );
    }

    private function balance(string $key): float
    {
        return round(Ledger::balance($this->business->id, $key), 3);
    }

    /* ═══════════════════ القبض ═══════════════════ */

    /**
     * شيكٌ مقبوضٌ لا يدخل البنك — يجلس في «شيكات تحت التحصيل».
     *
     * وهذا هو العطبُ كلُّه في سطرين: رصيدُ البنك صفر، والوسيطُ بالمبلغ.
     */
    public function test_a_received_cheque_does_not_enter_the_bank(): void
    {
        $invoice = $this->issued(100);
        $this->pay($invoice);

        // والإجماليُّ يُقرأ من الورقة لا يُكتب هنا: الضريبةُ تزيده وهي إعدادُ متجر
        $this->assertSame((float) $invoice->total, $this->balance(Cheques::ACCOUNT), 'الشيك لم يجلس في الحساب الوسيط');
        $this->assertSame(0.0, $this->balance('bank'), 'دخل الشيكُ البنكَ قبل أن يُصرَف');
    }

    /** والذمّةُ تنتقل من العميل إلى الورقة — فحساب الذمم ينقص */
    public function test_the_debt_moves_from_the_customer_to_the_paper(): void
    {
        $invoice = $this->issued(100);
        $this->assertSame((float) $invoice->total, $this->balance('receivable'));

        $this->pay($invoice);

        $this->assertSame(0.0, $this->balance('receivable'), 'بقيت الذمّة على العميل بعد قبض الشيك');
    }

    /** ويُولد «تحت التحصيل» — لا محصَّلًا */
    public function test_a_cheque_is_born_pending(): void
    {
        $payment = $this->pay($this->issued());

        $this->assertSame(Cheques::PENDING, $payment->cheque_status);
    }

    /**
     * وغيرُ الشيك بلا حالٍ أصلًا — و`null` لا «محصَّل».
     *
     * النقدُ لا يُحصَّل ولا يرتدّ. ووسمُه بحالِ شيكٍ يجعل كلَّ عدٍّ للشيكات
     * يبتلع تحصيلات المتجر كلَّها.
     */
    public function test_other_methods_carry_no_cheque_status(): void
    {
        $cashInvoice = $this->issued();
        $cash = $this->pay($cashInvoice, 'نقدي');
        $card = $this->pay($this->issued(), 'بطاقة');

        $this->assertNull($cash->cheque_status);
        $this->assertNull($card->cheque_status);
        $this->assertSame((float) $cashInvoice->total, $this->balance('cash'), 'النقدُ لم يدخل الصندوق');
        $this->assertSame(0.0, $this->balance(Cheques::ACCOUNT), 'دخل غيرُ الشيك حسابَ الشيكات');
    }

    /** واستحقاقُه يُحفظ إن كُتب */
    public function test_the_due_date_is_kept(): void
    {
        $due = now()->addDays(45)->toDateString();
        $payment = $this->pay($this->issued(), 'شيك', ['cheque_due_at' => $due]);

        $this->assertSame($due, Carbon::parse($payment->cheque_due_at)->toDateString());
    }

    /* ═══════════════════ الصرف ═══════════════════ */

    /** وحين يُصرَف ينتقل المال من الورقة إلى البنك — لا يُضاف مرّتين */
    public function test_clearing_moves_the_money_into_the_bank(): void
    {
        $invoice = $this->issued(100);
        $payment = $this->pay($invoice);

        Cheques::clear($payment, now()->toDateString(), $this->bank->id, $this->owner->id);

        $this->assertSame(0.0, $this->balance(Cheques::ACCOUNT), 'بقي المال في الحساب الوسيط بعد الصرف');
        $this->assertSame((float) $invoice->total, $this->balance('bank'), 'لم يصل المال إلى البنك');
        $this->assertSame(Cheques::CLEARED, $payment->fresh()->cheque_status);
    }

    /** ولا يُصرَف شيكٌ صُرِف — القيدُ لا يُرحَّل مرّتين */
    public function test_a_cleared_cheque_cannot_clear_again(): void
    {
        $payment = $this->pay($this->issued(100));
        Cheques::clear($payment, null, $this->bank->id, $this->owner->id);

        $this->expectException(RuntimeException::class);
        Cheques::clear($payment->fresh(), null, $this->bank->id, $this->owner->id);
    }

    /** والحسابُ قد يتغيّر: الشيكُ يُودَع في غير الحساب الذي كُتب يوم استلامه */
    public function test_the_cheque_may_be_deposited_in_another_account(): void
    {
        $other = BankAccount::create([
            'business_id' => $this->business->id, 'bank_name' => 'بنك ظفار',
            'label' => 'حساب التشغيل', 'active' => true, 'is_primary' => false,
        ]);

        $payment = $this->pay($this->issued(100));
        Cheques::clear($payment, null, $other->id, $this->owner->id);

        $this->assertSame($other->id, (int) $payment->fresh()->bank_account_id);
    }

    /** ولا يُودَع في حساب متجرٍ آخر */
    public function test_it_refuses_a_foreign_bank_account(): void
    {
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = BankAccount::create([
            'business_id' => $neighbour->id, 'bank_name' => 'بنك الجار',
            'label' => 'حسابهم', 'active' => true,
        ]);

        $payment = $this->pay($this->issued(100));

        $this->expectException(RuntimeException::class);
        Cheques::clear($payment, null, $theirs->id, $this->owner->id);
    }

    /* ═══════════════════ الارتداد ═══════════════════ */

    /**
     * وحين يرتدّ تعود الذمّةُ على العميل — في الدفتر.
     *
     * والعكسُ لا المحو: تاريخُ المال يبقى مقروءًا.
     */
    public function test_a_bounce_returns_the_debt_to_the_customer(): void
    {
        $invoice = $this->issued(100);
        $payment = $this->pay($invoice);
        $this->assertSame(0.0, $this->balance('receivable'));

        Cheques::bounce($payment, 'عدم كفاية الرصيد', $this->owner->id);

        $this->assertSame((float) $invoice->total, $this->balance('receivable'), 'لم تعد الذمّة على العميل');
        $this->assertSame(0.0, $this->balance(Cheques::ACCOUNT), 'بقي المال في الحساب الوسيط بعد الارتداد');
        $this->assertSame(0.0, $this->balance('bank'), 'دخل البنكَ مالُ شيكٍ رجع');
    }

    /**
     * وفي الورقة أيضًا — وهذا أخطرُ ما في الملفّ.
     *
     * لو عاد الدفتر وحده لَقالت الفاتورة «مدفوعة» ويقول الدفتر «عليه دَين».
     * رقمان يقولان الشيء نفسه ويفترقان، ولا يُعرف أيُّهما الصادق — ويبقى
     * العميل يُعامَل كمن سدّد.
     */
    public function test_a_bounce_reopens_the_invoice(): void
    {
        $invoice = $this->issued(100);
        $payment = $this->pay($invoice);

        $this->assertSame(0.0, $invoice->fresh()->outstanding(), 'لم تُقفل الورقة بالشيك');

        Cheques::bounce($payment, 'توقيع غير مطابق', $this->owner->id);

        $this->assertSame((float) $invoice->total, $invoice->fresh()->outstanding(), 'بقيت الورقة مسدَّدة بشيكٍ رجع');
    }

    /**
     * وقراءتا «المدفوع» تتّفقان.
     *
     * `paidSql` تُحقن في `whereRaw` للقوائم، و`paidTotal` تُقرأ لصفٍّ واحد.
     * وافتراقُهما يعني ورقةً تقول «مدفوعة» في الشاشة و«عليها باقٍ» في القائمة.
     */
    public function test_both_readings_of_paid_agree(): void
    {
        $invoice = $this->issued(100);
        $payment = $this->pay($invoice);
        Cheques::bounce($payment, 'رصيد', $this->owner->id);

        $viaRow = $invoice->fresh()->outstanding();
        $viaSql = (float) CustomerInvoice::whereKey($invoice->id)
            ->selectRaw(CustomerInvoice::outstandingSql().' as due')->value('due');

        $this->assertSame($viaRow, round($viaSql, 3), 'القراءتان تفترقان');
    }

    /** والسببُ يُطلب ولا يُترك فارغًا */
    public function test_a_bounce_needs_a_reason(): void
    {
        $payment = $this->pay($this->issued());

        $this->expectException(RuntimeException::class);
        Cheques::bounce($payment, '   ', $this->owner->id);
    }

    /** ولا يرتدّ ما صُرِف */
    public function test_a_cleared_cheque_cannot_bounce(): void
    {
        $payment = $this->pay($this->issued());
        Cheques::clear($payment, null, $this->bank->id, $this->owner->id);

        $this->expectException(RuntimeException::class);
        Cheques::bounce($payment->fresh(), 'رصيد', $this->owner->id);
    }

    /** ولا يُحرَّك ما ليس شيكًا */
    public function test_a_card_payment_is_not_a_cheque(): void
    {
        $payment = $this->pay($this->issued(), 'بطاقة');

        $this->expectException(RuntimeException::class);
        Cheques::clear($payment, null, $this->bank->id, $this->owner->id);
    }

    /* ═══════════════════ الدفتر والشجرة ═══════════════════ */

    /** والحسابُ يُستدرك لمتجرٍ شجرتُه أقدمُ من هذه النسخة */
    public function test_an_old_chart_gains_the_account_when_needed(): void
    {
        /*
         * والورقةُ تُصدَر أوّلًا ثمّ يُحذف الحساب.
         *
         * كان الحذفُ قبلها فتُعيده `issue` من طريقها — فيمرّ الحارسُ على
         * حسابٍ أعاده غيرُ من يُراد اختبارُه، ولا يقيس شيئًا.
         */
        $invoice = $this->issued(100);

        Account::where('business_id', $this->business->id)
            ->where('system_key', Cheques::ACCOUNT)->delete();

        $this->pay($invoice);

        $this->assertTrue(
            Account::where('business_id', $this->business->id)
                ->where('system_key', Cheques::ACCOUNT)->exists(),
            'لم يُستدرك حسابُ الشيكات',
        );
        $this->assertSame((float) $invoice->total, $this->balance(Cheques::ACCOUNT));
    }

    /** والقيدُ يحمل مصدرَه فيُعرف من أين جاء بعد سنة */
    public function test_the_entries_name_their_source(): void
    {
        $payment = $this->pay($this->issued(100));
        Cheques::clear($payment, null, $this->bank->id, $this->owner->id);

        $this->assertTrue(
            JournalEntry::where('business_id', $this->business->id)
                ->where('source', Cheques::SOURCE_CLEAR)->exists(),
            'قيدُ الصرف بلا مصدرٍ يُعرف',
        );
    }

    /* ═══════════════════ الشاشة ═══════════════════ */

    public function test_the_screen_lists_pending_cheques(): void
    {
        $invoice = $this->issued(100);
        $this->pay($invoice, 'شيك', ['cheque_due_at' => now()->addDays(10)->toDateString()]);

        $this->actingAs($this->owner)->get(route('admin.finance.cheques'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('status', Cheques::PENDING)
                ->has('cheques', 1)
                ->where('cheques.0.amount', fn ($v) => (float) $v === (float) $invoice->total)
                ->where('summary.pending_count', 1)
                ->where('summary.pending_total', fn ($v) => (float) $v === (float) $invoice->total)
                ->etc());
    }

    /**
     * والمتأخّرُ يُحسب في الخادم لا في المتصفّح.
     *
     * ساعةُ الجهاز تُضبط بيد صاحبها، وشاشةٌ تقرأ «متأخّر» من ساعةٍ مقدَّمةٍ
     * يومين تُري التاجر تأخّرًا لم يقع — فيتّصل ببنكه بلا سبب.
     */
    public function test_the_server_decides_what_is_overdue(): void
    {
        $this->pay($this->issued(100), 'شيك', ['cheque_due_at' => now()->subDay()->toDateString()]);

        $this->actingAs($this->owner)->get(route('admin.finance.cheques'))
            ->assertInertia(fn ($p) => $p
                ->where('cheques.0.overdue', true)
                ->where('summary.overdue_count', 1)
                ->etc());
    }

    /** ومن يستحقّ اليوم لا يتأخّر اليوم */
    public function test_due_today_is_not_yet_overdue(): void
    {
        $this->pay($this->issued(100), 'شيك', ['cheque_due_at' => now()->toDateString()]);

        $this->actingAs($this->owner)->get(route('admin.finance.cheques'))
            ->assertInertia(fn ($p) => $p
                ->where('cheques.0.overdue', false)
                ->where('summary.overdue_count', 0)
                ->etc());
    }

    /** والشاشةُ تحمل حسابات المتجر بأسمائها — لا قائمةً فارغة */
    public function test_the_screen_carries_named_bank_accounts(): void
    {
        $this->actingAs($this->owner)->get(route('admin.finance.cheques'))
            ->assertInertia(fn ($p) => $p
                ->has('bankAccounts', 1)
                ->where('bankAccounts.0.label', 'الحساب الرئيسي')
                ->etc());
    }

    /** ولا يُحرَّك شيكُ متجرٍ آخر من هذا الباب */
    public function test_a_foreign_cheque_is_not_found(): void
    {
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($neighbour->id);
        $theirCustomer = Customer::create(['business_id' => $neighbour->id, 'name' => 'زبونهم', 'phone' => '90000002']);
        $theirs = CustomerPayments::record(
            $neighbour->id, $theirCustomer, 50,
            ['method' => 'شيك', 'occurred_at' => now()->toDateString()], [], null,
        );

        $this->actingAs($this->owner)
            ->post(route('admin.finance.cheques.bounce', $theirs->id), ['reason' => 'رصيد'])
            ->assertNotFound();

        $this->assertSame(Cheques::PENDING, $theirs->fresh()->cheque_status, 'تحرّك شيكُ متجرٍ آخر');
    }

    /** والبابُ يردّ الارتدادَ بلا سبب */
    public function test_the_door_refuses_a_reasonless_bounce(): void
    {
        $payment = $this->pay($this->issued());

        $this->actingAs($this->owner)
            ->post(route('admin.finance.cheques.bounce', $payment->id), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(Cheques::PENDING, $payment->fresh()->cheque_status);
    }

    /** والواجهةُ تسمّي الشيك كما يسمّيه الخادم */
    public function test_the_screen_names_the_cheque_as_the_server_does(): void
    {
        $source = file_get_contents(resource_path('js/lib/cheques.ts'));

        $this->assertStringContainsString("'".Cheques::METHOD."'", $source, 'اسمُ الوسيلة في الواجهة يخالف الخادم');
    }
}
