<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\MerchantAccount;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «دخول كمستخدم» من قسم المستخدمين في لوحة المنصّة.
 *
 * «دخول كتاجر» يدخل المتجرَ بحساب مالكه — والمالكُ يرى كلَّ شيء، فشكوى
 * الكاشير «لا أرى هذا الزرّ» لا تُرى من حسابه. هذا البابُ يدخل الحسابَ
 * المختار بعينه، بصلاحياته كما هي، ثمّ يعود.
 *
 * والحرّاس هنا: الحسابُ المختار لا مالكُه · صلاحياتُه لا أكثر · لا يعبر
 * شيءٌ من متجرٍ إلى متجر ولا من جلسةٍ إلى جلسة · الدخولُ والخروجُ في
 * السجلّ بالاسمين · ولا يُدخل مديرُ منصّة ولا موقوفٌ ولا محذوف · والبابُ
 * لمدير المنصّة وحده على الخادم.
 */
class ThePlatformEntersTheChosenUserNotItsOwnerTest extends TestCase
{
    use RefreshDatabase;

    private Business $a;

    private Business $b;

    private User $ownerA;

    private User $ownerB;

    private User $clerkA;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->a, $this->ownerA] = $this->shop('متجر A', 'owner-a@abaad.om');
        [$this->b, $this->ownerB] = $this->shop('متجر B', 'owner-b@abaad.om');

        // موظّفةٌ بصلاحيّتين لا غير: لوحةُ التحكّم والعملاء — لا الموظّفين
        $this->clerkA = User::create([
            'business_id' => $this->a->id, 'name' => 'منى الموظّفة', 'email' => 'clerk-a@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['dashboard', 'customers'],
        ]);

        $this->super = User::create([
            'business_id' => null, 'name' => 'المنصّة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        Customer::create(['business_id' => $this->a->id, 'name' => 'CUSTOMER-A-SENTINEL', 'phone' => '91111111', 'language' => 'ar']);
        Customer::create(['business_id' => $this->b->id, 'name' => 'CUSTOMER-B-SENTINEL', 'phone' => '92222222', 'language' => 'ar']);
    }

    private function shop(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'status' => 'نشط', 'tier' => 'gold']);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي '.$name]);

        return [$shop, MerchantAccount::provision($shop, $email, bcrypt('password12345'))];
    }

    private function enter(User $target)
    {
        return $this->actingAs($this->super)->post(route('super-admin.users.impersonate', $target->id));
    }

    private function page(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page');
    }

    /* ══════════════ ١ · الحسابُ المختار بعينه ══════════════ */

    public function test_it_enters_the_chosen_user_not_the_owner_of_the_shop(): void
    {
        $this->enter($this->clerkA)->assertRedirect();

        $this->assertSame($this->clerkA->id, auth()->id(), 'دخل حسابًا غير المختار');
        $this->assertNotSame($this->ownerA->id, auth()->id(), 'دخل حسابَ المالك بدل الموظّفة');
        $this->assertSame($this->super->id, session('impersonator_id'));
    }

    public function test_it_lands_where_that_user_lands(): void
    {
        $this->enter($this->clerkA)->assertRedirect(Permissions::homeFor($this->clerkA));
    }

    /* ══════════════ ٢ · صلاحياتُه كما هي — لا أكثر ══════════════ */

    public function test_the_user_keeps_exactly_their_own_permissions(): void
    {
        $this->enter($this->clerkA);

        $this->get(route('admin.customers.index'))->assertOk();
        $this->get(route('admin.employees.index'))->assertForbidden();

        $abilities = $this->page(route('admin.customers.index'))['props']['auth']['abilities'];
        sort($abilities);
        $this->assertSame(['customers', 'dashboard'], $abilities, 'الواجهةُ تعرض أقسامًا ليست للموظّفة');
    }

    public function test_an_owner_can_be_entered_through_the_user_door_with_the_owners_reach(): void
    {
        $this->enter($this->ownerA)->assertRedirect();

        $this->assertSame($this->ownerA->id, auth()->id());
        $this->get(route('admin.employees.index'))->assertOk();
    }

    public function test_returning_leaves_none_of_the_users_reach_behind(): void
    {
        $this->enter($this->clerkA);
        $this->post(route('impersonate.stop'));

        $this->get(route('admin.customers.index'))->assertRedirect();
        $props = $this->page(route('super-admin.users.index'))['props']['auth'];
        $this->assertFalse($props['impersonating']);
        $this->assertSame($this->super->id, $props['user']['id']);
    }

    public function test_the_owner_door_still_sees_what_the_clerk_cannot(): void
    {
        // الفرقُ نفسُه هو سببُ الباب: المالك يرى الموظّفين والموظّفةُ لا
        $this->actingAs($this->super)->post(route('super-admin.businesses.impersonate', $this->a->id));

        $this->assertSame($this->ownerA->id, auth()->id());
        $this->get(route('admin.employees.index'))->assertOk();
    }

    public function test_entering_and_leaving_change_no_role_permission_or_password(): void
    {
        $before = User::whereKey($this->clerkA->id)->first(['role', 'permissions', 'password', 'status', 'business_id'])->toArray();

        $this->enter($this->clerkA);
        $this->post(route('impersonate.stop'))->assertRedirect();

        $this->assertSame($before, User::whereKey($this->clerkA->id)->first(['role', 'permissions', 'password', 'status', 'business_id'])->toArray());
    }

    /* ══════════════ ٣ · العودة ══════════════ */

    public function test_returning_brings_back_the_platform_admin_to_the_users_list(): void
    {
        $this->actingAs($this->super)->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.impersonate', $this->clerkA->id));

        $this->post(route('impersonate.stop'))->assertRedirect(route('super-admin.users.index'));

        $this->assertSame($this->super->id, auth()->id());
        $this->assertFalse(session()->has('impersonator_id'));
        $this->assertSame(0, Demo::bid(), 'مديرُ المنصّة بعد العودة يقع في متجر');
    }

    public function test_returning_from_the_user_page_goes_back_to_that_page(): void
    {
        $from = route('super-admin.users.show', $this->clerkA->id);
        $this->actingAs($this->super)->from($from)->post(route('super-admin.users.impersonate', $this->clerkA->id));

        $this->post(route('impersonate.stop'))->assertRedirect($from);
    }

    /* ══════════════ ٤ · لا يعبر شيءٌ من متجرٍ إلى متجر ولا من جلسة إلى جلسة ══════════════ */

    public function test_it_sees_its_own_shop_only(): void
    {
        $this->enter($this->clerkA);
        $body = json_encode($this->page(route('admin.customers.index')), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('CUSTOMER-A-SENTINEL', $body);
        $this->assertStringNotContainsString('CUSTOMER-B-SENTINEL', $body);
    }

    public function test_entering_forgets_what_the_last_session_left_and_leaving_too(): void
    {
        $left = ['current_branch' => 999, 'resume_cart' => ['items' => [1]], 'pos_cashier_id' => $this->ownerB->id, 'customer_import' => ['business_id' => $this->b->id], 'locale' => 'en'];

        $this->actingAs($this->super)->withSession($left)->post(route('super-admin.users.impersonate', $this->clerkA->id));
        foreach (['current_branch', 'resume_cart', 'pos_cashier_id', 'customer_import'] as $key) {
            $this->assertFalse(session()->has($key), "«{$key}» عبر إلى الحساب المدخول");
        }
        $this->assertSame('en', session('locale'));

        $this->withSession(['current_branch' => 777, 'impersonator_id' => $this->super->id])->post(route('impersonate.stop'));
        $this->assertFalse(session()->has('current_branch'), 'بقايا الحساب عادت إلى لوحة المنصّة');
    }

    public function test_one_user_then_another_shop_leaves_nothing_of_the_first(): void
    {
        $this->enter($this->clerkA);
        $this->post(route('impersonate.stop'));

        $this->post(route('super-admin.users.impersonate', $this->ownerB->id))->assertRedirect();
        $this->assertSame($this->ownerB->id, auth()->id());

        $body = json_encode($this->page(route('admin.customers.index')), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('CUSTOMER-B-SENTINEL', $body);
        $this->assertStringNotContainsString('CUSTOMER-A-SENTINEL', $body);
    }

    public function test_no_door_into_a_second_account_from_inside_the_first(): void
    {
        $this->enter($this->clerkA);

        $this->post(route('super-admin.users.impersonate', $this->ownerB->id));

        $this->assertSame($this->clerkA->id, auth()->id(), 'دخل من حسابٍ مُنتحَل إلى حسابٍ ثانٍ');
        $this->assertSame($this->super->id, session('impersonator_id'));
    }

    /* ══════════════ ٥ · السجلّ ══════════════ */

    public function test_entering_and_leaving_are_logged_with_both_identities(): void
    {
        $this->enter($this->clerkA);
        $this->post(route('impersonate.stop'));

        $who = 'منى الموظّفة #'.$this->clerkA->id.' — متجر A #'.$this->a->id;

        $in = ActivityLog::where('action', 'login')->where('subject_type', 'user')->latest('id')->firstOrFail();
        $this->assertSame($this->super->id, $in->user_id, 'الدخولُ لم يُنسب إلى مدير المنصّة');
        $this->assertSame('المنصّة', $in->user_name);
        $this->assertSame($this->clerkA->id, (int) $in->subject_id, 'الدخولُ لا يقول أيَّ حسابٍ دُخل');
        $this->assertSame('دخل كمستخدم: '.$who, $in->description, 'الدخولُ لا يقول الحسابَ ومتجرَه بمعرّفيهما');
        $this->assertNotNull($in->created_at, 'وقتُ البدء');

        $out = ActivityLog::where('action', 'logout')->latest('id')->firstOrFail();
        $this->assertSame($this->super->id, $out->user_id, 'الخروجُ لم يُنسب إلى مدير المنصّة');
        $this->assertSame($this->clerkA->id, (int) $out->subject_id, 'الخروجُ لا يقول من أيّ حسابٍ خرج');
        $this->assertSame('خرج من حساب: '.$who, $out->description);
        $this->assertGreaterThan($in->id, $out->id, 'الخروجُ بعد الدخول');
    }

    public function test_what_is_done_inside_is_logged_with_the_platform_admin_behind_it(): void
    {
        $this->enter($this->clerkA);
        Activity::log('update', 'فعلٌ داخل الحساب');

        $row = ActivityLog::where('description', 'فعلٌ داخل الحساب')->firstOrFail();
        $this->assertSame($this->clerkA->id, $row->user_id);
        $this->assertSame($this->super->id, $row->impersonator_id, 'ما فُعل باسم الموظّفة لا يقول من فعله حقًّا');
        $this->assertSame('المنصّة', $row->impersonator_name);
    }

    public function test_every_write_inside_is_traced_to_the_platform_admin_without_its_content(): void
    {
        $this->enter($this->clerkA);

        $this->post(route('admin.customers.store'), ['name' => 'CONTENT-NEVER-LOGGED', 'phone' => '93333333', 'language' => 'ar'])->assertRedirect();
        $this->assertDatabaseHas('customers', ['business_id' => $this->a->id, 'name' => 'CONTENT-NEVER-LOGGED']);

        $trace = ActivityLog::where('action', 'support')->latest('id')->firstOrFail();
        $this->assertSame($this->clerkA->id, $trace->user_id, 'الطلبُ لم يُنسب إلى الحساب المدخول');
        $this->assertSame($this->super->id, $trace->impersonator_id, 'الطلبُ لا يقول إنّ مدير المنصّة هو من فعله');
        $this->assertSame($this->a->id, (int) $trace->business_id);
        $this->assertStringContainsString('POST admin.customers.store /admin/customers', $trace->description);
        $this->assertStringNotContainsString('CONTENT-NEVER-LOGGED', $trace->description, 'محتوى النموذج دخل السجلّ');
        $this->assertStringNotContainsString('93333333', $trace->description);
    }

    public function test_reading_inside_writes_no_trace_line(): void
    {
        $this->enter($this->clerkA);
        $this->get(route('admin.customers.index'))->assertOk();

        $this->assertSame(0, ActivityLog::where('action', 'support')->count(), 'كلُّ قراءةٍ تملأ السجلّ');
    }

    public function test_the_employee_working_in_their_own_session_is_not_marked_as_support(): void
    {
        $this->actingAs($this->clerkA)->post(route('admin.customers.store'), ['name' => 'عميل من منى', 'phone' => '94444444', 'language' => 'ar'])->assertRedirect();
        $this->assertDatabaseHas('customers', ['name' => 'عميل من منى']);

        $this->assertSame(0, ActivityLog::where('action', 'support')->count());
        $this->assertSame(0, ActivityLog::whereNotNull('impersonator_id')->count(), 'عملُ الموظّفة نفسِها نُسب إلى الدعم');
    }

    /* ══════════════ ٥ب · الجلسة ══════════════ */

    public function test_the_session_id_changes_on_entering_and_on_leaving(): void
    {
        $this->actingAs($this->super)->get(route('super-admin.users.index'))->assertOk();
        $first = session()->getId();

        $this->post(route('super-admin.users.impersonate', $this->clerkA->id));
        $inside = session()->getId();
        $this->assertNotSame($first, $inside, 'معرّفُ الجلسة لم يتجدّد عند الدخول');

        $this->post(route('impersonate.stop'));
        $this->assertNotSame($inside, session()->getId(), 'معرّفُ الجلسة لم يتجدّد عند العودة');
    }

    public function test_entering_does_not_pretend_the_user_logged_in(): void
    {
        $this->clerkA->forceFill(['last_login_at' => null])->save();

        $this->enter($this->clerkA);

        $this->assertNull($this->clerkA->fresh()->last_login_at, 'دخولُ الدعم سُجّل «آخرَ دخولٍ» للموظّفة');
    }

    public function test_logging_out_inside_ends_the_whole_session_and_says_who(): void
    {
        $this->enter($this->clerkA);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse(session()->has('impersonator_id'));

        $row = ActivityLog::where('action', 'logout')->latest('id')->firstOrFail();
        $this->assertSame($this->clerkA->id, $row->user_id);
        $this->assertSame($this->super->id, $row->impersonator_id, 'الخروجُ من الداخل لا يقول إنّ الدعم هو من خرج');

        $this->get(route('super-admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_an_expired_session_inside_leaves_nothing_but_the_login_page(): void
    {
        $this->enter($this->clerkA);

        // انتهاءُ الجلسة: لا شيء في المخزن — لا هويّة ولا مُنتحِل
        auth()->guard('web')->logout();
        session()->flush();

        $this->get(route('admin.customers.index'))->assertRedirect(route('login'));
        $this->get(route('super-admin.users.index'))->assertRedirect(route('login'));
        $this->post(route('impersonate.stop'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_profile_cannot_be_rewritten_from_inside(): void
    {
        $this->enter($this->clerkA);

        $this->put(route('profile.update'), [
            'name' => 'منى', 'email' => 'attacker@evil.example',
            'password' => 'Sup3rSecret99', 'password_confirmation' => 'Sup3rSecret99',
        ])->assertRedirect()->assertSessionHas('toast');

        $this->assertSame('clerk-a@abaad.om', $this->clerkA->fresh()->email, 'بريدُ الموظّفة تبدّل من جلسة دعم');
        $row = ActivityLog::where('action', 'support')->latest('id')->firstOrFail();
        $this->assertSame($this->super->id, $row->impersonator_id);
        $this->assertStringNotContainsString('Sup3rSecret99', $row->description, 'كلمةُ المرور دخلت السجلّ');
        $this->assertStringNotContainsString('attacker@evil.example', $row->description);
    }

    public function test_the_employee_can_still_edit_their_own_profile_themselves(): void
    {
        $this->actingAs($this->clerkA)->put(route('profile.update'), ['name' => 'منى ع', 'email' => 'clerk-a@abaad.om'])->assertRedirect();

        $this->assertSame('منى ع', $this->clerkA->fresh()->name);
    }

    /* ══════════════ ٦ · من لا يُدخل باسمه ══════════════ */

    public function test_another_platform_admin_cannot_be_entered(): void
    {
        $other = User::create(['business_id' => null, 'name' => 'مدير آخر', 'email' => 'super2@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'super_admin', 'status' => 'نشط']);

        $this->enter($other)->assertForbidden();

        $this->assertSame($this->super->id, auth()->id());
        $this->assertFalse(session()->has('impersonator_id'));
    }

    public function test_a_suspended_user_cannot_be_entered(): void
    {
        $this->clerkA->forceFill(['status' => 'موقوف'])->save();

        $this->enter($this->clerkA)->assertRedirect()->assertSessionHas('toast');

        $this->assertSame($this->super->id, auth()->id(), 'دخل حسابًا موقوفًا');
        $this->assertFalse(session()->has('impersonator_id'));
        $this->assertSame(0, ActivityLog::where('action', 'login')->where('subject_type', 'user')->count(), 'دخولٌ لم يقع سُجِّل');
    }

    public function test_a_deleted_user_cannot_be_entered(): void
    {
        $this->clerkA->delete();

        $this->enter($this->clerkA)->assertNotFound();

        $this->assertSame($this->super->id, auth()->id());
        $this->assertFalse(session()->has('impersonator_id'));
    }

    public function test_a_user_with_no_shop_cannot_be_entered(): void
    {
        $lost = User::create(['business_id' => null, 'name' => 'بلا متجر', 'email' => 'lost@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'sales', 'status' => 'نشط']);

        $this->enter($lost)->assertRedirect();

        $this->assertSame($this->super->id, auth()->id());
        $this->assertFalse(session()->has('impersonator_id'));
    }

    public function test_an_unknown_user_is_not_found(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.impersonate', 999999))->assertNotFound();
    }

    public function test_a_user_of_a_disabled_shop_can_still_be_entered_like_the_merchant_door(): void
    {
        // كـ«دخول كتاجر»: الدعمُ يرى ما يشكو منه المعطَّل — الحسابُ نفسُه غير موقوف
        $this->a->forceFill(['status' => 'معطل'])->save();

        $this->enter($this->clerkA)->assertRedirect();
        $this->assertSame($this->clerkA->id, auth()->id());
    }

    /* ══════════════ ٦ب · المتجرُ الممنوع: يُقرأ ولا يُكتب فيه ══════════════ */

    /** @return array<string, array{0: \Closure}> */
    public static function blockedShops(): array
    {
        return [
            'معطَّل' => [fn (Business $b) => $b->forceFill(['status' => 'معطل'])->save()],
            'بانتظار السداد' => [fn (Business $b) => $b->forceFill(['status' => Business::AWAITING_PAYMENT])->save()],
            'اشتراكٌ منتهٍ' => [fn (Business $b) => $b->forceFill(['ends_at' => now()->subDays(120)])->save()],
            'الصيانة' => [fn () => Setting::updateOrCreate(['business_id' => null, 'key' => 'maintenance_mode'], ['value' => '1'])],
        ];
    }

    #[DataProvider('blockedShops')]
    public function test_inside_a_blocked_shop_support_reads_but_writes_nothing(\Closure $block): void
    {
        $block($this->a);
        $before = Business::whereKey($this->a->id)->first(['status', 'ends_at'])->toArray();

        $this->enter($this->clerkA);

        $this->get(route('admin.customers.index'))->assertOk();
        $this->assertNotNull($this->page(route('admin.customers.index'))['props']['auth']['impersonationLimit'], 'الشريطُ لا يقول إنّه للعرض فقط');

        $this->post(route('admin.customers.store'), ['name' => 'BLOCKED-WRITE', 'phone' => '95555555', 'language' => 'ar'])
            ->assertRedirect()->assertSessionHas('toast');
        $this->postJson(route('admin.customers.store'), ['name' => 'BLOCKED-WRITE', 'phone' => '95555555', 'language' => 'ar'])
            ->assertForbidden()->assertJsonPath('blocked', 'support_view_only');

        $this->assertDatabaseMissing('customers', ['name' => 'BLOCKED-WRITE']);
        $this->assertSame($before, Business::whereKey($this->a->id)->first(['status', 'ends_at'])->toArray(), 'المتجرُ عاد إلى العمل من جلسة دعم');
        $this->assertSame(2, ActivityLog::where('action', 'support')->where('impersonator_id', $this->super->id)
            ->where('description', 'like', '%مرفوض%')->count(), 'المحاولةُ المرفوضة لم تُقيَّد');

        // والعودةُ تعمل منه — الخروجُ ليس كتابةً في المتجر
        $this->post(route('impersonate.stop'))->assertRedirect();
        $this->assertSame($this->super->id, auth()->id());
    }

    public function test_an_active_shop_is_not_view_only(): void
    {
        $this->enter($this->clerkA);

        $this->assertNull($this->page(route('admin.customers.index'))['props']['auth']['impersonationLimit']);
    }

    public function test_the_merchant_door_into_a_disabled_shop_is_view_only_too(): void
    {
        $this->a->forceFill(['status' => 'معطل'])->save();
        $this->actingAs($this->super)->post(route('super-admin.businesses.impersonate', $this->a->id));

        $this->get(route('admin.dashboard'))->assertOk();
        $this->postJson(route('admin.customers.store'), ['name' => 'OWNER-DOOR-WRITE', 'phone' => '96666666', 'language' => 'ar'])->assertForbidden();
        $this->assertDatabaseMissing('customers', ['name' => 'OWNER-DOOR-WRITE']);
    }

    public function test_the_owner_of_a_disabled_shop_is_still_shut_out_on_their_own(): void
    {
        // الحارسُ لم يُليَّن لغير الدعم: صاحبُ المعطَّل يُطرد كما كان
        $this->a->forceFill(['status' => 'معطل'])->save();

        $this->actingAs($this->ownerA)->get(route('admin.customers.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /* ══════════════ ٧ · البابُ لمدير المنصّة وحده — على الخادم ══════════════ */

    public function test_a_merchant_cannot_use_the_door(): void
    {
        $this->actingAs($this->ownerA)->post(route('super-admin.users.impersonate', $this->clerkA->id));

        $this->assertSame($this->ownerA->id, auth()->id(), 'تاجرٌ دخل حسابَ موظّفته من باب المنصّة');
        $this->assertFalse(session()->has('impersonator_id'));
    }

    public function test_a_guest_cannot_use_the_door(): void
    {
        $this->post(route('super-admin.users.impersonate', $this->clerkA->id))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /* ══════════════ ٨ · الزرّ يُرسم لمن يُدخل وحده ══════════════ */

    public function test_the_button_is_offered_only_where_the_server_would_let_it_in(): void
    {
        $suspended = User::create(['business_id' => $this->a->id, 'name' => 'موقوف', 'email' => 'stopped@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'sales', 'status' => 'موقوف']);

        $this->actingAs($this->super);
        $users = collect($this->page(route('super-admin.users.index', ['per_page' => 50]))['props']['users'])->keyBy('id');

        $this->assertTrue($users[$this->clerkA->id]['can_enter']);
        $this->assertTrue($users[$this->ownerB->id]['can_enter']);
        $this->assertFalse($users[$this->super->id]['can_enter'], 'زرٌّ على حساب مدير المنصّة');
        $this->assertFalse($users[$suspended->id]['can_enter'], 'زرٌّ على حسابٍ موقوف');

        $this->assertTrue($this->page(route('super-admin.users.show', $this->clerkA->id))['props']['user']['can_enter']);
        $this->assertFalse($this->page(route('super-admin.users.show', $suspended->id))['props']['user']['can_enter']);
    }
}
