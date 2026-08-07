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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الجهاز يعرف المتجر والفرع، والموظف يعرف رمزه وحده.
 *
 * كان الجهاز يُعرَف بكوكي تحمل رقم المتجر لا غير: بلا فرع، بلا سجلّ، بلا
 * إبطال. فجهاز الخوير وجهاز السيب متطابقان في نظر النظام، والفرع يأتي من
 * جلسة المتصفّح — أي من مبدّل الفروع في لوحة الإدارة. وتبديلٌ في تبويبٍ آخر
 * كان ينقل مبيعات فرعٍ إلى فرعٍ آخر بلا إنذار.
 *
 * ولم يكن شيء يمنع كاشير الخوير من الدخول على جهاز السيب: `users.branch` نصٌّ
 * حرّ لا يُفحص عند الدخول أصلًا.
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
        RateLimiter::clear('pin-login:127.0.0.1');
        RateLimiter::clear('pin-login-hour:127.0.0.1');

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

    private function cashier(Business $biz, string $pin, string $email, array $branches = []): User
    {
        $u = User::create([
            'business_id' => $biz->id, 'name' => 'كاشير '.$email, 'email' => $email,
            'password' => 'password', 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط', 'pin' => $pin,
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

    private function tryPin(PosDevice $device, string $raw, string $pin)
    {
        return $this->onDevice($device, $raw)->post(route('pin.attempt'), ['pin' => $pin]);
    }

    /**
     * رسالة رفض الرمز.
     *
     * الحقيبة تُخزَّن في الجلسة مصفوفةً مسلسلة لا ViewErrorBag، فقراءتها
     * بـ->first() تُرجع فراغًا صامتًا — ويمرّ الاختبار على لا شيء.
     */
    private function pinError($response): string
    {
        $errors = $response->getSession()->get('errors');

        if (is_array($errors)) {
            return (string) ($errors['default']['messages']['pin'][0] ?? '');
        }

        return $errors ? (string) $errors->first('pin') : '';
    }

    /* --------------------------- تفرّد الرمز --------------------------- */

    /** متجران مختلفان لهما الرمز نفسه — وهو المقصود */
    public function test_two_tenants_may_share_the_same_pin(): void
    {
        $this->cashier($this->a, '1234', 'a1@abaad.om');
        $this->cashier($this->b, '1234', 'b1@abaad.om');

        [$devA, $rawA] = $this->device($this->khuwair);
        [$devB, $rawB] = $this->device($this->branchB);

        $this->tryPin($devA, $rawA, '1234')->assertSessionHasNoErrors();
        $this->assertSame($this->a->id, auth()->user()->business_id);

        $this->post(route('logout'));

        $this->tryPin($devB, $rawB, '1234')->assertSessionHasNoErrors();
        $this->assertSame($this->b->id, auth()->user()->business_id);
    }

    /** وداخل المتجر الواحد لا يتكرّر */
    public function test_the_same_tenant_refuses_a_duplicate_pin(): void
    {
        $this->cashier($this->a, '1234', 'a1@abaad.om');

        $this->actingAs($this->ownerA)->post(route('admin.employees.store'), [
            'name' => 'ثانٍ', 'job_title' => 'كاشير', 'pin' => '1234',
        ])->assertSessionHasErrors('pin');

        $this->assertSame(1, User::where('business_id', $this->a->id)->whereNotNull('pin')->count());
    }

    /* -------------------------- عزل المستأجرين -------------------------- */

    /** موظف من متجر أ لا يدخل على جهاز متجر ب */
    public function test_an_employee_cannot_sign_in_on_another_tenants_device(): void
    {
        $this->cashier($this->a, '4321', 'a1@abaad.om');
        [$devB, $rawB] = $this->device($this->branchB);

        $this->tryPin($devB, $rawB, '4321')->assertSessionHasErrors('pin');
        $this->assertGuest();
    }

    /* ---------------------------- إذن الفرع ---------------------------- */

    /** ممنوعٌ من فرعٍ لا يدخل على جهازه */
    public function test_an_employee_not_assigned_to_the_branch_is_refused(): void
    {
        $this->cashier($this->a, '1111', 'k@abaad.om', [$this->khuwair->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $this->tryPin($dev, $raw, '1111')->assertSessionHasErrors('pin');
        $this->assertGuest();
    }

    /** ومن له فرعان يدخل بالرمز نفسه على جهازي الفرعين */
    public function test_an_employee_of_two_branches_works_on_both_devices(): void
    {
        $this->cashier($this->a, '2222', 'k2@abaad.om', [$this->khuwair->id, $this->seeb->id]);
        [$d1, $r1] = $this->device($this->khuwair);
        [$d2, $r2] = $this->device($this->seeb, 'كاشير 02');

        $this->tryPin($d1, $r1, '2222')->assertSessionHasNoErrors();
        $this->assertAuthenticated();
        $this->post(route('logout'));

        $this->tryPin($d2, $r2, '2222')->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /** وبلا تحديد يعمل في كل فروع متجره — وإلا أُقفل كل كاشير قائم يوم النشر */
    public function test_an_employee_without_branches_works_everywhere_in_their_tenant(): void
    {
        $this->cashier($this->a, '3333', 'k3@abaad.om');
        [$dev, $raw] = $this->device($this->seeb);

        $this->tryPin($dev, $raw, '3333')->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /** والرسالة واحدة للرمز الخاطئ وللممنوع من الفرع: التمييز يدلّ المخمّن */
    public function test_the_refusal_message_does_not_reveal_the_reason(): void
    {
        $this->cashier($this->a, '1111', 'k@abaad.om', [$this->khuwair->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $wrong = $this->pinError($this->tryPin($dev, $raw, '9999'));
        $notAllowed = $this->pinError($this->tryPin($dev, $raw, '1111'));

        $this->assertNotEmpty($wrong);
        $this->assertSame($wrong, $notAllowed, 'الرسالتان مختلفتان — فيُعرف أن الرمز أصاب');
    }

    /* --------------------------- إبطال الجهاز --------------------------- */

    /** جهاز مُلغى لا يقبل رمزًا */
    public function test_a_revoked_device_refuses_pin_login(): void
    {
        $this->cashier($this->a, '5555', 'k5@abaad.om');
        [$dev, $raw] = $this->device($this->khuwair);
        PosTerminal::revoke($dev);

        $this->tryPin($dev, $raw, '5555')->assertSessionHasErrors('pin');
        $this->assertGuest();
    }

    /* ------------------------- صلاحية التفعيل ------------------------- */

    /** الكاشير لا يفعّل جهازًا ولا ينقله بين الفروع */
    public function test_a_cashier_cannot_activate_or_move_a_device(): void
    {
        $cashier = $this->cashier($this->a, '6666', 'k6@abaad.om');
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

        // والرمز القديم لا يفتح شيئًا بعدها
        $this->cashier($this->a, '7777', 'k7@abaad.om');
        $this->tryPin($dev, $raw, '7777')->assertSessionHasErrors('pin');
    }

    /* -------------------------- سياق الجلسة -------------------------- */

    /** الجلسة تحمل الموظف والفرع والجهاز والمتجر الصحيح */
    public function test_the_session_carries_the_right_context(): void
    {
        $cashier = $this->cashier($this->a, '8888', 'k8@abaad.om', [$this->seeb->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $this->tryPin($dev, $raw, '8888')->assertSessionHasNoErrors();

        $this->assertSame($cashier->id, auth()->id());
        $this->assertSame($this->a->id, auth()->user()->business_id);
        $this->assertSame($this->seeb->id, session('current_branch'));
        $this->assertNotNull($dev->fresh()->last_seen_at);
    }

    /** و«كل الفروع» لا تصل إلى نقطة البيع: الجهاز يفرض فرعه في كل طلب */
    public function test_all_branches_never_reaches_the_pos(): void
    {
        $this->cashier($this->a, '9090', 'k9@abaad.om');
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

    /* ------------------------- حدّ المحاولات ------------------------- */

    /** خمس محاولات خاطئة تكفي — والحدّ على الجهاز لا على المحل كلّه */
    public function test_repeated_bad_pins_are_rate_limited(): void
    {
        $this->cashier($this->a, '1212', 'k10@abaad.om');
        [$dev, $raw] = $this->device($this->khuwair);
        RateLimiter::clear('pin-login:dev'.$dev->id);
        RateLimiter::clear('pin-login-hour:dev'.$dev->id);

        for ($i = 0; $i < 5; $i++) {
            $this->tryPin($dev, $raw, '0000')->assertSessionHasErrors('pin');
        }

        // السادسة تُرفض بالحدّ لا بالرمز — والرمز الصحيح نفسه يُرفض معها
        $blocked = $this->tryPin($dev, $raw, '1212');
        $blocked->assertSessionHasErrors('pin');
        $this->assertGuest();
        // الرسالة رسالة حدٍّ لا رسالة رمز — والمقارنة بالترجمة لا بالنصّ
        $this->assertNotSame(__('رمز غير صحيح أو غير مسموح في هذا الفرع.'), $this->pinError($blocked));

        // وجهازٌ آخر في المحل نفسه لا يتأثّر
        [$other, $otherRaw] = $this->device($this->khuwair, 'كاشير 02');
        RateLimiter::clear('pin-login:dev'.$other->id);
        RateLimiter::clear('pin-login-hour:dev'.$other->id);
        $this->tryPin($other, $otherRaw, '1212')->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /* --------------------------- سجلّ البيع --------------------------- */

    /** الفاتورة تحمل المتجر والفرع والجهاز والموظف */
    public function test_a_sale_records_its_device_context(): void
    {
        $cashier = $this->cashier($this->a, '3131', 'k11@abaad.om', [$this->seeb->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $product = \App\Models\Product::create([
            'business_id' => $this->a->id, 'name' => 'صنف', 'price' => 1.5, 'quantity' => 10,
        ]);

        $this->tryPin($dev, $raw, '3131');

        $this->onDevice($dev, $raw)->post(route('pos.checkout'), [
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
        $this->cashier($this->a, '4141', 'k12@abaad.om');
        [$dev, $raw] = $this->device($this->khuwair);

        $this->tryPin($dev, $raw, '4141')->assertSessionHasNoErrors();
        $this->assertAuthenticated();

        $this->onDevice($dev, $raw)->post(route('pos.lock'))->assertRedirect(route('pin.form'));
        $this->assertGuest();

        // والجهاز ما زال يعرف نفسه: الرمز التالي يدخل بلا بريدٍ ولا كلمة مرور
        $this->tryPin($dev, $raw, '4141')->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }
}
