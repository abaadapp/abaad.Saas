<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceRevision;
use App\Models\CustomerPaymentLink;
use App\Models\PaymentGateway;
use App\Models\PointTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoiceEdits;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\Receivables;
use App\Support\RibbonPortal\Account;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * نقاطُ الولاء تتبع ما اشتراه العميلُ فعلًا بعد تصحيح فاتورته — بمثال المالك: فاتورةٌ بمئة
 * وخصمُ نقاطٍ بعشرة.
 *
 * ═══ القواعد (قرارُ المالك 2026-11-28، بانتظار اعتماده بعد عرض النتائج) ═══
 *
 *   - **المستبدَلة** تعود بحصّتها الفعليّة من البنود المتأثّرة، مع ما أُعيد قبلُ — لا بنسبة المبلغ.
 *     فبندٌ أضافه إشعارٌ مدين (لا حصّةَ له) ثمّ رُدّ لا يعيد نقطة.
 *   - **المكتسَبة برابط** تتبع صافيَ المشتريات: بعد إشعارٍ دائن يبقى منها بنسبة الصافي إلى المدفوع.
 *   - **المدين** لا يمنح مستبدَلةً ولا مكتسبةً بنفسه؛ زيادتُه تُكسب حين تُسدَّد برابط، ويعيد ما سحبه دائنٌ قبله.
 *   - **لا رصيدَ سالبًا ولا خصمَ مال:** عميلٌ صرف المكتسَبَ قبل التصحيح يُسحب منه ما في رصيده
 *     وحده، والباقي يُكتب في سجلّ النشاط بعدده بانتظار قرار صاحب النشاط، ولا يُعاد طلبُه.
 *   - لا يُعاد حسابُ نقاطٍ قديمة، ومجموعُ الأرصدة = مجموعُ الحركات دائمًا.
 *
 * والأرقام: الولاءُ ٥ نقاطٍ لكلّ ريال، والاستبدالُ ١٠٠ نقطةٍ بريال (افتراضُ المتجر).
 */
