<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * التجديدُ يأخذ سعرَ الباقة يومَ يصدر — وما صدر قبله يبقى بمبلغه.
 *
 * `Billing::renew` يقرأ السعر من الباقة (`Billing::price`) ويكتبه في
 * الاشتراك والفاتورة. فتغييرُ سعر الباقة — بترحيل
 * `2026_10_17_150000_the_live_plans_charge_the_official_prices` أو من شاشة
 * الباقات — يصل إلى ما يصدر بعده وحده: فاتورةُ أمس بـ24.900 تبقى 24.900.
 */
class ARenewalChargesThePriceOfItsDayTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_17_150000_the_live_plans_charge_the_official_prices.php';

    /** @var array{basic: Plan, pro: Plan, enterprise: Plan} */
    private array $plans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');

        // كما على الإنتاج قبل الترحيل
        $this->plans = [
            'basic' => Plan::create(['name' => 'الباقة الأساسية', 'code' => 'basic', 'monthly_price' => '9.900', 'yearly_price' => '99.000']),
            'pro' => Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => '24.900', 'yearly_price' => '249.000']),
            'enterprise' => Plan::create(['name' => 'باقة المؤسسات', 'monthly_price' => '59.900', 'yearly_price' => '599.000']),
        ];
    }

    private function officialPrices(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function shop(string $plan, ?string $endsAt = '2026-10-25'): Business
    {
        $shop = Business::create([
            'name' => 'متجر '.$plan, 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $this->plans[$plan]->id,
            'starts_at' => '2026-01-01', 'ends_at' => $endsAt,
        ]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);

        return $shop;
    }

    public function test_what_was_issued_keeps_its_amount_and_the_next_renewal_takes_the_new_price(): void
    {
        $shop = $this->shop('pro');
        // تجديدٌ معلّقٌ واحد للمتجر: المسدَّدُ أوّلًا، ثمّ الذي يبقى بلا سداد
        $paidBefore = Billing::renew($shop->fresh(), 'monthly')['invoice'];
        Billing::markPaid($paidBefore);
        ['invoice' => $oldInvoice, 'subscription' => $oldSubscription] = Billing::renew($shop->fresh(), 'monthly');
        $this->assertSame('24.900', $oldInvoice->fresh()->amount);

        $this->officialPrices();

        // ما صدر قبل الترحيل — مدفوعًا وغيرَ مدفوع — بمبلغه
        $this->assertSame('24.900', $oldInvoice->fresh()->amount);
        $this->assertSame(Billing::UNPAID, $oldInvoice->fresh()->status);
        $this->assertSame('24.900', $paidBefore->fresh()->amount);
        $this->assertSame(Billing::PAID, $paidBefore->fresh()->status);
        $this->assertSame('24.900', $oldSubscription->fresh()->amount);

        // ومن يسدّد الفاتورةَ القديمة بعد الترحيل يسدّد مبلغَها لا السعرَ الجديد
        Billing::markPaid($oldInvoice->fresh());
        $this->assertSame('24.900', $oldInvoice->fresh()->amount);

        // والتجديدُ التالي بالسعر الرسميّ
        ['invoice' => $new, 'subscription' => $newSubscription] = Billing::renew($shop->fresh(), 'monthly');
        $this->assertSame('29.900', $new->fresh()->amount);
        $this->assertSame('29.900', $newSubscription->fresh()->amount);
        $this->assertSame(3, Invoice::where('business_id', $shop->id)->where('amount', '24.900')->count() + Invoice::where('business_id', $shop->id)->where('amount', '29.900')->count());
    }

    public function test_each_plan_renews_at_its_official_price_monthly_and_yearly(): void
    {
        $this->officialPrices();

        foreach ([
            ['basic', 'monthly', '9.900'], ['basic', 'yearly', '99.000'],
            ['pro', 'monthly', '29.900'], ['pro', 'yearly', '299.000'],
            ['enterprise', 'monthly', '69.900'], ['enterprise', 'yearly', '699.000'],
        ] as [$plan, $cycle, $amount]) {
            $shop = $this->shop($plan);
            ['invoice' => $invoice, 'subscription' => $subscription] = Billing::renew($shop->fresh(), $cycle);
            $invoice = $invoice->fresh();
            $subscription = $subscription->fresh();
            $label = "{$plan} {$cycle}";

            $this->assertSame($amount, $invoice->amount, $label);
            $this->assertSame($amount, $subscription->amount, $label);
            $this->assertSame(Billing::KIND_SUBSCRIPTION, $invoice->kind, $label);
            $this->assertSame(Billing::UNPAID, $invoice->status, $label);
            $this->assertSame($this->plans[$plan]->id, (int) $invoice->plan_id, $label);
            $this->assertSame($this->plans[$plan]->id, (int) $subscription->plan_id, $label);
            $this->assertSame('غير مدفوع', $subscription->payment_status, $label);
        }
    }

    public function test_the_renewal_dates_and_settling_work_as_before(): void
    {
        $this->officialPrices();

        // اشتراكٌ لم ينتهِ: الإصدارُ لا يمسّه، والسدادُ يمدّه من نهايته — شهرًا وسنة
        $running = $this->shop('pro', '2026-10-25');
        $monthly = Billing::renew($running->fresh(), 'monthly');
        $this->assertSame('2026-10-25', $running->fresh()->ends_at->toDateString(), 'مُدّ قبل السداد');
        $this->assertNull($monthly['subscription']->fresh()->starts_at);

        $paid = Billing::markPaid($monthly['invoice']);
        $this->assertSame('2026-11-25', $running->fresh()->ends_at->toDateString());
        $this->assertSame('2026-10-25', $monthly['subscription']->fresh()->starts_at->toDateString());
        $this->assertSame('2026-01-01', $running->fresh()->starts_at->toDateString(), 'تغيّر تاريخُ البداية');

        $yearly = $this->shop('enterprise', '2026-10-25');
        Billing::markPaid(Billing::renew($yearly->fresh(), 'yearly')['invoice']);
        $this->assertSame('2027-10-25', $yearly->fresh()->ends_at->toDateString());

        // ومنتهٍ: من يوم السداد
        $lapsed = $this->shop('basic', '2026-09-01');
        Billing::markPaid(Billing::renew($lapsed->fresh(), 'monthly')['invoice']);
        $this->assertSame('2026-11-20', $lapsed->fresh()->ends_at->toDateString());

        // والسدادُ: الفاتورةُ مدفوعة بمبلغها، واشتراكُ دورتها مدفوع
        $this->assertSame(Billing::PAID, $paid->status);
        $this->assertSame('29.900', $paid->amount);
        $this->assertSame('مدفوع', $monthly['subscription']->fresh()->payment_status);
        $this->assertSame(1, Subscription::where('business_id', $running->id)->count());
    }
}
