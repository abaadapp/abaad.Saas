<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Bank;
use App\Support\Cheques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الشيكُ يدخل البنكَ مرّةً — ولو ضُغط «حُصِّل» مرّتين معًا.
 *
 * ═══ العطب ═══
 *
 * `Cheques::clear` تفحص حالَ الشيك **قبل** المعاملة، على النسخة التي في
 * اليد، ثمّ ترحّل القيدَ داخلها بلا أن تُعيد قراءتها:
 *
 *     self::guard($payment, self::PENDING, …);   // على نسخة المنادي
 *     DB::transaction(fn () => Ledger::post(… مدين البنك …));
 *
 * فنداءان يقعان قبل أن يكتب أحدُهما — وهو ما يقع حين يبطؤ الردُّ فيُضغط
 * الزرُّ ثانيةً — يمرّان كلاهما: **يُدين البنكُ مرّتين بشيكٍ واحد**، ويُدان
 * «شيكات تحت التحصيل» مرّتين فيصير سالبًا.
 *
 * وصفُّ الشيك يبدو سليمًا: كلاهما يكتب «محصَّل» بالقيم نفسِها. فالخللُ كلُّه
 * في الطرف الآخر — رصيدٌ بنكيٌّ في الدفتر ضِعفُ ما في الكشف، وحسابٌ وسيطٌ
 * سالبٌ لا معنى له. ولا يُكتشف إلّا عند مطابقة كشف الحساب — إن طوبق.
 *
 * ═══ وأخوه الارتداد سليم ═══
 *
 * `bounce` تمرّ على `Ledger::reverse`، وهي تُعيد قراءة القيد مقفلًا وتردّ
 * `null` إن كان معكوسًا — فالثاني لا يعكس شيئًا. والفرق أنّ `clear` تنادي
 * `Ledger::post` رأسًا، ولا حارسَ فيها.
 */
