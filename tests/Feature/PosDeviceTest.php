<?php

namespace Tests\Feature;

use App\Http\Controllers\Pos\DeviceController;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Support\Ledger;
use App\Support\PosTerminal;
use App\Support\TillActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الجهاز يعرف المتجر والفرع، والموظف يدخل ببريده وكلمة مروره.
 *
 * كان الجهاز يُعرَف بكوكي تحمل رقم المتجر لا غير: بلا فرع، بلا سجلّ، بلا
 * إبطال. فجهاز الخوير وجهاز السيب متطابقان في نظر النظام، والفرع يأتي من
 * جلسة المتصفّح — أي من مبدّل الفروع في لوحة الإدارة. وتبديلٌ في تبويبٍ آخر
 * كان ينقل مبيعات فرعٍ إلى فرعٍ آخر بلا إنذار.
 *
 * ولم يكن شيء يمنع كاشير الخوير من الدخول على جهاز السيب: `users.branch` نصٌّ
 * حرّ لا يُفحص عند الدخول أصلًا.
 *
 * وكان إسناد الفرع يُفحص عند لوحة الأرقام. ثمّ رُفع الدخول بالرمز، فانتقل
 * الفحص إلى حارس الطلب (BindPosBranch) — وهذه الاختبارات تقيسه هناك.
 */
class PosDeviceTest extends TestCase
{
    use RefreshDatabase;

    private Business $a;

    private Business $b;

    private Branch $khuwair;

    private Branch $seeb;

    private Branch $branchB;

    private User $ownerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Business::create(['name' => 'متجر أ', 'type' => 'عام', 'status' => 'نشط']);
        $this->b = Business::create(['name' => 'متجر ب', 'type' => 'عام', 'status' => 'نشط']);

        foreach ([$this->a, $this->b] as $biz) {
            JobTitle::create(['business_id' => $biz->id, 'name' => 'كاشير', 'role' => 'cashier']);
        }

        $this->khuwair = Branch::create(['business_id' => $this->a->id, 'name' => 'الخوير']);
        $this->seeb = Branch::create(['business_id' => $this->a->id, 'name' => 'السيب']);
        $this->branchB = Branch::create(['business_id' => $this->b->id, 'name' => 'فرع ب']);

        $this->ownerA = User::create([
            'business_id' => $this->a->id, 'name' => 'مالك أ', 'email' => 'a@abaad.om',
            'password' => 'password', 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* ------------------------------ أدوات ------------------------------ */

    private function cashier(Business $biz, string $email, array $branches = []): User
    {
        $u = User::create([
            'business_id' => $biz->id, 'name' => 'كاشير '.$email, 'email' => $email,
            'password' => 'password', 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط',
        ]);

        if ($branches) {
            $u->branches()->sync($branches);
        }

        return $u;
    }

    /** يفعّل جهازًا مباشرةً ويُرجع [الجهاز، الرمز الخام] */
    private function device(Branch $branch, string $name = 'كاشير 01'): array
    {
        $raw = Str::random(64);
        $device = PosDevice::create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'name' => $name,
            'token_hash' => hash('sha256', $raw),
            'status' => PosDevice::ACTIVE,
            'activated_at' => now(),
        ]);

        return [$device, $raw];
    }

    private function onDevice(PosDevice $device, string $raw): self
    {
        return $this->withCookie(PosTerminal::COOKIE, $device->id.'|'.$raw);
    }

    /** يدخل الكاشير ببريده ثمّ يفتح نقطة البيع على هذا الجهاز */
    private function enterPos(PosDevice $device, string $raw, User $user)
    {
        return $this->onDevice($device, $raw)->actingAs($user)->get(route('pos.index'));
    }

    /**
     * الفرعُ الذي قرأه الصندوقُ في طلبه — من ترويسته، لا من الجلسة.
     *
     * كانت هذه الاختبارات تقرأ `session('current_branch')`. والجلسةُ لم تعد
     * تُكتب (#165): الفرعُ على الطلب وحده، فيُقرأ ممّا رسمه الصندوق نفسُه.
     */
    private function tillBranch($response): ?int
    {
        $response->assertOk();

        return $response->viewData('page')['props']['context']['branchId'] ?? null;
    }

    /* ------------------------- لا باب إلا واحد ------------------------- */

    /** شاشة الرمز ومسارها رُفعا من النظام */
    public function test_the_pin_door_no_longer_exists(): void
    {
        $this->get('/pin-login')->assertNotFound();
        $this->post('/pin-login', ['pin' => '1234'])->assertNotFound();
    }

    /* -------------------------- عزل المستأجرين -------------------------- */

