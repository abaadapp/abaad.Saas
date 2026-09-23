<?php

namespace Tests\Feature;

use App\Http\Controllers\SuperAdmin\PageController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppMessagePack;
use App\Support\Billing;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppPacks;
use App\Support\WhatsAppQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * كلُّ متجرٍ يربط رقمه — ومن نفدت حصّتُه يشتري ولا ينتظر الشهر.
 *
 * ═══ ما يُحرَس هنا، وثلاثةٌ منه مالٌ لا شاشة ═══
 *
 *   ١) الإذنُ يُولد ممنوحًا. وكان يُولد ممنوعًا، فبقيت ميزةُ التسجيل المدمج
 *      مبنيّةً كاملةً لا يراها أحد: أربعةُ متاجرَ على الإنتاج وفي أربعتها
 *      الزرُّ مخفيّ.
 *
 *   ٢) الرصيدُ المشترى يُستهلَك **بعد** عطيّة الشهر لا قبلها. ولو عُكس لَخسر
 *      من اشترى رصيدَه في شهرٍ ما استهلك فيه عطيّتَه.
 *
 *   ٣) رسالةٌ مشتراةٌ يرفضها المزوّد تُردّ إلى الرصيد لا إلى عدّاد الشهر.
 *      والخطأُ هنا لا يُرى في شاشة: يربح التاجر رسالةً مجّانيّةً ويخسر ريالَه.
 *
 *   ٤) لا رصيدَ قبل السداد. زرُّ الاعتماد يُصدر فاتورةً ولا يُعطي شيئًا.
 *
 *   ٥) وسدادُ فاتورةِ رسائلَ لا يُقيّد اشتراكًا سنويًّا مدفوعًا. وهذا أخطرُ
 *      ما في الملفّ: خمسةُ ريالاتٍ تُسقط دَينَ مئةٍ، ولا يشكو أحد.
 */
class AShopBuysMoreOfAbaadsMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $cashier;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function platform(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
    }

    /** الرصيدُ من القاعدة لا من النموذج — انظر `WhatsAppQuota::credits` */
    private function credits(): int
    {
        return (int) DB::table('businesses')->where('id', $this->shop->id)->value('whatsapp_message_credits');
    }

    /* ═════════════════ ١) الإذنُ يُولد ممنوحًا ═════════════════ */

    public function test_a_shop_may_connect_its_own_number_the_day_it_is_created(): void
    {
        $fresh = Business::create(['name' => 'متجرٌ جديد', 'type' => 'محل ورود', 'status' => 'نشط']);

        $this->assertTrue((bool) $fresh->fresh()->whatsapp_own_allowed);
        $this->assertTrue(WhatsAppFeature::canUseOwnNumber($fresh->fresh()));
    }

    /**
     * ولا بابَ في النظام يُنشئ متجرًا ممنوعًا.
     *
     * الفحصُ على القاعدة كلِّها لا على صفٍّ بعينه: `Signup` و`DemoStore`
     * ولوحةُ المنصّة تُنشئ متاجرَ بحقولٍ مختلفة، وبابٌ واحدٌ يكتب `false`
     * صراحةً يكفي ليُولد تاجرٌ لا يرى الزرّ.
     *
     * ═══ وما لا يحرسه هذا ═══
     *
     * الجملةُ التي منحت **المتاجرَ القائمةَ قبل الهجرة** لا تمرّ من هنا:
     * قاعدةُ الاختبار تُبنى بالهجرات ثمّ تُملأ، فلا صفَّ فيها يسبقها.
     * وصِدقُها يُقاس على الإنتاج بعدّ الصفوف بعد النشر — لا هنا.
     */
    public function test_no_door_in_the_system_creates_a_shop_without_the_entitlement(): void
    {
        $this->assertSame(
            0,
            DB::table('businesses')->where('whatsapp_own_allowed', false)->count(),
        );
    }

    /* ═════════════════ ٢) جيبان بترتيبٍ ثابت ═════════════════ */

    public function test_the_month_is_spent_before_the_money(): void
    {
        $this->shop->update(['whatsapp_monthly_limit' => 2]);
        WhatsAppQuota::grant($this->shop, 3);

        $sources = [
            WhatsAppQuota::reserve($this->shop),
            WhatsAppQuota::reserve($this->shop),
            WhatsAppQuota::reserve($this->shop),
        ];

        $this->assertSame(
            [WhatsAppQuota::SOURCE_MONTHLY, WhatsAppQuota::SOURCE_MONTHLY, WhatsAppQuota::SOURCE_CREDIT],
            $sources,
        );
        $this->assertSame(2, WhatsAppQuota::used($this->shop));
        $this->assertSame(2, $this->credits());
    }

    /** وحدٌّ صفرٌ لا يمنع من اشترى — المنعُ هو ألّا يبقى شيءٌ في الجيبين */
    public function test_a_zero_monthly_limit_still_spends_purchased_credit(): void
    {
        $this->shop->update(['whatsapp_monthly_limit' => 0]);
        WhatsAppQuota::grant($this->shop, 1);

        $this->assertSame(WhatsAppQuota::SOURCE_CREDIT, WhatsAppQuota::reserve($this->shop));
        $this->assertNull(WhatsAppQuota::reserve($this->shop));
        $this->assertSame(0, $this->credits());
    }

    /** والرصيدُ لا ينزل تحت الصفر مهما تزاحم عليه */
    public function test_credit_never_goes_below_zero(): void
    {
        $this->shop->update(['whatsapp_monthly_limit' => 0]);
        WhatsAppQuota::grant($this->shop, 1);

        $results = [
            WhatsAppQuota::reserve($this->shop),
            WhatsAppQuota::reserve($this->shop),
            WhatsAppQuota::reserve($this->shop),
        ];

        $this->assertSame([WhatsAppQuota::SOURCE_CREDIT, null, null], $results);
        $this->assertSame(0, $this->credits());
    }

    /* ═════════════════ ٣) ما يُردّ يعود إلى جيبه ═════════════════ */

    public function test_a_rejected_purchased_message_returns_to_the_credit_not_to_the_month(): void
    {
        $this->shop->update(['whatsapp_monthly_limit' => 1]);
        WhatsAppQuota::grant($this->shop, 1);

        WhatsAppQuota::reserve($this->shop);                 // عطيّةُ الشهر
        $source = WhatsAppQuota::reserve($this->shop);       // الرصيد

        WhatsAppQuota::release($this->shop, $source);

        $this->assertSame(1, $this->credits(), 'الرصيد يعود كما كان');
        $this->assertSame(1, WhatsAppQuota::used($this->shop), 'وعدّاد الشهر لا يُمسّ');
    }

    /** ونداءٌ بلا مصدرٍ يعني عطيّةَ الشهر — كما كانت الدالّة قبل الرصيد */
    public function test_releasing_without_a_source_returns_to_the_month(): void
    {
        $this->shop->update(['whatsapp_monthly_limit' => 5]);
        WhatsAppQuota::reserve($this->shop);

        WhatsAppQuota::release($this->shop);

        $this->assertSame(0, WhatsAppQuota::used($this->shop));
        $this->assertSame(0, $this->credits());
    }

    /* ═════════════════ الصورةُ التي تقرؤها الشاشة ═════════════════ */

    public function test_a_shop_with_credit_left_is_not_shown_as_exhausted(): void
    {
        $this->shop->update(['whatsapp_monthly_limit' => 1]);
        WhatsAppQuota::grant($this->shop, 10);
        WhatsAppQuota::reserve($this->shop);

        $snapshot = WhatsAppQuota::snapshot($this->shop);

        $this->assertTrue($snapshot['monthly_exhausted'], 'عطيّةُ الشهر نفدت');
        $this->assertFalse($snapshot['is_exhausted'], 'لكنّ الرسائل تخرج — فلا يُقال «نفدت»');
        $this->assertSame(10, $snapshot['credits']);
    }

    public function test_a_shop_with_neither_is_shown_as_exhausted(): void
    {
        $this->shop->update(['whatsapp_monthly_limit' => 1]);
        WhatsAppQuota::reserve($this->shop);

        $this->assertTrue(WhatsAppQuota::snapshot($this->shop)['is_exhausted']);
    }

    /* ═════════════════ ٤) الطلبُ من باب التاجر ═════════════════ */

    public function test_the_owner_orders_a_pack_and_only_one_stays_open(): void
    {
        $this->platform(WhatsAppPacks::SIZE_KEY, '500');
        $this->platform(WhatsAppPacks::PRICE_KEY, '5.000');

        $this->actingAs($this->owner)
            ->post(route('admin.integrations.whatsapp.packs.request'))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.packs.request'));

        $packs = WhatsAppMessagePack::where('business_id', $this->shop->id)->get();

        $this->assertCount(1, $packs, 'طلبٌ مفتوحٌ واحد لا اثنان');
        $this->assertSame(WhatsAppPacks::REQUESTED, $packs[0]->status);
        $this->assertSame(500, (int) $packs[0]->messages);
        $this->assertSame('5.000', (string) $packs[0]->amount);
        $this->assertSame('المالك', $packs[0]->requested_by_name);
    }

    /** ولا رصيدَ من الطلب وحده — الطلبُ نيّةٌ لا سداد */
    public function test_ordering_alone_grants_nothing(): void
    {
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.packs.request'));

        $this->assertSame(0, $this->credits());
        $this->assertSame(0, Invoice::count());
    }

    /**
     * وحارسان لا واحد: القسمُ يُحجب عن الكاشير أصلًا (`CheckAbility`)،
     * والمتحكّمُ يسأل عن الإدارة ثانيةً.
     *
     * والفحصُ على القاعدة لا على رمز الردّ: مديرُ قسمٍ قد يُمنح «التكاملات»
     * غدًا فيمرّ من الحارس الأوّل — والثاني هو ما يقف دونه حينئذٍ. فما
     * يُحرَس هنا أنّه **لا صفَّ يُكتب**، بأيّ الحارسين وقف.
     */
    public function test_a_cashier_cannot_commit_the_shop_to_money(): void
    {
        $response = $this->actingAs($this->cashier)
            ->post(route('admin.integrations.whatsapp.packs.request'));

        $this->assertContains($response->getStatusCode(), [302, 403]);
        $this->assertSame(0, WhatsAppMessagePack::count(), 'ولا طلبَ كُتب');
        $this->assertSame(0, Invoice::count());
    }

    /**
     * ═══ وموظّفٌ مُنح «التكاملات» يدويًّا لا يزال لا يلتزم بمال ═══
     *
     * الحارسُ الأوّل (`CheckAbility`) يقيس القسم لا الدور، والصلاحياتُ
     * المخصّصة تُمنح يدويًّا لكلّ موظّف. فكاشيرٌ مُنح القسم ليربط رقمًا يمرّ
     * منه — ولا يمرّ من الثاني.
     *
     * ولولا هذا الاختبار لَبدا الحارسُ الثاني محروسًا وهو لا يُبلَغ أصلًا:
     * حذفُه كلَّه لا يُسقط اختبارًا، لأنّ الكاشير العاديّ يقف عند الأوّل.
     */
    public function test_a_clerk_granted_the_integrations_section_still_cannot_commit_to_money(): void
    {
        $this->cashier->update(['permissions' => ['integrations']]);

        $this->actingAs($this->cashier->fresh())
            ->post(route('admin.integrations.whatsapp.packs.request'))
            ->assertSessionHasErrors('pack');

        $this->assertSame(0, WhatsAppMessagePack::count());
    }

    /** ومن يُرسل من رقمه الخاصّ لا يُباع له ما لا يستعمله */
    public function test_a_shop_on_its_own_number_is_not_sold_shared_messages(): void
    {
        $this->shop->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN, 'whatsapp_own_allowed' => true]);

        $this->actingAs($this->owner)
            ->post(route('admin.integrations.whatsapp.packs.request'))
            ->assertSessionHasErrors('pack');

        $this->assertSame(0, WhatsAppMessagePack::count());
    }

    public function test_a_merchant_cannot_reach_the_platform_pack_routes(): void
    {
        $pack = WhatsAppPacks::request($this->shop, 'المالك')['pack'];

        $this->actingAs($this->owner)
            ->post(route('super-admin.whatsapp.packs.approve', $pack->id))->assertForbidden();

        $this->actingAs($this->owner)
            ->delete(route('super-admin.whatsapp.packs.cancel', $pack->id))->assertForbidden();

        $this->assertSame(WhatsAppPacks::REQUESTED, $pack->fresh()->status);
    }

    /* ═════════════════ ٥) الاعتمادُ يُصدر ولا يُعطي ═════════════════ */

    public function test_approving_issues_an_invoice_and_grants_nothing_yet(): void
    {
        $pack = WhatsAppPacks::request($this->shop, 'المالك')['pack'];

        $this->actingAs($this->super)
            ->post(route('super-admin.whatsapp.packs.approve', $pack->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $pack = $pack->fresh();
        $invoice = Invoice::firstOrFail();

        $this->assertSame(WhatsAppPacks::INVOICED, $pack->status);
        $this->assertSame($invoice->id, $pack->invoice_id);
        $this->assertSame(Billing::KIND_MESSAGES, $invoice->kind);
        $this->assertNull($invoice->plan_id, 'فاتورةُ رسائلَ لا باقةَ لها — وإلّا قُرئت تجديدًا');
        $this->assertSame(Billing::UNPAID, $invoice->status);
        $this->assertSame(0, $this->credits(), 'ولا رسالةَ واحدةٌ قبل السداد');
    }

    /** واعتمادٌ ثانٍ لا يُصدر فاتورةً ثانية */
    public function test_approving_twice_issues_one_invoice(): void
    {
        $pack = WhatsAppPacks::request($this->shop, 'المالك')['pack'];

        $this->actingAs($this->super)->post(route('super-admin.whatsapp.packs.approve', $pack->id));
        $this->actingAs($this->super)
            ->post(route('super-admin.whatsapp.packs.approve', $pack->id))
            ->assertSessionHasErrors('pack');

        $this->assertSame(1, Invoice::count());
    }

    public function test_cancelling_a_request_leaves_no_invoice(): void
    {
        $pack = WhatsAppPacks::request($this->shop, 'المالك')['pack'];

        $this->actingAs($this->super)
            ->delete(route('super-admin.whatsapp.packs.cancel', $pack->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppPacks::CANCELLED, $pack->fresh()->status);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, $this->credits());
    }

    /* ═════════════════ والسدادُ هو ما يُعطي ═════════════════ */

    public function test_recording_the_payment_is_what_adds_the_messages(): void
    {
        $this->platform(WhatsAppPacks::SIZE_KEY, '500');

        $pack = WhatsAppPacks::approve(WhatsAppPacks::request($this->shop, 'المالك')['pack']);

        $this->actingAs($this->super)
            ->post(route('super-admin.invoices.pay', $pack->invoice_id))
            ->assertRedirect();

        $this->assertSame(500, $this->credits());
        $this->assertSame(WhatsAppPacks::PAID, $pack->fresh()->status);
        $this->assertSame(Billing::PAID, Invoice::find($pack->invoice_id)->status);
    }

    /**
     * ═══ والحارسان اللذان لا يبلغهما الطريقُ الطويل ═══
     *
     * المتحكّمان يفحصان الحالةَ قبل النداء، و`markPaid` تخرج مبكّرًا من
     * فاتورةٍ مدفوعة. فحرّاسُ `WhatsAppPacks` نفسِها لا تُبلَغ من الشاشة —
     * وحذفُها لا يُسقط شيئًا. وهي آخرُ ما يقف يوم يُنادى الدالّان من أمرِ
     * سطرٍ أو مهمّةٍ مجدولة، ولا شاشةَ تحرسهما حينئذٍ.
     */
    public function test_the_support_class_refuses_to_approve_a_pack_twice(): void
    {
        $pack = WhatsAppPacks::request($this->shop, 'المالك')['pack'];

        WhatsAppPacks::approve($pack);
        WhatsAppPacks::approve($pack->fresh());

        $this->assertSame(1, Invoice::count());
    }

    public function test_the_support_class_refuses_to_settle_one_invoice_twice(): void
    {
        $this->platform(WhatsAppPacks::SIZE_KEY, '500');

        $pack = WhatsAppPacks::approve(WhatsAppPacks::request($this->shop, 'المالك')['pack']);
        $invoice = Invoice::findOrFail($pack->invoice_id);

        WhatsAppPacks::settle($invoice);
        WhatsAppPacks::settle($invoice);

        $this->assertSame(500, $this->credits());
    }

    /** وتسجيلُ السداد مرّتين لا يُضاعف الرصيد */
    public function test_paying_twice_does_not_double_the_credit(): void
    {
        $this->platform(WhatsAppPacks::SIZE_KEY, '500');

        $pack = WhatsAppPacks::approve(WhatsAppPacks::request($this->shop, 'المالك')['pack']);
        $invoice = Invoice::find($pack->invoice_id);

        Billing::markPaid($invoice);
        Billing::markPaid($invoice->fresh());

        $this->assertSame(500, $this->credits());
    }

    /**
     * ═══ وفاتورةُ الرسائل لا تُسقط دَينَ الاشتراك ═══
     *
     * `markPaid` كانت تُعلّم **آخرَ اشتراكٍ غيرِ مدفوع** مدفوعًا مع كلّ
     * فاتورةٍ تُسدَّد — وهو صحيحٌ ما دامت كلُّ فاتورةٍ دورةَ باقة. فأوّلُ
     * حزمةِ رسائلَ تُسدَّد بخمسة ريالاتٍ كانت تُسقط اشتراكًا بمئة، ولا شيء
     * في النظام يقول إنّ شيئًا جرى.
     */
    public function test_paying_for_messages_does_not_settle_an_unpaid_subscription(): void
    {
        $plan = Plan::create(['name' => 'احترافية', 'monthly_price' => 100, 'yearly_price' => 1000]);
        $this->shop->update(['plan_id' => $plan->id]);

        $subscription = Subscription::create([
            'business_id' => $this->shop->id, 'plan_id' => $plan->id,
            'starts_at' => now(), 'ends_at' => now()->addMonth(),
            'amount' => 100, 'payment_status' => 'غير مدفوع', 'status' => Billing::SUB_ACTIVE,
        ]);

        $pack = WhatsAppPacks::approve(WhatsAppPacks::request($this->shop, 'المالك')['pack']);
        Billing::markPaid(Invoice::find($pack->invoice_id));

        $this->assertSame('غير مدفوع', $subscription->fresh()->payment_status);
    }

    /** وفاتورةُ الاشتراك تبقى تفعل ما كانت تفعله */
    public function test_paying_for_a_subscription_still_settles_it(): void
    {
        $plan = Plan::create(['name' => 'احترافية', 'monthly_price' => 100, 'yearly_price' => 1000]);
        $this->shop->update(['plan_id' => $plan->id]);

        $result = Billing::renew($this->shop->fresh(), 'monthly');
        Billing::markPaid($result['invoice']);

        $this->assertSame('مدفوع', $result['subscription']->fresh()->payment_status);
        $this->assertSame(Billing::KIND_SUBSCRIPTION, $result['invoice']->fresh()->kind);
        $this->assertSame(0, $this->credits(), 'ولا رسائلَ تُهدى مع تجديد باقة');
    }

    /* ═════════════════ والعرضُ يُقرأ من إعدادٍ واحد ═════════════════ */

    public function test_the_offer_is_read_from_the_platform_setting(): void
    {
        $this->platform(WhatsAppPacks::SIZE_KEY, '1200');
        $this->platform(WhatsAppPacks::PRICE_KEY, '9.500');

        $this->assertSame(1200, WhatsAppPacks::size());
        $this->assertSame(9.5, WhatsAppPacks::price());
    }

    /**
     * والافتراضُ المعروضُ في لوحة المنصّة هو الافتراضُ المستعمَل.
     *
     * الرقمان مكتوبان حرفًا في `SETTING_DEFAULTS` لأنّ PHP 8.4 لا يقبل
     * تعبيرًا داخل `const`. فيُقارَنان هنا، وإلّا افترقا يومًا: تقرأ الشاشة
     * «٥٠٠» ويبيع الكود سبعمئة.
     */
    public function test_the_displayed_default_offer_matches_the_one_that_is_sold(): void
    {
        $defaults = (new \ReflectionClass(PageController::class))->getConstant('SETTING_DEFAULTS');

        $this->assertSame((string) WhatsAppPacks::FALLBACK_SIZE, $defaults['whatsapp_pack_size']);
        $this->assertSame(
            number_format(WhatsAppPacks::FALLBACK_PRICE, 3, '.', ''),
            $defaults['whatsapp_pack_price'],
        );
    }

    /** وحدُّ الحزمة لا يُرفع من باب التاجر */
    public function test_a_merchant_cannot_write_the_platform_offer(): void
    {
        $this->actingAs($this->owner)->post(route('admin.marketing.whatsapp.save'), [
            'whatsapp_pack_size' => 99999,
            'whatsapp_pack_price' => 0,
        ]);

        $this->assertSame(WhatsAppPacks::FALLBACK_SIZE, WhatsAppPacks::size());
        $this->assertDatabaseMissing('settings', [
            'business_id' => $this->shop->id, 'key' => WhatsAppPacks::SIZE_KEY,
        ]);
    }
}
