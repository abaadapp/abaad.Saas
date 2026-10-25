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
use Illuminate\Support\Facades\DB;
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
 *   - الأسعارُ والأسقفُ والشعبيّةُ كما اتُّفق — من البذرة نفسِها. والأسقفُ سلّمٌ
 *     واحد بقرار 2026-10-17 (فروع 1·3·10، مستخدمون 3·15·100، منتجات
 *     500·10,000·بلا حدّ).
 *   - الأساسيّةُ لا تفتح قدرةً: `[]` لا `null` (قرارُ 2026-10-13 الثاني — لا
 *     واتساب ولا موقع ولا تطبيقات تكامليّة)، ونصوصُها الخمسةُ وحدها.
 *   - الاحترافيّةُ والمؤسساتُ تُقرآن من `PlanFeatures` — قدرةٌ تُضاف غدًا تُفتح فيهما.
 *   - لا نصَّ «غير محدود» إلّا على موردٍ سقفُه `null` فعلًا، ولا وعدَ بما لا يُقدَّم.
 *   - السقفُ يُفرض على الباقة المزروعة، والتنزيلُ إلى ما يضيق يُردّ ولا يمحو شيئًا.
 */
class TheDefaultPlansSellWhatTheySayTest extends TestCase
{
    use RefreshDatabase;

