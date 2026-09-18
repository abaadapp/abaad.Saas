<?php

namespace Tests\Feature;

use App\Http\Controllers\Pos\DeviceController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\Product;
use App\Models\Shift;
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
            'name' => 'كاشير الخوير', 'branch_id' => $this->seeb->id,
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
        $this->enterPos($dev, $raw, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->khuwair->id, session('current_branch'));
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
}
