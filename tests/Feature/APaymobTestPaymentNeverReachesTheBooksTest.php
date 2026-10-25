<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PaymentGateway;
use App\Models\PaymobSettlement;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\PaymobSettlements;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * دفعةُ تجربةٍ من Paymob تُغلق رابطَها — ولا تصل الدفاتر ولا التسوية البنكيّة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - رابطٌ فُتح ببوّابةٍ في بيئة التجربة ثمّ دُفع بإشعارٍ موقَّع: «مدفوع» بعمليّته،
 *     بلا تحصيلٍ ولا قيدٍ ولا تخصيصٍ ولا نقاط، والفاتورةُ كما كانت.
 *   - وإعادةُ Paymob الإشعارَ لا تكتب شيئًا، ودفعةٌ ثانيةٌ تُوسم للمراجعة.
 *   - والرابطُ الحيّ بجواره يُحصَّل فورًا في الطلب نفسه كما كان.
 *   - وبيئةُ الإشعار (خارج التوقيع) لا تجعل رابطَ تجربةٍ حيًّا ولا العكس.
 *   - والتسوية: رابطُ التجربة لا يُعرض ولا يُقبل — ولو كُتب له تحصيلٌ قديم،
 *     يبقى قيدُه كما هو لا يُعكس آليًّا.
 *   - والعزل: لكلّ متجرٍ مالُه وتجاربُه.
 */
class APaymobTestPaymentNeverReachesTheBooksTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $ministry;

    private int $tx = 7000;

    private int $order = 616100000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@testmode.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->ministry = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

        foreach ([$this->shop, $this->other] as $shop) {
            PaymentGateway::create([
                'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
                'public_key' => 'pk_'.$shop->id, 'secret_key' => 'sk_'.$shop->id, 'hmac_secret' => 'hmac-'.$shop->id,
                'card_integration_id' => '111', 'api_key' => 'api-'.$shop->id, 'mode' => PaymentGateway::TEST,
            ]);
        }

        // ولكلّ رابطٍ طلبُ Paymob خاصٌّ به — به يُعرف في الإشعار
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => fn () => Http::response([
                'id' => 580010, 'shorten_url' => 'https://paymob.link/t'.$this->order, 'state' => 'created',
                'expires_at' => '2026-11-01T23:59:00', 'order' => ++$this->order,
            ]),
        ]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function mode(Business $shop, string $mode): void
    {
        PaymentGateway::where('business_id', $shop->id)->update(['mode' => $mode]);
    }

    /** فاتورةٌ صادرة بـ١٠٠ — الضريبةُ مطفأة */
    private function invoice(?Business $shop = null, ?Customer $customer = null): CustomerInvoice
    {
        $shop ??= $this->shop;
        $invoice = CustomerInvoices::create($shop->id, $customer ?? $this->ministry, ['issued_at' => '2026-10-24'], [
            ['description' => 'توريد زهور', 'quantity' => 2, 'unit_price' => 50],
        ]);

        return CustomerInvoices::issue($invoice);
    }

    private function link(?CustomerInvoice $invoice = null, ?float $amount = null, ?Business $shop = null, ?Customer $customer = null): CustomerPaymentLink
    {
        $shop ??= $this->shop;

        return PaymentLinks::create($shop->id, $customer ?? $this->ministry, $invoice, $amount, null, $this->owner->id);
    }

    /** إشعارُ Paymob — `is_live` كما تقولها Paymob، وهو حقلٌ خارج التوقيع */
    private function obj(CustomerPaymentLink $link, array $over = []): array
    {
        $obj = array_replace_recursive([
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-10-25T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => ++$this->tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => (bool) $link->is_live,
        ], $over);

        return $obj;
    }

    private function hook(array $obj, ?Business $shop = null): void
    {
        $secret = 'hmac-'.($shop ?? $this->shop)->id;
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, $secret), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    /** ما في الدفاتر وما على الفواتير لمتجر — يُقارن قبل الدفع وبعده */
    private function books(?Business $shop = null): array
    {
        $bid = ($shop ?? $this->shop)->id;

        return [
            'payments' => CustomerPayment::where('business_id', $bid)->count(),
            'allocations' => CustomerPaymentAllocation::whereIn('customer_invoice_id', CustomerInvoice::where('business_id', $bid)->select('id'))->count(),
            'entries' => JournalEntry::where('business_id', $bid)->count(),
            'lines' => JournalLine::whereIn('journal_entry_id', JournalEntry::where('business_id', $bid)->select('id'))->count(),
            'receivable' => round(Ledger::balance($bid, 'receivable'), 3),
            'clearing' => round(Ledger::balance($bid, 'paymob_clearing'), 3),
            'bank' => round(Ledger::balance($bid, 'bank'), 3),
            'cash' => round(Ledger::balance($bid, 'cash'), 3),
            'revenue' => round(Ledger::netRevenue($bid), 3),
            'settlements' => PaymobSettlement::where('business_id', $bid)->count(),
            'outstanding' => CustomerInvoice::where('business_id', $bid)->get()->map(fn (CustomerInvoice $i) => [$i->number, $i->status, round($i->outstanding(), 3)])->all(),
        ];
    }

    private function candidates(?Business $shop = null): array
    {
        return array_column(PaymobSettlements::candidates(($shop ?? $this->shop)->id), 'key');
    }

    /* ═══════════ ١ · دفعةُ التجربة تُغلق رابطَها ولا تكتب في الدفاتر ═══════════ */

    public function test_a_test_card_payment_closes_its_link_and_writes_nothing_in_the_books(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);
        $this->assertFalse($link->is_live, 'بوّابةُ تجربة تفتح رابطَ تجربة');

        $before = $this->books();
        $this->hook($this->obj($link));
        $link->refresh();

        $this->assertSame(CustomerPaymentLink::PAID, $link->status, 'من يجرّب يرى أنّ الدفعَ وصل');
        $this->assertSame((string) $this->tx, $link->provider_transaction_id);
        $this->assertNotNull($link->paid_at);
        $this->assertNull($link->customer_payment_id, 'دفعةُ تجربةٍ كُتبت تحصيلًا');
        $this->assertNull($link->error);
        $this->assertArrayNotHasKey('loyalty', (array) $link->meta, 'دفعةُ تجربةٍ تنتظر نقاطًا');
        $this->assertSame($before, $this->books(), 'دفعةُ تجربةٍ مسّت الدفاتر أو الفاتورة');
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->fresh()->status);
        $this->assertSame(100.0, round($invoice->fresh()->outstanding(), 3));

        // يُقال في السجلّ أنّها تجربة — وفي صفّ الرابط بلا تحصيل
        $this->assertTrue(ActivityLog::where('subject_type', 'customer_payment_link')->where('subject_id', $link->id)
            ->where('description', 'like', '%تجربة%')->exists());
        $row = PaymentLinks::row($link->fresh(['customer', 'invoice', 'payment']));
        $this->assertSame(['paid', false, null], [$row['status'], $row['is_live'], $row['payment']]);

        // ولا يرنّ جرسُ «وصلت دفعة»: لم يصل مال
        $this->assertNull(collect(Demo::notifications())->firstWhere('key', 'link-paid-'.$link->id));

        // والفاتورةُ تُطلب برابطٍ حقيقيٍّ بعد التجربة — لا يمنعه رابطُ التجربة المدفوع
        $this->mode($this->shop, PaymentGateway::LIVE);
        [$real, $existing] = PaymentLinks::forInvoice($invoice->fresh(), $this->owner->id);
        $this->assertFalse($existing);
        $this->assertTrue($real->is_live);
    }

    /* ═══════════ ٢ · وإعادةُ الإشعار لا تكتب شيئًا ═══════════ */

    public function test_paymob_repeating_a_test_notice_writes_nothing_and_a_second_transaction_is_flagged(): void
    {
        $link = $this->link($this->invoice());
        $obj = $this->obj($link);
        $before = $this->books();

        foreach (range(1, 5) as $_) {
            $this->hook($obj);
        }

        $this->assertSame($before, $this->books());
        $this->assertSame(CustomerPaymentLink::PAID, $link->fresh()->status);
        $this->assertSame((string) $obj['id'], $link->fresh()->provider_transaction_id, 'الإعادةُ بدّلت العمليّة');

        // عمليّةٌ ثانيةٌ على رابط التجربة نفسه: تُوسم ولا تُكتب
        $second = $this->obj($link);
        $this->hook($second);
        $this->assertSame([(string) $second['id']], $link->fresh()->meta['extra_transactions']);
        $this->assertSame($before, $this->books());
    }

    /* ═══════════ ٣ · والحيُّ يُحصَّل فورًا كما كان ═══════════ */

    public function test_a_live_link_beside_it_still_collects_at_once_in_the_same_notice(): void
    {
        $invoice = $this->invoice();
        $test = $this->link($invoice, 40);
        $this->hook($this->obj($test));

        $this->mode($this->shop, PaymentGateway::LIVE);
        $live = $this->link($invoice, 60);
        $this->assertTrue($live->is_live);
        $before = $this->books();

        $this->hook($this->obj($live));
        $live->refresh();

        $this->assertSame(CustomerPaymentLink::PAID, $live->status);
        $payment = CustomerPayment::where('business_id', $this->shop->id)->findOrFail($live->customer_payment_id);
        $this->assertSame([CustomerPayments::PAYMOB, 60.0, (string) $this->tx], [$payment->method, (float) $payment->amount, $payment->external_reference]);
        $this->assertArrayHasKey('loyalty', (array) $live->meta, 'الحيُّ تُحسم نقاطُه بعد تحصيله');

        $after = $this->books();
        $this->assertSame($before['payments'] + 1, $after['payments']);
        $this->assertSame($before['entries'] + 1, $after['entries']);
        $this->assertSame(round($before['receivable'] - 60, 3), $after['receivable']);
        $this->assertSame(round($before['clearing'] + 60, 3), $after['clearing']);
        $this->assertSame($before['revenue'], $after['revenue'], 'التحصيلُ ليس بيعًا');
        $this->assertSame(40.0, round($invoice->fresh()->outstanding(), 3), 'الحيُّ وحده سدّد من الفاتورة');

        // ورابطُ التجربة قبله باقٍ بلا تحصيل
        $this->assertNull($test->fresh()->customer_payment_id);
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count());
    }

    /* ═══════════ ٤ · وبيئةُ الإشعار لا تبدّل بيئةَ الرابط ═══════════ */

    public function test_the_notices_unsigned_live_flag_never_turns_a_test_link_into_money_or_the_reverse(): void
    {
        $test = $this->link($this->invoice());
        $before = $this->books();

        // رابطُ تجربةٍ بإشعارٍ يقول «حيّ» — حقلٌ خارج التوقيع: يُردّ ولا يُكتب
        $this->hook($this->obj($test, ['is_live' => true]));
        $this->assertSame(CustomerPaymentLink::PENDING, $test->fresh()->status);
        $this->assertNotNull($test->fresh()->error);
        $this->assertSame($before, $this->books());

        // وبلا بيئةٍ في الإشعار: بيئةُ الرابط هي الحكم — تجربة، فلا دفاتر
        $bare = $this->obj($test);
        unset($bare['is_live']);
        $this->hook($bare);
        $this->assertSame(CustomerPaymentLink::PAID, $test->fresh()->status);
        $this->assertNull($test->fresh()->customer_payment_id);
        $this->assertSame($before, $this->books());

        // ورابطٌ حيٌّ بإشعار تجربة: يُردّ كما كان
        $this->mode($this->shop, PaymentGateway::LIVE);
        $live = $this->link($this->invoice());
        $this->hook($this->obj($live, ['is_live' => false]));
        $this->assertSame(CustomerPaymentLink::PENDING, $live->fresh()->status);
        $this->assertSame(0, CustomerPayment::where('business_id', $this->shop->id)->count());
    }

    /* ═══════════ ٥ · ولا تسويةَ بنكيّةَ لرابط تجربة ═══════════ */

    public function test_a_test_link_is_never_offered_or_accepted_for_a_bank_settlement_and_an_old_one_is_left_as_it_is(): void
    {
        $this->assertFalse(PaymobSettlements::paused(), 'التسوياتُ الحقيقيّة مفتوحة');

        // رابطُ تجربةٍ جديد: «مدفوع» بلا تحصيل
        $test = $this->link($this->invoice(), 30);
        $this->hook($this->obj($test));

        // ورابطُ تجربةٍ قديم كُتب له تحصيلٌ قبل هذا الإصلاح — كما كان يكتبه `settle`
        $old = $this->link(null, 13);
        $oldPayment = CustomerPayments::record($this->shop->id, $this->ministry, 13, [
            'method' => CustomerPayments::PAYMOB, 'external_reference' => 'OLD-TEST', 'auto_allocate' => false,
        ]);
        $old->forceFill([
            'status' => CustomerPaymentLink::PAID, 'paid_at' => now(), 'provider_transaction_id' => 'OLD-TEST',
            'customer_payment_id' => $oldPayment->id,
        ])->save();
        $oldEntry = JournalEntry::where('business_id', $this->shop->id)
            ->where('sourceable_type', CustomerPayment::class)->where('sourceable_id', $oldPayment->id)->firstOrFail();

        // ورابطٌ حيّ
        $this->mode($this->shop, PaymentGateway::LIVE);
        $live = $this->link($this->invoice(), 50);
        $this->hook($this->obj($live));

        $this->assertSame(['link:'.$live->id], $this->candidates(), 'رابطُ تجربةٍ عُرض للتسوية');

        foreach ([$test, $old] as $link) {
            $before = $this->books();
            try {
                PaymobSettlements::record($this->shop->id, [
                    'items' => ['link:'.$link->id], 'fee' => 0, 'net' => (float) $link->amount, 'settled_on' => '2026-10-25',
                ], $this->owner->id);
                $this->fail('سُوّي رابطُ تجربة');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('تجريبيّ', $e->getMessage());
            }
            $this->assertSame($before, $this->books(), 'رفضٌ كتب شيئًا');
        }

        // وتسويةٌ تخلط الحيَّ بالتجربة تُردّ كلُّها
        try {
            PaymobSettlements::record($this->shop->id, [
                'items' => ['link:'.$live->id, 'link:'.$old->id], 'fee' => 1, 'net' => 62, 'settled_on' => '2026-10-25',
            ], $this->owner->id);
            $this->fail('سُوّيت تسويةٌ فيها رابطُ تجربة');
        } catch (RuntimeException) {
        }
        $this->assertSame(0, PaymobSettlement::count());

        // والحيُّ وحده يُسوّى: الصافي إلى البنك، والرسوم مصروف
        $settlement = PaymobSettlements::record($this->shop->id, [
            'items' => ['link:'.$live->id], 'fee' => 1, 'net' => 49, 'settled_on' => '2026-10-25',
        ], $this->owner->id);
        $this->assertSame(PaymobSettlements::POSTED, $settlement->status);
        $this->assertSame(49.0, round(Ledger::balance($this->shop->id, 'bank'), 3));

        // والقديمُ باقٍ كما هو — لا يُعكس آليًّا، ومالُه المخترَع في التسوية حتّى يُلغى بيد صاحبه
        $this->assertNull($oldEntry->fresh()->reversed_at);
        $this->assertNull($oldPayment->fresh()->cancelled_at);
        $this->assertSame(13.0, round(Ledger::balance($this->shop->id, 'paymob_clearing'), 3));
        $this->assertSame([], $this->candidates());
    }

    /* ═══════════ ٦ · لكلّ متجرٍ مالُه وتجاربُه ═══════════ */

    public function test_each_shop_keeps_its_own_test_and_live_money_apart(): void
    {
        $neighbour = Customer::create(['business_id' => $this->other->id, 'name' => 'عميل الجار', 'phone' => '93333333', 'allow_credit_sales' => true]);
        $this->mode($this->other, PaymentGateway::LIVE);

        $mine = $this->link($this->invoice(), 25);
        $theirs = $this->link($this->invoice($this->other, $neighbour), 35, $this->other, $neighbour);
        $this->assertSame([false, true], [$mine->is_live, $theirs->is_live]);

        $mineBefore = $this->books();
        $theirsBefore = $this->books($this->other);

        // إشعارُ رابط الجار بسرّ متجرنا: يُردّ
        $this->hook($this->obj($theirs));
        $this->assertSame(CustomerPaymentLink::PENDING, $theirs->fresh()->status);

        $this->hook($this->obj($mine));
        $this->hook($this->obj($theirs), $this->other);

        $this->assertSame($mineBefore, $this->books(), 'تجربتُنا كتبت في دفاترنا');
        $theirsAfter = $this->books($this->other);
        $this->assertSame($theirsBefore['payments'] + 1, $theirsAfter['payments'], 'مالُ الجار الحيّ لم يُحصَّل');
        $this->assertSame(round($theirsBefore['clearing'] + 35, 3), $theirsAfter['clearing']);

        $this->assertSame([], $this->candidates());
        $this->assertSame(['link:'.$theirs->id], $this->candidates($this->other));

        // ولا يُسوّي متجرٌ مدفوعةَ غيره
        $this->expectException(RuntimeException::class);
        PaymobSettlements::record($this->shop->id, [
            'items' => ['link:'.$theirs->id], 'fee' => 0, 'net' => 35, 'settled_on' => '2026-10-25',
        ], $this->owner->id);
    }
}