    /** الاسم ← [شهريّ، سنويّ، فروع، مستخدمون نشطون، منتجات، الأكثر طلبًا] — `null` لا سقف */
    private const AGREED = [
        'الباقة الأساسية' => ['9.900', '99.000', 1, 3, 500, false],
        'الباقة الاحترافية' => ['29.900', '299.000', 3, 15, 10000, true],
        'باقة المؤسسات' => ['69.900', '699.000', 10, 100, null, false],
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
            $caps = array_map(fn ($v) => $v === null ? null : (int) $v, [$plan->max_branches, $plan->max_employees, $plan->max_products]);
            $this->assertSame([$branches, $employees, $products], $caps, $name);
            $this->assertSame($popular, (bool) $plan->is_popular, $name);
        }
    }

    public function test_the_basic_plan_opens_nothing_and_the_others_open_everything(): void
    {
        $plans = $this->seeded();

        /*
         * والأساسيّةُ تشغيلٌ يوميٌّ وحده — قائمةٌ فارغة لا `null`.
         *
         * كانت `['whatsapp']` لرقم أبعاد وحصّته. وقرارُ 2026-10-13 الثاني:
         * لا واتساب فيها ولا موقع ولا تطبيقات تكامليّة. والفراغُ عند الحارس
         * «كلُّ شيءٍ مغلق»، و`null` «كلُّ شيءٍ مفتوح» — فالفرقُ بينهما هو
         * الباقةُ كلُّها.
         */
        $this->assertSame([], $plans['الباقة الأساسية']['capabilities']);
        $this->assertSame(
            ['إدارة متجرك اليومية', 'فرع واحد', '3 مستخدمين مع المالك', '500 منتج', 'الأدوات التشغيلية الأساسية'],
            $plans['الباقة الأساسية']['features'],
        );

        // والاحترافيّةُ كلُّ ما قبل المؤسسات، والمؤسساتُ كلُّ شيء (`PlanFeatures::ENTERPRISE`)
        $this->assertEqualsCanonicalizing(PlanFeatures::baseKeys(), $plans['الباقة الاحترافية']['capabilities']);
        $this->assertEqualsCanonicalizing(PlanFeatures::keys(), $plans['باقة المؤسسات']['capabilities']);
    }

    /** والحارسُ يوافق البذرة — قدرةً قدرة، باقةً باقة */
    public function test_the_guard_agrees_with_every_seeded_plan(): void
    {
        // والأساسيّةُ تُغلق كلَّ قدرة — قدرةٌ تُضاف غدًا تُغلق فيها كذلك حتى يُقرَّر غيرُه
        $closedOnBasic = PlanFeatures::keys();

        foreach (SeedData::plans() as $spec) {
            $plan = Plan::create([
                'name' => $spec['name'], 'monthly_price' => $spec['monthly'],
                'yearly_price' => $spec['yearly'], 'capabilities' => $spec['capabilities'],
            ]);

            foreach (PlanFeatures::keys() as $key) {
                $expected = ! ($spec['name'] === 'الباقة الأساسية' && in_array($key, $closedOnBasic, true))
                    // وقدراتُ المؤسسات لا تُفتح في الاحترافيّة
                    && ! ($spec['name'] === 'الباقة الاحترافية' && in_array($key, PlanFeatures::ENTERPRISE, true));
                $this->assertSame($expected, PlanFeatures::allowedBy($plan, $key), "«{$spec['name']}» و«{$key}»");
            }
        }
    }

    /**
     * ولا تُترك `null`: الحارسُ يقرؤها «مفتوحةً كلَّها».
     *
     * والأساسيّةُ قائمةٌ **فارغة** مقصودة — وهي غيرُ `null` تمامًا.
     */
    public function test_the_capabilities_are_listed_not_left_null(): void
    {
        foreach (SeedData::plans() as $plan) {
            $this->assertIsArray($plan['capabilities'], $plan['name']);

            if ($plan['name'] !== 'الباقة الأساسية') {
                $this->assertNotEmpty($plan['capabilities'], $plan['name']);
            }
        }

        // والمزروعةُ في القاعدة كذلك: `[]` تُقرأ `[]` لا `null`
        $this->seed(DatabaseSeeder::class);
        $basic = Plan::where('name', 'الباقة الأساسية')->firstOrFail();
        $this->assertSame([], $basic->capabilities);
        $this->assertSame('[]', DB::table('plans')->where('id', $basic->id)->value('capabilities'));
    }

    /** والنصُّ لا يعد بما لا يُفرض ولا بما لا يُقدَّم */
    public function test_the_marketing_lines_promise_nothing_the_system_does_not_enforce(): void
    {
        foreach (SeedData::plans() as $plan) {
            $text = implode(' | ', $plan['features']);

            foreach (['كل القدرات', 'مدير حساب', 'على مدار الساعة'] as $never) {
                $this->assertStringNotContainsString($never, $text, "«{$plan['name']}» تعد بـ«{$never}»");
            }

            /*
             * و«غير محدود» صادقةٌ حيث لا سقف — وكذبٌ فوق سقفٍ يُفرض.
             *
             * كانت ممنوعةً كلَّها: كلُّ موردٍ في البذرة كان له سقف. ثمّ صارت
             * منتجاتُ المؤسسات بلا حدّ (`null` — قرارُ 2026-10-17). فالسطرُ الذي
             * يقولها يسمّي موردًا، وسقفُ ذلك المورد `null` وإلّا سقط.
             */
            [$branches, $employees, $products] = $plan['limits'];
            $unbounded = ['فروع' => $branches, 'موظف' => $employees, 'مستخدم' => $employees, 'منتج' => $products];
            foreach ($plan['features'] as $line) {
                if (! str_contains($line, 'غير محدود')) {
                    continue;
                }
                $named = array_filter($unbounded, fn ($word) => str_contains($line, $word), ARRAY_FILTER_USE_KEY);
                $this->assertNotEmpty($named, "«{$plan['name']}»: «{$line}» لا تسمّي موردًا");
                foreach ($named as $word => $cap) {
                    $this->assertNull($cap, "«{$plan['name']}»: «{$line}» وسقفُ {$word} {$cap}");
                }
            }
        }

        $basic = implode(' | ', $this->seeded()['الباقة الأساسية']['features']);
        foreach (['فرع واحد', '3 مستخدمين مع المالك', '500 منتج'] as $line) {
            $this->assertStringContainsString($line, $basic);
        }
    }

    /**
     * ونصُّ العرض يقول السقفَ نفسَه — رقمًا رقمًا.
     *
     * كان «100,000 منتج» في نصّ باقةٍ سقفُها في القاعدة شيءٌ آخر، و«15 موظف»
     * فوق سقفٍ يعدّ المالكَ معهم. فكلُّ سقفٍ يُكتب في نصوص باقته كما هو.
     */
    public function test_each_plan_states_its_own_caps_in_its_lines(): void
    {
        foreach (SeedData::plans() as $plan) {
            $text = implode(' | ', $plan['features']);
            [$branches, $employees, $products] = $plan['limits'];

            if ($branches > 1) {
                $this->assertStringContainsString(number_format($branches).' فر', $text, $plan['name']);
            }
            $this->assertStringContainsString(number_format($employees).' مستخدم', $text, $plan['name']);
            $this->assertStringContainsString('مع المالك', $text, "«{$plan['name']}»: المالك يُعدّ مستخدمًا نشطًا");
            $this->assertStringContainsString($products === null ? 'منتجات غير محدودة' : number_format($products).' منتج', $text, $plan['name']);
        }
    }

    /** والسقفُ المزروع يُفرض — المنتجُ الخمسمئة يُقبل والذي بعده يُردّ */
    public function test_the_seeded_basic_caps_are_enforced(): void
    {
        $this->seed(DatabaseSeeder::class);
        $basic = Plan::where('name', 'الباقة الأساسية')->firstOrFail();
        $shop = Business::create(['name' => 'متجر صغير', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $basic->id]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);

        foreach (range(1, 499) as $n) {
            Product::create(['business_id' => $shop->id, 'name' => 'صنف '.$n, 'price' => 1]);
        }
        $this->assertFalse(PlanLimits::reached($shop->fresh(), 'products'));

        Product::create(['business_id' => $shop->id, 'name' => 'صنف 500', 'price' => 1]);
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
