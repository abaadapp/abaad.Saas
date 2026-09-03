<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Support\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الباقة حدٌّ في النظام لا سطرٌ في الفاتورة.
 *
 * `PlanLimits` تحرس الإنشاء: لا فرعَ حادي عشر لمن باقتُه عشرة. لكنّها
 * تقيس السقف **الحاليّ** — فتنزيلُ الباقة كان يمرّ بلا فحص، ومتجرٌ بثلاثة
 * فروعٍ يُنقَل إلى باقةِ فرعٍ واحد فتبقى الثلاثة تعمل. ولا شيء يقول إنّها
 * زائدة، ولا شيء يمنع التنزيل.
 */
class PlanBindsTheShopTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;
    private Business $business;
    private Plan $basic;
    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = User::create([
            'name' => 'مشغّل', 'email' => 'p@plan.local', 'password' => bcrypt('secret'),
            'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->basic = Plan::create([
            'name' => 'الأساسية', 'monthly_price' => 10, 'yearly_price' => 100,
            'max_branches' => 1, 'max_employees' => 3, 'max_products' => 10,
        ]);

        $this->pro = Plan::create([
            'name' => 'الاحترافية', 'monthly_price' => 30, 'yearly_price' => 300,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 100,
        ]);

        $this->business = Business::create([
            'name' => 'متجري', 'email' => 'b@plan.local', 'status' => 'نشط', 'plan_id' => $this->pro->id,
        ]);

        // حسابُ دخولٍ قائم — وإلّا طالب `syncAccount` باسمٍ وكلمةِ مرور
        \App\Support\MerchantAccount::create($this->business, 'mataji', 'secret-pass');
    }

    private function save(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->platform)->put(
            route('super-admin.businesses.update', $this->business->id),
            array_merge(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط'], $extra),
        );
    }

    /* --------------------------- السقف عند الإنشاء --------------------------- */

    public function test_a_shop_cannot_pass_its_branch_cap(): void
    {
        $this->business->update(['plan_id' => $this->basic->id]);
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);

        $owner = \App\Support\MerchantAccount::owner($this->business);

        $this->actingAs($owner)->post(route('admin.branches.store'), ['name' => 'صلالة'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Branch::where('business_id', $this->business->id)->count());
    }

    /* ---------------------------- تنزيل الباقة ---------------------------- */

    /**
     * ثلاثة فروعٍ لا تدخل باقةَ فرعٍ واحد.
     *
     * والمنع لا الإقفال التلقائيّ: إقفالُ فرعٍ فيه بيعُ اليوم وموظّفوه
     * قرارٌ لا يُتَّخذ بتغيير قائمةٍ منسدلة.
     */
    public function test_a_downgrade_the_shop_already_exceeds_is_refused(): void
    {
        foreach (['مسقط', 'صلالة', 'صحار'] as $name) {
            Branch::create(['business_id' => $this->business->id, 'name' => $name]);
        }

        $this->save(['plan_id' => $this->basic->id])->assertSessionHasErrors('plan_id');

        $this->assertSame($this->pro->id, (int) $this->business->fresh()->plan_id);
    }

    /** والرسالة تقول ما الزائد بالضبط — لا «غير مسموح» وحدها */
    public function test_the_refusal_names_what_exceeds(): void
    {
        foreach (['مسقط', 'صلالة'] as $name) {
            Branch::create(['business_id' => $this->business->id, 'name' => $name]);
        }
        for ($i = 0; $i < 12; $i++) {
            Product::create([
                'business_id' => $this->business->id, 'name' => "صنف {$i}",
                'price' => 1, 'cost' => 1, 'quantity' => 1,
            ]);
        }

        $over = PlanLimits::exceededBy($this->business, $this->basic);

        // الأرقام لا التسميات: التسمية تُترجَم بلغة الواجهة
        $this->assertCount(2, $over);
        $this->assertStringContainsString('2', $over[0]);   // فرعان من واحد
        $this->assertStringContainsString('12', $over[1]);  // اثنا عشر صنفًا من عشرة
    }

    public function test_a_downgrade_that_fits_is_allowed(): void
    {
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);

        $this->save(['plan_id' => $this->basic->id])->assertSessionHasNoErrors();

        $this->assertSame($this->basic->id, (int) $this->business->fresh()->plan_id);
    }

    public function test_an_upgrade_is_always_allowed(): void
    {
        $this->business->update(['plan_id' => $this->basic->id]);
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);

        $this->save(['plan_id' => $this->pro->id])->assertSessionHasNoErrors();

        $this->assertSame($this->pro->id, (int) $this->business->fresh()->plan_id);
    }

    /* ----------------------------- باقةٌ وهميّة ----------------------------- */

    /**
     * ورقمٌ لا باقةَ له كان يُقبل — فيصير المتجر بلا سقفٍ إطلاقًا.
     *
     * `$business->plan` يعود فارغًا، و`PlanLimits::cap` تقرأ الفراغ «لا
     * سقف». فباقةٌ وهميّة أوسعُ من باقة المؤسسات.
     */
    public function test_a_plan_id_that_does_not_exist_is_refused(): void
    {
        $this->save(['plan_id' => 999999])->assertSessionHasErrors('plan_id');

        $this->assertSame($this->pro->id, (int) $this->business->fresh()->plan_id);
    }

    public function test_a_shop_may_still_be_left_without_a_plan(): void
    {
        // متجرٌ أُنشئ قبل الباقات لا يُقفل لأنّ حقلًا فيه فارغ
        $this->save(['plan_id' => ''])->assertSessionHasNoErrors();

        $this->assertNull(PlanLimits::cap($this->business->fresh(), 'branches'));
    }
}