    /** موظف من متجر أ لا يبيع على جهاز متجر ب — يُخرَج من الجلسة */
    public function test_an_employee_cannot_use_another_tenants_device(): void
    {
        $cashier = $this->cashier($this->a, 'a1@abaad.om');
        [$devB, $rawB] = $this->device($this->branchB);

        $this->enterPos($devB, $rawB, $cashier)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** ورسالتُه تقول الحقيقة: الجهازُ لمتجرٍ آخر، لا صلاحيّةٌ تنقصه */
    public function test_and_he_is_told_the_device_belongs_to_another_shop(): void
    {
        $cashier = $this->cashier($this->a, 'a2@abaad.om');
        [$devB, $rawB] = $this->device($this->branchB);

        $this->enterPos($devB, $rawB, $cashier)
            ->assertSessionHasErrors(['email' => 'هذا الجهاز صندوقٌ لمتجرٍ آخر — انسَه ليفتح صندوقُ متجرك عليه.']);
    }

    /**
     * ومخرجُه يُعرض زرًّا في الرسالة، لا رابطًا رماديًّا أسفل الشاشة.
     *
     * وقعت على موظّفٍ حقيقيّ: دخل ببريده الصحيح وكلمته الصحيحة فأُخرج عند
     * الباب مرّةً بعد مرّة — وظنّ صاحبُ المتجر أنّ كلمة المرور هي العطب
     * فأعاد تعيينها مرارًا، وهي ليست المشكلة أصلًا.
     */
    public function test_and_the_login_screen_shows_him_the_way_out(): void
    {
        $cashier = $this->cashier($this->a, 'a3@abaad.om');
        [$devB, $rawB] = $this->device($this->branchB);

        $this->enterPos($devB, $rawB, $cashier);

        $this->assertTrue(session('foreign_device'), 'لم يُعلَّم البابُ أنّ السببَ جهازُ متجرٍ آخر');

        $this->get(route('login'))->assertInertia(
            fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Auth/Login')
                ->where('foreignDevice', true)
        );
    }

    /** وفرعٌ في متجره لم يُسنَد إليه لا زرَّ له — ذاك إسنادٌ يُصلحه مديرُه */
    public function test_but_a_branch_of_his_own_shop_raises_no_such_button(): void
    {
        $cashier = $this->cashier($this->a, 'a4@abaad.om', [$this->khuwair->id]);
        [$devSeeb, $rawSeeb] = $this->device($this->seeb, 'صندوق السيب');

        $this->enterPos($devSeeb, $rawSeeb, $cashier);

        $this->assertNotTrue(session('foreign_device'), 'عُرض نسيانُ صندوقٍ من متجره هو');
    }

    /* ═══════ ومن له بابٌ غيرُ الصندوق لا تُهدَم جلستُه ═══════ */

    public function test_an_owner_on_another_shops_till_keeps_his_session(): void
    {
        /*
         * ═══ وهذه وقعت على صاحب متجرٍ حقيقيّ ═══
         *
         * جهازُ متجرٍ مفعَّلٌ على هاتفه، ثمّ دخل بحساب متجرٍ آخر: فكلُّ فتحةٍ
         * لنقطة البيع تُخرجه من النظام كلِّه — لا من الشاشة وحدها. وهو لا
         * يحتاج صندوقَ ذلك المتجر أصلًا، ولوحتُه مفتوحةٌ له.
         */
        [$devB, $rawB] = $this->device($this->branchB);

        $this->enterPos($devB, $rawB, $this->ownerA)
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
    }

    /**
     * ═══ والمخرجُ يُعطى في يده، لا يُشار إلى شاشةٍ لا يبلغها ═══
     *
     * «اضغط ليس هذا متجرك؟ في شاشة الدخول» تُقال لمن يقف في شاشة الدخول.
     * أمّا من دخل إلى لوحته فلا يبلغ ذلك الزرَّ إلّا أن يخرج من النظام ثمّ
     * يعود — فيبقى يضغط «نقطة البيع» ويُردّ كلَّ مرّة. وقد وقعت على صاحب
     * متجرٍ حقيقيّ بعد أن كُفّ عنه الطرد.
     */
    public function test_and_the_warning_carries_the_way_out(): void
    {
        [$devB, $rawB] = $this->device($this->branchB);

        $this->enterPos($devB, $rawB, $this->ownerA);

        $toast = session('toast');

        $this->assertSame(route('device.forget'), $toast['confirm']['url'] ?? null, 'التنبيهُ بلا زرِّ نسيان');
        $this->assertSame('post', $toast['confirm']['method'] ?? null);
        $this->assertStringContainsString('انسَه', $toast['msg']);
    }

    /** وفرعٌ في متجره لم يُسنَد إليه لا زرَّ له: ذاك إسنادٌ يُصلحه مديرُه */
    public function test_but_a_branch_he_is_not_assigned_to_offers_no_such_button(): void
    {
        $manager = User::create([
            'business_id' => $this->a->id, 'name' => 'مدير الخوير', 'email' => 'm2@abaad.om',
            'password' => 'password', 'role' => 'manager', 'status' => 'نشط',
        ]);
        $manager->branches()->sync([$this->khuwair->id]);

        [$devMain, $rawMain] = $this->device($this->seeb, 'صندوق السيب');

        $this->enterPos($devMain, $rawMain, $manager);

        $this->assertArrayNotHasKey('confirm', session('toast') ?? [], 'يُعرض نسيانُ صندوقٍ من متجره هو');
    }

    /** والنسيانُ من داخل اللوحة يفتح الصندوق — لا يُرسله إلى شاشة الدخول */
    public function test_forgetting_from_inside_opens_the_till(): void
    {
        [$devB, $rawB] = $this->device($this->branchB);

        $this->onDevice($devB, $rawB)->actingAs($this->ownerA)
            ->post(route('device.forget'))
            ->assertRedirect(route('pos.index'))
            ->assertCookieExpired(PosTerminal::COOKIE);

        $this->assertAuthenticated();

        /*
         * والكعكةُ تُسقط في الردّ لا في عميل الاختبار — فتُمحى هنا بيدٍ لتُقاس
         * الطلبةُ التالية كما يقيسها متصفّحٌ نسيَها.
         */
        $this->defaultCookies = [];

        // فلا يردّه حارسُ الفرع إلى لوحته: البابُ التالي بابُ تفعيلٍ لمتجره هو
        $this->actingAs($this->ownerA)->get(route('pos.index'))
            ->assertRedirect(route('pos.setup'));
    }

    /** ومتجرُ الفرع الواحد يُفتح صندوقُه من فوره — بلا شاشة تفعيل */
    public function test_and_a_one_branch_shop_opens_its_till_at_once(): void
    {
        $shop = Business::create(['name' => 'متجر الفرع الواحد', 'type' => 'عام', 'status' => 'نشط']);
        $only = Branch::create(['business_id' => $shop->id, 'name' => 'الوحيد']);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'مالك', 'email' => 'one@abaad.om',
            'password' => 'password', 'role' => 'admin', 'status' => 'نشط',
        ]);

        [$devB, $rawB] = $this->device($this->branchB);

        $this->onDevice($devB, $rawB)->actingAs($owner)
            ->post(route('device.forget'))
            ->assertRedirect(route('pos.index'));

        $this->defaultCookies = [];