class AChequeIsBankedOnceTest extends TestCase
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
            'password' => bcrypt('password1'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة التراث', 'phone' => '90000001',
        ]);
        $this->bank = BankAccount::create([
            'business_id' => $this->business->id, 'bank_name' => 'بنك مسقط',
            'label' => 'الحساب الرئيسي', 'active' => true, 'is_primary' => true,
        ]);

        $this->actingAs($this->owner);
    }

    private function cheque(float $total = 100): CustomerPayment
    {
        $invoice = CustomerInvoices::create(
            $this->business->id, $this->customer, [],
            [['description' => 'باقة', 'quantity' => 1, 'unit_price' => $total]],
            $this->owner->id,
        );
        $invoice = CustomerInvoices::issue($invoice, $this->owner->id);

        return CustomerPayments::record(
            $this->business->id, $this->customer, (float) $invoice->total,
            [
                'method' => 'شيك',
                'bank_account_id' => $this->bank->id,
                'occurred_at' => now()->toDateString(),
            ],
            [$invoice->id => (float) $invoice->total],
            $this->owner->id,
        );
    }

    private function clear(CustomerPayment $cheque)
    {
        return $this->post(route('admin.finance.cheques.clear', $cheque->id), [
            'bank_account_id' => $this->bank->id,
        ]);
    }

    private function bounce(CustomerPayment $cheque, string $reason)
    {
        return $this->post(route('admin.finance.cheques.bounce', $cheque->id), ['reason' => $reason]);
    }

    private function balance(string $key): float
    {
        return round(Ledger::balance($this->business->id, $key), 3);
    }

    /**
     * ما في البنك بحسب الدفتر.
     *
     * وورقةُ الحساب حين تكون له ورقةٌ خاصّة، وإلّا فورقةُ «البنك» العامّة —
     * وهو ما تسقط إليه `Cheques::clear` نفسُها.
     */
    private function bankLeaf(): float
    {
        $leaf = Bank::leaf($this->business->id, $this->bank->id);

        return $leaf ? round((float) $leaf->balance(), 3) : $this->balance('bank');
    }

    private function clearingEntries(CustomerPayment $cheque): int
    {
        return JournalEntry::where('business_id', $this->business->id)
            ->where('source', Cheques::SOURCE_CLEAR)->count();
    }

    /**
     * زميلٌ يُحصّله بين قراءتنا وكتابتنا.
     *
     * ويُقاس محسومًا بلا خيطين: يُنصَت لاستعلام الشيك — وهو يقع قبل الحارس
     * وقبل المعاملة — فيُحصَّل كاملًا من تحته. فتمضي نسختُنا بحالٍ تجاوزها.
     */
    private function someoneElseClearsItFirst(CustomerPayment $cheque): void
    {
        $fired = false;

        DB::listen(function ($q) use (&$fired, $cheque) {
            if ($fired || ! str_contains($q->sql, 'customer_payments') || ! str_starts_with(ltrim($q->sql), 'select')) {
                return;
            }
            $fired = true;

            $this->clear($cheque);
        });
    }

    /* ─────────────── ما يجب أن يقع ─────────────── */

    /** البنكُ يُدان بالشيك مرّةً واحدة */
    public function test_the_bank_is_debited_once(): void
    {
        $cheque = $this->cheque(100);
        $amount = round((float) $cheque->amount, 3);

        $this->someoneElseClearsItFirst($cheque);
        $this->clear($cheque);

        $this->assertSame(
            $amount,
            $this->bankLeaf(),
            'دخل الشيكُ البنكَ مرّتين',
        );
    }

    /** والحسابُ الوسيط يُفرَّغ ولا يصير سالبًا */
    public function test_the_holding_account_is_emptied_not_overdrawn(): void
    {
        $cheque = $this->cheque(100);

        $this->someoneElseClearsItFirst($cheque);
        $this->clear($cheque);

        $this->assertSame(0.0, $this->balance(Cheques::ACCOUNT), 'حسابُ الشيكات تحت التحصيل خرج عن الصفر');
    }

    /** ولا يُكتب إلّا قيدُ تحصيلٍ واحد */
    public function test_only_one_clearing_entry_is_written(): void
    {
        $cheque = $this->cheque(100);

        $this->someoneElseClearsItFirst($cheque);
        $this->clear($cheque);

        $this->assertSame(1, $this->clearingEntries($cheque), 'الدفترُ يحمل قيدَي تحصيلٍ لشيكٍ واحد');
    }

    /* ─────────────── والارتدادُ مثلُه ─────────────── */

    /**
     * وارتدادان معًا لا يُقيَّدان ارتدادين.
     *
     * والدفترُ هنا محروسٌ بغيره (`Ledger::reverse` لا تعكس معكوسًا)، لكنّ
     * الثاني كان يكتب «مرتدّ» من جديدٍ ويُقيّد سطرًا ثانيًا في سجلّ النشاط —
     * وهو أوّلُ ما يُقرأ حين يُسأل عن الورقة.
     */
    public function test_two_bounces_at_once_are_recorded_once(): void
    {
        $cheque = $this->cheque(100);

        $fired = false;
        DB::listen(function ($q) use (&$fired, $cheque) {
            if ($fired || ! str_contains($q->sql, 'customer_payments') || ! str_starts_with(ltrim($q->sql), 'select')) {
                return;
            }
            $fired = true;

            $this->bounce($cheque, 'لا رصيد');
        });

        $this->bounce($cheque, 'لا رصيد');

        $this->assertSame(Cheques::BOUNCED, $cheque->fresh()->cheque_status);
        $this->assertSame(
            1,
            DB::table('activity_logs')->where('subject_type', 'customer_payment')
                ->where('subject_id', $cheque->id)
                ->where('description', 'like', '%شيك مرتجع%')->count(),
            'سجلُّ النشاط يحمل ارتدادين لورقةٍ واحدة',
        );
    }

    /** والارتدادُ الهادئ يقع كما كان — الذمّةُ تعود على العميل */
    public function test_a_quiet_bounce_still_returns_the_debt(): void
    {
        $cheque = $this->cheque(100);
        $amount = round((float) $cheque->amount, 3);

        $this->bounce($cheque, 'لا رصيد')->assertSessionHasNoErrors();

        $this->assertSame(Cheques::BOUNCED, $cheque->fresh()->cheque_status);
        $this->assertSame($amount, $this->balance('receivable'), 'لم تعد الذمّةُ على العميل');
        $this->assertSame(0.0, $this->balance(Cheques::ACCOUNT));
    }

    /* ─────────────── وما كان يعمل يبقى ─────────────── */

    /** والتحصيلُ الهادئ يقع كما كان */
    public function test_a_quiet_clearing_still_banks_the_cheque(): void
    {
        $cheque = $this->cheque(100);
        $amount = round((float) $cheque->amount, 3);

        $this->clear($cheque)->assertSessionHasNoErrors();

        $this->assertSame(Cheques::CLEARED, $cheque->fresh()->cheque_status);
        $this->assertSame($amount, $this->bankLeaf());
        $this->assertSame(0.0, $this->balance(Cheques::ACCOUNT));
        $this->assertSame(1, $this->clearingEntries($cheque));
    }

    /** والمحصَّلُ لا يُحصَّل ثانيةً — حارسٌ قائمٌ لا يُكسر بالإصلاح */
    public function test_clearing_twice_in_a_row_is_refused(): void
    {
        $cheque = $this->cheque(100);

        $this->clear($cheque)->assertSessionHasNoErrors();
        $this->clear($cheque)->assertSessionHasErrors();

        $this->assertSame(1, $this->clearingEntries($cheque));
    }

    /** وشيكُ الجار لا يُحصَّل */
    public function test_a_neighbours_cheque_is_not_cleared(): void
    {
        $cheque = $this->cheque(100);

        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = User::create([
            'business_id' => $neighbour->id, 'name' => 'جار', 'email' => 'jar@abaad.om',
            'password' => bcrypt('password1'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($theirs)
            ->post(route('admin.finance.cheques.clear', $cheque->id))
            ->assertNotFound();

        $this->assertSame(Cheques::PENDING, $cheque->fresh()->cheque_status);
    }
}
