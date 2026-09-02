<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\PlanFeatures;
use App\Support\SeedData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الباقات تفتح القدرات كلَّها — والفرق بينها في الأسقف.
 *
 * قرارُ صاحب المنصّة. والآلة باقيةٌ كما هي: `PlanFeatures` يفحص ويرفض كما
 * كان، فإغلاقُ قدرةٍ يومًا تأشيرةٌ واحدة في شاشة الباقات لا تعديلُ كود.
 *
 * وأشدُّ ما يُحرَس هنا أنّ قدرةً تُضاف غدًا إلى `PlanFeatures` لا تبقى مغلقةً
 * في الباقات الثلاث بلا أن يقول أحدٌ لماذا: القائمة تُقرأ من مصدرها، وهذا
 * الاختبار يسقط إن كُتبت بأسمائها من جديد.
 */
class EveryPlanOpensEverythingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeded_plans_grant_every_capability(): void
    {
        $all = PlanFeatures::keys();

        foreach (SeedData::plans() as $plan) {
            $this->assertEqualsCanonicalizing(
                $all, $plan['capabilities'], 'الباقة «'.$plan['name'].'» لا تفتح كلّ شيء'
            );
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

    /** والحارس يوافق: يُسأل عن كلّ قدرةٍ في كلّ باقة فيأذن */
    public function test_the_guard_agrees_for_every_plan_and_capability(): void
    {
        foreach (SeedData::plans() as $spec) {
            $plan = Plan::create([
                'name' => $spec['name'], 'monthly_price' => $spec['monthly'],
                'yearly_price' => $spec['yearly'], 'capabilities' => $spec['capabilities'],
            ]);

            foreach (PlanFeatures::keys() as $key) {
                $this->assertTrue(
                    PlanFeatures::allowedBy($plan, $key),
                    'الباقة «'.$plan->name.'» تُغلق «'.$key.'»'
                );
            }
        }
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
