<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\User;
use App\Support\MerchantAccount;
use App\Support\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الباقةُ تبيع موظّفين يعملون، لا صفوفًا في جدول.
 *
 * ═══ العطب ═══
 *
 * لا مسارَ حذفٍ للموظّف — والصحيحُ ألّا يكون: `orders.user_id` و`shifts`
 * و`payroll_lines` وسجلُّ النشاط كلُّها تشير إليه، ومحوُه يمحو نسبةَ ما باع
 * إليه. فالتعطيلُ هو المقبض الوحيد، وهو ما يفعله التاجرُ حين يترك موظّفٌ
 * العمل.
 *
 * وكان العدُّ يشمل المعطَّل. فمتجرٌ بلغ سقفَه يعطّل من تركه ثمّ يوظّف بديلًا
 * فيُردّ: «بلغت حدّ باقتك: ٣ من الموظفين» — وهو ينظر إلى اثنين يعملان. ولا
 * مخرجَ له إلّا ترقيةُ الباقة، أو أن يُعيد استعمال حساب الراحل باسمٍ آخر
 * فتُنسب مبيعاتُ الأوّل إلى الثاني.
 */
class ASeatIsFreedWhenAnEmployeeLeavesTest extends TestCase
{
    use RefreshDatabase;

    private Business $biz;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::updateOrCreate(['name' => 'باقة ثلاثة'], [
            'monthly_price' => 10, 'yearly_price' => 100,
            'max_branches' => 5, 'max_employees' => 3, 'max_products' => 100,
        ]);