        $this->actingAs($owner)->get(route('pos.index'))->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('pos_devices', ['business_id' => $shop->id, 'branch_id' => $only->id]);
    }

    /** ومن يقف في شاشة الدخول يبقى بابُه كما كان */
    public function test_and_a_guest_who_forgets_still_lands_on_the_login_screen(): void
    {
        [$devB, $rawB] = $this->device($this->branchB);

        $this->onDevice($devB, $rawB)->post(route('device.forget'))
            ->assertRedirect(route('login'));
    }

    public function test_and_the_loop_that_trapped_him_is_cut(): void
    {
        /*
         * الخروجُ كان يحفظ الوجهة المقصودة، فيدخل من جديدٍ فيُردّ إلى `/pos`
         * فيُخرَج ثانيةً — دورةٌ لا مخرج منها إلّا زرٌّ رماديٌّ لا يعرفه أحد.
         */
        [$devB, $rawB] = $this->device($this->branchB);

        $this->onDevice($devB, $rawB)->actingAs($this->ownerA)
            ->withSession(['url.intended' => route('pos.index')])
            ->get(route('pos.index'));

        $this->assertNull(session('url.intended'), 'الوجهةُ المحفوظة باقيةٌ — تعيده الدورة');
    }

    public function test_a_manager_of_the_wrong_branch_is_sent_home_not_out(): void
    {
        // وداخلَ متجره كذلك: القاعدةُ في البابِ لا في المتجر
        $manager = User::create([
            'business_id' => $this->a->id, 'name' => 'مدير الخوير', 'email' => 'm@abaad.om',
            'password' => 'password', 'role' => 'manager', 'status' => 'نشط',
        ]);
        $manager->branches()->sync([$this->khuwair->id]);

        [$dev, $raw] = $this->device($this->seeb);

        $this->enterPos($dev, $raw, $manager)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_but_a_cashier_is_still_put_out_at_the_door(): void
    {
        /*
         * ولا يُنقض الحارسُ القائم: صفحةُ الكاشير هي نقطةُ البيع نفسُها، فردُّه
         * إليها يدور بلا نهاية. فيبقى الخروجُ له وحدَه.
         */
        $cashier = $this->cashier($this->a, 'a3@abaad.om', [$this->khuwair->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $this->enterPos($dev, $raw, $cashier)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /* ---------------------------- إذن الفرع ---------------------------- */

    /** ممنوعٌ من فرعٍ لا يبيع على جهازه ولو دخل ببريدٍ صحيح */
    public function test_an_employee_not_assigned_to_the_branch_is_refused(): void
    {
        $cashier = $this->cashier($this->a, 'k@abaad.om', [$this->khuwair->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $this->enterPos($dev, $raw, $cashier)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** ومن له فرعان يعمل على جهازي الفرعين */
    public function test_an_employee_of_two_branches_works_on_both_devices(): void
    {
        $cashier = $this->cashier($this->a, 'k2@abaad.om', [$this->khuwair->id, $this->seeb->id]);
        [$d1, $r1] = $this->device($this->khuwair);
        [$d2, $r2] = $this->device($this->seeb, 'كاشير 02');

        $res = $this->enterPos($d1, $r1, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->khuwair->id, $this->tillBranch($res));

        $res = $this->enterPos($d2, $r2, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->seeb->id, $this->tillBranch($res));
    }

    /** وبلا تحديد يعمل في كل فروع متجره — وإلا أُقفل كل كاشير قائم يوم النشر */
    public function test_an_employee_without_branches_works_everywhere_in_their_tenant(): void
    {
        $cashier = $this->cashier($this->a, 'k3@abaad.om');
        [$dev, $raw] = $this->device($this->seeb);

        $res = $this->enterPos($dev, $raw, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->seeb->id, $this->tillBranch($res));
    }

    /* --------------------------- إبطال الجهاز --------------------------- */

    /** جهاز مُلغى لا يبيع: يعود إلى شاشة الإعداد لا إلى الصندوق */
    public function test_a_revoked_device_cannot_sell(): void
    {
        $cashier = $this->cashier($this->a, 'k5@abaad.om');
        [$dev, $raw] = $this->device($this->khuwair);
        PosTerminal::revoke($dev);

        $this->enterPos($dev, $raw, $cashier)->assertRedirect(route('pos.setup'));
    }

    /* ------------------------- صلاحية التفعيل ------------------------- */

    /** الكاشير لا يفعّل جهازًا ولا ينقله بين الفروع */
    public function test_a_cashier_cannot_activate_or_move_a_device(): void
    {
        $cashier = $this->cashier($this->a, 'k6@abaad.om');
        [$dev] = $this->device($this->khuwair);

        // شاشةُ الإعداد لا تصفعه بـ403 — تقول له ما ينقص؛ والتفعيلُ نفسُه يبقى ممنوعًا
        $this->actingAs($cashier)->get(route('pos.setup'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Pos/DeviceNotActivated'));
        $this->actingAs($cashier)->post(route('pos.setup.activate'), [
            'branch_id' => $this->seeb->id, 'name' => 'جهاز الكاشير',
        ])->assertForbidden();

        // وإدارة الأجهزة تحت قسم الإعدادات، وهو ليس للكاشير
        $this->actingAs($cashier)->put(route('admin.devices.update', $dev->id), [
            'name' => 'منقول', 'branch_id' => $this->seeb->id,
        ])->assertForbidden();

        $this->assertSame($this->khuwair->id, $dev->fresh()->branch_id);
    }

    /** والمدير يفعّل ويُلغي */
    public function test_an_admin_can_activate_and_revoke(): void
    {
        $this->actingAs($this->ownerA)->post(route('pos.setup.activate'), [
            'branch_id' => $this->khuwair->id, 'name' => 'كاشير الخوير 1',
        ])->assertRedirect(route('pos.index'));

        $device = PosDevice::where('business_id', $this->a->id)->firstOrFail();
        $this->assertSame($this->khuwair->id, $device->branch_id);
        // الرمز لا يُخزَّن خامًا أبدًا
        $this->assertSame(64, strlen($device->token_hash));

        $this->actingAs($this->ownerA)->delete(route('admin.devices.revoke', $device->id));
        $this->assertSame(PosDevice::REVOKED, $device->fresh()->status);
    }

    /** ولا يفعّل مديرٌ جهازًا على فرعٍ من متجر آخر */
    public function test_activation_refuses_a_branch_from_another_tenant(): void
    {
        $this->actingAs($this->ownerA)->post(route('pos.setup.activate'), [
            'branch_id' => $this->branchB->id, 'name' => 'اختراق',
        ])->assertSessionHasErrors('branch_id');

        $this->assertSame(0, PosDevice::count());
    }

    /** ونقل الجهاز إلى فرعٍ آخر يُبطل تفعيله ويدوّر رمزه */
    public function test_moving_a_device_revokes_it(): void
    {
        [$dev, $raw] = $this->device($this->khuwair);
        $oldHash = $dev->token_hash;

        $this->actingAs($this->ownerA)->put(route('admin.devices.update', $dev->id), [
            'name' => $dev->name, 'branch_id' => $this->seeb->id, 'confirm_move' => true,
        ]);

        $dev->refresh();
        $this->assertSame($this->seeb->id, $dev->branch_id);
        $this->assertSame(PosDevice::REVOKED, $dev->status);
        $this->assertNotSame($oldHash, $dev->token_hash);

        // والكوكي القديمة لا تفتح صندوقًا بعدها
        $cashier = $this->cashier($this->a, 'k7@abaad.om');
        $this->enterPos($dev, $raw, $cashier)->assertRedirect(route('pos.setup'));
    }

    /* ------------------- الجهاز يُحيا ولا يُستنسخ ------------------- */

    /**
     * إعادةُ التفعيل تُحيي الصفَّ نفسَه — بعتاده.
     *
     * ═══ العطب ═══
     *
     * نقلُ جهازٍ إلى فرعٍ آخر يُبطل تفعيله، وتقول الرسالةُ للمدير: «أعد
     * تفعيله من الجهاز نفسه». فكان يفعل، فيُنشأ صفٌّ ثانٍ: واحدٌ ملغًى يحمل
     * طابعةَ الصندوق ودرجَه، وواحدٌ نشطٌ بلا عتاد.
     *
     * فيتوقّف الإيصالُ عن الطباعة ولا أحدَ يعرف لماذا: المديرُ اتّبع ما قيل
     * له، والشاشةُ قالت «تمّ التفعيل»، والملحقاتُ معلَّقةٌ على صفٍّ لا يُقرأ.
     *
     * فيُسأل عن الثلاثة: صفٌّ واحد، نشطٌ على الفرع الجديد، وعتادُه معه.
     */
    public function test_reactivating_revives_the_same_register_with_its_hardware(): void
    {
        [$dev] = $this->device($this->khuwair, 'كاشير الخوير');
        $printer = $this->printer($dev);

        $this->actingAs($this->ownerA)->put(route('admin.devices.update', $dev->id), [
            'name' => 'كاشير الخوير', 'branch_id' => $this->seeb->id, 'confirm_move' => true,
        ])->assertRedirect();

        $this->assertSame(PosDevice::REVOKED, $dev->fresh()->status);

        // «أعد تفعيله من الجهاز نفسه» — بالكوكي التي يحملها هذا المتصفّح
        $this->onDevice($dev, 'رمزٌ بطل')->actingAs($this->ownerA)
            ->post(route('pos.setup.activate'), ['branch_id' => $this->seeb->id, 'name' => 'كاشير الخوير'])
            ->assertRedirect(route('pos.index'));

        $this->assertSame(1, PosDevice::where('business_id', $this->a->id)->count(), 'وُلد للجهاز توأم');

        $dev->refresh();
        $this->assertSame(PosDevice::ACTIVE, $dev->status);
        $this->assertSame($this->seeb->id, $dev->branch_id);
        $this->assertSame($dev->id, $printer->fresh()->pos_device_id, 'الطابعةُ بقيت على صفٍّ ملغى');
        $this->assertCount(1, $dev->peripherals, 'الجهازُ عاد بلا عتاده');
    }

    /** والرمزُ القديم يبقى ميتًا بعد الإحياء — الإحياءُ ليس استرجاعًا لما بطل */
    public function test_the_dead_token_stays_dead_after_a_revival(): void
    {
        [$dev, $raw] = $this->device($this->khuwair);

        $this->actingAs($this->ownerA)->delete(route('admin.devices.revoke', $dev->id));

        $this->onDevice($dev, $raw)->actingAs($this->ownerA)
            ->post(route('pos.setup.activate'), ['branch_id' => $this->khuwair->id, 'name' => 'كاشير 01']);

        $cashier = $this->cashier($this->a, 'k20@abaad.om');
        $this->enterPos($dev->fresh(), $raw, $cashier)->assertRedirect(route('pos.setup'));
    }

    /**
     * ولا يُتبنّى صفُّ جارٍ بمعرّفٍ في كوكي.
     *
     * الكوكي مشفَّرةٌ بمفتاح التطبيق فلا يكتبها إلّا النظام — لكنّ متصفّحًا
     * خدم متجرًا ثمّ صار في يد متجرٍ آخر يحملها. فيُحصر البحثُ بالمتجر،
     * وإلّا انتقل صفُّ جهازٍ — بعتاده وسجلّه — إلى متجرٍ لا يملكه.
     */
    public function test_a_cookie_from_another_tenant_does_not_hand_over_a_register(): void
    {
        [$theirs] = $this->device($this->branchB, 'جهازهم');
        $ownerB = User::create([
            'business_id' => $this->b->id, 'name' => 'صاحب ب', 'email' => 'ob@abaad.om',
            'password' => 'password12345', 'role' => 'admin', 'status' => 'نشط',
        ]);

        // صاحبُ «ب» يفعّل على متصفّحٍ يحمل كوكي جهازِ «أ»
        [$mine] = $this->device($this->khuwair, 'جهازي');

        $this->onDevice($mine, 'أيًّا كان')->actingAs($ownerB)
            ->post(route('pos.setup.activate'), ['branch_id' => $this->branchB->id, 'name' => 'جهاز ب الجديد']);

        $mine->refresh();
        $this->assertSame($this->a->id, $mine->business_id, 'صفُّ جهازٍ انتقل بين متجرين');
        $this->assertSame('جهازي', $mine->name);
        $this->assertSame(2, PosDevice::where('business_id', $this->b->id)->count(), 'لم يُنشأ صفٌّ لمتجر ب');
        $this->assertSame($theirs->id, $theirs->fresh()->id);
    }

    /** طابعةُ شبكةٍ على هذا الصندوق */
    private function printer(PosDevice $device): PosPeripheral
    {
        return PosPeripheral::create([
            'business_id' => $device->business_id,
            'pos_device_id' => $device->id,
            'name' => 'طابعة الإيصالات',
            'type' => 'طابعة',
            'connection' => 'شبكة',
            'address' => '192.168.1.50',
            'paper_width' => 80,
            'auto_print' => true,
            'active' => true,
        ]);
    }

    /* -------------------------- سياق الجلسة -------------------------- */

    /**
     * الجلسة تحمل الموظف والجهاز والمتجر الصحيح، والطلبُ يحمل الفرع.
     *
     * والفرعُ لا يُكتب في الجلسة: هي جلسةُ لوحة الإدارة كذلك (#165).
     */
    public function test_the_session_carries_the_right_context(): void
    {
        $cashier = $this->cashier($this->a, 'k8@abaad.om', [$this->seeb->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $res = $this->enterPos($dev, $raw, $cashier);

        $this->assertSame($cashier->id, auth()->id());
        $this->assertSame($this->a->id, auth()->user()->business_id);
        $this->assertSame($this->seeb->id, $this->tillBranch($res));
        $this->assertNull(session('current_branch'), 'كتب الصندوقُ فرعَه في جلسة الإدارة');
        $this->assertNotNull($dev->fresh()->last_seen_at);
    }

    /** و«كل الفروع» لا تصل إلى نقطة البيع: الجهاز يفرض فرعه في كل طلب */
    public function test_all_branches_never_reaches_the_pos(): void
    {
        [$dev, $raw] = $this->device($this->seeb);

        // المالك يفتح نقطة البيع وجلسته على «كل الفروع» (null)
        // «كل الفروع» = null في الجلسة. والمهمّ أن الصندوق قرأ فرعَ الجهاز،
        // وأنّ «كل الفروع» بقيت للوحة كما اختارها.
        $res = $this->onDevice($dev, $raw)->actingAs($this->ownerA)
            ->withSession(['current_branch' => null])
            ->get(route('pos.index'));

        $this->assertSame($this->seeb->id, $this->tillBranch($res));
        $this->assertNull(session('current_branch'));
    }

    /** وجهازٌ غير مفعَّل لا يبيع — يُساق إلى شاشة الإعداد */
    public function test_an_unactivated_browser_is_sent_to_setup(): void
    {
        $this->actingAs($this->ownerA)->get(route('pos.index'))
            ->assertRedirect(route('pos.setup'));
    }

    /* --------------------------- سجلّ البيع --------------------------- */

    /** الفاتورة تحمل المتجر والفرع والجهاز والموظف */
    public function test_a_sale_records_its_device_context(): void
    {
        $cashier = $this->cashier($this->a, 'k11@abaad.om', [$this->seeb->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $product = Product::create([
            'business_id' => $this->a->id, 'name' => 'صنف', 'price' => 1.5, 'quantity' => 10,
        ]);

        $this->enterPos($dev, $raw, $cashier);

        $this->onDevice($dev, $raw)->actingAs($cashier)->post(route('pos.checkout'), [
            'items' => [['id' => $product->id, 'name' => 'صنف', 'qty' => 1, 'price' => 1.5]],
            'payment_method' => 'نقدي',
        ]);

        $order = Order::where('business_id', $this->a->id)->latest('id')->first();
        $this->assertNotNull($order, 'لم تُسجَّل فاتورة');
        $this->assertSame($this->a->id, $order->business_id);
        $this->assertSame($this->seeb->id, $order->branch_id);
        $this->assertSame($dev->id, $order->pos_device_id);
        $this->assertSame($cashier->id, $order->user_id);
    }

    /** والقفل يُنهي جلسة الموظف ويُبقي الجهاز */
    public function test_locking_ends_the_employee_session_and_keeps_the_device(): void
    {
        $cashier = $this->cashier($this->a, 'k12@abaad.om');
        [$dev, $raw] = $this->device($this->khuwair);

        $this->enterPos($dev, $raw, $cashier);
        $this->assertAuthenticated();

        $this->onDevice($dev, $raw)->post(route('pos.lock'))->assertRedirect(route('login'));
        $this->assertGuest();

        // والجهاز ما زال يعرف نفسه: الكاشير التالي يدخل ببريده ويجد فرعه
        $res = $this->enterPos($dev, $raw, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->khuwair->id, $this->tillBranch($res));
    }

    /* ─────────── حذفُ صفّ الجهاز — غيرُ إبطال تفعيله ─────────── */

    /**
     * صندوقٌ فُعِّل بالخطأ ولم يبع يُمحى صفُّه.
     *
     * القائمةُ كانت تنمو ولا تنقص: جهازٌ فُعِّل على الفرع الخطأ، أو تجربةُ
     * يوم التركيب، يبقى صفُّه أبدًا يقول «ملغى». وبعد سنةٍ لا يُعرف الصندوقُ
     * القائم من أثرِ تجربةٍ قديمة.
     */
    public function test_a_revoked_till_that_never_sold_is_erased(): void
    {
        [$dev] = $this->device($this->khuwair, 'تجربة التركيب');
        PosTerminal::revoke($dev);

        $this->actingAs($this->ownerA)
            ->delete(route('admin.devices.destroy', $dev->id))
            ->assertRedirect();

        $this->assertNull(PosDevice::find($dev->id), 'بقي صفُّ صندوقٍ لم يبع شيئًا');
    }

    /** وملحقاتُه تذهب معه — طابعةٌ بلا جهازها صفٌّ لا معنى له */
    public function test_erasing_a_till_takes_its_peripherals(): void
    {
        [$dev] = $this->device($this->khuwair);
        PosTerminal::revoke($dev);

        $p = PosPeripheral::create([
            'business_id' => $this->a->id, 'pos_device_id' => $dev->id,
            'name' => 'طابعة', 'type' => 'printer', 'connection' => 'network', 'active' => true,
        ]);

        $this->actingAs($this->ownerA)->delete(route('admin.devices.destroy', $dev->id));

        $this->assertNull(PosPeripheral::find($p->id), 'بقيت طابعةٌ معلَّقةٌ على جهازٍ محذوف');
    }

    /**
     * ═══ وصندوقٌ باع لا يُحذف ═══
     *
     * `orders.pos_device_id` يسقط إلى `NULL` عند حذف الصفّ. فحذفُ الجهاز
     * يمحو الجوابَ عن سؤالٍ لا يُسأل إلّا حين يقع خطب: «الدرج ناقصٌ عشرين
     * ريالًا — من أيّ صندوقٍ خرجت؟». والعمودُ كُتب لهذا وحده.
     */
    public function test_a_till_that_sold_is_never_erased(): void
    {
        $cashier = $this->cashier($this->a, 'k20@abaad.om', [$this->seeb->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $product = Product::create([
            'business_id' => $this->a->id, 'name' => 'صنف', 'price' => 1.5, 'quantity' => 10,
        ]);

        $this->enterPos($dev, $raw, $cashier);
        $this->onDevice($dev, $raw)->actingAs($cashier)->post(route('pos.checkout'), [
            'items' => [['id' => $product->id, 'name' => 'صنف', 'qty' => 1, 'price' => 1.5]],
            'payment_method' => 'نقدي',
        ]);

        $order = Order::where('pos_device_id', $dev->id)->first();
        $this->assertNotNull($order, 'لم تُسجَّل فاتورة على الجهاز');

        PosTerminal::revoke($dev);
        $this->actingAs($this->ownerA)->delete(route('admin.devices.destroy', $dev->id));

        $this->assertNotNull(PosDevice::find($dev->id), 'حُذف صندوقٌ باع');
        $this->assertSame($dev->id, $order->fresh()->pos_device_id,
            'فُقدت نسبةُ فاتورةٍ إلى صندوقها');
    }

    /** والرفضُ يقول كم عليه — على القناة التي تعرضها الشاشة */
    public function test_the_refusal_counts_what_is_on_the_till(): void
    {
        [$dev] = $this->device($this->khuwair, 'صندوق الخوير');
        PosTerminal::revoke($dev);
        Order::create([
            'business_id' => $this->a->id, 'branch_id' => $this->khuwair->id,
            'pos_device_id' => $dev->id, 'number' => 'INV-9', 'status' => 'مكتمل',
            'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        $this->actingAs($this->ownerA)->delete(route('admin.devices.destroy', $dev->id));

        $toast = session('toast');
        $this->assertSame('danger', $toast['type'] ?? null, 'رفضٌ لا يصل الشاشةَ بلون الخطأ');
        $this->assertStringContainsString('صندوق الخوير', (string) ($toast['msg'] ?? ''));
        $this->assertStringContainsString('1', (string) ($toast['msg'] ?? ''), 'الرفض لا يقول كم عليه');
    }

    /**
     * ووردياتٌ بلا فاتورةٍ واحدة تمنع الحذف كذلك.
     *
     * الوردية تُفتح ويُعدّ الدرج ثمّ تُغلق — ولو لم تُبع فيها قطعة. والفرقُ
     * في الدرج يُنسب إلى صندوقه من `shifts.pos_device_id`؛ فحذفُ الصفّ يمحو
     * الجوابَ عن «أيُّ صندوقٍ نقص»، وهو أوّلُ ما يُسأل.
     *
     * والشرطُ شرطان لا واحد: فواتيرُه **أو** ورديّاته. ولو قِيس بالفواتير
     * وحدَها لَمُحي صندوقٌ فُتحت عليه ورديةٌ بلا بيع — وهو يقع كلَّ يومٍ
     * هادئ.
     */
    public function test_a_till_with_shifts_but_no_sales_is_not_erased(): void
    {
        [$dev] = $this->device($this->khuwair, 'صندوق الورديّة');
        PosTerminal::revoke($dev);

        $shift = Shift::create([
            'business_id' => $this->a->id, 'branch_id' => $this->khuwair->id,
            'pos_device_id' => $dev->id, 'user_id' => $this->ownerA->id,
            'employee_name' => 'مالك أ', 'opened_at' => now()->subHours(3),
            'closed_at' => now(), 'opening_balance' => 20, 'status' => 'مغلقة',
        ]);

        $this->assertSame(0, Order::where('pos_device_id', $dev->id)->count(), 'الوردية ليست بلا بيع');

        $this->actingAs($this->ownerA)->delete(route('admin.devices.destroy', $dev->id));

        $this->assertNotNull(PosDevice::find($dev->id), 'حُذف صندوقٌ فُتحت عليه ورديّة');
        $this->assertSame($dev->id, $shift->fresh()->pos_device_id, 'فُقدت نسبةُ وردية إلى صندوقها');
    }

    /** وجهازٌ نشطٌ لا يُحذف قبل إبطاله — خطوتان لا واحدة */
    public function test_an_active_till_is_not_erased_in_one_step(): void
    {
        [$dev] = $this->device($this->khuwair);

        $this->actingAs($this->ownerA)->delete(route('admin.devices.destroy', $dev->id));

        $this->assertNotNull(PosDevice::find($dev->id), 'حُذف صندوقٌ ما زال مفعَّلًا');
        $this->assertSame(PosDevice::ACTIVE, $dev->fresh()->status);
        $this->assertSame('danger', session('toast')['type'] ?? null);
    }

    /** ولا يُحذف صندوقُ متجرٍ آخر بمعرّفه */
    public function test_another_tenants_till_is_not_erased(): void
    {
        [$his] = $this->device($this->branchB);
        PosTerminal::revoke($his);

        $this->actingAs($this->ownerA)
            ->delete(route('admin.devices.destroy', $his->id))
            ->assertNotFound();

        $this->assertNotNull(PosDevice::find($his->id), 'حُذف صندوقُ متجرٍ آخر');
    }

    /** والكاشير لا يحذف سجلَّ صندوق — القسم تحت «الإعدادات» */
    public function test_a_cashier_cannot_erase_a_till(): void
    {
        $cashier = $this->cashier($this->a, 'k21@abaad.om');
        [$dev] = $this->device($this->khuwair);
        PosTerminal::revoke($dev);

        $this->actingAs($cashier)
            ->delete(route('admin.devices.destroy', $dev->id))
            ->assertForbidden();

        $this->assertNotNull(PosDevice::find($dev->id));
    }

    /**
     * ═══ والشاشةُ تقيس ما يقيسه الخادم ═══
     *
     * `erasable` هي ما يرسم به الجدولُ زرَّ الحذف، وشرطُ `destroy` هو ما
     * يردّ به الخادم. ومقياسان لسؤالٍ واحد يفترقان يوم يُبدَّل أحدهما —
     * فيُعرض زرٌّ يردّه الخادم، وهو أسوأ من غيابه.
     */
    public function test_the_screen_and_the_server_agree_on_what_is_erasable(): void
    {
        [$clean] = $this->device($this->khuwair, 'نظيف');
        PosTerminal::revoke($clean);

        [$sold] = $this->device($this->seeb, 'باع');
        PosTerminal::revoke($sold);
        Order::create([
            'business_id' => $this->a->id, 'branch_id' => $this->seeb->id,
            'pos_device_id' => $sold->id, 'number' => 'INV-8', 'status' => 'مكتمل',
            'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        [$live] = $this->device($this->khuwair, 'نشط');

        $this->actingAs($this->ownerA);
        $rows = collect(DeviceController::panelData()['devices'])
            ->keyBy('id');

        foreach ([$clean, $sold, $live] as $d) {
            $says = (bool) $rows[$d->id]['erasable'];

            $this->delete(route('admin.devices.destroy', $d->id));
            $gone = PosDevice::find($d->id) === null;

            $this->assertSame($says, $gone,
                'الشاشة تقول عن «'.$d->name.'» غيرَ ما يفعله الخادم');
        }
    }

    /* ------------------- متجرُ الفرع الواحد يُربط وحدَه ------------------- */

    /**
     * كاشيرٌ على متصفّحٍ جديد في متجرٍ بفرعٍ واحد يصل الصندوقَ مباشرة.
     *
     * كان يُحوَّل إلى تفعيلٍ لا يملكه فيقف على 403 وقد كتب بريدَه وكلمتَه
     * صحيحَين. ولا فرعَ يُخلط به هنا، فيُربط الجهازُ بالوحيد ويُقيَّد.
     */
    public function test_a_single_branch_shop_binds_a_new_browser_by_itself(): void
    {
        $c = Business::create(['name' => 'متجر ج', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $c->id, 'name' => 'كاشير', 'role' => 'cashier']);
        $only = Branch::create(['business_id' => $c->id, 'name' => 'الرئيسي']);
        $cashier = $this->cashier($c, 'solo@abaad.om');

        $first = $this->actingAs($cashier)->get(route('pos.index'));
        $first->assertRedirect(route('pos.index'));

        $device = PosDevice::where('business_id', $c->id)->first();
        $this->assertNotNull($device, 'لم يُربط الجهاز');
        $this->assertSame($only->id, (int) $device->branch_id);
        $this->assertSame($cashier->id, (int) $device->activated_by);

        // والكوكي في الردّ — فالطلبُ التالي يدخل الصندوق
        $cookie = collect($first->headers->getCookies())->first(fn ($k) => $k->getName() === PosTerminal::COOKIE);
        $this->assertNotNull($cookie, 'لا كوكي جهازٍ في الردّ');
        $this->assertNotNull(\App\Models\ActivityLog::where('business_id', $c->id)->where('description', 'like', '%فُعّل جهاز نقطة بيع تلقائيًّا%')->first());
    }

    /** ومتجرُ الفروع يبقى على بابه — لا يُخمَّن فرع */
    public function test_a_multi_branch_shop_still_asks(): void
    {
        $cashier = $this->cashier($this->a, 'multi@abaad.om');

        $this->actingAs($cashier)->get(route('pos.index'))->assertRedirect(route('pos.setup'));
        $this->assertSame(0, PosDevice::where('business_id', $this->a->id)->count());
    }

    /* ═══════════ جهازٌ يحلّ محلَّ صندوقٍ قائم — من متصفّحٍ آخر أو من التطبيق ═══════════ */

    /** تفعيلٌ من متصفّحٍ بلا كوكي — متصفّحٌ جديد، أو تطبيقُ أبعاد، أو بعد مسح البيانات: الحالُ واحد */
    private function activateFromANewBrowser(User $user, array $body)
    {
        $this->defaultCookies = [];

        return $this->actingAs($user)->post(route('pos.setup.activate'), $body);
    }

    private function sale(PosDevice $dev): Order
    {
        return Order::create([
            'business_id' => $dev->business_id, 'branch_id' => $dev->branch_id,
            'pos_device_id' => $dev->id, 'number' => 'INV-'.Str::random(5), 'status' => 'مكتمل',
            'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);
    }

    /**
     * من انتقل من Chrome إلى تطبيق أبعاد لا يحمل كوكي الصندوق القديم: كان
     * يولد صفٌّ جديدٌ بلا طابعة، وطابعتُه على صفٍّ لا يُحذف لأنّه باع.
     * فيختار المديرُ «يحلّ محلّ» الصندوق الملغى، فيُحيا صفُّه — بهويّته
     * ومبيعاته ووردياته وطابعته.
     */
    public function test_a_new_browser_revives_a_revoked_register_with_its_history_and_printer(): void
    {
        [$old] = $this->device($this->khuwair, 'كاشير الخوير');
        $printer = $this->printer($old);
        $sale = $this->sale($old);
        $shift = Shift::create([
            'business_id' => $this->a->id, 'branch_id' => $this->khuwair->id,
            'pos_device_id' => $old->id, 'user_id' => $this->ownerA->id,
            'employee_name' => 'مالك أ', 'opened_at' => now()->subHours(3),
            'closed_at' => now(), 'opening_balance' => 20, 'status' => 'مغلقة',
        ]);
        $bank = BankAccount::create([
            'business_id' => $this->a->id, 'label' => 'بنك مسقط', 'active' => true, 'is_primary' => true,
            'opening_balance' => 0, 'account_id' => Ledger::account($this->a->id, 'bank')->id,
        ]);
        $old->update(['bank_account_id' => $bank->id]);
        PosTerminal::revoke($old);
        $old->refresh();

        $this->activateFromANewBrowser($this->ownerA, [
            'mode' => 'replace', 'replaces' => $old->id, 'version' => TillActivation::version($old),
            'branch_id' => $this->khuwair->id, 'name' => 'كاشير الخوير (التطبيق)',
        ])->assertRedirect(route('pos.index'));

        $this->assertSame(1, PosDevice::where('business_id', $this->a->id)->count(), 'وُلد صندوقٌ ثانٍ بلا طابعة');
        $old->refresh();
        $this->assertSame(PosDevice::ACTIVE, $old->status);
        $this->assertSame('كاشير الخوير (التطبيق)', $old->name);
        $this->assertSame($old->id, $printer->fresh()->pos_device_id);
        $this->assertSame($old->id, $sale->fresh()->pos_device_id, 'نسبةُ البيع إلى صندوقه تبدّلت');
        $this->assertSame($old->id, $shift->fresh()->pos_device_id, 'نسبةُ الورديّة إلى صندوقها تبدّلت');
        $this->assertSame($bank->id, $old->bank_account_id, 'الصندوقُ المستبدَل فقد بنكه');
        $this->assertSame($this->khuwair->id, $old->branch_id);
    }

    /** وصندوقٌ يعمل الآن على جهازٍ آخر لا يُوقَف بلا تأكيدٍ صريح */
    public function test_an_active_register_is_not_replaced_without_an_explicit_yes(): void
    {
        [$old, $oldRaw] = $this->device($this->khuwair, 'كاشير الخوير');
        $this->printer($old);
        $body = ['mode' => 'replace', 'replaces' => $old->id, 'version' => TillActivation::version($old),
            'branch_id' => $this->khuwair->id, 'name' => 'كاشير الخوير'];

        $this->activateFromANewBrowser($this->ownerA, $body)->assertSessionHasErrors('confirm_active');
        $this->assertTrue($this->tokenStillWorks($old->fresh(), $oldRaw), 'أُوقف الصندوقُ القائم بلا تأكيد');

        $this->activateFromANewBrowser($this->ownerA, $body + ['confirm_active' => true])->assertRedirect(route('pos.index'));

        $this->assertSame(1, PosDevice::where('business_id', $this->a->id)->count());
        $this->assertFalse($this->tokenStillWorks($old->fresh(), $oldRaw), 'الجهازُ السابق ما زال يبيع باسم الصندوق');
    }

    private function tokenStillWorks(PosDevice $dev, string $raw): bool
    {
        return $dev->isActive() && hash_equals($dev->token_hash, hash('sha256', $raw));
    }

    /** والاستبدالُ لا ينقل: صندوقُ الخوير لا يصير صندوقَ السيب بطابعته وبنكه */
    public function test_replacing_does_not_move_a_register_to_another_branch(): void
    {
        [$old] = $this->device($this->khuwair, 'كاشير الخوير');
        $this->printer($old);
        PosTerminal::revoke($old);
        $old->refresh();

        $this->activateFromANewBrowser($this->ownerA, [
            'mode' => 'replace', 'replaces' => $old->id, 'version' => TillActivation::version($old),
            'branch_id' => $this->seeb->id, 'name' => 'كاشير السيب',
        ])->assertSessionHasErrors('branch_id');

        $old->refresh();
        $this->assertSame($this->khuwair->id, $old->branch_id);
        $this->assertSame(PosDevice::REVOKED, $old->status);
        $this->assertSame('كاشير الخوير', $old->name);
    }

    /**
     * وما تغيّر بعد أن فُتحت الشاشة يُردّ — لا يُكتب فوقه.
     *
     * جهازان يفعّلان الصندوقَ نفسَه في اللحظة نفسِها: الأوّلُ يُدوّر الرمز
     * فتتبدّل البصمة، والثاني يحمل بصمةً قديمة فيُردّ. وكذا صندوقٌ أُلغي أو
     * فُعّل من مكانٍ آخر بين الفتح والضغط.
     */
    public function test_a_second_activation_with_the_same_view_is_refused(): void
    {
        [$old] = $this->device($this->khuwair, 'كاشير الخوير');
        PosTerminal::revoke($old);
        $seen = TillActivation::version($old->fresh());
        $body = ['mode' => 'replace', 'replaces' => $old->id, 'version' => $seen,
            'branch_id' => $this->khuwair->id, 'name' => 'كاشير الخوير', 'confirm_active' => true];

        $this->activateFromANewBrowser($this->ownerA, $body)->assertRedirect(route('pos.index'));
        $winner = $old->fresh()->token_hash;

        $this->activateFromANewBrowser($this->ownerA, $body)->assertSessionHasErrors('replaces');
        $this->assertSame($winner, $old->fresh()->token_hash, 'كتب الثاني فوق الأوّل');
    }

    /** وبلا اختيارٍ صريح لا يُتبنّى صفٌّ بمعرّفٍ من الواجهة */
    public function test_choosing_new_makes_a_new_register_and_leaves_the_old_one(): void
    {
        [$old] = $this->device($this->khuwair, 'كاشير الخوير');
        $printer = $this->printer($old);

        $this->activateFromANewBrowser($this->ownerA, [
            'mode' => 'new', 'replaces' => $old->id, 'branch_id' => $this->khuwair->id, 'name' => 'كاشير 02',
        ])->assertRedirect(route('pos.index'));

        $this->assertSame(2, PosDevice::where('business_id', $this->a->id)->count());
        $this->assertSame(PosDevice::ACTIVE, $old->fresh()->status);
        $this->assertSame($old->id, $printer->fresh()->pos_device_id);
    }

    /** وصندوقُ متجرٍ آخر لا يُتبنّى بمعرّفه */
    public function test_a_register_of_another_shop_cannot_be_taken_over(): void
    {
        [$theirs] = $this->device($this->branchB, 'جهازهم');
        $this->printer($theirs);

        $this->activateFromANewBrowser($this->ownerA, [
            'mode' => 'replace', 'replaces' => $theirs->id, 'version' => TillActivation::version($theirs),
            'branch_id' => $this->khuwair->id, 'name' => 'كاشير', 'confirm_active' => true,
        ])->assertSessionHasErrors('replaces');

        $this->assertSame($this->b->id, $theirs->fresh()->business_id);
        $this->assertSame('جهازهم', $theirs->fresh()->name);
        $this->assertSame(0, PosDevice::where('business_id', $this->a->id)->count());
    }

    /** والكاشير لا يستبدل صندوقًا — في متجر الفروع لا يفعّل أصلًا */
    public function test_a_cashier_cannot_take_over_a_register(): void
    {
        [$old] = $this->device($this->khuwair, 'كاشير الخوير');
        $cashier = $this->cashier($this->a, 'k31@abaad.om');

        $this->activateFromANewBrowser($cashier, [
            'mode' => 'replace', 'replaces' => $old->id, 'version' => TillActivation::version($old),
            'branch_id' => $this->khuwair->id, 'name' => 'لي', 'confirm_active' => true,
        ])->assertForbidden();

        $this->assertSame('كاشير الخوير', $old->fresh()->name);
    }

    /**
     * وصفحةٌ قديمة لا ترسل اختيارًا: يُحيا صفُّ هذا المتصفّح وحده — ملغًى
     * في فرعه. وصفُّه وقد صار يعمل على جهازٍ آخر لا يُسلب بصمت.
     */
    public function test_an_old_cookie_never_steals_a_register_now_running_elsewhere(): void
    {
        [$dev, $raw] = $this->device($this->khuwair, 'كاشير الخوير');
        // استُبدل من جهازٍ آخر: رمزُه دُوِّر، وهذا المتصفّحُ يحمل القديم
        $dev->update(['token_hash' => hash('sha256', 'رمز الجهاز الجديد')]);

        $this->onDevice($dev, $raw)->actingAs($this->ownerA)
            ->post(route('pos.setup.activate'), ['branch_id' => $this->khuwair->id, 'name' => 'استرداد'])
            ->assertSessionHasErrors('replaces');

        $this->assertSame(hash('sha256', 'رمز الجهاز الجديد'), $dev->fresh()->token_hash);
        $this->assertSame('كاشير الخوير', $dev->fresh()->name);
    }

    /** ولا يُنقل صفُّ هذا المتصفّح إلى فرعٍ آخر بإحيائه */
    public function test_reviving_this_browsers_register_does_not_move_it(): void
    {
        [$dev, $raw] = $this->device($this->khuwair, 'كاشير الخوير');
        PosTerminal::revoke($dev);

        $this->onDevice($dev, $raw)->actingAs($this->ownerA)
            ->post(route('pos.setup.activate'), ['branch_id' => $this->seeb->id, 'name' => 'كاشير الخوير'])
            ->assertSessionHasErrors('branch_id');

        $this->assertSame($this->khuwair->id, $dev->fresh()->branch_id);
        $this->assertSame(PosDevice::REVOKED, $dev->fresh()->status);
    }

    /** وشاشةُ الإعداد تعرض صناديقَ المتجر وحده، بعدد طابعاتها وبصمة حالها */
    public function test_the_setup_screen_lists_this_shops_registers_only(): void
    {
        [$mine] = $this->device($this->khuwair, 'كاشير الخوير');
        $this->printer($mine);
        $this->device($this->branchB, 'جهازهم');

        $this->defaultCookies = [];
        $page = $this->actingAs($this->ownerA)->get(route('pos.setup'))->assertOk()->viewData('page')['props'];

        $this->assertTrue($page['canReplace']);
        $this->assertNull($page['previous']);
        $this->assertSame([[
            'id' => $mine->id, 'name' => 'كاشير الخوير', 'branchId' => $this->khuwair->id,
            'branch' => 'الخوير', 'active' => true, 'printers' => 1, 'lastSeen' => null,
            'version' => TillActivation::version($mine),
        ]], $page['devices']);
    }

    /* ═══════════ متجرُ الفرع الواحد — لا صندوقَ جديدًا بلا سؤال ═══════════ */

    private function oneBranchShop(): array
    {
        $c = Business::create(['name' => 'متجر د', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $c->id, 'name' => 'كاشير', 'role' => 'cashier']);
        $only = Branch::create(['business_id' => $c->id, 'name' => 'الوحيد']);

        return [$c, $only, $this->cashier($c, 'one-'.Str::random(4).'@abaad.om')];
    }

    /**
     * متصفّحٌ جديد في متجرٍ له صندوقٌ بطابعة — تطبيقُ أبعاد بعد Chrome: كان
     * يولد له صندوقٌ بلا طابعة وحدَه. الآن يُسأل.
     */
    public function test_a_one_branch_shop_with_a_register_asks_a_new_browser(): void
    {
        [$c, $only, $cashier] = $this->oneBranchShop();
        [$old] = $this->device($only, 'كاشير المحلّ');
        $this->printer($old);

        $this->defaultCookies = [];
        $this->actingAs($cashier)->get(route('pos.index'))->assertRedirect(route('pos.setup'));

        $this->assertSame(1, PosDevice::where('business_id', $c->id)->count(), 'وُلد صندوقٌ بلا طابعة بلا سؤال');

        // والكاشيرُ يرى الاختيار — ولا يستبدل، ولا يولّد بجوار الطابعة صندوقًا بلا طابعة
        $page = $this->actingAs($cashier)->get(route('pos.setup'))->assertOk()->viewData('page');
        $this->assertSame('Pos/DeviceSetup', $page['component']);
        $this->assertFalse($page['props']['canReplace']);
        $this->assertSame([$only->id], $page['props']['newNeedsManager']);

        $this->actingAs($cashier)->post(route('pos.setup.activate'), [
            'mode' => 'replace', 'replaces' => $old->id, 'version' => TillActivation::version($old),
            'branch_id' => $only->id, 'name' => 'لي', 'confirm_active' => true,
        ])->assertSessionHasErrors('replaces');

        /*
         * «صندوق جديد» كان الخيارَ الوحيدَ المفتوحَ له — فيعود العطبُ بضغطة:
         * صندوقٌ بلا طابعة، والطابعةُ على القديم. الآن للمدير.
         */
        $this->actingAs($cashier)->post(route('pos.setup.activate'), [
            'mode' => 'new', 'branch_id' => $only->id, 'name' => 'كاشير 02',
        ])->assertSessionHasErrors('mode');
        // وصفحةٌ قديمةٌ بلا اختيار لا تفتح البابَ نفسَه
        $this->actingAs($cashier)->post(route('pos.setup.activate'), [
            'branch_id' => $only->id, 'name' => 'كاشير 02',
        ])->assertSessionHasErrors('mode');
        $this->assertSame(1, PosDevice::where('business_id', $c->id)->count(), 'وُلد صندوقٌ بلا طابعة بجوار طابعة');
        $this->assertSame(PosDevice::ACTIVE, $old->fresh()->status);

        // والمديرُ يفتح الثاني إن أراده صندوقًا إضافيًّا
        $manager = User::create([
            'business_id' => $c->id, 'name' => 'مدير د', 'email' => 'd-'.Str::random(4).'@abaad.om',
            'password' => 'password', 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->assertSame([], $this->actingAs($manager)->get(route('pos.setup'))->viewData('page')['props']['newNeedsManager']);
        $this->actingAs($manager)->post(route('pos.setup.activate'), [
            'mode' => 'new', 'branch_id' => $only->id, 'name' => 'كاشير 02',
        ])->assertRedirect(route('pos.index'));
        $this->assertSame(2, PosDevice::where('business_id', $c->id)->count());
    }

    /** ومتجرٌ لا طابعةَ فيه: الكاشيرُ يفتح صندوقًا ثانيًا بنفسه كما كان — لا طابعةَ تُفقد */
    public function test_a_cashier_still_opens_a_second_till_where_no_printer_can_be_lost(): void
    {
        [$c, $only, $cashier] = $this->oneBranchShop();
        $this->device($only, 'كاشير المحلّ');

        $this->defaultCookies = [];
        $this->actingAs($cashier)->post(route('pos.setup.activate'), [
            'mode' => 'new', 'branch_id' => $only->id, 'name' => 'كاشير 02',
        ])->assertRedirect(route('pos.index'));

        $this->assertSame(2, PosDevice::where('business_id', $c->id)->count());
    }

    /** وصندوقُ هذا المتصفّح نفسِه يُحيا وحدَه — باسمه وطابعته */
    public function test_a_one_branch_shop_revives_this_browsers_own_register(): void
    {
        [$c, $only, $cashier] = $this->oneBranchShop();
        [$dev, $raw] = $this->device($only, 'كاشير المحلّ');
        $printer = $this->printer($dev);
        PosTerminal::revoke($dev);

        $this->onDevice($dev, $raw)->actingAs($cashier)->get(route('pos.index'))->assertRedirect(route('pos.index'));

        $this->assertSame(1, PosDevice::where('business_id', $c->id)->count());
        $this->assertSame(PosDevice::ACTIVE, $dev->fresh()->status);
        $this->assertSame('كاشير المحلّ', $dev->fresh()->name, 'أُعيدت تسميتُه باسم الموظف');
        $this->assertSame($dev->id, $printer->fresh()->pos_device_id);
    }

    /* ═══════════ النقلُ بابٌ مستقلّ — يُرى أثرُه ويُؤكَّد ═══════════ */

    /**
     * نقلُ صندوقٍ إلى فرعٍ آخر يأخذ طابعتَه (على شبكة الفرع القديم) وبنكَه.
     * فلا يقع بنقرةٍ على قائمة: يُردّ بلا تأكيد، ويقول أثرَه.
     */
    public function test_a_register_moves_only_with_an_explicit_confirmation(): void
    {
        [$dev, $raw] = $this->device($this->khuwair, 'كاشير الخوير');
        $printer = $this->printer($dev);
        $body = ['name' => 'كاشير الخوير', 'branch_id' => $this->seeb->id];

        $this->actingAs($this->ownerA)->put(route('admin.devices.update', $dev->id), $body)
            ->assertSessionHasErrors('confirm_move');
        $dev->refresh();
        $this->assertSame($this->khuwair->id, $dev->branch_id);
        $this->assertTrue($dev->isActive() && hash_equals($dev->token_hash, hash('sha256', $raw)), 'نُقل أو أُبطل بلا تأكيد');

        $this->actingAs($this->ownerA)->put(route('admin.devices.update', $dev->id), $body + ['confirm_move' => true])
            ->assertSessionHasNoErrors();
        $dev->refresh();
        $this->assertSame($this->seeb->id, $dev->branch_id);
        $this->assertSame(PosDevice::REVOKED, $dev->status);
        $this->assertSame($dev->id, $printer->fresh()->pos_device_id, 'الطابعةُ تبقى على صندوقها — تُراجَع بعد النقل');
    }

    /** وتعديلُ الاسم أو البنك في الفرع نفسِه لا يُسأل عنه */
    public function test_editing_without_moving_needs_no_confirmation(): void
    {
        [$dev] = $this->device($this->khuwair, 'كاشير الخوير');

        $this->actingAs($this->ownerA)->put(route('admin.devices.update', $dev->id), [
            'name' => 'اسمٌ جديد', 'branch_id' => $this->khuwair->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('اسمٌ جديد', $dev->fresh()->name);
        $this->assertTrue($dev->fresh()->isActive());
    }
}
