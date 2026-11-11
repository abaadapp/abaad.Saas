<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\MerchantAccount;
use App\Support\Permissions;
use App\Support\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «دخول كمستخدم» يقف على شاشة الدخول — والسببُ كوكي صندوقٍ في متصفّح الدعم.
 *
 * ═══ ما وقع ═══
 *
 * مديرُ المنصّة دخل يومًا متجرًا بفرعٍ واحد وفتح نقطةَ البيع، فرُبط متصفّحُه
 * صندوقًا لذلك المتجر سنتين (`Pos\PageController::index` يفعّل وحده). ثمّ
 * دخل كاشيرًا في متجرٍ آخر: صفحتُه الأولى نقطةُ البيع، فردّه `BindPosBranch`
 * «جهازُ متجرٍ آخر» وأخرج الجلسةَ كلَّها — وفيها حسابُ مدير المنصّة — فوقف
 * على شاشة الدخول وعليها اسمُ المتجر الأوّل.
 *
 * ═══ والقاعدة ═══
 *
 * في جلسة الدعم لا يُقرأ كوكي الصندوق ولا يُكتب: الصندوقُ صندوقُ المستخدم
 * المختار في فرعه، ولا جهازَ يُفعَّل، والمتصفّحُ يخرج كما دخل.
 */
class SupportEntersTheChosenTillNotTheBrowsersTest extends TestCase
{
    use RefreshDatabase;

    private Business $ribbon;

    private Business $zahra;

    private Business $multi;

    private Branch $ribbonBranch;

    private Branch $zahraBranch;

    private Branch $khuwair;

    private Branch $seeb;

    private User $richard;

    private User $ownerRibbon;

    private User $super;

    private PosDevice $zahraTill;

