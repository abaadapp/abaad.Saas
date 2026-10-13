<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Support\Billing;
use App\Support\WhatsAppPacks;
use App\Support\WhatsAppQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * رقمُ أبعاد المشترك بشروطه — حصّةٌ شهريّة، ثمّ رصيدٌ مشترًى، وحزمةٌ بثمنها.
 *
 * يكمّل `AShopBuysMoreOfAbaadsMessagesTest` و`WhatsAppSharedTest` و
 * `AMessageThatNeverLeftIsNotChargedForTest` بما لم يقيسوه حرفًا:
 *
 *   - المئةُ الافتراضيّة: الرسالةُ المئة تخرج، والحاديةُ بعد المئة تقف بلا رصيد.
 *   - والرصيدُ المشترى بعد الحصّة، ولا ينتهي بتبدّل الشهر.
 *   - وثمنُ الحزمة الافتراضيّ ٧٫٥٠٠ لخمسمئة — وما ضبطه مدير المنصّة يعلو عليه.
 *   - وحزمةٌ أُلغيت بعد فاتورتها لا تُضيف رسالةً ولو سُدّدت الفاتورة.
 */
class AbaadsSharedNumberKeepsItsTermsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 10:00:00');

        $this->shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_hundredth_message_goes_and_the_next_waits_for_credit(): void
    {
        $this->assertSame(100, WhatsAppQuota::effectiveLimit($this->shop), 'الحصّةُ الافتراضيّة ليست مئة');

        foreach (range(1, 100) as $n) {
            $this->assertSame(WhatsAppQuota::SOURCE_MONTHLY, WhatsAppQuota::reserve($this->shop), "الرسالة {$n}");
        }

        $this->assertNull(WhatsAppQuota::reserve($this->shop), 'الحاديةُ بعد المئة خرجت بلا رصيد');

        WhatsAppQuota::grant($this->shop, 5);
        $this->assertSame(WhatsAppQuota::SOURCE_CREDIT, WhatsAppQuota::reserve($this->shop));
        $this->assertSame(4, WhatsAppQuota::credits($this->shop));
    }

    /** والرصيدُ المشترى لا يعود إلى الصفر مع الشهر — والحصّةُ وحدها تتجدّد */
    public function test_purchased_credit_outlives_the_month(): void
    {
        $this->shop->update(['whatsapp_monthly_limit' => 1]);
        WhatsAppQuota::grant($this->shop, 10);

        $this->assertSame(WhatsAppQuota::SOURCE_MONTHLY, WhatsAppQuota::reserve($this->shop));
        $this->assertSame(WhatsAppQuota::SOURCE_CREDIT, WhatsAppQuota::reserve($this->shop));
        $this->assertSame(9, WhatsAppQuota::credits($this->shop));

        Carbon::setTestNow('2026-11-02 09:00:00');

        $this->assertSame(0, WhatsAppQuota::used($this->shop), 'الشهرُ الجديد لم تتجدّد حصّتُه');
        $this->assertSame(9, WhatsAppQuota::credits($this->shop), 'الرصيدُ المشترى انتهى مع الشهر');
        $this->assertSame(WhatsAppQuota::SOURCE_MONTHLY, WhatsAppQuota::reserve($this->shop), 'الحصّةُ تُصرف قبل الرصيد في الشهر الجديد');
        $this->assertSame(9, WhatsAppQuota::credits($this->shop));
    }

    /** ورسالةٌ رُدّت تعود إلى جيبها هي */
    public function test_a_refused_message_goes_back_to_the_pocket_it_came_from(): void
    {
        $this->shop->update(['whatsapp_monthly_limit' => 1]);
        WhatsAppQuota::grant($this->shop, 2);

        WhatsAppQuota::reserve($this->shop);
        $source = WhatsAppQuota::reserve($this->shop);
        WhatsAppQuota::release($this->shop, $source);

        $this->assertSame(WhatsAppQuota::SOURCE_CREDIT, $source);
        $this->assertSame(2, WhatsAppQuota::credits($this->shop));
        $this->assertSame(1, WhatsAppQuota::used($this->shop), 'ردُّ رسالةٍ مشتراة مسّ الحصّة');
    }

    public function test_the_default_pack_is_five_hundred_for_seven_and_a_half(): void
    {
        $this->assertSame(500, WhatsAppPacks::size());
        $this->assertSame(7.5, WhatsAppPacks::price());

        $pack = WhatsAppPacks::request($this->shop, 'المالك')['pack'];

        $this->assertSame(500, (int) $pack->messages);
        $this->assertSame('7.500', number_format((float) $pack->amount, 3, '.', ''));
        $this->assertSame(0, WhatsAppQuota::credits($this->shop), 'الطلبُ وحده أضاف رسائل');
    }

    /** وما ضبطه مدير المنصّة يعلو على الافتراضيّ — لا يستبدله نشر */
    public function test_a_price_the_platform_set_beats_the_default(): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppPacks::PRICE_KEY], ['value' => '5.000']);

        $this->assertSame(5.0, WhatsAppPacks::price());
        $this->assertSame('5.000', number_format((float) WhatsAppPacks::request($this->shop, 'المالك')['pack']->amount, 3, '.', ''));
    }

    /** وحزمةٌ أُلغيت بعد فاتورتها لا تُضيف شيئًا — ولو سُدّدت الفاتورة */
    public function test_a_cancelled_pack_grants_nothing_even_if_its_invoice_is_paid(): void
    {
        $super = User::create(['business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@terms.om', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط']);
        $pack = WhatsAppPacks::approve(WhatsAppPacks::request($this->shop, 'المالك')['pack']);
        WhatsAppPacks::cancel($pack);

        $this->actingAs($super)->post(route('super-admin.invoices.pay', $pack->invoice_id));

        $this->assertSame(Billing::PAID, Invoice::find($pack->invoice_id)->status);
        $this->assertSame(0, WhatsAppQuota::credits($this->shop), 'حزمةٌ ملغاة أضافت رسائل');
        $this->assertSame(WhatsAppPacks::CANCELLED, $pack->fresh()->status);
    }
}
