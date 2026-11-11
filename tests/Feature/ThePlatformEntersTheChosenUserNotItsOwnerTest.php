<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\MerchantAccount;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $in = ActivityLog::where('action', 'login')->where('subject_type', 'user')->latest('id')->firstOrFail();
        $this->assertSame($this->super->id, $in->user_id, 'الدخولُ لم يُنسب إلى مدير المنصّة');
        $this->assertSame($this->clerkA->id, (int) $in->subject_id, 'الدخولُ لا يقول أيَّ حسابٍ دُخل');
        $this->assertStringContainsString('منى الموظّفة', $in->description);
        $this->assertStringContainsString('متجر A', $in->description);

        $out = ActivityLog::where('action', 'logout')->latest('id')->firstOrFail();
        $this->assertSame($this->super->id, $out->user_id, 'الخروجُ لم يُنسب إلى مدير المنصّة');
        $this->assertSame($this->clerkA->id, (int) $out->subject_id, 'الخروجُ لا يقول من أيّ حسابٍ خرج');
        $this->assertSame('خرج من حساب: منى الموظّفة', $out->description);
    }

    public function test_what_is_done_inside_is_logged_with_the_platform_admin_behind_it(): void
    {
        $this->enter($this->clerkA);
        Activity::log('update', 'فعلٌ داخل الحساب');

        $row = ActivityLog::where('description', 'فعلٌ داخل الحساب')->firstOrFail();
        $this->assertSame($this->clerkA->id, $row->user_id);
        $this->assertSame($this->super->id, $row->impersonator_id, 'ما فُعل باسم الموظّفة لا يقول من فعله حقًّا');
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