    private string $zahraRaw;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->ribbon, $this->ownerRibbon, $this->ribbonBranch] = $this->shop('RIBBON', 'owner-ribbon@abaad.om');
        [$this->zahra, , $this->zahraBranch] = $this->shop('زهرة مسقط', 'owner-zahra@abaad.om');
        [$this->multi] = $this->shop('متجر الفرعين', 'owner-multi@abaad.om');
        $this->khuwair = Branch::where('business_id', $this->multi->id)->firstOrFail();
        $this->seeb = Branch::create(['business_id' => $this->multi->id, 'name' => 'السيب']);

        $this->richard = $this->cashier($this->ribbon, 'Richard', 'richard@abaad.om');

        $this->super = User::create([
            'business_id' => null, 'name' => 'المنصّة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        // متصفّحُ مدير المنصّة صندوقٌ مفعَّلٌ لـ«زهرة مسقط» — بقيّةُ زيارةٍ سابقة
        $this->zahraRaw = Str::random(64);
        $this->zahraTill = PosDevice::create([
            'business_id' => $this->zahra->id, 'branch_id' => $this->zahraBranch->id, 'name' => 'جهاز المنصّة',
            'token_hash' => hash('sha256', $this->zahraRaw), 'status' => PosDevice::ACTIVE, 'activated_at' => now(),
        ]);

        Customer::create(['business_id' => $this->ribbon->id, 'name' => 'CUSTOMER-RIBBON', 'phone' => '91111111', 'language' => 'ar']);
        Customer::create(['business_id' => $this->multi->id, 'name' => 'CUSTOMER-MULTI', 'phone' => '92222222', 'language' => 'ar']);
        Customer::create(['business_id' => $this->zahra->id, 'name' => 'CUSTOMER-ZAHRA', 'phone' => '93333333', 'language' => 'ar']);
    }

    /* ------------------------------ أدوات ------------------------------ */

    private function shop(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'status' => 'نشط', 'tier' => 'gold', 'type' => 'عام']);
        $branch = Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي '.$name]);
        JobTitle::create(['business_id' => $shop->id, 'name' => 'كاشير', 'role' => 'cashier']);

        return [$shop, MerchantAccount::provision($shop, $email, bcrypt('password12345')), $branch];
    }

    private function cashier(Business $shop, string $name, string $email, array $branches = []): User
    {
        $user = User::create([
            'business_id' => $shop->id, 'name' => $name, 'email' => $email,
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'job_title' => 'كاشير', 'status' => 'نشط',
        ]);

        if ($branches) {
            $user->branches()->sync($branches);
        }

        return $user;
    }

    /** متصفّحُ الدعم يحمل كوكي صندوق «زهرة مسقط» في كلّ طلب */
    private function onZahrasTill(): self
    {
        return $this->withCookie(PosTerminal::COOKIE, $this->zahraTill->id.'|'.$this->zahraRaw);
    }

    private function enter(User $target)
    {
        return $this->actingAs($this->super)->post(route('super-admin.users.impersonate', $target->id));
    }

    private function till(): array
    {
        $res = $this->get(route('pos.index'));
        // يُقال إلى أين رُدّ — شاشةُ الدخول هي العطبُ نفسُه
        $this->assertFalse($res->isRedirect(), 'رُدّ من نقطة البيع إلى '.$res->headers->get('Location'));
        $res->assertOk();
        $page = $res->viewData('page');
        $this->assertSame('Pos/Index', $page['component'], 'لم تُفتح نقطة البيع');

        return [$res, $page];
    }

    /* ══════════════ ١ · الكاشير يُدخل مباشرةً ولو كان المتصفّحُ صندوقَ متجرٍ آخر ══════════════ */

    public function test_a_cashier_is_entered_on_a_browser_that_is_another_shops_till(): void
    {
        $this->onZahrasTill()->enter($this->richard)->assertRedirect(route('pos.index'));

        [$res, $page] = $this->till();

        $this->assertSame($this->richard->id, auth()->id(), 'أُخرج الكاشير المختار');
        $this->assertSame($this->super->id, session('impersonator_id'), 'ضاعت جلسةُ مدير المنصّة');
        $this->assertNotTrue(session('foreign_device'), 'رُدّ بـ«جهاز متجرٍ آخر»');
        $this->assertSame($this->ribbonBranch->id, $page['props']['context']['branchId'], 'الصندوقُ على غير فرع الكاشير');
        $this->assertTrue($page['props']['auth']['impersonating'], 'لا شريطَ أحمر');

        $names = collect($page['props']['customers'])->pluck('name');
        $this->assertContains('CUSTOMER-RIBBON', $names);
        $this->assertNotContains('CUSTOMER-ZAHRA', $names, 'تسرّب زبونُ متجر الجهاز');

        // صندوقُ «زهرة مسقط» لم يُمسّ: لا آخرَ ظهور، ولا كوكي تُكتب أو تُمحى
        $this->assertNull($this->zahraTill->fresh()->last_seen_at, 'خُتم صندوقُ متجرٍ آخر بزيارة الدعم');
        $res->assertCookieMissing(PosTerminal::COOKIE);
    }

    public function test_outside_support_the_same_cashier_on_that_browser_is_still_put_out(): void
    {
        // الحارسُ لم يُرخَ لغير الدعم: الكاشيرُ نفسُه بحسابه على صندوق متجرٍ آخر يُردّ كما كان
        $this->onZahrasTill()->actingAs($this->richard)->get(route('pos.index'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue(session('foreign_device'));
    }

    /* ══════════════ ٢ · الدعمُ لا يربط المتصفّحَ بصندوق ══════════════ */

    public function test_support_opening_the_till_of_a_shop_without_devices_activates_nothing(): void
    {
        $this->assertSame(0, PosDevice::where('business_id', $this->ribbon->id)->count());

        $this->enter($this->richard);
        [$res] = $this->till();

        $this->assertSame(0, PosDevice::where('business_id', $this->ribbon->id)->count(), 'فُعّل صندوقٌ في المتجر بزيارة الدعم');
        $res->assertCookieMissing(PosTerminal::COOKIE);
    }

    public function test_the_setup_screen_and_activation_are_closed_inside_support(): void
    {
        $this->enter($this->ownerRibbon);

        $this->get(route('pos.setup'))->assertRedirect(route('pos.index'));

        $res = $this->post(route('pos.setup.activate'), ['branch_id' => $this->ribbonBranch->id, 'name' => 'جهاز الدعم', 'mode' => 'new']);
        $res->assertSessionHasErrors('branch_id');
        $res->assertCookieMissing(PosTerminal::COOKIE);
        $this->assertSame(0, PosDevice::where('business_id', $this->ribbon->id)->count(), 'فُعّل صندوقٌ من جلسة دعم');
    }

    /* ══════════════ ٣ · كلُّ دورٍ إلى صفحته هو ══════════════ */

    public function test_the_owner_lands_on_the_owners_home(): void
    {
        $home = Permissions::homeFor($this->ownerRibbon);
        $this->assertNotSame(route('login'), $home);

        $this->onZahrasTill()->enter($this->ownerRibbon)->assertRedirect($home);
        $this->get($home)->assertOk();
        $this->assertSame($this->ownerRibbon->id, auth()->id());

        // ونقطةُ البيع من حسابه على فرع متجره، لا على صندوق المتصفّح
        [, $page] = $this->till();
        $this->assertSame($this->ribbonBranch->id, $page['props']['context']['branchId']);
    }

    public function test_a_limited_employee_lands_on_the_one_screen_they_have(): void
    {
        $clerk = User::create([
            'business_id' => $this->ribbon->id, 'name' => 'موظّفة الطلبات', 'email' => 'orders@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => ['orders'],
        ]);
        $home = Permissions::homeFor($clerk);
        $this->assertNotContains($home, [route('login'), route('pos.index')]);

        $this->onZahrasTill()->enter($clerk)->assertRedirect($home);
        $this->get($home)->assertOk();
        $this->assertSame($clerk->id, auth()->id());
        $this->get(route('admin.customers.index'))->assertStatus(403);
    }

    public function test_a_user_added_now_is_enterable_with_no_setup(): void
    {
        $fresh = $this->cashier($this->ribbon, 'كاشير جديد', 'new-cashier@abaad.om');

        $this->actingAs($this->super);
        $users = collect($this->get(route('super-admin.users.index', ['per_page' => 50]))->viewData('page')['props']['users'])->keyBy('id');
        $this->assertTrue($users[$fresh->id]['can_enter'], 'المستخدمُ الجديد بلا زرّ');

        $this->onZahrasTill()->enter($fresh)->assertRedirect(route('pos.index'));
        $this->till();
        $this->assertSame($fresh->id, auth()->id());
    }

    public function test_a_cashier_in_a_multi_branch_shop_sees_their_own_branch(): void
    {
        $seebCashier = $this->cashier($this->multi, 'كاشير السيب', 'seeb@abaad.om', [$this->seeb->id]);

        $this->onZahrasTill()->enter($seebCashier)->assertRedirect(route('pos.index'));
        [, $page] = $this->till();

        $this->assertSame($this->seeb->id, $page['props']['context']['branchId'], 'الصندوقُ على فرعٍ لا يعمل فيه');
        $this->assertSame(0, PosDevice::where('business_id', $this->multi->id)->count());
    }

    /* ══════════════ ٤ · التنقّلُ بين متجرين والعودة ══════════════ */

    public function test_moving_between_shops_carries_nothing_and_returns_to_the_platform(): void
    {
        $seebCashier = $this->cashier($this->multi, 'كاشير السيب', 'seeb@abaad.om', [$this->seeb->id]);
        $this->onZahrasTill();

        $this->enter($this->richard);
        [, $first] = $this->till();
        $this->assertSame($this->ribbonBranch->id, $first['props']['context']['branchId']);
        $this->post(route('impersonate.stop'))->assertRedirect();
        $this->assertSame($this->super->id, auth()->id());

        $this->post(route('super-admin.users.impersonate', $seebCashier->id))->assertRedirect(route('pos.index'));
        [, $second] = $this->till();
        $this->assertSame($seebCashier->id, auth()->id());
        $this->assertSame($this->seeb->id, $second['props']['context']['branchId'], 'بقي فرعُ المتجر الأوّل');
        $names = collect($second['props']['customers'])->pluck('name');
        $this->assertContains('CUSTOMER-MULTI', $names);
        $this->assertNotContains('CUSTOMER-RIBBON', $names, 'عبر زبونُ المتجر الأوّل');

        $this->post(route('impersonate.stop'))->assertRedirect(route('super-admin.users.index'));
        $this->assertSame($this->super->id, auth()->id());
        $this->assertNull(session('impersonator_id'));
        $this->get(route('super-admin.users.index'))->assertOk();
    }

    public function test_locking_the_till_inside_support_returns_to_the_platform_not_the_login_screen(): void
    {
        $this->enter($this->richard);
        $this->till();

        $this->post(route('pos.lock'))->assertRedirect(route('super-admin.users.index'));

        $this->assertSame($this->super->id, auth()->id(), 'القفلُ أخرج مديرَ المنصّة من حسابه');
        $this->assertNull(session('impersonator_id'));
    }

    public function test_locking_the_till_outside_support_is_unchanged(): void
    {
        $this->actingAs($this->richard)->post(route('pos.lock'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /* ══════════════ ٥ · المتجرُ الممنوع للعرض وحده ══════════════ */

    public function test_a_cashier_of_a_disabled_shop_is_shown_the_till_but_cannot_sell(): void
    {
        $this->ribbon->forceFill(['status' => 'معطل'])->save();

        $this->onZahrasTill()->enter($this->richard)->assertRedirect(route('pos.index'));
        [, $page] = $this->till();
        $this->assertNotNull($page['props']['auth']['impersonationLimit'], 'الشريطُ لا يقول إنّه للعرض فقط');

        $this->postJson(route('pos.checkout'), ['items' => [], 'payment_method' => 'cash'])
            ->assertStatus(403)->assertJsonPath('blocked', 'support_view_only');
        $this->assertSame(0, Order::where('business_id', $this->ribbon->id)->count());
        $this->assertSame('معطل', $this->ribbon->fresh()->status, 'أُعيد تفعيلُ المتجر');
    }

    /* ══════════════ ٦ · حسابٌ لا شاشةَ له يُردّ قبل الدخول ══════════════ */

    public function test_an_account_that_opens_no_screen_is_refused_before_entry(): void
    {
        $empty = User::create([
            'business_id' => $this->ribbon->id, 'name' => 'بلا صلاحيات', 'email' => 'empty@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => [],
        ]);
        $this->assertSame(route('login'), Permissions::homeFor($empty), 'الحالةُ المفترضة تغيّرت');

        $this->enter($empty)->assertRedirect()->assertSessionHas('toast');

        $this->assertSame($this->super->id, auth()->id(), 'دخل حسابًا يقف على شاشة الدخول');
        $this->assertNull(session('impersonator_id'));

        $users = collect($this->get(route('super-admin.users.index', ['per_page' => 50]))->viewData('page')['props']['users'])->keyBy('id');
        $this->assertFalse($users[$empty->id]['can_enter']);
        $this->assertTrue($users[$this->richard->id]['can_enter']);
    }
}
