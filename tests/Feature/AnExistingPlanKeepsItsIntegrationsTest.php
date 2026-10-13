<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use App\Support\PlanFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * النشرُ لا يُغلق على متجرٍ قائمٍ تطبيقاتِه التكامليّة.
 *
 * ═══ العطب الذي يُحرَس ═══
 *
 * قوائمُ القدرات على الإنتاج صريحة ولا تعرف `integrations`. والحارسُ يقرأ
 * الغائبَ من قائمةٍ صريحة مغلقًا — فلو نزل حارسُها والقوائمُ لا تعرفها
 * لَأُغلقت التكاملاتُ على كلّ متجرٍ قائم. فتنزل القدرةُ أوّلًا مع ترحيلٍ يضيفها
 * إلى كلّ قائمةٍ صريحة (#109)، ثمّ يصل حارسُها — كما نزل الموقع (#107 ثمّ #106).
 *
 * ═══ وما يُحرَس ═══
 *
 *   - القائمةُ القديمة تكسبها مرّةً ولا تفقد شيئًا ولا يتبدّل ترتيبُها.
 *   - الفارغةُ تكسبها، و`null` تبقى `null` (مفتوحةً كما كانت).
 *   - لا سعرَ ولا سقفَ ولا اسمَ ولا نصَّ يُمسّ، ولا يُعرف شيءٌ بالاسم.
 *   - الإعادةُ بلا أثر، والتراجعُ يُسقطها وحدها.
 *   - ومتجرٌ على باقةٍ قديمة: تُغلَق تكاملاتُه بلا الترحيل، وتُفتح بعده.
 */
class AnExistingPlanKeepsItsIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_13_180000_a_plan_keeps_the_integrations_it_already_had.php');
    }

    /** باقةٌ كما كانت على الإنتاج قبل هذا الإصدار — بلا المرور بالنموذج */
    private function oldPlan(string $name, ?array $capabilities, array $extra = []): int
    {
        return DB::table('plans')->insertGetId([
            'name' => $name, 'monthly_price' => 9.9, 'yearly_price' => 99,
            'capabilities' => $capabilities === null ? null : json_encode($capabilities),
            'created_at' => now(), 'updated_at' => now(),
        ] + $extra);
    }

    private function raw(int $id): ?array
    {
        $value = DB::table('plans')->where('id', $id)->value('capabilities');

        return $value === null ? null : json_decode((string) $value, true);
    }

    public function test_every_explicit_list_gains_integrations_once_and_loses_nothing(): void
    {
        $basic = $this->oldPlan('الباقة الأساسية', ['whatsapp']);
        $pro = $this->oldPlan('الباقة الاحترافية', ['reports_advanced', 'custom_permissions', 'loyalty', 'whatsapp', 'website', 'marketing_tools']);
        $custom = $this->oldPlan('باقة رمضان الخاصّة', ['website', 'reports_advanced']);
        $empty = $this->oldPlan('باقة مغلقة', []);

        $this->migration()->up();

        $this->assertSame(['whatsapp', 'integrations'], $this->raw($basic));
        $this->assertSame(['reports_advanced', 'custom_permissions', 'loyalty', 'whatsapp', 'website', 'marketing_tools', 'integrations'], $this->raw($pro));
        $this->assertSame(['website', 'reports_advanced', 'integrations'], $this->raw($custom), 'باقةٌ بغير الأسماء الثلاثة لم تُعامَل كأخواتها');
        $this->assertSame(['integrations'], $this->raw($empty));

        foreach ([$basic, $pro, $custom, $empty] as $id) {
            $this->assertTrue(PlanFeatures::allowedBy(Plan::findOrFail($id), 'integrations'));
        }

        // وما كان مغلقًا يبقى مغلقًا — الترحيلُ لا يفتح غيرَها
        $this->assertFalse(PlanFeatures::allowedBy(Plan::findOrFail($basic), 'website'));
        $this->assertFalse(PlanFeatures::allowedBy(Plan::findOrFail($empty), 'whatsapp'));
    }

    public function test_prices_caps_names_and_lines_are_not_touched(): void
    {
        $id = $this->oldPlan('الباقة الأساسية', ['whatsapp'], [
            'monthly_price' => 9.9, 'yearly_price' => 99,
            'max_branches' => 1, 'max_employees' => 3, 'max_products' => 100,
            'features' => json_encode(['إدارة متجرك اليومية', 'فرع واحد']), 'is_popular' => false,
        ]);
        $before = (array) DB::table('plans')->where('id', $id)->first();

        $this->migration()->up();

        $after = (array) DB::table('plans')->where('id', $id)->first();
        unset($before['capabilities'], $after['capabilities'], $before['updated_at'], $after['updated_at']);

        $this->assertSame($before, $after, 'الترحيلُ مسّ غيرَ القدرات');
    }

    public function test_null_stays_null_and_open(): void
    {
        $open = $this->oldPlan('باقة قديمة', null);

        $this->migration()->up();

        $this->assertNull($this->raw($open));
        $this->assertTrue(PlanFeatures::allowedBy(Plan::findOrFail($open), 'integrations'));
    }

    public function test_running_it_again_writes_nothing_twice(): void
    {
        $plan = $this->oldPlan('نصف', ['whatsapp']);
        $already = $this->oldPlan('سبقت', ['integrations', 'whatsapp']);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(['whatsapp', 'integrations'], $this->raw($plan));
        $this->assertSame(['integrations', 'whatsapp'], $this->raw($already), 'قائمةٌ تعرفها أُعيد ترتيبُها');
    }

    public function test_rolling_back_drops_integrations_and_nothing_else(): void
    {
        $basic = $this->oldPlan('الباقة الأساسية', ['whatsapp']);
        $empty = $this->oldPlan('باقة مغلقة', []);
        $open = $this->oldPlan('باقة قديمة', null);

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(['whatsapp'], $this->raw($basic));
        $this->assertSame([], $this->raw($empty));
        $this->assertNull($this->raw($open));
    }

    /**
     * والمتجرُ نفسُه: على باقةٍ قديمة — وبلا الترحيل تُغلَق تكاملاتُه.
     *
     * النصفُ الأوّل يُثبت أنّ الخطر حقيقيّ (الحارسُ يُغلق ما لم يُرحَّل)،
     * والثاني أنّ الترحيل يُبقيه مفتوحًا — صفحتَه وGoogle.
     */
    public function test_a_shop_on_an_old_plan_keeps_its_integrations(): void
    {
        Http::fake();

        $plan = $this->oldPlan('الباقة الأساسية', ['whatsapp']);
        $shop = Business::create(['name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط', 'plan_id' => $plan]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => 'o@integrations.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->actingAs($owner)->get(route('admin.integrations.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.integrations.google'))->assertForbidden();

        $this->migration()->up();

        // والمستخدمُ يُقرأ من جديد — كما في كلّ طلبٍ حقيقيّ؛ نسخةُ الطلب السابق تحمل الباقةَ قبل الترحيل
        $this->actingAs($owner->fresh())->get(route('admin.integrations.index'))->assertOk();
        $this->actingAs($owner->fresh())->get(route('admin.integrations.google'))->assertOk();
    }
}
