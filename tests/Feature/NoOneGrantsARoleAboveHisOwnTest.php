<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\User;
use App\Support\Demo;
use App\Support\OrderStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لا يُسند أحدٌ دورًا يفوق دورَه — ولا تُرسم يدٌ لا تصل.
 *
 * ═══ ١ · الدورُ سلطةٌ كامنة، وكان يُمنح بلا قياس ═══
 *
 * `refuseGrantingMoreThanIHave` كانت تقيس **إمّا** القائمةَ المخصّصة **وإمّا**
 * دورَ الوظيفة. ونموذجُ الموظّف يرسل `manual_permissions` في كلّ حفظة — فالطريقُ
 * الذي تسلكه كلُّ ضغطةِ «حفظ» من الشاشة كان يقيس القائمةَ وحدها ولا ينظر إلى
 * الدور إطلاقًا. والدورُ يبقى مكتوبًا في الصفّ: `Permissions::beyond` تقرؤه،
 * و`PANEL_ROLES` تقرؤه، وتقرؤه `MAP` كاملةً يومَ تُنزع القائمة.
 *
 * فكان المحاسبُ — وهو يملك «الموظفين» بدوره — يفعل هذا في ثلاث خطوات:
 *
 *   ١ — يُنشئ حسابًا بوظيفةٍ دورُها `admin`، ويؤشّر له ما يملكه هو وحده.
 *   ٢ — يدخل بكلمة المرور التي كتبها بيده.
 *   ٣ — يعيد تعيين كلمة مرور **صاحب المتجر** ويقرؤها على الشاشة.
 *
 * وكلُّ صلاحيةٍ استعملها يملكها بحقّ. والحارسُ الذي كُتب ليمنع هذا بعينه
 * (`StaffCannotOutrankThemselves`) كان يختبر الطريقَ الذي لا تسلكه الشاشة.
 *
 * ═══ ٢ · ويدٌ تُرسم ولا تصل ═══
 *
 * وقائمةُ الموظفين ترسم «تعديل» و«تعطيل الحساب» على كلّ صفّ — وبطاقةُ الموظّف
 * نفسِه تُخفيهما حين لا يُمسّ. شاشتان تسألان سؤالًا واحدًا وتجيبان جوابين.
 *
 * والنموذجُ يعرض كلَّ مربّعٍ وكلَّ وظيفة: يملؤه المحاسبُ ويختار «بائعًا»
 * ويحفظ، فتُصفع به صفحةُ ٤٠٣ — تمحو ما كتبه ولا تقول أيُّ حقلٍ سبّبها.
 */
class NoOneGrantsARoleAboveHisOwnTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        foreach ([['صاحب النشاط', 'admin'], ['مدير فرع', 'manager'],
            ['محاسب', 'accountant'], ['بائع', 'sales'], ['كاشير', 'cashier']] as [$n, $r]) {
            JobTitle::create(['business_id' => $this->shop->id, 'name' => $n, 'role' => $r]);
        }

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->accountant = User::create([
            'business_id' => $this->shop->id, 'name' => 'محاسب', 'email' => 'a@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'accountant',
            'job_title' => 'محاسب', 'status' => 'نشط',
        ]);
    }

    /** حمولةُ النموذج كما يرسلها فعلًا — و`manual_permissions` فيها دائمًا */
    private function form(array $over = []): array
    {
        return array_merge([
            'name' => 'حساب جديد', 'login_username' => 'newone',
            'password' => 'password12345', 'job_title' => 'كاشير',
            'manual_permissions' => true, 'permissions' => ['dashboard', 'pos'],
        ], $over);
    }

    /* ══════════════ ١ · الدورُ يُقاس كما تُقاس القائمة ══════════════ */

    /**
     * المحاسبُ لا يصنع «صاحب نشاط» ولو أشّر له ما يملكه هو.
     *
     * وهذا هو الطريقُ الذي تسلكه الشاشة — لا الطريقُ الذي كان محروسًا.
     */
    public function test_an_accountant_cannot_mint_an_owner_with_manual_permissions(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('admin.employees.store'), $this->form([
                'job_title' => 'صاحب النشاط',
                'permissions' => ['employees', 'orders'],
            ]))->assertForbidden();

        $this->assertNull(User::where('email', 'newone@abaadapp.om')->first());
    }

    /** ولا «مدير فرع» — والدورُ هو المقيس لا ما أُشّر */
    public function test_an_accountant_cannot_mint_a_manager_with_manual_permissions(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('admin.employees.store'), $this->form([
                'job_title' => 'مدير فرع',
                'permissions' => ['employees'],
            ]))->assertForbidden();

        $this->assertNull(User::where('email', 'newone@abaadapp.om')->first());
    }

    /**
     * ولا يرفع موظّفًا قائمًا إلى وظيفةٍ تفوقه.
     *
     * والبابُ الثاني: من لا يصنعه لا يُرقّيه.
     */
    public function test_an_accountant_cannot_promote_an_existing_employee(): void
    {
        $clerk = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاتب', 'email' => 'clerk1@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier',
            'job_title' => 'كاشير', 'status' => 'نشط', 'permissions' => ['dashboard', 'pos'],
        ]);

        $this->actingAs($this->accountant)
            ->put(route('admin.employees.update', $clerk->id), [
                'name' => 'كاتب', 'login_username' => 'clerk1', 'job_title' => 'صاحب النشاط',
                'manual_permissions' => true, 'permissions' => ['dashboard', 'pos'],
            ])->assertForbidden();

        $this->assertSame('cashier', $clerk->fresh()->role);
    }

    /** والمالكُ يصنع ما شاء — الحدُّ على من دونه */
    public function test_the_owner_still_mints_anyone(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), $this->form([
                'job_title' => 'مدير فرع', 'permissions' => ['dashboard', 'orders'],
            ]))->assertRedirect();

        $this->assertSame('manager', User::where('email', 'newone@abaadapp.om')->firstOrFail()->role);
    }

    /** ويصنع المحاسبُ ما يبلغه دورُه — الحدُّ يضيّق ولا يُقفل */
    public function test_an_accountant_still_mints_what_his_role_reaches(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('admin.employees.store'), $this->form())
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('cashier', User::where('email', 'newone@abaadapp.om')->firstOrFail()->role);
    }

    /**
     * وحارسٌ ثانٍ تحت الأوّل: صفٌّ دورُه `admin` وصلاحياتُه مخصّصةٌ ليس مالكًا.
     *
     * الأوّلُ يمنع إنشاءه، وهذا يمنع أثرَه: قد يكون مكتوبًا من قبلُ، أو من
     * هجرة، أو بيدِ المالك نفسِه وهو يضيّق شريكًا. و`beyond` كانت تُعفيه
     * بالدور فتعطيه على **الحسابات** ما نُزع عنه في **الشاشات**.
     */
    public function test_a_narrowed_admin_row_does_not_reach_the_owner(): void
    {
        $narrowed = User::create([
            'business_id' => $this->shop->id, 'name' => 'شريك مُضيَّق', 'email' => 'p@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'admin',
            'job_title' => 'صاحب النشاط', 'status' => 'نشط',
            'permissions' => ['employees', 'orders'],
        ]);

        $this->assertFalse(Permissions::mayTouch($narrowed, $this->owner));

        $this->actingAs($narrowed)
            ->post(route('admin.employees.resetPassword', $this->owner->id))
            ->assertForbidden();

        $this->assertNull(session('issued_password'));
    }

    /** والمالكُ الحقيقيّ — دورُه `admin` بلا تخصيص — يمسّ الجميع كما كان */
    public function test_the_real_owner_still_touches_everyone(): void
    {
        $this->assertTrue(Permissions::mayTouch($this->owner, $this->accountant));

        $this->actingAs($this->owner)
            ->post(route('admin.employees.resetPassword', $this->accountant->id))
            ->assertRedirect();

        $this->assertNotNull(session('issued_password'));
    }

    /* ══════════════ ٢ · ولا تُرسم يدٌ لا تصل ══════════════ */

    /** صفُّ من لا يُمسّ يحمل الجواب — ولا يُترك للشاشة أن تخمّنه */
    public function test_the_row_says_whether_it_may_be_touched(): void
    {
        $this->actingAs($this->accountant);

        $rows = collect(Demo::employees())->keyBy('id');

        $this->assertFalse($rows[$this->owner->id]['may_touch'], 'صفُّ المالك يُقال عنه إنّه يُمسّ');
        $this->assertTrue($rows[$this->accountant->id]['may_touch']);
        $this->assertTrue($rows[$this->accountant->id]['is_me']);
        $this->assertFalse($rows[$this->owner->id]['is_me']);
    }

    /** وما يقوله الصفُّ هو ما يفعله الخادم — لا قاعدةٌ ثانية تُكتب للشاشة */
    public function test_the_row_and_the_server_agree(): void
    {
        $this->actingAs($this->accountant);

        foreach (Demo::employees() as $row) {
            $code = $this->get(route('admin.employees.edit', $row['id']))->getStatusCode();

            $this->assertSame(
                $row['may_touch'],
                $code !== 403,
                'صفُّ «'.$row['name'].'» يقول '.json_encode($row['may_touch']).' والخادم يردّ '.$code,
            );
        }
    }

    /** والقائمةُ تقرأ الجواب قبل أن ترسم — حارسٌ يقرأ مصدرًا، ويُقال إنّه ضعيف */
    public function test_the_list_reads_the_answer(): void
    {
        $panel = file_get_contents(base_path('resources/js/Pages/Admin/Settings/panels/EmployeesPanel.tsx'));

        /*
         * وموضعان لا موضع: القلمُ وقائمةُ «تعطيل الحساب». وأوّلُ صياغةٍ لهذا
         * الحارس كانت تكتفي بوجود النصّ مرّةً — فنجت طفرةٌ تردّ القلمَ إلى
         * الرسم بلا شرطٍ، لأنّ النصّ يبقى في الموضع الثاني.
         */
        $this->assertSame(2, substr_count($panel, 'e.may_touch === false'));
        // القلمُ يُرفع رفعًا لا يُعطَّل: `RowActions` لا ترسم ما لم يُمرَّر
        $this->assertStringContainsString('? undefined', $panel);
        // و«تعطيل الحساب» لا تُرسم على صفّ نفسك: الخادمُ يردّها دائمًا
        $this->assertStringContainsString('e.may_touch === false || e.is_me', $panel);
    }

    /* ══════════════ ٣ · والنموذج لا يعرض ما يردّه ══════════════ */

    /** ما يملك الفاعلُ منحَه يصل الشاشةَ — وهو ما يقيس به الحارسُ نفسُه */
    public function test_the_form_is_told_what_may_be_granted(): void
    {
        $props = $this->actingAs($this->accountant)
            ->get(route('admin.employees.create'))->viewData('page')['props'];

        $grantable = $props['grantable'];

        // ما يملكه: «الموظفين» فيها، و«المنتجات» ليست
        $this->assertContains('employees', $grantable);
        $this->assertNotContains('products', $grantable);
        $this->assertContains(Permissions::PAYROLL_VIEW, $grantable);
        $this->assertNotContains(Permissions::PAYROLL_PAY, $grantable);

        // ولا مربّعَ يُعرض قابلًا وهو يُردّ — الأقسامُ كلُّها معروضة والحكمُ منفصل
        $this->assertArrayHasKey('products', $props['sections']);
    }

    /** والوظيفةُ التي لا تُسند تُقال قبل الاختيار لا بعد الحفظ */
    public function test_the_form_names_the_titles_that_will_be_refused(): void
    {
        $props = $this->actingAs($this->accountant)
            ->get(route('admin.employees.create'))->viewData('page')['props'];

        $this->assertContains('صاحب النشاط', $props['blockedTitles']);
        $this->assertContains('مدير فرع', $props['blockedTitles']);
        // «بائع» يفتح المنتجات ولوحة التجهيز — ولا يملكهما المحاسب
        $this->assertContains('بائع', $props['blockedTitles']);
        $this->assertNotContains('كاشير', $props['blockedTitles']);
    }

    /** والمالكُ لا يُمنع من وظيفة */
    public function test_the_owner_is_blocked_from_no_title(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.employees.create'))->viewData('page')['props'];

        $this->assertSame([], $props['blockedTitles']);
        $this->assertContains('products', $props['grantable']);
    }

    /**
     * وما تقوله الشاشةُ هو ما يفعله الخادم — على كلّ وظيفةٍ في المتجر.
     *
     * وهذا هو الحارسُ الذي يمنع افتراقَ القائمتين: `blockedTitles` تُحسب
     * بـ`mayAssignRole`، والحارسُ يقيس بـ`grantable` — فتُجرَّب الوظائفُ
     * كلُّها واحدةً واحدة ويُقارن الجوابان.
     */
    public function test_every_blocked_title_is_refused_and_every_other_is_not(): void
    {
        $this->actingAs($this->accountant);

        $props = $this->get(route('admin.employees.create'))->viewData('page')['props'];
        $blocked = $props['blockedTitles'];

        foreach (JobTitle::where('business_id', $this->shop->id)->pluck('name') as $i => $title) {
            $res = $this->post(route('admin.employees.store'), $this->form([
                'job_title' => $title,
                'login_username' => 'try'.$i,
                // ولا يُؤشَّر إلا ما يملكه المحاسب — فالردُّ إن وقع فمن الدور
                'permissions' => ['orders'],
            ]));

            $this->assertSame(
                in_array($title, $blocked, true),
                $res->getStatusCode() === 403,
                'وظيفة «'.$title.'»: الشاشة تقول '.(in_array($title, $blocked, true) ? 'ممنوعة' : 'متاحة')
                    .' والخادم ردّ '.$res->getStatusCode(),
            );
        }
    }

    /* ══════════════ ٤ · وبطاقةُ الموظّف لا تناقض نفسَها ══════════════ */

    /**
     * إجماليُّ مبيعاته ومخطّطُها رقمٌ واحد — ولو صُحّح اسمُه.
     *
     * `employee_name` لقطةٌ تُكتب لحظة البيع ولا تتغيّر. والمخطّطُ كان يجمع
     * بها: فتصحيحُ اسمٍ يقطع الصلة بكلّ ما باعه، ويهبط المخطّطُ إلى صفرٍ في
     * الاثني عشر شهرًا — والإجماليُّ فوقه يبقى على رقمه.
     */
    public function test_the_card_does_not_contradict_itself_after_a_rename(): void
    {
        $noura = User::create([
            'business_id' => $this->shop->id, 'name' => 'نورة', 'email' => 'n@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        Order::create([
            'business_id' => $this->shop->id, 'number' => 'INV-000001',
            'customer_name' => 'زبون', 'employee_name' => 'نورة', 'user_id' => $noura->id,
            'subtotal' => 500, 'tax' => 0, 'total' => 500, 'payment_method' => 'نقدي',
            'payment_status' => 'مدفوع', 'status' => OrderStatus::COMPLETED,
            'is_held' => false, 'ordered_at' => now(),
        ]);

        $noura->forceFill(['name' => 'نورة العامري'])->save();

        $this->actingAs($this->owner);
        $card = $this->get(route('admin.employees.show', $noura->id))->viewData('page')['props'];

        $this->assertEqualsWithDelta(500.0, (float) $card['employee']['sales'], 0.001);
        $this->assertEqualsWithDelta(
            500.0,
            array_sum($card['salesSeries']['data']),
            0.001,
            'المخطّط يناقض الإجماليَّ فوقه بعد تصحيح الاسم',
        );
    }

    /* ══════════════ ٥ · واللوحةُ لا تخلط نطاقين ══════════════ */

    /**
     * «أداء الموظفين» يتبع الفرع المختار كما تتبعه جاراتُه.
     *
     * كانت تقرأ المتجر كلَّه: يقف التاجر على صلالة فتقول بطاقةُ «مبيعات
     * الشهر» فوقها ٧٠٠ وتقول هي «نورة ١٠٠٠» — وبينهما «أفضل المنتجات»
     * تُنادى بالفرع صراحةً. شاشةٌ واحدة وشهرٌ واحد ورقمان.
     */
    public function test_the_dashboard_staff_card_follows_the_branch(): void
    {
        $salalah = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);
        $muscat = Branch::where('business_id', $this->shop->id)->where('name', 'الرئيسي')->firstOrFail();

        $noura = User::create([
            'business_id' => $this->shop->id, 'name' => 'نورة', 'email' => 'n2@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        foreach ([[$muscat, 300.0, 'A'], [$salalah, 700.0, 'B']] as [$branch, $total, $k]) {
            Order::create([
                'business_id' => $this->shop->id, 'branch_id' => $branch->id, 'branch' => $branch->name,
                'number' => 'INV-'.$k, 'customer_name' => 'زبون',
                'employee_name' => 'نورة', 'user_id' => $noura->id,
                'subtotal' => $total, 'tax' => 0, 'total' => $total, 'payment_method' => 'نقدي',
                'payment_status' => 'مدفوع', 'status' => OrderStatus::COMPLETED,
                'is_held' => false, 'ordered_at' => now(),
            ]);
        }

        $this->actingAs($this->owner);

        $achieved = function (): float {
            $props = $this->get(route('admin.dashboard'))->viewData('page')['props'];

            return (float) collect($props['topEmployees'])->firstWhere('name', 'نورة')['achieved'];
        };

        $this->get(route('admin.branch.switch', $muscat->id));
        $this->assertEqualsWithDelta(300.0, $achieved(), 0.001, 'اللوحة على مسقط تقرأ المتجر كلَّه');

        $this->get(route('admin.branch.switch', $salalah->id));
        $this->assertEqualsWithDelta(700.0, $achieved(), 0.001, 'اللوحة على صلالة تقرأ المتجر كلَّه');
    }

    /** و«كل الفروع» يجمعهما — والحدُّ يُطلب ولا يُفترض */
    public function test_all_branches_still_sums_the_shop(): void
    {
        $salalah = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);
        $noura = User::create([
            'business_id' => $this->shop->id, 'name' => 'نورة', 'email' => 'n3@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'sales', 'status' => 'نشط',
        ]);
        Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $salalah->id, 'branch' => 'صلالة',
            'number' => 'INV-C', 'customer_name' => 'زبون',
            'employee_name' => 'نورة', 'user_id' => $noura->id,
            'subtotal' => 700, 'tax' => 0, 'total' => 700, 'payment_method' => 'نقدي',
            'payment_status' => 'مدفوع', 'status' => OrderStatus::COMPLETED,
            'is_held' => false, 'ordered_at' => now(),
        ]);

        $this->actingAs($this->owner);

        $props = $this->get(route('admin.dashboard'))->viewData('page')['props'];
        $this->assertEqualsWithDelta(
            700.0,
            (float) collect($props['topEmployees'])->firstWhere('name', 'نورة')['achieved'],
            0.001,
        );

        /*
         * وقسمُ الموظفين يبقى على المتجر كلِّه — **والفرعُ مختارٌ فعلًا**.
         *
         * وبلا التبديل لا يفرّق الحارسُ شيئًا: `Demo::employees()` بلا وسيطٍ
         * تُرجع الإجماليَّ سواءٌ أقرأت الوسيط أم سقطت إلى الفرع الحالي —
         * لأنّ الفرع الحالي لا شيء. فيُبدَّل إلى صلالة، وتبقى مبيعةُ صلالة
         * وحدَها هناك: لو سقطت الدالّةُ إلى الفرع لَما تغيّر الرقم، فتُضاف
         * مبيعةٌ في الرئيسي ليفترق الجوابان.
         */
        Order::create([
            'business_id' => $this->shop->id,
            'branch_id' => Branch::where('business_id', $this->shop->id)->where('name', 'الرئيسي')->value('id'),
            'branch' => 'الرئيسي', 'number' => 'INV-D', 'customer_name' => 'زبون',
            'employee_name' => 'نورة', 'user_id' => $noura->id,
            'subtotal' => 300, 'tax' => 0, 'total' => 300, 'payment_method' => 'نقدي',
            'payment_status' => 'مدفوع', 'status' => OrderStatus::COMPLETED,
            'is_held' => false, 'ordered_at' => now(),
        ]);

        $this->get(route('admin.branch.switch', $salalah->id));

        $this->assertEqualsWithDelta(
            1000.0,
            (float) collect(Demo::employees())->firstWhere('id', $noura->id)['sales'],
            0.001,
            'قسمُ الموظفين تبع الفرعَ المختار — والحدُّ يُطلب ولا يُفترض',
        );

        // واللوحةُ في اللحظة نفسِها تقرأ صلالة وحدها
        $props = $this->get(route('admin.dashboard'))->viewData('page')['props'];
        $this->assertEqualsWithDelta(
            700.0,
            (float) collect($props['topEmployees'])->firstWhere('name', 'نورة')['achieved'],
            0.001,
        );
    }
}
