<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing;
use App\Support\PlanFeatures;
use App\Support\SeedData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * أسعارُ الباقات القائمة تصير الأسعار الرسميّة — ولا يمسّ ذلك فاتورةً صدرت.
 *
 * والقاعدةُ هنا كما كانت على الإنتاج يوم كُتب الترحيل (قراءةٌ فقط،
 * 2026-10-17): أسعارُها وحدودُها ونصوصُها وقدراتُها، وفواتيرُ الاحترافيّة
 * بـ24.900 مدفوعةً وغيرَ مدفوعة، واشتراكٌ بـ249.
 */
class TheLivePlansChargeTheOfficialPricesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_17_150000_the_live_plans_charge_the_official_prices.php';

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    /** @return array{basic: Plan, pro: Plan, enterprise: Plan} */
    private function livePlans(string $proMonthly = '24.900', string $enterpriseMonthly = '59.900'): array
    {
        $make = fn (string $name, ?string $code, string $monthly, string $yearly, array $limits, ?array $caps, bool $popular, array $features) => Plan::create([
            'name' => $name, 'code' => $code, 'monthly_price' => $monthly, 'yearly_price' => $yearly,
            'max_branches' => $limits[0], 'max_employees' => $limits[1], 'max_products' => $limits[2],
            'capabilities' => $caps, 'is_popular' => $popular, 'features' => $features, 'color' => 'primary',
        ]);

        return [
            'basic' => $make('الباقة الأساسية', 'basic', '9.900', '99.000', [1, 3, 500], [], false, ['500 منتج']),
            'pro' => $make('الباقة الاحترافية', null, $proMonthly, '249.000', [3, 15, 10000], PlanFeatures::keys(), true, ['10,000 منتج']),
            'enterprise' => $make('باقة المؤسسات', null, $enterpriseMonthly, '599.000', [10, 100, null], PlanFeatures::keys(), false, ['منتجات غير محدودة']),
        ];
    }

    /** الصفُّ كما في القاعدة — بالبيسة للسعر، كي لا تُقارن عشريّةٌ بعشريّة */
    private function row(Plan $plan): array
    {
        $row = (array) DB::table('plans')->where('id', $plan->id)->first();
        $row['monthly_price'] = (int) round(((float) $row['monthly_price']) * 1000);
        $row['yearly_price'] = (int) round(((float) $row['yearly_price']) * 1000);

        return $row;
    }

    private function prices(Plan $plan): array
    {
        $row = $this->row($plan);

        return [$row['monthly_price'], $row['yearly_price']];
    }

    /** يشغّل `up` ويعيد رسالةَ توقّفه — أو يُسقط الاختبار إن لم يتوقّف */
    private function refusal(): string
    {
        try {
            $this->migration()->up();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('الترحيل لم يتوقّف');
    }

    /* ─────────── ١ · الأسعارُ الرسميّة، ولا شيءَ سواها ─────────── */

    public function test_the_live_plans_take_the_official_prices(): void
    {
        $plans = $this->livePlans();

        $this->migration()->up();

        $this->assertSame([9900, 99000], $this->prices($plans['basic']));
        $this->assertSame([29900, 299000], $this->prices($plans['pro']));
        $this->assertSame([69900, 699000], $this->prices($plans['enterprise']));
    }

    public function test_nothing_but_the_two_prices_changes(): void
    {
        $plans = $this->livePlans();
        $before = array_map(fn (Plan $p) => $this->row($p), $plans);
        $this->travel(5)->minutes();

        $this->migration()->up();

        foreach ($plans as $key => $plan) {
            $after = $this->row($plan);
            $ignore = ['monthly_price', 'yearly_price', 'updated_at'];
            $this->assertSame(
                array_diff_key($before[$key], array_flip($ignore)),
                array_diff_key($after, array_flip($ignore)),
                "{$key}: تغيّر غيرُ السعر",
            );
        }
        // والأساسيّةُ سعرُها رسميٌّ أصلًا — لا تُكتب
        $this->assertSame($before['basic']['updated_at'], $this->row($plans['basic'])['updated_at']);
    }

    public function test_the_basic_plan_is_found_by_its_code_not_its_name(): void
    {
        // اسمُها يُعدَّل من شاشة الباقات، ورمزُها لا — فإعادةُ تسميتها لا تُغيّبها
        $plans = $this->livePlans();
        $plans['basic']->update(['name' => 'الباقة الأساسية 2027']);

        $this->migration()->up();

        $this->assertSame([9900, 99000], $this->prices($plans['basic']));
        $this->assertSame([29900, 299000], $this->prices($plans['pro']));
        $this->assertSame([69900, 699000], $this->prices($plans['enterprise']));
    }

    public function test_a_custom_plan_is_left_alone(): void
    {
        $this->livePlans();
        $custom = Plan::create(['name' => 'باقة سلسلة خاصّة', 'monthly_price' => '24.900', 'yearly_price' => '249.000']);

        $this->migration()->up();

        $this->assertSame([24900, 249000], $this->prices($custom));
    }

    public function test_running_it_again_on_the_official_prices_writes_nothing(): void
    {
        $plans = $this->livePlans();
        $this->migration()->up();
        $stamps = array_map(fn (Plan $p) => $this->row($p)['updated_at'], $plans);
        $this->travel(5)->minutes();

        $this->migration()->up();

        foreach ($plans as $key => $plan) {
            $this->assertSame($stamps[$key], $this->row($plan)['updated_at'], "{$key}: كُتبت ثانيةً");
        }
        $this->assertSame([29900, 299000], $this->prices($plans['pro']));
    }

    public function test_an_empty_install_is_left_for_the_seed(): void
    {
        // كلُّ `migrate:fresh` وكلُّ اختبار: لا باقة بعد، والبذرةُ تزرعها بالسعر الرسميّ
        $this->migration()->up();

        $this->assertSame(0, Plan::count());
        foreach (SeedData::plans() as $spec) {
            $key = ($spec['code'] ?? null) === 'basic' ? 'basic' : $spec['name'];
            $this->assertSame(
                $this->migration()::TARGET[$key],
                [number_format($spec['monthly'], 3, '.', ''), number_format($spec['yearly'], 3, '.', '')],
                "{$key}: البذرةُ لا تزرع السعر الرسميّ",
            );
        }
    }

    /* ─────────── ٢ · تتوقّف قبل أيّ كتابة ─────────── */

    public function test_a_second_professional_plan_stops_it_before_any_write(): void
    {
        $plans = $this->livePlans();
        $twin = Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => '24.900', 'yearly_price' => '249.000']);

        $this->assertStringContainsString('الباقة الاحترافية', $this->refusal());

        $this->assertSame([24900, 249000], $this->prices($plans['pro']));
        $this->assertSame([24900, 249000], $this->prices($twin));
        $this->assertSame([59900, 599000], $this->prices($plans['enterprise']), 'كُتب نصفُ الأسعار');
    }

    public function test_a_missing_enterprise_plan_stops_it_before_any_write(): void
    {
        $plans = $this->livePlans();
        $plans['enterprise']->delete();

        $this->assertStringContainsString('باقة المؤسسات', $this->refusal());

        $this->assertSame([24900, 249000], $this->prices($plans['pro']), 'كُتبت الاحترافيّة والمؤسساتُ غائبة');
        $this->assertSame([9900, 99000], $this->prices($plans['basic']));
    }

    public function test_an_unexpected_price_stops_it_before_any_write(): void
    {
        // سعرٌ عدّله المشغّل بيده: لا القديم ولا الرسميّ — لا يُكتب فوقه، ولا فوق غيره
        $plans = $this->livePlans(proMonthly: '27.000');

        $this->assertStringContainsString('27.000', $this->refusal());

        $this->assertSame([27000, 249000], $this->prices($plans['pro']));
        $this->assertSame([59900, 599000], $this->prices($plans['enterprise']), 'كُتبت المؤسساتُ والاحترافيّةُ مرفوضة');
    }

    public function test_an_unexpected_price_on_the_last_plan_still_stops_the_first(): void
    {
        // المؤسساتُ تُفحص بعد الاحترافيّة: لو كُتبت كلٌّ عند فحصها لكُتبت الاحترافيّةُ قبل الرفض
        $plans = $this->livePlans(enterpriseMonthly: '65.000');

        $this->assertStringContainsString('65.000', $this->refusal());

        $this->assertSame([24900, 249000], $this->prices($plans['pro']), 'كُتبت الاحترافيّةُ قبل رفض المؤسسات');
        $this->assertSame([65000, 599000], $this->prices($plans['enterprise']));
    }

    /* ─────────── ٣ · الرجوع ─────────── */

    public function test_down_restores_the_old_prices_while_they_are_still_the_official_ones(): void
    {
        $plans = $this->livePlans();
        $this->migration()->up();

        $this->migration()->down();

        $this->assertSame([9900, 99000], $this->prices($plans['basic']));
        $this->assertSame([24900, 249000], $this->prices($plans['pro']));
        $this->assertSame([59900, 599000], $this->prices($plans['enterprise']));
    }

    public function test_down_keeps_a_price_the_operator_set_from_the_plans_screen(): void
    {
        $plans = $this->livePlans();
        $this->migration()->up();

        // المشغّل يعدّل سعر المؤسسات من شاشة الباقات — والشاشةُ ما زالت تكتب السعر
        $super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'sa@prices.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->actingAs($super)->put(route('super-admin.plans.update', $plans['enterprise']->id), [
            'name' => 'باقة المؤسسات', 'monthly_price' => '64.900', 'yearly_price' => '649.000',
        ])->assertSessionHasNoErrors();
        $this->assertSame([64900, 649000], $this->prices($plans['enterprise']));

        $this->migration()->down();

        $this->assertSame([64900, 649000], $this->prices($plans['enterprise']), 'الرجوعُ محا سعرَ المشغّل');
        $this->assertSame([24900, 249000], $this->prices($plans['pro']));
    }

    /* ─────────── ٤ · ما صدر لا يُمسّ ─────────── */

    public function test_issued_invoices_subscriptions_and_shop_dates_are_not_touched(): void
    {
        $plans = $this->livePlans();
        $shop = Business::create([
            'name' => 'متجر نامٍ', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $plans['pro']->id,
            'starts_at' => '2026-01-01', 'ends_at' => '2026-11-15',
        ]);
        $paid = Invoice::create([
            'number' => 'INV-P-0001', 'business_id' => $shop->id, 'plan_id' => $plans['pro']->id, 'amount' => '24.900',
            'issued_at' => '2026-09-15', 'status' => Billing::PAID, 'kind' => Billing::KIND_SUBSCRIPTION,
        ]);
        $unpaid = Invoice::create([
            'number' => 'INV-P-0002', 'business_id' => $shop->id, 'plan_id' => $plans['pro']->id, 'amount' => '24.900',
            'issued_at' => '2026-10-15', 'status' => Billing::UNPAID, 'kind' => Billing::KIND_SUBSCRIPTION,
        ]);
        $subscription = Subscription::create([
            'business_id' => $shop->id, 'plan_id' => $plans['pro']->id, 'starts_at' => '2025-11-15', 'ends_at' => '2026-11-15',
            'amount' => '249.000', 'payment_status' => 'مدفوع', 'status' => Billing::SUB_ACTIVE,
        ]);
        $snapshot = fn () => [
            (array) DB::table('invoices')->where('id', $paid->id)->first(),
            (array) DB::table('invoices')->where('id', $unpaid->id)->first(),
            (array) DB::table('subscriptions')->where('id', $subscription->id)->first(),
            (array) DB::table('businesses')->where('id', $shop->id)->first(),
        ];
        $before = $snapshot();
        $this->travel(5)->minutes();

        $this->migration()->up();

        $this->assertSame($before, $snapshot(), 'مسّ الترحيلُ فاتورةً أو اشتراكًا أو متجرًا');
        $this->assertSame('24.900', $paid->fresh()->amount);
        $this->assertSame('24.900', $unpaid->fresh()->amount);
        $this->assertSame('249.000', $subscription->fresh()->amount);
        $this->assertSame('2026-11-15', $shop->fresh()->ends_at->toDateString());
    }
}
