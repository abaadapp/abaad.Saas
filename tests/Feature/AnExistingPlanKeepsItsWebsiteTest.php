<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPlanFeature;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\PlanFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * النشرُ لا يُغلق على متجرٍ قائمٍ ما كان مفتوحًا له قبله.
 *
 * ═══ العطب الذي يُحرَس ═══
 *
 * قوائمُ القدرات على الإنتاج صريحة (`['loyalty','whatsapp']` وأخواتها)، ولا
 * تعرف `website` ولا `marketing_tools`. والحارسُ يقرأ الغائبَ من قائمةٍ صريحة
 * مغلقًا — فلو نُشرت القدرتان وحدهما لَأُغلق الموقعُ العامّ وأدواتُ التسويق
 * على كلّ متجرٍ قائم لحظةَ النشر. والترحيلُ يضيفهما إلى كلّ قائمةٍ صريحة.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - القائمةُ القديمة تكسب القدرتين ولا تفقد شيئًا ولا يتبدّل ترتيبُها.
 *   - الفارغةُ تكسبهما، و`null` تبقى `null` (مفتوحةً كما كانت).
 *   - لا يُعرف شيءٌ بالاسم: باقةٌ سمّاها مدير المنصّة بيده تُعامَل كغيرها.
 *   - الإعادةُ بلا أثر، والتراجعُ يُسقطهما وحدهما.
 *   - ولا تُغلق القدرتان شيئًا بعد — لا مسارَ في الخريطة يقرؤهما، ومتجرٌ يبيع
 *     من موقعه على باقةٍ قديمة يبقى كما هو قبل الترحيل وبعده. فهذه مرحلةٌ
 *     أولى تُعرّف القاعدةَ بالمفتاحين، وحارساهما في الطلب التالي.
 */
class AnExistingPlanKeepsItsWebsiteTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_13_090000_a_plan_keeps_the_website_it_already_had.php');
    }

    /** باقةٌ كما كانت على الإنتاج قبل هذا الإصدار — بلا المرور بالنموذج */
    private function oldPlan(string $name, ?array $capabilities): int
    {
        return DB::table('plans')->insertGetId([
            'name' => $name, 'monthly_price' => 9.9, 'yearly_price' => 99,
            'capabilities' => $capabilities === null ? null : json_encode($capabilities),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function raw(int $id): ?array
    {
        $value = DB::table('plans')->where('id', $id)->value('capabilities');

        return $value === null ? null : json_decode((string) $value, true);
    }

    public function test_every_explicit_list_gains_both_and_loses_nothing(): void
    {
        $basic = $this->oldPlan('الباقة الأساسية', ['loyalty', 'whatsapp']);
        $pro = $this->oldPlan('الباقة الاحترافية', ['loyalty', 'whatsapp', 'reports_advanced', 'custom_permissions']);
        $custom = $this->oldPlan('باقة رمضان الخاصّة', ['reports_advanced']);
        $empty = $this->oldPlan('باقة مغلقة', []);

        $this->migration()->up();

        $this->assertSame(['loyalty', 'whatsapp', 'website', 'marketing_tools'], $this->raw($basic));
        $this->assertSame(['loyalty', 'whatsapp', 'reports_advanced', 'custom_permissions', 'website', 'marketing_tools'], $this->raw($pro));
        $this->assertSame(['reports_advanced', 'website', 'marketing_tools'], $this->raw($custom), 'باقةٌ بغير الأسماء الثلاثة لم تُعامَل كأخواتها');
        $this->assertSame(['website', 'marketing_tools'], $this->raw($empty));

        foreach ([$basic, $pro, $custom, $empty] as $id) {
            $plan = Plan::findOrFail($id);
            $this->assertTrue(PlanFeatures::allowedBy($plan, 'website'), $plan->name);
            $this->assertTrue(PlanFeatures::allowedBy($plan, 'marketing_tools'), $plan->name);
        }

        // وما كان مغلقًا يبقى مغلقًا — الترحيلُ لا يفتح غيرَ القدرتين
        $this->assertFalse(PlanFeatures::allowedBy(Plan::findOrFail($basic), 'reports_advanced'));
    }

    public function test_null_stays_null_and_open(): void
    {
        $open = $this->oldPlan('باقة قديمة', null);

        $this->migration()->up();

        $this->assertNull($this->raw($open));
        $this->assertTrue(PlanFeatures::allowedBy(Plan::findOrFail($open), 'website'));
    }

    public function test_running_it_again_writes_nothing_twice(): void
    {
        $half = $this->oldPlan('نصف', ['whatsapp', 'website']);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(['whatsapp', 'website', 'marketing_tools'], $this->raw($half));
    }

    public function test_rolling_back_drops_the_two_keys_and_nothing_else(): void
    {
        $basic = $this->oldPlan('الباقة الأساسية', ['loyalty', 'whatsapp']);
        $open = $this->oldPlan('باقة قديمة', null);

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(['loyalty', 'whatsapp'], $this->raw($basic));
        $this->assertNull($this->raw($open));
    }

    /**
     * والقدرتان لا تُغلقان بابًا بعد — لا في اللوحة ولا خارجها.
     *
     * فالكودُ ينزل قبل الترحيل بدقائق، وفيها تقرأ كلُّ قائمةٍ قديمة القدرتين
     * غائبتين. ولا يضرّ ذلك ما دام لا حارسَ يسأل عنهما — وهذا ما يُحرس هنا.
     * ويوم يصل الحارسان يُستبدل هذا الاختبار بأنّ الترحيلَ هو ما يُبقي الباب
     * مفتوحًا.
     */
    public function test_neither_new_capability_closes_a_door_yet(): void
    {
        $guarded = collect(Router::getRoutes()->getRoutes())
            ->map(fn (Route $r) => (string) $r->getName())
            ->filter(fn ($n) => in_array(CheckPlanFeature::featureFor($n), ['website', 'marketing_tools'], true))
            ->values()->all();

        $this->assertSame([], $guarded, 'مسارٌ صار يُغلق بقدرةٍ لم يصل ترحيلُها بعد');
    }

    /** والمتجرُ نفسُه: يبيع من موقعه على باقةٍ قديمة — قبل الترحيل وبعده */
    public function test_a_shop_selling_from_its_site_on_an_old_plan_notices_nothing(): void
    {
        $plan = $this->oldPlan('الباقة الأساسية', ['loyalty', 'whatsapp']);
        $shop = Business::create(['name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567', 'plan_id' => $plan]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => 'o@keep.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        Product::create(['business_id' => $shop->id, 'name' => 'بوكيه ورد', 'price' => 12.5, 'cost' => 5, 'quantity' => 10, 'alert_qty' => 2, 'active' => true]);
        $shop->forceFill(['site_slug' => 'ward-alkhuwair'])->save();
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1']);

        // الكودُ نزل والترحيلُ لم يُشغَّل — والقائمةُ القديمة لا تعرف القدرتين
        $this->assertFalse(PlanFeatures::allows($shop->fresh(), 'website'));
        $this->assertSame(['loyalty', 'whatsapp'], $this->raw($plan));

        $before = $this->doors($owner, 'BEFORE');
        $this->migration()->up();
        $after = $this->doors($owner, 'AFTER');

        $this->assertSame(['site' => 200, 'panel' => 200, 'reviews' => 200, 'coupons' => 200, 'coupon_saved' => true], $before, 'أُغلق بابٌ قبل الترحيل');
        $this->assertSame($before, $after, 'تغيّر بابٌ بالترحيل');
    }

    /**
     * ما يراه التاجرُ وزبونُه — الموقعُ العامّ ولوحتُه والتقييماتُ والكوبونات.
     *
     * والمستخدمُ يُقرأ من جديد في كلّ نداء — كما في كلّ طلبٍ حقيقيّ؛ نسخةُ
     * الطلب السابق تحمل الباقةَ قبل الترحيل.
     *
     * @return array<string, int|bool>
     */
    private function doors(User $owner, string $code): array
    {
        $site = $this->get(route('store.show', 'ward-alkhuwair'));
        $site->assertSee('بوكيه ورد');

        $as = fn () => $this->actingAs($owner->fresh());
        $as()->post(route('admin.coupons.store'), ['code' => $code, 'type' => 'نسبة', 'value' => 10]);

        return [
            'site' => $site->status(),
            'panel' => $as()->get(route('admin.website.index'))->status(),
            'reviews' => $as()->get(route('admin.marketing.reviews'))->status(),
            'coupons' => $as()->get(route('admin.marketing.coupons'))->status(),
            'coupon_saved' => Coupon::where('business_id', $owner->business_id)->where('code', $code)->exists(),
        ];
    }
}