class PointsFollowWhatTheCustomerReallyBoughtTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Customer $maryam;

    private int $n = 0;

    private int $order = 616100000;

    private int $tx = 7100000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-28 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود النقاط الصافية', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الفرع']);
        foreach (['vat_enabled' => '0', 'loyalty_enabled' => '1', 'loyalty_earn_rate' => '5'] as $k => $v) {
            Setting::create(['business_id' => $this->shop->id, 'key' => $k, 'value' => $v]);
        }
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@net-points.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->maryam = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'مريم', 'phone' => '93456781', 'points' => 1000,
            'language' => 'ar', 'allow_credit_sales' => true,
        ]);

        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-net',
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => 'api', 'mode' => PaymentGateway::LIVE,
        ]);
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580090, 'amount_cents' => 0, 'shorten_url' => 'https://paymob.link/net',
                'client_url' => 'https://oman.paymob.com/net', 'created_at' => '2026-11-28T10:00:00',
                'expires_at' => '2026-12-05T23:59:00', 'state' => 'created', 'order' => 616057600,
            ]),
        ]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══ أدوات ═══ */

    /** فاتورةُ المثال: «أ» ٦ × ١٠ و«ب» ٤ × ١٠ = مئة، وخصمُ نقاطٍ بعشرة (١٠٠٠ نقطة) = تسعون */
    private function hundredWithTen(int $redeem = 1000): CustomerInvoice
    {
        return CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->maryam, [
            'issued_at' => '2026-11-28', 'redeem_points' => $redeem,
        ], [
            ['description' => 'أ', 'quantity' => 6, 'unit_price' => 10],
            ['description' => 'ب', 'quantity' => 4, 'unit_price' => 10],
        ], $this->owner->id), $this->owner->id)->fresh();
    }

    /** يُسدَّد برابط Paymob حقيقيّ المسار: إنشاؤه ثمّ إشعارُه الموقَّع */
    private function payByLink(CustomerInvoice $invoice, ?float $amount = null): void
    {
        $link = PaymentLinks::create($this->shop->id, $this->maryam, $invoice->fresh(), $amount, null, $this->owner->id);
        $link->forceFill(['provider_order_id' => (string) (++$this->order)])->save();
        $obj = [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-11-28T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => ++$this->tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => true,
        ];
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-net'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
        $this->assertSame(CustomerPaymentLink::PAID, $link->fresh()->status);
    }

    /** تصحيحُ كمّيّات البنود بمفاتيحها — وبنودٌ جديدة إن أُعطيت */
    private function correct(CustomerInvoice $invoice, array $quantities, array $newLines = [])
    {
        $invoice = $invoice->fresh('items');
        $lines = array_map(fn ($l) => [
            'key' => $l['key'], 'description' => $l['description'], 'quantity' => (float) $l['quantity'],
            'unit_price' => (float) $l['unit_price'],
            // الشاشةُ ترسل الخصمَ اليدويّ وحده — وحصّةُ النقاط تتبع البند (`pointsShareFollows`)
            'discount' => round((float) $l['discount'] - (float) ($l['loyalty_discount'] ?? 0), 3), 'tax_rate' => (float) $l['tax_rate'],
        ], CustomerInvoiceEdits::state($invoice)['lines']);
        foreach ($quantities as $i => $q) {
            $lines[$i]['quantity'] = $q;
        }
        // وبندٌ كمّيّتُه صفر يُرفع من الورقة كما تفعل الشاشة
        $lines = array_values(array_filter($lines, fn ($l) => (float) $l['quantity'] > 0));
        foreach ($newLines as $l) {
            $lines[] = $l + ['discount' => 0, 'tax_rate' => 0];
        }

        return $this->put(route('admin.customerInvoices.update', $invoice->id), [
            'expected_revision' => (int) $invoice->revision, 'edit_token' => 'net-'.(++$this->n), 'customer_id' => $invoice->customer_id,
            'issued_at' => $invoice->issued_at->toDateString(), 'due_at' => optional($invoice->due_at)->toDateString(),
            'payment_terms_days' => $invoice->payment_terms_days, 'branch_id' => $invoice->branch_id, 'notes' => $invoice->notes,
            'internal_notes' => $invoice->internal_notes, 'reason' => 'تصحيح الكمية', 'basis' => CustomerInvoiceRevision::ERROR, 'items' => $lines,
        ])->assertSessionHasNoErrors();
    }

    private function manualCredit(CustomerInvoice $invoice, float $amount): void
    {
        $this->post('/admin/customer-invoices/'.$invoice->id.'/credit-note', [
            'amount' => $amount, 'tax_amount' => 0, 'reason' => 'أعادت العميلة بعض الورد',
            'basis' => CustomerCreditNote::RETURN, 'token' => 'mcn-'.(++$this->n),
        ])->assertSessionHasNoErrors();
    }

    private function points(): int
    {
        return (int) $this->maryam->fresh()->points;
    }

    /** المستبدَلُ العائد والمكتسَبُ المسحوب على الفاتورة — من حركاتها */
    private function moves(CustomerInvoice $invoice): array
    {
        $sum = fn (string $like) => (int) PointTransaction::where('customer_invoice_id', $invoice->id)->where('source_key', 'like', $like)->sum('points');

        return [
            'returned' => $sum('cinv:'.$invoice->id.':redeem:%'),
            'earned' => $sum('link:%'),
            'net' => $sum('cinv:'.$invoice->id.':net:%'),
        ];
    }

    private function assertBalancesAreTheirMoves(): void
    {
        $this->assertSame(
            (int) Customer::where('business_id', $this->shop->id)->sum('points') - 1000,
            (int) PointTransaction::where('business_id', $this->shop->id)->sum('points'),
            'مجموعُ الأرصدة لا يساوي مجموعَ الحركات',
        );
        $this->assertGreaterThanOrEqual(0, $this->points(), 'رصيدٌ سالب');
    }

    /* ═══ المثال: مئةٌ وخصمُ نقاطٍ بعشرة، سُدّدت تسعين برابط ═══ */

    public function test_the_example_spends_a_thousand_and_earns_four_hundred_and_fifty(): void
    {
        $invoice = $this->hundredWithTen();
        $this->assertSame(90.0, (float) $invoice->total);
        $this->assertSame([6.0, 4.0], $invoice->items->map(fn ($i) => round((float) $i->loyalty_discount, 3))->all(), 'حصّةُ كلّ بندٍ بنسبة مبلغه');
        $this->assertSame(0, $this->points());

        $this->payByLink($invoice);
        $this->assertSame(450, $this->points());
        $this->assertBalancesAreTheirMoves();
    }

    public function test_lowering_a_quantity_returns_its_share_of_the_spent_and_takes_back_what_it_no_longer_bought(): void
    {
        $invoice = $this->hundredWithTen();
        $this->payByLink($invoice);

        // «أ» من ستٍّ إلى ثلاث: حصّتُه من ستّةٍ إلى ثلاثة، والإشعارُ ٣٠ − ٣ = ٢٧
        $this->correct($invoice, [0 => 3]);
        $this->assertSame(27.0, round((float) CustomerCreditNote::sole()->amount, 3));

        // المستبدَل: ١٠٠٠ × ٣ ÷ ١٠ = ٣٠٠ · المكتسَب: يبقى ⌊٤٥٠ × ٦٣ ÷ ٩٠⌋ = ٣١٥، فيُسحب ١٣٥
        $this->assertSame(['returned' => 300, 'earned' => 450, 'net' => -135], $this->moves($invoice));
        $this->assertSame(450 + 300 - 135, $this->points());

        // والمدفوعُ فوق الصافي رصيدٌ للعميلة — لا يُردّ مالٌ وحده
        $this->assertEquals(27.0, round(Receivables::credit($this->shop->id), 3));
        $this->assertBalancesAreTheirMoves();
    }

    public function test_lowering_twice_returns_and_takes_cumulatively_never_twice(): void
    {
        $invoice = $this->hundredWithTen();
        $this->payByLink($invoice);

        $this->correct($invoice, [0 => 3]);
        $this->correct($invoice, [0 => 1]);

        // الحصصُ ٦ ← ٣ ← ١: يعود ٥٠٠ كلَّه (٣٠٠ ثمّ ٢٠٠). والصافي ٩ + ٣٦ = ٤٥: يبقى ٢٢٥ فيُسحب ٢٢٥ (١٣٥ ثمّ ٩٠)
        $this->assertSame(['returned' => 500, 'earned' => 450, 'net' => -225], $this->moves($invoice));
        $this->assertSame(450 + 500 - 225, $this->points());
        $this->assertBalancesAreTheirMoves();
    }

    public function test_raising_a_quantity_spends_and_earns_nothing_until_the_increase_is_paid_by_link(): void
    {
        $invoice = $this->hundredWithTen();
        $this->payByLink($invoice);

        // «ب» من أربعٍ إلى ستّ: إشعارٌ مدين بعشرين، بلا حصّةِ نقاطٍ جديدة ولا نقاط
        $this->correct($invoice, [1 => 6]);
        $this->assertSame(['returned' => 0, 'earned' => 450, 'net' => 0], $this->moves($invoice));
        $this->assertSame(450, $this->points());

        // تُسدَّد الزيادةُ برابط: تُكسب نقاطُها بالقاعدة نفسِها — ⌊١١٠ × ٥⌋ − ⌊٩٠ × ٥⌋ = ١٠٠
        $this->payByLink($invoice);
        $this->assertSame(550, $this->points());
        $this->assertBalancesAreTheirMoves();
    }

    public function test_a_line_added_by_a_debit_note_and_then_returned_gives_back_no_spent_points(): void
    {
        $invoice = $this->hundredWithTen();

        // بندٌ ثالث بخمسين بإشعارٍ مدين — لا حصّةَ له من النقاط — ثمّ يُردّ كلُّه بدائن
        $this->correct($invoice, [], [['description' => 'ج', 'quantity' => 1, 'unit_price' => 50]]);
        $this->correct($invoice, [2 => 0]);

        // بالنسبة من المبلغ كان يعود ⌊١٠٠٠ × ٥٠ ÷ ٩٠⌋ = ٥٥٥ نقطةً لم تُصرف على هذا البند
        $this->assertSame(0, $this->moves($invoice)['returned']);
        $this->assertSame(0, $this->points());
        $this->assertBalancesAreTheirMoves();
    }

    public function test_a_partial_then_full_return_by_hand_ends_where_the_customer_started(): void
    {
        $invoice = $this->hundredWithTen();
        $this->payByLink($invoice);

        // إشعارٌ يدويّ بلا بنود بثلاثين: المستبدَلُ بنسبة المبلغ ⌊١٠٠٠ × ٣٠ ÷ ٩٠⌋ = ٣٣٣، ويبقى من المكتسَب ⌊٤٥٠ × ٦٠ ÷ ٩٠⌋ = ٣٠٠
        $this->manualCredit($invoice, 30);
        $this->assertSame(['returned' => 333, 'earned' => 450, 'net' => -150], $this->moves($invoice));

        // ثمّ ما بقي كلُّه: يعود المستبدَلُ كلُّه، ولا يبقى من المكتسَب شيء
        $this->manualCredit($invoice, 60);
        $this->assertSame(['returned' => 1000, 'earned' => 450, 'net' => -450], $this->moves($invoice));
        $this->assertSame(1000, $this->points(), 'عادت العميلةُ إلى ما بدأت به');
        $this->assertBalancesAreTheirMoves();
    }

    public function test_a_customer_who_spent_her_points_first_loses_only_what_she_holds_and_the_rest_waits_for_the_owner(): void
    {
        // فاتورةٌ بمئة بلا استبدال، سُدّدت برابط: ٥٠٠ نقطة
        $plain = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->maryam, ['issued_at' => '2026-11-28'], [
            ['description' => 'أ', 'quantity' => 10, 'unit_price' => 10],
        ], $this->owner->id), $this->owner->id);
        $this->payByLink($plain);
        $this->assertSame(1500, $this->points());

        // صرفت ١٤٥٠ على فاتورتين قبل التصحيح — بقي ٥٠
        foreach ([1000, 450] as $spend) {
            CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->maryam, ['issued_at' => '2026-11-28', 'redeem_points' => $spend], [
                ['description' => 'أخرى', 'quantity' => 1, 'unit_price' => 30],
            ], $this->owner->id), $this->owner->id);
        }
        $this->assertSame(50, $this->points());

        // التصحيحُ من عشرٍ إلى ست: يبقى ⌊٥٠٠ × ٦٠ ÷ ١٠٠⌋ = ٣٠٠ فيُطلب ٢٠٠ — يُسحب ٥٠ وحده
        $this->correct($plain, [0 => 6]);
        $this->assertSame(0, $this->points(), 'لا ينزل الرصيدُ تحت الصفر');
        $this->assertSame(-50, $this->moves($plain)['net']);
        $log = ActivityLog::where('subject_type', 'customer_invoice')->where('subject_id', $plain->id)->where('description', 'like', 'لم يُسحب 150 نقطة%')->first();
        $this->assertNotNull($log, 'لم يُكتب ما لم يُسحب بانتظار قرار صاحب النشاط');

        // ولا يُطلب لاحقًا: إشعارٌ مدين يعيد الصافي إلى مئة يعيد ما سُحب فعلًا (٥٠) لا أكثر
        $this->correct($plain, [0 => 10]);
        $this->assertSame(50, $this->points());
        $this->assertSame(0, $this->moves($plain)['net']);
        $this->assertBalancesAreTheirMoves();
    }

    public function test_the_customer_file_the_portal_and_the_log_tell_the_same_story(): void
    {
        $invoice = $this->hundredWithTen();
        $this->payByLink($invoice);
        $this->correct($invoice, [0 => 3]);

        // سجلُّ الحركات: العائدُ «ردّ» والمسحوبُ «عكس» — لا «استُعمل»
        $portal = (new Account($this->maryam->fresh(), 'ar'))->points();
        $this->assertSame(615, $portal['balance']);
        $moves = collect($portal['rows'])->pluck('move', 'points')->all();
        $this->assertSame('move.returned', $moves[300] ?? null, 'المستبدَلُ العائد');
        $this->assertSame('move.reversed', $moves[-135] ?? null, 'المكتسَبُ المسحوب يُقرأ عكسًا');
        $this->assertSame('move.earned', $moves[450] ?? null);

        // وملفُّ العميل في اللوحة يقرأ الرصيد نفسه
        $page = $this->get(route('admin.customers.show', $this->maryam->id))->assertOk();
        $this->assertSame(615, (int) ($page->viewData('page')['props']['customer']['points'] ?? -1));
        $this->assertBalancesAreTheirMoves();
    }
}
