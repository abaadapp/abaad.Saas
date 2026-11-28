<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\UserSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * حسابُ صاحب البوتيك يُخرَج من كلّ جهازٍ حين يُعطَّل أو يتغيّر ربطُه — ويُنبَّه صاحبُ النشاط
 * إلى حسابات «المنتجات» التي لم تُربط بعد.
 *
 * ═══ ما كان ═══
 *
 * التعطيلُ يُطرد في الطلب التالي (`CheckTenantStatus`)، لكنّ كعكة «تذكّرني» تبقى: يُعاد
 * تفعيلُ الحساب فيعود جهازٌ قديمٌ كأنّ شيئًا لم يكن. والربطُ أو الفكُّ لا يمسّ الجلسةَ
 * المفتوحة: من فُكّ ربطُه عاد إلى صلاحيّاته كلِّها على هاتفٍ لم يدخله التاجر ليراه.
 *
 * ═══ ما صار (`UserSessions`) ═══
 *
 * التعطيلُ وتغيّرُ الربط يحذفان جلساتِه ويبدّلان رمزَ «تذكّرني»، وكلُّ جلسةٍ مختومةٌ بالربط
 * الذي فُتحت عليه — فجلسةٌ فُتحت على غيره تُنهى في طلبها التالي، أيًّا كان مشغّلُ الجلسات.
 */
