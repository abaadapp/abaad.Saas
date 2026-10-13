<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Support\MerchantAccount;
use App\Support\PlanFeatures;
use App\Support\PlanLimits;
use App\Support\SeedData;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الباقاتُ الافتراضيّة تبيع ما تقوله — سعرًا وسقفًا وقدرة.
 *
 * ═══ القرار ═══
 *
 * كان الفرقُ بين الباقات في الأسقف وحدها، والقدراتُ كلُّها مفتوحةٌ في الثلاث.
 * وقرارُ صاحب المنصّة (2026-10-13): الأساسيّةُ للتشغيل اليوميّ، والنموُّ —
 * الموقعُ وأدواتُ التسويق والتقاريرُ المتقدّمة والصلاحياتُ المخصّصة والولاء —
 * من الاحترافيّة. وهذه تركيبةُ التثبيت الجديد: الباقاتُ القائمة لا تُمسّ
 * (`AnExistingPlanKeepsItsWebsiteTest`).
 *
 * ═══ وما يُحرَس ═══
 *
 *   - الأسعارُ والأسقفُ والشعبيّةُ كما اتُّفق — من البذرة نفسِها.
 *   - الأساسيّةُ تفتح واتساب وحده: رقمُ أبعاد وحصّتُه يقفان عليه.
 *   - الاحترافيّةُ والمؤسساتُ تُقرآن من `PlanFeatures` — قدرةٌ تُضاف غدًا تُفتح فيهما.
 *   - لا نصَّ «غير محدود» فوق سقفٍ يُفرض، ولا وعدَ بما لا يُقدَّم.
 *   - السقفُ يُفرض على الباقة المزروعة، والتنزيلُ إلى ما يضيق يُردّ ولا يمحو شيئًا.
 */
class TheDefaultPlansSellWhatTheySayTest extends TestCase
{
    use RefreshDatabase;

    /** الاسم ← [شهريّ، سنويّ، فروع، موظفون، منتجات، الأكثر طلبًا] */
    private const AGREED = [
        'الباقة الأساسية' => ['9.900', '99.000', 1, 3, 100, false],
        'الباقة الاحترافية' => ['29.900', '299.000', 3, 15, 5000, true],
        'باقة المؤسسات' => ['69.900', '699.000', 25, 100, 100000, false],
    ];

    /** @return array<string, array<string, mixed>> */
    private function seeded(): array
    {
        return collect(SeedData::plans())->keyBy('name')->all();
    }

    public function test_a_fresh_install_seeds_the_agreed_prices_caps_and_popular_plan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $plans = Plan::orderBy('id')->get()->keyBy('name');
        $this->assertSame(array_keys(self::AGREED), $plans->keys()->all());

