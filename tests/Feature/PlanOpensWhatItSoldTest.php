<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Permissions;
use App\Support\PlanFeatures;
use App\Support\Reports;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * الباقة تفتح ما بيعت به — والوعدُ الذي لا يُفرَض ليس وعدًا.
 *
 * كان في الباقة عمودان من نوعين: أسقفٌ عدديّة تُفرَض، و`features` قائمةُ
 * نصوصٍ حرّة تُعرض في صفحة التسعير ولا يقرؤها حارسٌ واحد. فمن اشترى الأرخص
 * يفتح «التقارير المتقدّمة» و«الصلاحيات المخصّصة» كما يفتحها من اشترى أغلاها.
 *
 * وهذا ليس عطبًا في شاشة: هو تسريبُ إيراد — من اكتشفه لا يرقّي أبدًا ولا
 * يخبر أحدًا.
 *
 * والقفلُ الخاطئ هنا أسوأ من الفتح الخاطئ: من فُتح له ما لم يشترِه يُكتشف
 * ويُصحَّح، ومن أُغلق دونه ما اشتراه يتوقّف عمله اليوم. فكلّ اختبارٍ يقفل
 * يقابله اختبارٌ يفتح.
 */
class PlanOpensWhatItSoldTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** يضع المتجر على باقةٍ بقدراتٍ بعينها — و`null` تعني «لم تُضبط» */
    private function onPlan(?array $capabilities, string $name = 'الباقة الأساسية'): Plan
    {
        $plan = Plan::create([
            'name' => $name, 'monthly_price' => 9.9, 'yearly_price' => 99,
            'capabilities' => $capabilities,
        ]);

        $this->business->update(['plan_id' => $plan->id]);
        $this->business->refresh();

        return $plan;
    }

    /* ====================== الأصل: مفتوحٌ لا مغلق ====================== */

    public function test_a_shop_with_no_plan_at_all_keeps_everything(): void
    {
        /*
         * متجرٌ أُنشئ قبل الباقات لا يُقفل لأنّ حقلًا فيه فارغ — كما في
         * `PlanLimits`: «بلا باقة لا سقف».
         */
        foreach (array_keys(PlanFeatures::CAPABILITIES) as $key) {
            $this->assertTrue(PlanFeatures::allows($this->business, $key), "أُغلق «{$key}» على متجرٍ بلا باقة");
        }

        $this->actingAs($this->owner)->get(route('admin.reports.waste'))->assertOk();
    }

    public function test_a_plan_whose_capabilities_were_never_set_keeps_everything(): void
    {
        // `null` تعني «لم تُضبط» لا «لا شيء» — والفرق بينهما متجرٌ يتوقّف عمله
        $this->onPlan(null);

        $this->assertTrue(PlanFeatures::allows($this->business->fresh(), 'reports_advanced'));
        $this->actingAs($this->owner)->get(route('admin.reports.waste'))->assertOk();
    }

    public function test_a_capability_that_is_granted_opens_its_door(): void
    {
        $this->onPlan(['reports_advanced']);

        $this->actingAs($this->owner)->get(route('admin.reports.waste'))->assertOk();
    }

    /* ========================= التقارير المتقدّمة ========================= */

    public function test_a_plan_without_advanced_reports_closes_the_waste_screen(): void
    {
        $this->onPlan(['loyalty']);

        $this->actingAs($this->owner)->get(route('admin.reports.waste'))->assertForbidden();
    }

    public function test_the_refusal_names_the_plan_and_the_feature(): void
    {
        /*
         * 403 وحدها لا تقول شيئًا: من يصطدم بها اشترى نظامًا وله أن يعرف أنّ
         * البابَ موجودٌ وأنّ باقتَه لا تفتحه — وإلّا ظنّ العطب في النظام
         * فاتّصل بالدعم بدل أن يرقّي.
         */
        $this->onPlan([], 'الباقة الأساسية');

        $message = PlanFeatures::refusal($this->business->fresh(), 'reports_advanced');

        $this->assertStringContainsString('التقارير المتقدّمة', $message);
        $this->assertStringContainsString('الباقة الأساسية', $message);
    }

    public function test_the_report_exports_are_closed_with_the_screen(): void
    {
        // قفلُ الشاشة وحدها وترك ملفّها مفتوحًا يعني عنوانًا يُكتب فيصل الملفّ
        $this->onPlan([]);

        $this->actingAs($this->owner)->get(route('admin.reports.xlsx'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('admin.reports.pdf'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('admin.export.reports'))->assertForbidden();
    }

    public function test_the_plain_sales_summary_stays_open_to_the_basic_plan(): void
    {
        /*
         * «تقارير أساسية» تعني تقاريرَ تُقرأ على الشاشة — لا حجبَ التقارير
         * كلّها. وسحبُ ما لم يُوعَد بسحبه أسوأ من ترك ما لم يُبَع مفتوحًا.
         */
        $this->onPlan([]);

        $this->actingAs($this->owner)->get(route('admin.reports.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.reports.sales'))->assertOk();
    }

    public function test_exporting_a_customer_list_is_not_an_advanced_report(): void
    {
        // تصديرُ قائمةٍ ليس تقريرًا، وقفلُه على «التقارير المتقدّمة» يسحب ما لم يُوعَد بسحبه
        $this->onPlan([]);

        $this->actingAs($this->owner)->get(route('admin.export.customers'))->assertOk();
    }

    public function test_a_closed_report_leaves_no_card_in_the_index(): void
    {
        /*
         * بطاقةٌ تقود إلى 403 تجعل صاحبها يظنّ العطب في النظام ويعيد المحاولة
         * — والفهرس يقرأ من مصدر الحارس نفسه فلا تفترق بطاقةٌ عن بابها.
         */
        $this->onPlan([]);

        $keys = collect(Reports::forUser($this->owner->fresh()))->pluck('key')->all();
        $this->assertNotContains('waste', $keys, 'بطاقةُ تقريرٍ مغلق ما زالت تُعرض');

        $this->onPlan(['reports_advanced']);
        $keys = collect(Reports::forUser($this->owner->fresh()))->pluck('key')->all();
        $this->assertContains('waste', $keys, 'أُخفيت بطاقةُ تقريرٍ مفتوح');
    }

    /* ======================== الصلاحيات المخصّصة ======================== */

    private function jobTitle(string $name = 'كاشير', string $role = 'cashier'): JobTitle
    {
        return JobTitle::create(['business_id' => $this->business->id, 'name' => $name, 'role' => $role]);
    }

    private function employeePayload(JobTitle $title, array $permissions): array
    {
        return [
            'name' => 'موظف', 'email' => 'e'.uniqid().'@abaadapp.om',
            'job_title' => $title->name, 'status' => 'نشط',
            'manual_permissions' => true, 'permissions' => $permissions,
        ];
    }

    /** ما يمنحه الدور — الحدّ يقع على الانحراف عنه لا على إرسال القائمة */
    private function roleSections(JobTitle $title): array
    {
        return array_values(array_filter(
            Permissions::sections(),
            fn ($s) => Permissions::allows($title->role, $s),
        ));
    }

    public function test_hiring_by_the_plain_role_is_never_a_paid_feature(): void
    {
        /*
         * النموذج يرسل `manual_permissions` دائمًا ومعه القائمة، حتّى حين
         * تُترك كما جاءت من الدور. فقياسُ القدرة بوصول القائمة كان يقفل
         * إضافةَ الموظّفين كلّها على الباقة الأساسية — وهو ما لم يعده أحد.
         */
        $this->onPlan([]);
        $title = $this->jobTitle();

        $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), $this->employeePayload($title, $this->roleSections($title)))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['business_id' => $this->business->id, 'name' => 'موظف']);
    }

    public function test_departing_from_the_role_needs_the_plan_that_sells_it(): void
    {
        $this->onPlan([]);
        $title = $this->jobTitle();

        $wider = array_values(array_unique([...$this->roleSections($title), 'inventory']));

        /*
         * والرفضُ رسالةٌ في النموذج لا ٤٠٣ عارية — انظر
         * `test_the_refusal_is_a_message_in_the_form_not_a_bare_403`.
         */
        $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), $this->employeePayload($title, $wider))
            ->assertSessionHasErrors('permissions');

        $this->assertDatabaseMissing('users', ['business_id' => $this->business->id, 'name' => 'موظف']);
    }

    public function test_the_plan_that_sells_it_lets_it_through(): void
    {
        $this->onPlan(['custom_permissions']);
        $title = $this->jobTitle();

        $wider = array_values(array_unique([...$this->roleSections($title), 'inventory']));

        $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), $this->employeePayload($title, $wider))
            ->assertSessionHasNoErrors();

        $saved = User::where('business_id', $this->business->id)->where('name', 'موظف')->firstOrFail();
        $this->assertContains('inventory', $saved->permissions ?? []);
    }

    public function test_an_employee_hired_under_a_richer_plan_stays_editable_after_it_lapses(): void
    {
        /*
         * أخطرُ ما في هذا الحارس، ووقع على متجرٍ حقيقيّ.
         *
         * موظّفٌ صلاحياتُه مخصّصة — منحها له مالكُه يوم كانت باقتُه تسمح، أو
         * قبل أن يوجد الحدّ أصلًا. ثمّ صارت الباقة أساسية.
         *
         * والنموذج يرسل صلاحياته الحالية كما هي في كلّ حفظ، وهي تنحرف عن
         * الدور، فيُقاس الانحرافُ ويُردّ الطلبُ بـ403 — **حتى لو لم يُغيَّر
         * إلا رقمُ الهاتف**. فلا يستطيع المالك تعديل موظّفه إطلاقًا، ولا
         * تعطيلَه، ولا حتى تصحيحَ اسمه.
         *
         * والحدُّ يمنع **إحداث** تخصيصٍ جديد، لا يمنع إبقاء ما وقع.
         */
        $this->onPlan(['custom_permissions']);
        $title = $this->jobTitle();
        $wider = array_values(array_unique([...$this->roleSections($title), 'inventory']));

        $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), $this->employeePayload($title, $wider))
            ->assertSessionHasNoErrors();

        $employee = User::where('business_id', $this->business->id)->where('name', 'موظف')->firstOrFail();

        // ثمّ نزلت الباقة — والمستخدم يُقرأ من جديد وإلّا بقيت باقتُه محفوظةً على كائنه
        $this->onPlan([]);

        $this->actingAs(User::find($this->owner->id))
            ->put(route('admin.employees.update', $employee->id), [
                'name' => 'موظف', 'email' => $employee->email, 'phone' => '90000000',
                'job_title' => $title->name,
                'manual_permissions' => true, 'permissions' => $wider,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('90000000', $employee->fresh()->phone, 'تعذّر تعديل موظفٍ لم تُمسّ صلاحياته');
    }

    public function test_but_widening_them_further_still_needs_the_plan(): void
    {
        // الإبقاء مسموح، والزيادة ليست: وإلّا صار الحدُّ حبرًا
        $this->onPlan(['custom_permissions']);
        $title = $this->jobTitle();
        $wider = array_values(array_unique([...$this->roleSections($title), 'inventory']));

        $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), $this->employeePayload($title, $wider))
            ->assertSessionHasNoErrors();

        $employee = User::where('business_id', $this->business->id)->where('name', 'موظف')->firstOrFail();
        $this->onPlan([]);

        $wider2 = array_values(array_unique([...$wider, 'customers']));

        $this->actingAs(User::find($this->owner->id))
            ->put(route('admin.employees.update', $employee->id), [
                'name' => 'موظف', 'email' => $employee->email,
                'job_title' => $title->name,
                'manual_permissions' => true, 'permissions' => $wider2,
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertNotContains('customers', $employee->fresh()->permissions ?? []);
    }

    public function test_the_refusal_is_a_message_in_the_form_not_a_bare_403(): void
    {
        /*
         * والرفضُ يُقال في النموذج لا يُصفع به.
         *
         * `abort(403)` على حفظِ نموذجٍ يُخرج صفحة خطأٍ كاملة: يفقد المالك ما
         * كتبه، ولا يعرف أيُّ حقلٍ سبّبها ولا أنّ السبب باقتُه أصلًا.
         */
        $this->onPlan([]);
        $title = $this->jobTitle();
        $wider = array_values(array_unique([...$this->roleSections($title), 'inventory']));

        $response = $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), $this->employeePayload($title, $wider));

        $response->assertSessionHasErrors('permissions');
        $this->assertStringContainsString(
            'الصلاحيات المخصّصة',
            session('errors')->first('permissions'),
            'الرسالة لا تقول أيُّ ميزةٍ نقصت'
        );
    }

    public function test_the_screen_knows_the_plan_before_the_owner_tries(): void
    {
        /*
         * بابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض: كانت الشاشة ترسم
         * مربّعات الصلاحيات كاملةً على الباقة الأساسية، فيؤشّرها المالك
         * ويحفظ ثمّ يُردّ — والقاعدة في هذا المشروع أن يُخفى ما لا يُفتح.
         */
        $source = file_get_contents(resource_path('js/Pages/Admin/Employees/partials/EmployeeForm.tsx'));

        $this->assertStringContainsString('custom_permissions', $source, 'شاشة الموظف لا تعرف الباقة');
    }

    /* ============================ الولاء ============================ */

    public function test_points_are_not_earned_on_a_plan_that_does_not_sell_them(): void
    {
        /*
         * والقفل عند الصندوق لا في الشاشة وحدها: النقاط تُمنح عند إتمام
         * البيعة، فقفلٌ في اللوحة يترك الرصيد ينمو لمن لم يشترِ البرنامج ثمّ
         * يُستبدَل. والنقاط مالٌ لا عدّاد.
         */
        $this->onPlan([]);

        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => 'vat_enabled'], ['value' => '0']);
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 40, 'quantity' => 50, 'alert_qty' => 1, 'active' => true,
        ]);
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '90000001', 'points' => 0,
        ]);

        $this->actingAs($this->owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $product->id, 'name' => 'صنف', 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('p', true),
            'customer_id' => $customer->id,
        ])->assertOk();

        $this->assertSame(0, (int) $customer->fresh()->points, 'مُنحت نقاطٌ على باقةٍ لا تبيع الولاء');
    }

    public function test_the_same_sale_earns_points_on_a_plan_that_does(): void
    {
        $this->onPlan(['loyalty']);

        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => 'vat_enabled'], ['value' => '0']);
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 40, 'quantity' => 50, 'alert_qty' => 1, 'active' => true,
        ]);
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '90000002', 'points' => 0,
        ]);

        $this->actingAs($this->owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $product->id, 'name' => 'صنف', 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('p', true),
            'customer_id' => $customer->id,
        ])->assertOk();

        $this->assertGreaterThan(0, (int) $customer->fresh()->points, 'لم تُمنح نقاطٌ على باقةٍ تبيع الولاء');
    }

    public function test_the_register_does_not_promise_points_it_cannot_give(): void
    {
        /*
         * يعِد الكاشيرُ زبونَه بنقاطٍ يردّها الخادم — والوعدُ المكسور عند
         * الصندوق أسوأ من ميزةٍ لا تُعرض أصلًا.
         *
         * والفروع تُحذف هنا لا لأنّ الأمر يتعلّق بها: شاشة البيع لا تُفتح على
         * متجرٍ له فروعٌ قبل تفعيل جهازها، والمقصود قياسُ ما تحمله لا بوّابتها.
         */
        $this->onPlan([]);
        Branch::where('business_id', $this->business->id)->delete();

        $this->actingAs($this->owner)->get(route('pos.index'))
            ->assertInertia(fn ($p) => $p->where('settings.loyaltyEnabled', false));
    }

    public function test_and_it_does_promise_them_when_the_plan_sells_them(): void
    {
        $this->onPlan(['loyalty']);
        Branch::where('business_id', $this->business->id)->delete();

        $this->actingAs($this->owner)->get(route('pos.index'))
            ->assertInertia(fn ($p) => $p->where('settings.loyaltyEnabled', true));
    }

    /* =========================== واتساب =========================== */

    public function test_the_sender_refuses_for_a_plan_that_excludes_it(): void
    {
        /*
         * الإشعار يخرج من الطلب حين تتغيّر حالته لا من زرٍّ يضغطه أحد، فقفلٌ
         * في الشاشة وحدها يعني رسائل تُرسَل — وتُحاسَب على المنصّة — لباقةٍ
         * لا تشملها.
         */
        $this->onPlan([]);
        $this->business->update(['whatsapp_enabled' => true]);

        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppFeature::GLOBAL_KEY], ['value' => '1']);

        $this->assertSame(
            WhatsAppStatus::SKIP_PLAN,
            WhatsAppFeature::blockReason($this->business->fresh()),
        );
    }

    /* ==================== محرّر الباقة في لوحة المنصّة ==================== */

    public function test_the_platform_editor_saves_what_the_guard_reads(): void
    {
        /*
         * وإلّا كان المفتاح يُؤشَّر في الشاشة ولا يصل الحارس — وهو `features`
         * نفسها بثوبٍ جديد.
         */
        $admin = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'sa@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $plan = Plan::create(['name' => 'باقة', 'monthly_price' => 5, 'yearly_price' => 50, 'capabilities' => null]);

        $this->actingAs($admin)->put(route('super-admin.plans.update', $plan->id), [
            'name' => 'باقة', 'monthly_price' => 5, 'yearly_price' => 50,
            'capabilities' => ['loyalty'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['loyalty'], $plan->fresh()->capabilities);
    }

    public function test_a_capability_that_is_not_in_the_closed_list_is_refused(): void
    {
        $admin = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'sa2@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $plan = Plan::create(['name' => 'باقة', 'monthly_price' => 5, 'yearly_price' => 50]);

        $this->actingAs($admin)->put(route('super-admin.plans.update', $plan->id), [
            'name' => 'باقة', 'monthly_price' => 5, 'yearly_price' => 50,
            'capabilities' => ['everything'],
        ])->assertSessionHasErrors('capabilities.0');
    }

    public function test_every_listed_capability_is_actually_guarded_somewhere(): void
    {
        /*
         * مفتاحٌ في القائمة بلا حارسٍ في الكود هو `features` نفسها بثوبٍ
         * جديد: يُؤشَّر في الشاشة، ويُقرأ في صفحة التسعير، ولا يمنع شيئًا.
         *
         * والبحث في المصدر لا في الشاشة: الحارس قد يكون مسارًا في الخريطة أو
         * سطرًا في متحكّم، والاثنان يُكتبان بالمفتاح نفسه.
         */
        $sources = collect(File::allFiles(app_path()))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->reject(fn ($f) => str_ends_with($f->getFilename(), 'PlanFeatures.php'))
            ->map(fn ($f) => file_get_contents($f->getPathname()))
            ->implode("\n");

        foreach (array_keys(PlanFeatures::CAPABILITIES) as $key) {
            $this->assertStringContainsString("'{$key}'", $sources, "القدرة «{$key}» تُعرض ولا يفحصها أحد");
        }
    }
}