class ABoutiqueAccountIsSentOutWhenItsBindingChangesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Boutique $reem;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        $this->shop = $this->business('متجر الورد', true);
        $this->owner = $this->user($this->shop, 'admin', null, 'المالك');
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك ريم', 'commission_rate' => 10, 'active' => true]);
        Product::create(['business_id' => $this->shop->id, 'name' => 'خاتم لمى', 'price' => 12, 'cost' => 0, 'quantity' => 20, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $this->lama->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function business(string $name, bool $boutiques): Business
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => $boutiques]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        return $shop;
    }

    private function user(Business $shop, string $role, ?array $permissions, ?string $name = null, ?Boutique $boutique = null, string $status = 'نشط'): User
    {
        $mail = 'u'.(++$this->seq);
        $user = User::create(['business_id' => $shop->id, 'name' => $name ?? $mail, 'email' => $mail.'@abaad.om', 'password' => bcrypt('secret-pass-1'), 'role' => $role, 'status' => $status, 'permissions' => $permissions, 'job_title' => 'بائع']);
        if ($boutique) {
            DB::table('users')->where('id', $user->id)->update(['boutique_id' => $boutique->id]);
        }

        return $user->fresh();
    }

    /** جلسةٌ مفتوحةٌ على جهاز — صفٌّ في جدول الجلسات كما يكتبه مشغّلُ الإنتاج */
    private function openSession(User $user, string $id): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => base64_encode('a:0:{}'), 'last_activity' => time()]);
    }

    private function sessionsOf(User $user): int
    {
        return DB::table('sessions')->where('user_id', $user->id)->count();
    }

    private function rememberOf(User $user): ?string
    {
        return DB::table('users')->where('id', $user->id)->value('remember_token');
    }

    /** ما يُرسل من شاشة تعديل الموظف — الاسمُ والوظيفة، والربطُ إن قيل */
    private function save(User $employee, array $extra = [])
    {
        return $this->actingAs($this->owner)->put(route('admin.employees.update', $employee->id), ['name' => $employee->name, 'job_title' => 'بائع'] + $extra);
    }

    /* ═══ ١ · التعطيلُ وتغيّرُ الربط يُنهيان كلَّ جلسةٍ و«تذكّرني» ═══ */

    public function test_binding_rebinding_unbinding_or_disabling_ends_every_session_and_the_remember_cookie(): void
    {
        config(['session.driver' => 'database']);
        $bystander = $this->user($this->shop, 'sales', ['products']);

        foreach ([
            'ربطٌ' => fn (User $u) => $this->save($u, ['boutique_id' => $this->lama->id]),
            'نقلٌ إلى بوتيكٍ آخر' => fn (User $u) => $this->save($u, ['boutique_id' => $this->reem->id]),
            'فكٌّ' => fn (User $u) => $this->save($u, ['boutique_id' => '']),
            'تعطيلٌ من الزرّ' => fn (User $u) => $this->actingAs($this->owner)->post(route('admin.employees.toggle', $u->id)),
            'تعطيلٌ من النموذج' => fn (User $u) => $this->save($u, ['status' => '0']),
        ] as $how => $change) {
            $before = match ($how) {
                'نقلٌ إلى بوتيكٍ آخر', 'فكٌّ' => $this->lama,
                default => null,
            };
            $account = $this->user($this->shop, 'sales', ['products'], null, $before);
            $account->forceFill(['remember_token' => 'old-token-'.$account->id])->saveQuietly();
            $this->openSession($account, 'phone-'.$account->id);
            $this->openSession($account, 'laptop-'.$account->id);
            $this->openSession($bystander, 'bystander-'.$account->id);

            $change($account)->assertSessionHasNoErrors();

            $this->assertSame(0, $this->sessionsOf($account), "$how: بقيت جلسةٌ مفتوحة");
            $this->assertNotSame('old-token-'.$account->id, $this->rememberOf($account), "$how: بقيت كعكةُ «تذكّرني» صالحة");
            $this->assertSame(1, DB::table('sessions')->where('id', 'bystander-'.$account->id)->count(), "$how: أُنهيت جلسةُ حسابٍ آخر");
        }
    }

    public function test_a_save_that_changes_neither_the_binding_nor_the_status_ends_nothing(): void
    {
        config(['session.driver' => 'database']);
        $account = $this->user($this->shop, 'sales', ['products'], null, $this->lama);
        $account->forceFill(['remember_token' => 'kept'])->saveQuietly();
        $this->openSession($account, 'phone');

        // الحقلُ نفسُه، أو بلا حقل — وتفعيلُ حسابٍ معطَّل لا يُخرج أحدًا
        $this->save($account, ['boutique_id' => $this->lama->id])->assertSessionHasNoErrors();
        $this->save($account)->assertSessionHasNoErrors();

        $this->assertSame(1, $this->sessionsOf($account));
        $this->assertSame('kept', $this->rememberOf($account));
    }

    /* ═══ ٢ · الختم: جلسةٌ فُتحت على ربطٍ غيرِ ربطه الآن تُنهى في طلبها التالي — أيًّا كان المشغّل ═══ */

    public function test_a_session_opened_on_another_binding_is_ended_on_its_next_request(): void
    {
        $account = $this->user($this->shop, 'sales', ['products'], null, $this->lama);

        foreach ([
            'كانت على بوتيكٍ آخر' => $this->reem->id,
            'كانت بلا ربط' => null,
        ] as $was => $boutique) {
            $this->actingAs($account)
                ->withSession([UserSessions::STAMP => ['user' => (int) $account->id, 'boutique' => $boutique]])
                ->get(route('admin.products.index'))
                ->assertRedirect(route('login'));
            $this->assertGuest();
            $this->flushSession();
            Auth::forgetGuards();
        }

        // وطلبٌ برمجيّ يُقال له ٤٠١ لا تحويل
        $this->actingAs($account)
            ->withSession([UserSessions::STAMP => ['user' => (int) $account->id, 'boutique' => $this->reem->id]])
            ->getJson(route('admin.products.index'))
            ->assertStatus(401);
    }

    public function test_a_session_without_a_stamp_or_of_another_identity_is_stamped_not_ended(): void
    {
        $account = $this->user($this->shop, 'sales', ['products'], null, $this->lama);

        // جلسةٌ فُتحت قبل هذا النشر: لا يُخرَج أحدٌ يومَ النشر
        $this->actingAs($account)->get(route('admin.products.index'))->assertOk();
        $this->assertSame(['user' => (int) $account->id, 'boutique' => (int) $this->lama->id], session(UserSessions::STAMP));

        // وتبدّلُ الهويّة في الجلسة نفسِها (الدخولُ كمستخدم، والخروجُ منه) يُختم من جديد
        $this->actingAs($this->owner)->get(route('admin.products.index'))->assertOk();
        $this->assertSame(['user' => (int) $this->owner->id, 'boutique' => null], session(UserSessions::STAMP));
        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_a_real_login_is_ended_on_its_device_once_the_owner_rebinds_the_account(): void
    {
        config(['session.driver' => 'database']);
        $account = $this->user($this->shop, 'sales', ['products'], null, $this->lama);

        // جهازُ صاحب البوتيك: دخولٌ حقيقيّ بكلمة المرور و«تذكّرني»
        $login = $this->post(route('login.attempt'), ['email' => $account->email, 'password' => 'secret-pass-1', 'remember' => true]);
        $login->assertRedirect();
        $this->assertAuthenticatedAs($account);
        $this->assertSame(1, $this->sessionsOf($account), 'لم تُكتب جلسةُ الدخول في الجدول');
        $sessionId = (string) DB::table('sessions')->where('user_id', $account->id)->value('id');
        $recaller = $login->getCookie(Auth::guard('web')->getRecallerName(), false);
        $this->assertNotNull($recaller, 'لم تُكتب كعكةُ «تذكّرني»');

        $device = function () use ($sessionId, $recaller) {
            Auth::forgetGuards();
            $this->flushSession();

            return $this->withUnencryptedCookies([config('session.cookie') => $sessionId, $recaller->getName() => $recaller->getValue()])
                ->get(route('admin.products.index'));
        };
        $device()->assertOk();

        // صاحبُ النشاط ينقله إلى بوتيكٍ آخر من جهازه هو
        Auth::forgetGuards();
        $this->flushSession();
        $this->save($account, ['boutique_id' => $this->reem->id])->assertSessionHasNoErrors();

        // والجهازُ القديم: لا جلسةَ ولا «تذكّرني» — يُردّ إلى الدخول
        $device()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /* ═══ ٣ · تنبيهُ صاحب النشاط: حساباتُ «المنتجات» التي لم تُربط ═══ */

    public function test_the_owner_is_told_by_name_of_active_products_only_accounts_left_unbound(): void
    {
        $this->user($this->shop, 'sales', ['products'], 'حسابُ منتجاتٍ قديم');
        $this->user($this->shop, 'sales', ['dashboard', 'products'], 'منتجاتٌ ولوحة');
        // ليست هيئةَ صاحب بوتيك: مربوط، أو معطَّل، أو يقرأ الطلباتِ أو الماليّة، أو لا يحمل «المنتجات»
        $this->user($this->shop, 'sales', ['products'], 'مربوطٌ أصلًا', $this->lama);
        $this->user($this->shop, 'sales', ['products'], 'معطَّل', null, 'معطل');
        $this->user($this->shop, 'sales', ['products', 'orders'], 'بائعٌ يقرأ الطلبات');
        $this->user($this->shop, 'manager', ['products', 'finance'], 'محاسب');
        $this->user($this->shop, 'sales', ['customers'], 'بلا منتجات');
        // وحسابُ متجرٍ آخر لا يُسمّى هنا أبدًا
        $other = $this->business('متجر آخر', true);
        Boutique::create(['business_id' => $other->id, 'name' => 'بوتيك هناك', 'commission_rate' => 5, 'active' => true]);
        $this->user($other, 'sales', ['products'], 'حسابُ المتجر الآخر');

        $expected = ['حسابُ منتجاتٍ قديم', 'منتجاتٌ ولوحة'];
        foreach (['admin.dashboard', 'admin.employees.index'] as $page) {
            $this->actingAs($this->owner)->get(route($page))->assertOk()
                ->assertInertia(fn (Assert $p) => $p->where('unboundBoutiqueAccounts', fn ($list) => collect($list)->pluck('name')->sort()->values()->all() === collect($expected)->sort()->values()->all()
                    && collect($list)->every(fn ($row) => array_keys((array) $row) === ['id', 'name'])));
        }
    }

    public function test_no_notice_in_a_shop_without_boutiques_for_anyone_but_the_owner_or_once_they_are_bound(): void
    {
        $old = $this->user($this->shop, 'sales', ['products'], 'حسابُ منتجاتٍ قديم');

        // مديرٌ يدير الموظفين ليس صاحبَ النشاط
        $manager = $this->user($this->shop, 'manager', ['employees', 'products', 'dashboard', 'orders']);
        $this->actingAs($manager)->get(route('admin.employees.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('unboundBoutiqueAccounts', []));

        // متجرٌ بلا بوتيكات: لا شيء، ولو فيه حسابُ «منتجات»
        $plain = $this->business('بلا بوتيك', false);
        $plainOwner = $this->user($plain, 'admin', null);
        $this->user($plain, 'sales', ['products'], 'منتجاتُ متجرٍ بلا بوتيك');
        $this->actingAs($plainOwner)->get(route('admin.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('unboundBoutiqueAccounts', []));

        // والتاجرُ يربطه بيده فيختفي — لا ربطَ ولا تعطيلَ تلقائيًّا قبل ذلك
        $this->assertNull(DB::table('users')->where('id', $old->id)->value('boutique_id'));
        $this->assertSame('نشط', DB::table('users')->where('id', $old->id)->value('status'));
        $this->save($old, ['boutique_id' => $this->lama->id])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('unboundBoutiqueAccounts', []));
    }
}