        foreach (self::AGREED as $name => [$monthly, $yearly, $branches, $employees, $products, $popular]) {
            $plan = $plans[$name];
            $this->assertSame($monthly, (string) $plan->monthly_price, $name);
            $this->assertSame($yearly, (string) $plan->yearly_price, $name);
            $this->assertSame([$branches, $employees, $products], [(int) $plan->max_branches, (int) $plan->max_employees, (int) $plan->max_products], $name);
            $this->assertSame($popular, (bool) $plan->is_popular, $name);
        }
    }

    public function test_the_basic_plan_opens_whatsapp_alone_and_the_others_open_everything(): void
    {
        $plans = $this->seeded();

        // واتساب في الأساسيّة: رقمُ أبعاد وحصّتُه الشهريّة يقفان عليه (`WhatsAppFeature::blockReason`)
        $this->assertSame(['whatsapp'], $plans['الباقة الأساسية']['capabilities']);

        foreach (['الباقة الاحترافية', 'باقة المؤسسات'] as $name) {
            $this->assertEqualsCanonicalizing(PlanFeatures::keys(), $plans[$name]['capabilities'], $name);
        }
    }

    /** والحارسُ يوافق البذرة — قدرةً قدرة، باقةً باقة */
    public function test_the_guard_agrees_with_every_seeded_plan(): void
    {
        /*
         * و`integrations` منها: قائمةُ الأساسيّة المزروعة صريحةٌ لم تمنحها قطّ.
         * ولا يقرؤها حارسٌ بعد (مرحلتُها الأولى — انظر `PlanFeatures`)، فلا
         * يتغيّر بها شيء؛ والحارسُ يقول عن البذرة ما تقوله هي.
         */
        $closedOnBasic = ['reports_advanced', 'custom_permissions', 'loyalty', 'website', 'marketing_tools', 'integrations'];

        foreach (SeedData::plans() as $spec) {
            $plan = Plan::create([
                'name' => $spec['name'], 'monthly_price' => $spec['monthly'],
                'yearly_price' => $spec['yearly'], 'capabilities' => $spec['capabilities'],
            ]);

            foreach (PlanFeatures::keys() as $key) {
                $expected = ! ($spec['name'] === 'الباقة الأساسية' && in_array($key, $closedOnBasic, true));
                $this->assertSame($expected, PlanFeatures::allowedBy($plan, $key), "«{$spec['name']}» و«{$key}»");
            }
        }
    }

    /** ولا تُترك فارغةً: الحارس يقرأ الفراغ «مفتوحًا» وشاشةُ الباقات تقرؤه «مغلقًا» */
    public function test_the_capabilities_are_listed_not_left_null(): void
    {
        foreach (SeedData::plans() as $plan) {
            $this->assertIsArray($plan['capabilities'], $plan['name']);
            $this->assertNotEmpty($plan['capabilities'], $plan['name']);
        }
    }

    /** والنصُّ لا يعد بما لا يُفرض ولا بما لا يُقدَّم */
    public function test_the_marketing_lines_promise_nothing_the_system_does_not_enforce(): void
    {
        foreach (SeedData::plans() as $plan) {
            $text = implode(' | ', $plan['features']);

            foreach (['غير محدود', 'كل القدرات', 'مدير حساب', 'على مدار الساعة'] as $never) {
                $this->assertStringNotContainsString($never, $text, "«{$plan['name']}» تعد بـ«{$never}»");
            }
        }

        $basic = implode(' | ', $this->seeded()['الباقة الأساسية']['features']);
        foreach (['فرع واحد', '3 موظفين', '100 منتج'] as $line) {
            $this->assertStringContainsString($line, $basic);
        }
    }

    /** والسقفُ المزروع يُفرض — المنتجُ المئة يُقبل والذي بعده يُردّ */
    public function test_the_seeded_basic_caps_are_enforced(): void
    {
        $this->seed(DatabaseSeeder::class);
        $basic = Plan::where('name', 'الباقة الأساسية')->firstOrFail();
        $shop = Business::create(['name' => 'متجر صغير', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $basic->id]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);

        foreach (range(1, 99) as $n) {
            Product::create(['business_id' => $shop->id, 'name' => 'صنف '.$n, 'price' => 1]);
        }
        $this->assertFalse(PlanLimits::reached($shop->fresh(), 'products'));

        Product::create(['business_id' => $shop->id, 'name' => 'صنف 100', 'price' => 1]);
        $this->assertTrue(PlanLimits::reached($shop->fresh(), 'products'));
        $this->assertTrue(PlanLimits::reached($shop->fresh(), 'branches'), 'الأساسيّة فرعٌ واحد');
    }

    /** والتنزيلُ إلى باقةٍ يتجاوزها المتجر يُردّ — ولا يُمحى فرعٌ ولا صنف */
    public function test_a_downgrade_below_usage_is_refused_and_deletes_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $platform = User::where('role', 'super_admin')->firstOrFail();
        $pro = Plan::where('name', 'الباقة الاحترافية')->firstOrFail();
        $basic = Plan::where('name', 'الباقة الأساسية')->firstOrFail();

        $shop = Business::create(['name' => 'متجر نامٍ', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $pro->id]);
        MerchantAccount::create($shop, 'growing', 'secret-pass');
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Branch::create(['business_id' => $shop->id, 'name' => 'السيب']);

        $this->actingAs($platform)->put(route('super-admin.businesses.update', $shop->id), [
            'name' => 'متجر نامٍ', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $basic->id,
        ])->assertSessionHasErrors('plan_id');

        $this->assertSame($pro->id, (int) $shop->fresh()->plan_id);
        $this->assertSame(2, Branch::where('business_id', $shop->id)->count());
    }

    /** والآلة لم تُنزع: باقةٌ يُنقص منها مفتاحٌ تُغلقه فعلًا */
    public function test_the_gate_still_works_when_a_plan_is_narrowed(): void
    {
        $narrow = Plan::create([
            'name' => 'باقة ضيّقة', 'monthly_price' => 1, 'yearly_price' => 10,
            'capabilities' => ['loyalty'],
        ]);

        $this->assertTrue(PlanFeatures::allowedBy($narrow, 'loyalty'));
        $this->assertFalse(PlanFeatures::allowedBy($narrow, 'reports_advanced'));
    }

    /** وشبكة البطاقات تُعيد نفسها إلى الافتراضي بضغطة — لا بطاقةً بطاقة */
    public function test_the_stat_grid_can_be_restored_to_its_default(): void
    {
        $grid = file_get_contents(resource_path('js/Components/StatGrid.tsx'));

        $this->assertStringContainsString("{t('إعادة الافتراضي')}", $grid);
        $this->assertMatchesRegularExpression(
            '/const restore = \(\) => \{\s*write\(key, \[\], setHidden\);\s*write\(addedKey, \[\], setAdded\);/u',
            $grid,
            'الزرّ يمسح المخفيّات ولا يمسح المضافات — أو العكس',
        );
    }
}
