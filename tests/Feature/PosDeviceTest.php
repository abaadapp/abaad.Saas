<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\PosTerminal;
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

        $this->enterPos($d1, $r1, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->khuwair->id, session('current_branch'));

        $this->enterPos($d2, $r2, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->seeb->id, session('current_branch'));
    }

    /** وبلا تحديد يعمل في كل فروع متجره — وإلا أُقفل كل كاشير قائم يوم النشر */
    public function test_an_employee_without_branches_works_everywhere_in_their_tenant(): void
    {
        $cashier = $this->cashier($this->a, 'k3@abaad.om');
        [$dev, $raw] = $this->device($this->seeb);

        $this->enterPos($dev, $raw, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->seeb->id, session('current_branch'));
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

        $this->actingAs($cashier)->get(route('pos.setup'))->assertForbidden();
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
            'name' => $dev->name, 'branch_id' => $this->seeb->id,
        ]);

        $dev->refresh();
        $this->assertSame($this->seeb->id, $dev->branch_id);
        $this->assertSame(PosDevice::REVOKED, $dev->status);
        $this->assertNotSame($oldHash, $dev->token_hash);

        // والكوكي القديمة لا تفتح صندوقًا بعدها
        $cashier = $this->cashier($this->a, 'k7@abaad.om');
        $this->enterPos($dev, $raw, $cashier)->assertRedirect(route('pos.setup'));
    }

    /* -------------------------- سياق الجلسة -------------------------- */

    /** الجلسة تحمل الموظف والفرع والجهاز والمتجر الصحيح */
    public function test_the_session_carries_the_right_context(): void
    {
        $cashier = $this->cashier($this->a, 'k8@abaad.om', [$this->seeb->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $this->enterPos($dev, $raw, $cashier);

        $this->assertSame($cashier->id, auth()->id());
        $this->assertSame($this->a->id, auth()->user()->business_id);
        $this->assertSame($this->seeb->id, session('current_branch'));
        $this->assertNotNull($dev->fresh()->last_seen_at);
    }

    /** و«كل الفروع» لا تصل إلى نقطة البيع: الجهاز يفرض فرعه في كل طلب */
    public function test_all_branches_never_reaches_the_pos(): void
    {
        [$dev, $raw] = $this->device($this->seeb);

        // المالك يفتح نقطة البيع وجلسته على «كل الفروع» (null)
        // «كل الفروع» = null في الجلسة. الوجهة لا تهمّ هنا (قد تُطلب شاشة
        // اختيار الكاشير) — المهمّ أن الفرع صار فرع الجهاز قبل أن يُقرأ.
        $this->onDevice($dev, $raw)->actingAs($this->ownerA)
            ->withSession(['current_branch' => null])
            ->get(route('pos.index'));

        $this->assertSame($this->seeb->id, session('current_branch'));
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

        $product = \App\Models\Product::create([
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
        $this->enterPos($dev, $raw, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->khuwair->id, session('current_branch'));
    }
}