        $this->biz = Business::create([
            'name' => 'متجري', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $plan->id,
        ]);
        Branch::create(['business_id' => $this->biz->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->biz->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->biz->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function hire(string $username)
    {
        return $this->post(route('admin.employees.store'), [
            'name' => $username, 'login_username' => $username, 'job_title' => 'كاشير',
            'permissions' => ['pos'], 'manual_permissions' => 1,
        ]);
    }

    private function staff(string $name): User
    {
        return User::where('business_id', $this->biz->id)->where('name', $name)->firstOrFail();
    }

    /* ─────────── السقفُ يُفرض ─────────── */

    /** السقفُ قائمٌ كما كان — ولا يُرفع بهذا الإصلاح */
    public function test_the_cap_still_refuses_a_fourth_working_employee(): void
    {
        $this->hire('emp1')->assertSessionHasNoErrors();
        $this->hire('emp2')->assertSessionHasNoErrors();

        $this->hire('emp3')->assertSessionHasErrors('name');

        $this->assertSame(3, User::where('business_id', $this->biz->id)->count(),
            'مرّ موظّفٌ رابعٌ فوق السقف');
    }

    /* ─────────── والمعطَّلُ لا يشغل مقعدًا ─────────── */

    /** من تركَ العملَ وعُطِّل حسابُه يُفرِج عن مقعده */
    public function test_disabling_a_leaver_frees_his_seat(): void
    {
        $this->hire('emp1');
        $this->hire('emp2');

        $this->post(route('admin.employees.toggle', $this->staff('emp2')->id));
        $this->assertSame('معطل', $this->staff('emp2')->status);

        $this->hire('emp3')->assertSessionHasNoErrors();

        $this->assertSame('نشط', $this->staff('emp3')->status, 'لم يُوظَّف البديل');
    }

    /** والعدُّ نفسُه يقول ذلك — مصدرٌ واحدٌ تقرؤه الشاشةُ والحارس */
    public function test_the_count_itself_ignores_disabled_rows(): void
    {
        $this->hire('emp1');
        $this->assertSame(2, PlanLimits::used($this->biz->id, 'employees'));

        $this->post(route('admin.employees.toggle', $this->staff('emp1')->id));

        $this->assertSame(1, PlanLimits::used($this->biz->id, 'employees'),
            'المعطَّل ما زال يُعدّ في السقف');
    }

    /** وصفُّه يبقى — فما باع يبقى منسوبًا إليه */
    public function test_the_leavers_row_is_kept_not_erased(): void
    {
        $this->hire('emp1');
        $id = $this->staff('emp1')->id;

        $this->post(route('admin.employees.toggle', $id));

        $this->assertNotNull(User::find($id), 'مُحي صفُّ من عُطِّل');
    }

    /* ─────────── ولا يُلتفّ على السقف بالتعطيل ─────────── */

    /**
     * عطِّل، وظِّف، ثمّ أعِد التفعيل — بابٌ يُغلق.
     *
     * ولولا هذا لَصار التعطيلُ حيلةً: ثلاثةٌ في الباقة وأربعةٌ يعملون.
     */
    public function test_re_enabling_beyond_the_cap_is_refused(): void
    {
        $this->hire('emp1');
        $this->hire('emp2');

        $this->post(route('admin.employees.toggle', $this->staff('emp2')->id));
        $this->hire('emp3')->assertSessionHasNoErrors();

        $this->post(route('admin.employees.toggle', $this->staff('emp2')->id));

        $this->assertSame('معطل', $this->staff('emp2')->status, 'عاد رابعٌ إلى العمل فوق السقف');
    }

    /** ويُقال له لماذا — على قناةٍ تُعرض */
    public function test_the_refusal_to_re_enable_is_shown(): void
    {
        $this->hire('emp1');
        $this->hire('emp2');
        $this->post(route('admin.employees.toggle', $this->staff('emp2')->id));
        $this->hire('emp3');

        $this->post(route('admin.employees.toggle', $this->staff('emp2')->id));

        $toast = session('toast');
        $this->assertSame('danger', $toast['type'] ?? null, 'رفضٌ لا يصل الشاشة');
        $this->assertStringContainsString('3', (string) ($toast['msg'] ?? ''), 'الرفض لا يقول السقف');
    }

    /** والتعطيلُ نفسُه لا يُقاس بالسقف — النزولُ عنه لا يُمنع أبدًا */
    public function test_disabling_is_never_refused_by_the_cap(): void
    {
        $this->hire('emp1');
        $this->hire('emp2');

        $this->post(route('admin.employees.toggle', $this->staff('emp2')->id));

        $this->assertSame('معطل', $this->staff('emp2')->status, 'رُفض تعطيلٌ بسبب السقف');
    }

    /* ─────────── واسمُ الدخول لا ينهار على صفٍّ محذوف ─────────── */

    /**
     * ═══ العطب ═══
     *
     * `users.email` فريدٌ في القاعدة، والفهرسُ لا يعرف الحذفَ الليّن. وكان
     * الفحصُ يقرأ عبر النموذج فيُخفي المحذوف: يقول «الاسمُ حرّ»، ثمّ يصطدم
     * الإدراجُ بالفهرس — **صفحةُ خطأٍ بيضاء** مكان رسالةٍ تقول ما جرى.
     *
     * ويقع فعلًا: حسابات الموظّفين تُحذف ليّنًا من لوحة المنصّة، ثمّ يفتح
     * التاجرُ شاشته ويعيد إنشاء الموظّف بالاسم نفسِه.
     */
    public function test_reusing_a_deleted_logins_name_is_refused_not_crashed(): void
    {
        $this->hire('emp1');
        $this->staff('emp1')->delete();

        $this->hire('emp1')->assertSessionHasErrors('login_username');

        $this->assertSame(1, User::withTrashed()->where('business_id', $this->biz->id)
            ->where('name', 'emp1')->count(), 'أُنشئ صفٌّ ثانٍ بالعنوان نفسِه');
    }

    /** والفحصُ يقيس بما يقيس به الفهرس */
    public function test_the_name_check_reads_deleted_rows(): void
    {
        $this->hire('emp1');
        $this->staff('emp1')->delete();

        $this->assertTrue(MerchantAccount::taken('emp1'),
            'الفحص يقول «حرّ» عن اسمٍ يرفضه الفهرس');
    }

    /** واسمٌ لم يُستعمل قطّ يبقى حرًّا */
    public function test_an_unused_name_is_still_free(): void
    {
        $this->assertFalse(MerchantAccount::taken('nobody'));
        $this->hire('nobody')->assertSessionHasNoErrors();
    }
}
