<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * عمليات الكتابة في لوحة المنصة.
 *
 * الصفحات كانت مغطّاة بالكنس، أما ما يكتب في القاعدة — إنشاء شركة، تعديل
 * باقة، إيقاف حساب — فلم يكن مغطّى بشيء. وهذه بالذات لا يكفي فيها أن
 * تُرجع الشاشة تنبيه نجاح.
 */
class PlatformPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name' => 'الباقة الأساسية', 'monthly_price' => 10, 'yearly_price' => 100,
            'max_branches' => 1, 'max_employees' => 3, 'max_products' => 100,
        ]);
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /* ------------------------------ الشركات ------------------------------ */

    public function test_it_creates_a_business(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.businesses.store'), [
            'name' => 'شركة الفحص', 'type' => 'عام', 'owner_name' => 'المالك',
            'email' => 'biz@abaad.om', 'phone' => '+96890000001',
            'country' => 'عُمان', 'city' => 'مسقط', 'plan_id' => $this->plan->id,
            'status' => 'نشط',
            'login_username' => 'fahes', 'login_password' => 'secret12345',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('businesses', ['name' => 'شركة الفحص', 'status' => 'نشط']);
    }

    public function test_it_updates_a_business(): void
    {
        $biz = Business::create(['name' => 'قديم', 'type' => 'عام', 'status' => 'نشط']);

        $this->actingAs($this->super)->put(route('super-admin.businesses.update', $biz->id), [
            'name' => 'جديد', 'type' => 'عام', 'status' => 'نشط',
            // بلا حساب، فالتعديل يستكمله (انظر اختبارات «حساب دخول التاجر»)
            'login_username' => 'qadeem', 'login_password' => 'secret12345',
        ])->assertSessionHasNoErrors();

        $this->assertSame('جديد', $biz->fresh()->name);
    }

    public function test_removing_a_business_disables_it_and_keeps_its_data(): void
    {
        // متعمَّد ولا يجوز أن ينقلب: محو مستأجر يأخذ معه طلباته وفواتيره
        // وسجلّه الضريبي. الواجهة تسمّيه «تعطيل» لهذا السبب.
        $biz = Business::create(['name' => 'للتعطيل', 'type' => 'عام', 'status' => 'نشط']);

        $this->actingAs($this->super)
            ->delete(route('super-admin.businesses.destroy', $biz->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('businesses', ['id' => $biz->id, 'status' => 'معطل']);
    }

    public function test_a_business_needs_a_name(): void
    {
        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.store'), ['name' => '', 'status' => 'نشط'])
            ->assertSessionHasErrors('name');
    }

    /* ----------------------------- المستخدمون ---------------------------- */

    public function test_it_creates_a_platform_user(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'مستخدم الفحص', 'email' => 'u@abaad.om',
            'role' => 'manager', 'password' => 'secret12345',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'u@abaad.om', 'role' => 'manager']);
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'مكرّر', 'email' => $this->super->email, 'role' => 'manager',
        ])->assertSessionHasErrors('email');
    }

    public function test_the_stored_password_is_hashed_not_plain(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'مستخدم', 'email' => 'hash@abaad.om',
            'role' => 'manager', 'password' => 'secret12345',
        ]);

        $stored = User::where('email', 'hash@abaad.om')->value('password');
        $this->assertNotSame('secret12345', $stored);
        $this->assertTrue(password_verify('secret12345', $stored));
    }

    public function test_toggling_a_user_flips_the_status_both_ways(): void
    {
        $user = User::create([
            'business_id' => null, 'name' => 'هدف', 'email' => 't@abaad.om',
            'password' => bcrypt('x'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        $this->actingAs($this->super)->post(route('super-admin.users.toggle', $user->id));
        $this->assertSame('موقوف', $user->fresh()->status);

        $this->actingAs($this->super)->post(route('super-admin.users.toggle', $user->id));
        $this->assertSame('نشط', $user->fresh()->status);
    }

    public function test_the_platform_admin_cannot_lock_themselves_out(): void
    {
        // لا يوجد باب خلفي لإعادة التفعيل — الإيقاف الذاتي يُقفل اللوحة للأبد
        $this->actingAs($this->super)->post(route('super-admin.users.toggle', $this->super->id));

        $this->assertSame('نشط', $this->super->fresh()->status);
    }

    /* ------------------------------ الباقات ------------------------------ */

    public function test_it_updates_a_plan_price(): void
    {
        $this->actingAs($this->super)->put(route('super-admin.plans.update', $this->plan->id), [
            'name' => 'الباقة الأساسية', 'monthly_price' => 25, 'yearly_price' => 250,
            'max_branches' => 2, 'max_employees' => 5, 'max_products' => 200,
        ])->assertSessionHasNoErrors();

        $this->assertSame(25.0, (float) $this->plan->fresh()->monthly_price);
    }

    /* ------------------------------ الحراسة ------------------------------ */

    public static function writeRoutes(): array
    {
        return [
            ['post', 'super-admin.businesses.store'],
            ['post', 'super-admin.users.store'],
            ['post', 'super-admin.settings.update'],
            ['post', 'super-admin.plans.store'],
        ];
    }

    #[DataProvider('writeRoutes')]
    public function test_a_merchant_owner_cannot_write_through_the_platform_panel(string $verb, string $name): void
    {
        $biz = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $biz->id, 'name' => 'المالك', 'email' => 'owner@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->{$verb}(route($name), [])->assertForbidden();
    }

    #[DataProvider('writeRoutes')]
    public function test_a_guest_cannot_write_either(string $verb, string $name): void
    {
        $this->{$verb}(route($name), [])->assertRedirect(route('login'));
    }

    /* ------------------------------ الشعار ------------------------------ */

    private function business(array $extra = []): Business
    {
        $b = Business::create(array_merge([
            'name' => 'شركة', 'type' => 'مغسلة', 'status' => 'نشط',
        ], $extra));

        // ومعها حسابها: تعديل شركةٍ بلا حساب يطالب باستكماله أولًا
        \App\Support\MerchantAccount::create($b, 'sharika'.$b->id, 'secret12345');

        return $b;
    }

    private function edit(Business $b, array $extra = [])
    {
        return $this->actingAs($this->super)->put(route('super-admin.businesses.update', $b->id), array_merge([
            'name' => $b->name, 'type' => $b->type, 'status' => $b->status,
        ], $extra));
    }

    /**
     * «حذف الشعار» يمسحه من القاعدة لا من الشاشة وحدها.
     *
     * إخفاء المعاينة دون علامةٍ تصل مع الطلب كان يترك الملف القديم مربوطًا:
     * يحفظ المستخدم، ويعود الشعار عند فتح الصفحة — حذفٌ ظاهرٌ لم يحدث.
     */
    public function test_removing_the_logo_actually_clears_it(): void
    {
        $b = $this->business(['logo' => 'logos/old.png']);

        $this->edit($b, ['remove_logo' => '1'])->assertSessionHasNoErrors();

        // العمود الخام: getLogoAttribute يُرجع رابطًا بديلًا حين يكون فارغًا
        $this->assertNull($b->fresh()->getRawOriginal('logo'));
    }

    /**
     * وتعديلُ حقلٍ آخر لا يمسّ الشعار.
     *
     * حقل الملف غائب عن الطلب حين لا يُختار ملف — فتمريره كما هو كان سيمسح
     * الشعار عند كل حفظٍ لاسمٍ أو هاتف.
     */
    public function test_editing_another_field_keeps_the_logo(): void
    {
        $b = $this->business(['logo' => 'logos/keep.png']);

        $this->edit($b, ['name' => 'اسم جديد'])->assertSessionHasNoErrors();

        $this->assertSame('logos/keep.png', $b->fresh()->getRawOriginal('logo'));
        $this->assertSame('اسم جديد', $b->fresh()->name);
    }

    /** والعلامة ليست عمودًا: لا تُكتب في الجدول */
    public function test_the_remove_flag_is_not_stored(): void
    {
        $b = $this->business();

        $this->edit($b, ['remove_logo' => '0'])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('remove_logo', $b->fresh()->getAttributes());
    }

    /* --------------------------- نوعٌ ومدينةٌ حرّان --------------------------- */

    /**
     * النوع كتابةٌ حرّة: من يسجّل مغسلةً لا يضطرّ إلى اختيار «بقالة».
     *
     * ستّة أنواع لا سابع لها كانت تعني نوعًا مكذوبًا في السجلّ من أول يوم.
     */
    public function test_an_unlisted_business_type_is_accepted(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.businesses.store'), [
            'name' => 'مغسلة النور', 'type' => 'مغسلة ملابس', 'city' => 'البريمي', 'status' => 'نشط',
            'login_username' => 'noor', 'login_password' => 'secret12345',
        ])->assertSessionHasNoErrors();

        $b = Business::where('name', 'مغسلة النور')->firstOrFail();
        $this->assertSame('مغسلة ملابس', $b->type);
        $this->assertSame('البريمي', $b->city);
    }

    /** ويأخذ تصنيفات البداية العامة بدل لوحةٍ بيضاء */
    public function test_an_unlisted_type_still_gets_starter_categories(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.businesses.store'), [
            'name' => 'ورشة الحرفي', 'type' => 'ورشة', 'status' => 'نشط',
            'login_username' => 'harafi', 'login_password' => 'secret12345',
        ])->assertSessionHasNoErrors();

        $b = Business::where('name', 'ورشة الحرفي')->firstOrFail();
        $this->assertGreaterThan(0, \App\Models\Category::where('business_id', $b->id)->count());
    }

    /* ------------------ حساب دخول التاجر ------------------ */

    private function newBusiness(array $extra = [])
    {
        return $this->actingAs($this->super)->post(route('super-admin.businesses.store'), array_merge([
            'name' => 'زهرة مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'login_username' => 'zahra', 'login_password' => 'secret12345',
        ], $extra));
    }

    /**
     * الشركة تُنشأ ومعها حسابُ دخول مالكها.
     *
     * كانت تُسجَّل بلا حساب: سجلٌّ في جدول لا يفتحه أحد — لا التاجر يدخل، ولا
     * الدعم يستطيع «الدخول كتاجر» لأنه لا يجد من ينتحله. ولا يظهر العطب إلا
     * حين يتصل صاحبها يسأل عن كلمة مروره.
     */
    public function test_creating_a_business_creates_its_owner_account(): void
    {
        $this->newBusiness()->assertSessionHasNoErrors();

        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();
        $owner = \App\Support\MerchantAccount::owner($biz);

        $this->assertNotNull($owner, 'أُنشئت الشركة بلا حساب');
        $this->assertSame('zahra@abaadapp.om', $owner->email);
        $this->assertSame('admin', $owner->role);
    }

    /** والنطاق ثابت: يُكتب الاسم وحده ويُلحق به */
    public function test_the_domain_is_appended_not_typed(): void
    {
        $this->newBusiness(['login_username' => 'AlNoor']);

        $this->assertDatabaseHas('users', ['email' => 'alnoor@abaadapp.om']);
    }

    /** وبها يدخل التاجر فعلًا — لا حسابًا يُكتب ولا يُفتح */
    public function test_the_owner_can_actually_sign_in(): void
    {
        $this->newBusiness();
        $this->post(route('logout'));

        $this->post(route('login.attempt'), [
            'email' => 'zahra@abaadapp.om', 'password' => 'secret12345',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
        $this->assertSame('زهرة مسقط', auth()->user()->business->name);
    }

    /** ويدخل لوحته لا صفحة خطأ */
    public function test_the_owner_lands_on_their_panel(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->actingAs(\App\Support\MerchantAccount::owner($biz))
            ->get(route('admin.dashboard'))->assertOk();
    }

    /** ولا تُنشأ شركة بلا حساب */
    public function test_a_business_cannot_be_created_without_an_account(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.businesses.store'), [
            'name' => 'بلا حساب', 'type' => 'عام', 'status' => 'نشط',
        ])->assertSessionHasErrors(['login_username', 'login_password']);

        $this->assertNull(Business::where('name', 'بلا حساب')->first());
    }

    /** واسم المستخدم لا يتكرّر — والفحص على البريد الكامل لا على الاسم */
    public function test_a_duplicate_username_is_refused(): void
    {
        $this->newBusiness();

        $this->newBusiness(['name' => 'زهرة ثانية'])->assertSessionHasErrors('login_username');

        $this->assertSame(1, User::where('email', 'zahra@abaadapp.om')->count());
    }

    /** والعربية والمسافات مرفوضة: البريد يُملى في الهاتف ويُكتب على ورقة */
    public function test_an_arabic_or_spaced_username_is_refused(): void
    {
        $this->newBusiness(['login_username' => 'زهرة'])->assertSessionHasErrors('login_username');
        $this->newBusiness(['login_username' => 'al noor'])->assertSessionHasErrors('login_username');
    }

    /** وكلمة مرور قصيرة مرفوضة، والمحفوظة مجزَّأة لا نصًّا */
    public function test_the_password_is_checked_and_hashed(): void
    {
        $this->newBusiness(['login_password' => '123'])->assertSessionHasErrors('login_password');

        $this->newBusiness();
        $stored = User::where('email', 'zahra@abaadapp.om')->value('password');
        $this->assertNotSame('secret12345', $stored);
        $this->assertTrue(password_verify('secret12345', $stored));
    }

    /** والدعم يستطيع الدخول كتاجر فورًا — لا شركة بلا مستخدم بعد الآن */
    public function test_a_new_business_can_be_impersonated_immediately(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.impersonate', $biz->id))
            ->assertRedirect();

        $this->assertSame($biz->id, auth()->user()->business_id);
    }

    /* --------- الشركات القديمة: حسابٌ يُستكمل من صفحة التعديل --------- */

    /** شركة سُجّلت قبل إلزام الحساب */
    private function accountlessBusiness(): Business
    {
        return Business::create(['name' => 'قديمة', 'type' => 'عام', 'status' => 'نشط']);
    }

    private function updateBusiness(Business $biz, array $extra = [])
    {
        return $this->actingAs($this->super)->put(route('super-admin.businesses.update', $biz->id), array_merge([
            'name' => $biz->name, 'type' => 'عام', 'status' => 'نشط',
        ], $extra));
    }

    /**
     * التعديل يُنشئ الحساب الغائب.
     *
     * كانت الصفحة تعرض «—» بلا حقلٍ ولا زرّ: شركةٌ لا يفتحها أحد ولا سبيل إلى
     * إصلاحها من اللوحة — إلا بفتح قاعدة البيانات يدويًّا.
     */
    public function test_editing_creates_a_missing_owner_account(): void
    {
        $biz = $this->accountlessBusiness();

        $this->updateBusiness($biz, ['login_username' => 'qadeema', 'login_password' => 'secret12345'])
            ->assertSessionHasNoErrors();

        $owner = \App\Support\MerchantAccount::owner($biz);
        $this->assertNotNull($owner, 'بقيت الشركة بلا حساب بعد التعديل');
        $this->assertSame('qadeema@abaadapp.om', $owner->email);
    }

    /** وبه يدخل صاحبها فعلًا */
    public function test_the_completed_account_can_sign_in(): void
    {
        $biz = $this->accountlessBusiness();
        $this->updateBusiness($biz, ['login_username' => 'qadeema', 'login_password' => 'secret12345']);
        $this->post(route('logout'));

        $this->post(route('login.attempt'), [
            'email' => 'qadeema@abaadapp.om', 'password' => 'secret12345',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    /** ولا يُحفظ التعديل بحسابٍ ناقص — وإلا بقيت مقفلة وظنّ المشغّل أنه أصلحها */
    public function test_editing_an_accountless_business_requires_credentials(): void
    {
        $biz = $this->accountlessBusiness();

        $this->updateBusiness($biz, ['name' => 'محاولة'])
            ->assertSessionHasErrors(['login_username', 'login_password']);
    }

    /** والشركة التي لها حساب لا تُطالَب بشيء */
    public function test_editing_a_business_that_has_an_account_needs_nothing(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['name' => 'زهرة مسقط الجديدة'])->assertSessionHasNoErrors();

        $this->assertSame('زهرة مسقط الجديدة', $biz->fresh()->name);
        $this->assertSame(1, User::where('business_id', $biz->id)->count());
    }

    /**
     * وكلمة المرور تُبدَّل من هنا.
     *
     * «نسيت كلمة المرور» محذوفة، وصفحة الموظفين لا يفتحها من لا يدخل أصلًا —
     * فمن نسي كلمته كان لا مخرج له.
     */
    public function test_a_new_password_replaces_the_old_one(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_password' => 'newsecret999'])->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->post(route('login.attempt'), ['email' => 'zahra@abaadapp.om', 'password' => 'secret12345'])
            ->assertSessionHasErrors();
        $this->post(route('login.attempt'), ['email' => 'zahra@abaadapp.om', 'password' => 'newsecret999'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /** والفارغ يعني «لا تغيّرها»: تصحيحُ مدينةٍ لا يُخرج التاجر من حسابه */
    public function test_an_empty_password_field_leaves_the_password_alone(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();
        $before = User::where('email', 'zahra@abaadapp.om')->value('password');

        $this->updateBusiness($biz, ['city' => 'صحار', 'login_password' => '']);

        $this->assertSame($before, User::where('email', 'zahra@abaadapp.om')->value('password'));
    }

    /** والقصيرة مرفوضة هنا أيضًا */
    public function test_a_short_new_password_is_refused(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_password' => '123'])->assertSessionHasErrors('login_password');
    }

    /* ------------------ تعديل بريد الدخول ------------------ */

    /**
     * البريد قابل للتعديل.
     *
     * اسمٌ يُكتب خطأً عند التسجيل كان يبقى إلى الأبد: الحقل يُعرض ولا يُكتب،
     * فلا سبيل إلى تصحيحه إلا بحسابٍ ثانٍ يُترك الأول بجانبه.
     */
    public function test_the_login_email_can_be_changed(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_username' => 'zahra.muscat'])->assertSessionHasNoErrors();

        $this->assertSame('zahra.muscat@abaadapp.om', \App\Support\MerchantAccount::owner($biz)->email);
        // لا حساب ثانٍ: البريد بُدّل ولم يُضف
        $this->assertSame(1, User::where('business_id', $biz->id)->count());
    }

    /** وبالجديد يدخل، وبالقديم لا */
    public function test_the_old_email_stops_working_after_the_change(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();
        $this->updateBusiness($biz, ['login_username' => 'zahra.muscat']);
        $this->post(route('logout'));

        $this->post(route('login.attempt'), ['email' => 'zahra@abaadapp.om', 'password' => 'secret12345'])
            ->assertSessionHasErrors();

        $this->post(route('login.attempt'), ['email' => 'zahra.muscat@abaadapp.om', 'password' => 'secret12345'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /** والبريد المحجوز لتاجرٍ آخر مرفوض — وإلا صار لاثنين بريدُ دخولٍ واحد */
    public function test_taking_another_merchants_email_is_refused(): void
    {
        $this->newBusiness();
        $this->newBusiness(['name' => 'ورود صحار', 'login_username' => 'sohar']);
        $biz = Business::where('name', 'ورود صحار')->firstOrFail();

        $this->updateBusiness($biz, ['login_username' => 'zahra'])->assertSessionHasErrors('login_username');

        $this->assertSame('sohar@abaadapp.om', \App\Support\MerchantAccount::owner($biz)->email);
    }

    /** وحفظُ الاسم نفسه لا يُعدّ اصطدامًا بالنفس */
    public function test_saving_the_same_username_is_not_a_conflict(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_username' => 'zahra', 'city' => 'مسقط'])
            ->assertSessionHasNoErrors();

        $this->assertSame('zahra@abaadapp.om', \App\Support\MerchantAccount::owner($biz)->email);
    }

    /** والعربية مرفوضة في التعديل كما في الإنشاء */
    public function test_an_invalid_username_is_refused_on_edit(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_username' => 'زهرة'])->assertSessionHasErrors('login_username');

        $this->assertSame('zahra@abaadapp.om', \App\Support\MerchantAccount::owner($biz)->email);
    }

    /* -------------- تعديل الحساب من صفحة الشركة (مسار مستقلّ) -------------- */

    private function saveAccount(Business $biz, array $data)
    {
        return $this->actingAs($this->super)->post(route('super-admin.businesses.account', $biz->id), $data);
    }

    /**
     * الحساب يُعدَّل بمساره وحده.
     *
     * تمريرُه عبر نموذج الشركة كان يعني إعادة كتابة الاسم والنوع والحالة
     * لتغيير كلمة مرورٍ نسيها تاجر — وأيّ حقلٍ يسقط من الطلب يمحو ما في
     * القاعدة.
     */
    public function test_the_account_can_be_changed_from_its_own_route(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->saveAccount($biz, ['login_username' => 'zahra2', 'login_password' => 'newsecret999'])
            ->assertSessionHasNoErrors();

        $this->assertSame('zahra2@abaadapp.om', \App\Support\MerchantAccount::owner($biz)->email);
        // ولا تُمسّ بيانات الشركة
        $this->assertSame('زهرة مسقط', $biz->fresh()->name);
        $this->assertSame('محل ورود', $biz->fresh()->type);
    }

    /** وبالجديدة يدخل */
    public function test_the_password_set_from_the_business_page_works(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();
        $this->saveAccount($biz, ['login_username' => 'zahra', 'login_password' => 'newsecret999']);
        $this->post(route('logout'));

        $this->post(route('login.attempt'), ['email' => 'zahra@abaadapp.om', 'password' => 'newsecret999'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /**
     * وكلمة المرور تُعاد في الرسالة مرّةً واحدة.
     *
     * مخزَّنة مجزَّأة فلا تُقرأ بعدها أبدًا — وبلا عرضها هنا لا سبيل لإبلاغ
     * التاجر بها إلا أن يخترع المشغّل واحدةً أخرى.
     */
    public function test_the_new_password_is_shown_once(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->saveAccount($biz, ['login_username' => 'zahra', 'login_password' => 'newsecret999']);

        $this->assertStringContainsString('newsecret999', (string) json_encode(session('toast')));
    }

    /** ولا تُعرض حين لا تُغيَّر */
    public function test_no_password_is_shown_when_it_is_not_changed(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->saveAccount($biz, ['login_username' => 'zahra.new']);

        $toast = (string) json_encode(session('toast'));
        $this->assertStringNotContainsString('secret', $toast);
    }

    /** ولا يفتحه إلا مدير المنصة */
    public function test_only_the_platform_admin_may_change_a_merchant_account(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();
        $owner = \App\Support\MerchantAccount::owner($biz);

        $this->actingAs($owner)
            ->post(route('super-admin.businesses.account', $biz->id), [
                'login_username' => 'hacked', 'login_password' => 'secret12345',
            ])->assertForbidden();

        $this->assertSame('zahra@abaadapp.om', $owner->fresh()->email);
    }

    /**
     * وحسابٌ على نطاقٍ قديم يُنقل إلى النطاق الموحَّد.
     *
     * حسابات أُنشئت قبل توحيد النطاق تحمل غيره. وكان الحقل يُملأ بنزع
     * «@abaadapp.om» فلا يطابق شيئًا — فيصل البريد كاملًا ويُرفض الحفظ لأن @
     * ليست حرفًا مسموحًا. والقطع عند @ يجعله يعمل.
     */
    public function test_a_legacy_domain_account_moves_to_the_unified_domain(): void
    {
        $biz = Business::create(['name' => 'قديمة النطاق', 'type' => 'عام', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $biz->id, 'name' => 'مالك', 'email' => 'admin@abadpos.com',
            'password' => 'password', 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->saveAccount($biz, ['login_username' => 'admin'])->assertSessionHasNoErrors();

        $this->assertSame('admin@abaadapp.om', $owner->fresh()->email);
    }

    /** والبريد وكلمة المرور يتغيّران معًا في حفظةٍ واحدة */
    public function test_both_credentials_change_together(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_username' => 'zahra.new', 'login_password' => 'newsecret999'])
            ->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->post(route('login.attempt'), ['email' => 'zahra.new@abaadapp.om', 'password' => 'newsecret999'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }
}
