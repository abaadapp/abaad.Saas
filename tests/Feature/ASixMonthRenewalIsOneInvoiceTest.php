<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * اشتراكُ ستّة أشهر يُجدَّد مرّةً واحدة بفاتورةٍ واحدة.
 *
 * الموقع يبيع «نصف سنوي» بسعر الشهر ستَّ مرّات، ولوحةُ المنصّة لم تكن
 * تعرف إلّا شهرًا وسنة: من اشترك ستّة أشهر جُدِّد «شهرًا» ستَّ مرّات،
 * فخرج له ستُّ فواتير عن دفعةٍ واحدة.
 */
class ASixMonthRenewalIsOneInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $plan = Plan::create([
            'name' => 'الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299,
            'max_branches' => 3, 'max_employees' => 3, 'max_products' => 100,
        ]);

        $this->shop = Business::create([
            'name' => 'متجري', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $plan->id,
        ]);
    }

    public function test_six_months_cost_six_monthly_prices_and_no_less(): void
    {
        $this->assertSame(179.4, Billing::price($this->shop, 'semiannual'));
        $this->assertSame(29.9, Billing::price($this->shop, 'monthly'));
        $this->assertSame(299.0, Billing::price($this->shop, 'yearly'));
    }

    public function test_renewing_six_months_issues_one_invoice_and_extends_six_months(): void
    {
        $this->shop->update(['ends_at' => now()->addDays(5)]);

        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.renew', $this->shop->id), ['cycle' => 'semiannual'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->shop->fresh()->ends_at->isSameDay(now()->addDays(5)->addMonths(6)));

        $invoices = Invoice::where('business_id', $this->shop->id)->get();
        $this->assertCount(1, $invoices, 'ستّة أشهر أصدرت أكثر من فاتورة');
        $this->assertEqualsWithDelta(179.4, (float) $invoices->first()->amount, 0.0001);

        $sub = Subscription::where('business_id', $this->shop->id)->sole();
        $this->assertEqualsWithDelta(179.4, (float) $sub->amount, 0.0001);
        $this->assertTrue($sub->ends_at->isSameDay($sub->starts_at->copy()->addMonths(6)));
    }

    /** والشهريّ والسنويّ كما كانا: الدورةُ الجديدة لم تُزِح جارتَيها */
    public function test_monthly_and_yearly_still_extend_by_their_own_length(): void
    {
        $this->shop->update(['ends_at' => now()->subDay(), 'status' => 'منتهي']);

        Billing::renew($this->shop->fresh(), 'monthly');
        $this->assertTrue($this->shop->fresh()->ends_at->isSameDay(now()->addMonth()));

        Billing::renew($this->shop->fresh(), 'yearly');
        $this->assertTrue($this->shop->fresh()->ends_at->isSameDay(now()->addMonth()->addYear()));
    }

    /** ودورةٌ لا يعرفها النظام تُرفض، لا تُقرأ شهرًا */
    public function test_an_unknown_cycle_is_refused_without_an_invoice(): void
    {
        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.renew', $this->shop->id), ['cycle' => 'quarterly'])
            ->assertSessionHasErrors('cycle');

        $this->assertSame(0, Invoice::where('business_id', $this->shop->id)->count());
    }

    /** وشاشةُ المتجر في لوحة المنصّة تعرض سعرَ الأشهر الستّة لزرّها */
    public function test_the_shop_screen_offers_the_six_month_price(): void
    {
        $this->actingAs($this->super)
            ->get(route('super-admin.businesses.show', $this->shop->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('renewal.monthly', 29.9)
                ->where('renewal.semiannual', 179.4)
                ->where('renewal.yearly', 299));
    }
}
