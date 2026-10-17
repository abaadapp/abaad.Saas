<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Plan;
use App\Support\PlanFeatures;
use App\Support\PlanLimits;
use App\Support\SeedData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الباقاتُ القائمة تُنقل إلى سلّم الحدود — والسقفُ الجديد يُفرض فعلًا.
 *
 * كانت الأرقامُ ثلاثةَ أشياء: ما على الإنتاج (المؤسسات 999 فرعًا)، وما في
 * البذرة (25)، وما على الموقع (25). وقرارُ 2026-10-17 سلّمٌ واحد — والحدُّ
 * الذي يُفرض هو ما في القاعدة، فالترحيلُ هو ما يجعل القرارَ حقيقة.
 *
 * والقاعدةُ هنا كما كانت على الإنتاج يوم كُتب الترحيل: أسعارُها ونصوصُها
 * وقدراتُها — كي يُرى أنّه يكتب السقوفَ والنصوصَ وحدها.
 */
class ThePlansClimbOneLadderOfLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_17_120000_the_plans_climb_one_ladder_of_limits.php';

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    /** الباقاتُ كما كانت على الإنتاج — بأسعارها لا بأسعار البذرة */
    private function livePlans(): array
    {
        $before = $this->migration()::BEFORE;
        $make = fn (string $name, ?string $code, string $monthly, array $limits, ?array $caps, bool $popular) => Plan::create([
            'name' => $name, 'code' => $code, 'monthly_price' => $monthly, 'yearly_price' => (float) $monthly * 10,
            'max_branches' => $limits[0], 'max_employees' => $limits[1], 'max_products' => $limits[2],
            'features' => $limits[3], 'capabilities' => $caps, 'is_popular' => $popular,
        ]);

        return [
            'basic' => $make('الباقة الأساسية', 'basic', '9.900', $before['basic'], [], false),
            'pro' => $make('الباقة الاحترافية', null, '24.900', $before['الباقة الاحترافية'], PlanFeatures::keys(), true),
            'enterprise' => $make('باقة المؤسسات', null, '59.900', $before['باقة المؤسسات'], PlanFeatures::keys(), false),
        ];
    }

    private function caps(Plan $plan): array
    {
        $row = DB::table('plans')->where('id', $plan->id)->first(['max_branches', 'max_employees', 'max_products']);

        return array_map(fn ($v) => $v === null ? null : (int) $v, [$row->max_branches, $row->max_employees, $row->max_products]);
    }

    public function test_the_live_plans_take_the_agreed_caps_and_lines(): void
    {
        $plans = $this->livePlans();
        $custom = Plan::create([
            'name' => 'باقة خاصة لسلسلة', 'monthly_price' => 150, 'yearly_price' => 1500,
            'max_branches' => 40, 'max_employees' => 400, 'max_products' => null, 'features' => ['40 فرعًا'],
        ]);

        $this->migration()->up();

        $this->assertSame([1, 3, 500], $this->caps($plans['basic']));
        $this->assertSame([3, 15, 10000], $this->caps($plans['pro']));
        $this->assertSame([10, 100, null], $this->caps($plans['enterprise']), 'المؤسسات: عشرة فروع، ومنتجاتٌ بلا حدّ');

        $this->assertSame(['إدارة متجرك اليومية', 'فرع واحد', '3 مستخدمين مع المالك', '500 منتج', 'الأدوات التشغيلية الأساسية'], $plans['basic']->fresh()->features);
        $this->assertContains('10,000 منتج', $plans['pro']->fresh()->features);
        $this->assertNotContains('دعم فني على مدار الساعة', $plans['pro']->fresh()->features);
        $this->assertContains('10 فروع', $plans['enterprise']->fresh()->features);
        foreach (['فروع غير محدودة', 'موظفون غير محدودين', 'API ووصول كامل', 'مدير حساب مخصص'] as $gone) {
            $this->assertNotContains($gone, $plans['enterprise']->fresh()->features);
        }

        // والسعرُ والقدراتُ والاسمُ والشعبيّةُ كما كانت — الأسعارُ قرارٌ لم يُتّخذ بعد
        foreach (['basic' => '9.900', 'pro' => '24.900', 'enterprise' => '59.900'] as $key => $monthly) {
            $fresh = $plans[$key]->fresh();
            $this->assertSame($monthly, (string) $fresh->monthly_price, $key);
            $this->assertSame($plans[$key]->capabilities, $fresh->capabilities, $key);
            $this->assertSame($plans[$key]->name, $fresh->name, $key);
            $this->assertSame((bool) $plans[$key]->is_popular, (bool) $fresh->is_popular, $key);
        }

        // وباقةٌ خاصّةٌ أنشأها المشغّل لا تُمسّ
        $this->assertSame([40, 400, null], $this->caps($custom));
        $this->assertSame(['40 فرعًا'], $custom->fresh()->features);
    }

    public function test_a_migrated_install_and_a_fresh_one_sell_the_same_caps(): void
    {
        /*
         * والبذرةُ والترحيلُ مصدران لرقمٍ واحد: تثبيتٌ جديد يأخذه من البذرة،
         * وقائمٌ يأخذه من الترحيل. فإن افترقا صار للباقة الواحدة سقفان.
         */
        $after = $this->migration()::AFTER;
        $seeded = collect(SeedData::plans())->keyBy(fn ($p) => ($p['code'] ?? null) === 'basic' ? 'basic' : $p['name']);

        $this->assertSame(array_keys($after), $seeded->keys()->all());
        foreach ($after as $which => [$branches, $employees, $products, $features]) {
            $this->assertSame([$branches, $employees, $products], $seeded[$which]['limits'], $which);
            $this->assertSame($features, $seeded[$which]['features'], $which);
        }
    }

    public function test_a_plan_it_cannot_tell_apart_is_left_alone(): void
    {
        /*
         * باقتان بالاسم نفسه: أيُّهما المؤسسات؟ — لا يُختار. وسقفٌ يُكتب على
         * الخاطئة يُغلق متجرًا لم يتجاوز ما اشتراه.
         */
        $before = $this->migration()::BEFORE['باقة المؤسسات'];
        $twins = collect([1, 2])->map(fn ($n) => Plan::create([
            'name' => 'باقة المؤسسات', 'monthly_price' => 59.9, 'yearly_price' => 599,
            'max_branches' => $before[0], 'max_employees' => $before[1], 'max_products' => $before[2],
        ]));
        // والأساسيّةُ برمزها وحده — اسمُها بلا رمزٍ لا يكفي
        $unmarked = Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99, 'max_branches' => 1, 'max_employees' => 3, 'max_products' => 100]);

        $this->migration()->up();

        $twins->each(fn ($plan) => $this->assertSame([999, 999, 1000000], $this->caps($plan)));
        $this->assertSame([1, 3, 100], $this->caps($unmarked));
    }

    public function test_down_restores_what_was_live_unless_the_operator_changed_it_since(): void
    {
        $plans = $this->livePlans();
        $this->migration()->up();

        // المشغّل يعدّل الاحترافيّة من شاشة الباقات بعد الترحيل
        $plans['pro']->update(['max_products' => 20000]);

        $this->migration()->down();

        $this->assertSame([1, 3, 100], $this->caps($plans['basic']));
        $this->assertSame([999, 999, 1000000], $this->caps($plans['enterprise']));
        $this->assertSame($this->migration()::BEFORE['باقة المؤسسات'][3], $plans['enterprise']->fresh()->features);
        $this->assertSame([3, 15, 20000], $this->caps($plans['pro']), 'ما عدّله المشغّل بعد الترحيل لا يُمحى');
    }

    public function test_the_new_caps_are_enforced(): void
    {
        $plans = $this->livePlans();
        $this->migration()->up();

        // المؤسسات: الفرعُ العاشر يُقبل والحادي عشر يُردّ — كان يُقبل حتى 999
        $chain = Business::create(['name' => 'سلسلة', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $plans['enterprise']->id]);
        foreach (range(1, 9) as $n) {
            Branch::create(['business_id' => $chain->id, 'name' => 'فرع '.$n]);
        }
        $this->assertFalse(PlanLimits::reached($chain->fresh(), 'branches'));
        Branch::create(['business_id' => $chain->id, 'name' => 'فرع 10']);
        $this->assertTrue(PlanLimits::reached($chain->fresh(), 'branches'));

        // ومنتجاتُ المؤسسات بلا سقف
        $this->assertNull(PlanLimits::cap($chain->fresh(), 'products'));
        $this->assertFalse(PlanLimits::reached($chain->fresh(), 'products'));
        $this->assertSame(100, PlanLimits::cap($chain->fresh(), 'employees'));

        // والأساسيّةُ خمسمئة، والاحترافيّةُ عشرةُ آلاف
        $small = Business::create(['name' => 'محل', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $plans['basic']->id]);
        $growing = Business::create(['name' => 'محلات', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $plans['pro']->id]);
        $this->assertSame(500, PlanLimits::cap($small, 'products'));
        $this->assertSame(10000, PlanLimits::cap($growing, 'products'));
    }
}
