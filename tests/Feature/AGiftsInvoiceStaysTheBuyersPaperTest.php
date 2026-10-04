<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\DocumentPaper;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderNotice;
use App\Support\PublicDocument;
use App\Support\Store\GiftCard;
use App\Support\Store\GiftCardProduct;
use App\Support\Store\GiftOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * فاتورةُ الهديّة ورقةُ مشتريها — وتفاصيلُ التجهيز في الشاشة الداخليّة وحدها.
 *
 * ═══ ما يُحرس ═══
 *
 * - **الفاتورةُ والإيصالُ ورابطُهما العامّ:** العميلُ هو المشتري باسمه
 *   ورقمه، والمبالغُ كما هي. ولا رقمَ للمستلِم عليها، ولا «بانتظار التواصل
 *   مع المستلم»، ولا رسالةُ كرت الهدية تحت بندها (`GiftCardProduct::paperNote`)
 *   — وملاحظةُ بندٍ عاديّ تُطبع كما كانت.
 * - **رسائلُ المشتري لا ترجع إلى المستلِم في طلب هديّة** (`OrderNotice::phone`):
 *   الفاتورةُ وحالُ الطلب ودعوةُ التقييم تكشف الثمنَ والمُهدي.
 * - **كتلةُ «طلب هدية» في الشاشة:** المشتري يُرى ولو أُخفي عن المستلِم، ورقمُه
 *   لمن يرى العملاء وحده (`GiftOrders::buyerPhone`). ولا صلاحيةَ جديدة: من لا
 *   يفتح المبيعات لا يبلغ الطلبَ ولا زرَّ مستلِمه.
 */
class AGiftsInvoiceStaysTheBuyersPaperTest extends TestCase
{
    use RefreshDatabase;

    private const BUYER_PHONE = '96899110001';

    private const RECIPIENT_PHONE = '96899110002';

    private const MESSAGE = 'كل عام وأنتِ بخير يا سارة';

    private Business $shop;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');
        config(['storefront.ribbon_english_checkout_businesses' => [], 'storefront.ribbon_gift_card_product_businesses' => []]);

        $this->shop = Business::create([
            'name' => 'متجر ribbon', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_areas' => 'الخوير',
            'store_delivery_slots' => '9 ص – 12 م', 'store_delivery_fee' => '2', 'store_free_delivery_over' => '',
            GiftOrders::KEY => '1',
        ]);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::where('business_id', $this->shop->id)->where('role', 'admin')->firstOrFail();
    }

    private function employee(string $role): User
    {
        return User::create([
            'business_id' => $this->shop->id, 'name' => 'موظف '.$role, 'email' => $role.'@abaad.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    /** هديّةٌ ينتظر المتجرُ موقعَ مستلِمها، أُخفي فيها المُهدي */
    private function giftOrder(array $over = []): Order
    {
        $this->postJson('/s/ribbon/checkout', $over + [
            'items' => [['id' => $this->rose->id, 'qty' => 1]],
            'fulfil' => 'delivery', 'pay' => 'cod', 'name' => 'مريم المُهدية', 'phone' => self::BUYER_PHONE,
            'area' => 'الخوير', 'address' => '', 'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
            'is_gift' => true, 'recipient_name' => 'سارة', 'recipient_phone' => self::RECIPIENT_PHONE,
            'recipient_location' => GiftOrders::CONTACT, 'hide_sender' => true, 'occasion' => 'birthday',
        ])->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    /** @return array{a4: string, strip: string} */
    private function papers(Order $order): array
    {
        $this->actingAs($this->owner());

        return [
            'a4' => PdfController::saleHtml($this->shop->id, $order->fresh('items'))['html'],
            'strip' => PdfController::saleHtml($this->shop->id, $order->fresh('items'), thermal: true)['html'],
        ];
    }

    /* ═══════════ الورقةُ الماليّة ═══════════ */

    public function test_the_invoice_keeps_the_buyer_and_its_totals_and_prints_no_recipient_phone_nor_workflow(): void
    {
        $o = $this->giftOrder();
        $this->assertTrue(GiftOrders::awaitingLocation($o));

        $doc = DocumentPaper::forSale($o->fresh('items'));
        $this->assertSame('العميل', $doc['parties'][0]['cap']);
        $this->assertSame('مريم المُهدية', $doc['parties'][0]['lines'][0]);
        $this->assertContains(self::BUYER_PHONE, $doc['parties'][0]['lines'], 'رقمُ المشتري غاب عن فاتورته');
        $this->assertSame(['سارة'], $doc['parties'][1]['lines'], 'طرفُ المستلِم يحمل غيرَ اسمه');
        // والمبالغُ كما هي — الهديّةُ لا تمسّ مالًا
        $this->assertSame((float) $o->total, 22.0);
        $this->assertStringContainsString('22.000', collect($doc['totals'])->firstWhere('grand', true)['value']);
        // والمناسبةُ باسمها لا بمفتاحها
        $this->assertContains(['label' => 'المناسبة', 'value' => 'عيد ميلاد'], $doc['meta']);

        ['a4' => $a4, 'strip' => $strip] = $this->papers($o);

        foreach (['a4' => $a4, 'strip' => $strip] as $paper => $html) {
            $this->assertStringContainsString('مريم المُهدية', $html, "{$paper}: المشتري غاب عن ورقته");
            $this->assertStringNotContainsString(self::RECIPIENT_PHONE, $html, "{$paper}: رقمُ المستلِم على ورقة المشتري");
            foreach (['بانتظار التواصل', 'تواصلوا مع المستلم', 'contact_recipient'] as $workflow) {
                $this->assertStringNotContainsString($workflow, $html, "{$paper}: سيرُ العمل الداخليّ طُبع «{$workflow}»");
            }
        }

        // ورابطُ الورقة العامّ — يفتحه من يمسك الرمز
        $public = $this->get((string) PublicDocument::url($o))->assertOk()->getContent();
        $this->assertStringContainsString('مريم المُهدية', $public);
        $this->assertStringNotContainsString(self::RECIPIENT_PHONE, $public);
    }

    /** الكرتُ معلَّمًا كما يولد في «المنتجات» (`products.is_gift_card`) */
    private function card(): Product
    {
        config(['storefront.ribbon_gift_card_product_businesses' => [$this->shop->id]]);

        return Product::create([
            'business_id' => $this->shop->id, 'name' => GiftCard::PRODUCT_NAME, 'price' => 3, 'cost' => 0,
            'quantity' => 0, 'tracks_stock' => false, 'is_gift_card' => true,
            'alert_qty' => 0, 'active' => true, 'published' => true,
        ]);
    }

    /** الفاتورةُ والإيصالُ والرابطُ العامّ — نصًّا واحدًا لكلٍّ */
    private function allPapers(Order $order): array
    {
        $papers = $this->papers($order);
        $papers['public'] = $this->get((string) PublicDocument::url($order))->assertOk()->getContent();

        return $papers;
    }

    public function test_a_gift_card_message_never_reaches_the_invoice_the_receipt_or_the_public_paper(): void
    {
        $card = $this->card();
        $o = $this->giftOrder(['items' => [
            ['id' => $this->rose->id, 'qty' => 1],
            ['id' => $card->id, 'qty' => 1, 'note' => self::MESSAGE],
        ]]);

        // محفوظةٌ كما كانت — في البند وفي الطلب — وتُرى في الشاشة الداخليّة
        $this->assertSame(self::MESSAGE, $o->items()->where('product_id', $card->id)->value('note'));
        $this->assertSame(self::MESSAGE, $o->card_message);

        foreach ($this->allPapers($o) as $paper => $html) {
            $this->assertStringNotContainsString(self::MESSAGE, $html, "{$paper}: رسالةُ الكرت الخاصّة طُبعت على الورقة الماليّة");
            // والبندُ وثمنُه باقيان: الكرتُ مبيعٌ يُحاسَب
            $this->assertStringContainsString(GiftCard::PRODUCT_NAME, $html, "{$paper}: بندُ الكرت سقط من الورقة");
        }

        $this->actingAs($this->owner())->get(route('admin.orders.show', $o->number))->assertOk()
            ->assertInertia(fn ($p) => $p->where('order.card_message', self::MESSAGE));
    }

    public function test_a_renamed_card_is_still_known_by_its_mark_and_its_message_stays_off_paper(): void
    {
        $card = $this->card();
        $o = $this->giftOrder(['items' => [['id' => $card->id, 'qty' => 1, 'note' => self::MESSAGE], ['id' => $this->rose->id, 'qty' => 1]]]);

        // يُعاد تسميتُه بعد البيع — بالاسمين — والعلامةُ هي الهويّة (#63)
        $card->update(['name' => 'Bouquet note', 'name_en' => 'Bouquet note']);
        $o->items()->where('product_id', $card->id)->update(['name' => 'Bouquet note']);
        // ورسالةُ الطلب عُدّلت في الشاشة بعدُ: لا لقطةَ تحمي — العلامةُ وحدها
        $o->forceFill(['card_message' => 'نصٌّ آخر للكرت'])->save();

        foreach ($this->allPapers($o->fresh()) as $paper => $html) {
            $this->assertStringContainsString('Bouquet note', $html, "{$paper}: بندُ الكرت المُعاد تسميتُه سقط");
            $this->assertStringNotContainsString(self::MESSAGE, $html, "{$paper}: كرتٌ أُعيدت تسميتُه سرّب رسالتَه");
        }

        // ويُحذف صنفُه بعد البيع: العلامةُ تُقرأ من المحذوف كذلك
        $card->delete();
        $item = $o->items()->where('product_id', $card->id)->firstOrFail();
        $this->assertNull(GiftCardProduct::paperNote($item, $o->fresh()));
    }

    public function test_an_old_order_whose_card_lost_its_mark_is_protected_by_its_snapshot(): void
    {
        $card = $this->card();
        $o = $this->giftOrder(['items' => [['id' => $card->id, 'qty' => 1, 'note' => self::MESSAGE], ['id' => $this->rose->id, 'qty' => 1]]]);

        // لا علامةَ اليوم (سبق البيعُ العلامةَ أو نُزعت) — ورسالةُ الطلب لقطةُ يوم البيع
        $card->forceFill(['is_gift_card' => false])->save();
        $item = $o->items()->where('product_id', $card->id)->firstOrFail();

        $this->assertNull(GiftCardProduct::paperNote($item, $o->fresh()));
        $this->assertStringNotContainsString(self::MESSAGE, $this->papers($o)['a4']);

        // وكرتان في الطلب: كلُّ رسالةٍ بتمامها لقطةٌ — `orderMessage` تفصلها بسطرٍ فارغ
        foreach (["أخرى\n\n".self::MESSAGE, self::MESSAGE."\n\nأخرى", "أ\n\n".self::MESSAGE."\n\nب"] as $both) {
            $this->assertNull(GiftCardProduct::paperNote($item, $o->fresh()->fill(['card_message' => $both])));
        }

        // وصنفُه ذهب من القاعدة كلّها: لا صنفَ يُثبت أنّه عاديّ — اللقطةُ تحمي
        $card->forceDelete();
        $this->assertNull(GiftCardProduct::paperNote($item->fresh(), $o->fresh()));

        // ولا لقطةَ لغير طلب الموقع: ملاحظةُ الصندوق تُطبع ولو طابقت رسالتَه
        $till = $o->fresh()->fill(['channel' => 'pos']);
        $this->assertSame(self::MESSAGE, GiftCardProduct::paperNote($item->fresh(), $till));
    }

    /**
     * ملاحظةُ بندٍ عاديّ تُطبع ولو طابقت رسالةَ الكرت نصًّا أو كانت جزءًا منها.
     *
     * اللقطةُ (`orders.card_message`) احتياطٌ لبند كرتٍ قديم لا علامةَ له —
     * لا مصفاةُ نصوصٍ تحجب كلَّ ما يشبه رسالةً على الطلب.
     */
    public function test_an_ordinary_note_matching_or_inside_the_card_message_still_prints(): void
    {
        $card = $this->card();
        $o = $this->giftOrder(['items' => [['id' => $this->rose->id, 'qty' => 1], ['id' => $card->id, 'qty' => 1, 'note' => self::MESSAGE]]]);
        $rose = $o->items()->where('product_id', $this->rose->id)->firstOrFail();
        $cardLine = $o->items()->where('product_id', $card->id)->firstOrFail();

        foreach (['نصًّا' => self::MESSAGE, 'جزءًا' => 'كل عام'] as $how => $note) {
            $rose->forceFill(['note' => $note])->save();

            $this->assertSame($note, GiftCardProduct::paperNote($rose->fresh(), $o->fresh()),
                "ملاحظةُ بندٍ عاديّ طابقت رسالةَ الكرت {$how} فحُجبت");
            $this->assertNull(GiftCardProduct::paperNote($cardLine->fresh(), $o->fresh()), 'رسالةُ الكرت ظهرت');
        }

        // وصنفٌ عاديّ خارجَ دفتر المخزون: بعضُ الرسالة ليس رسالةً — يُطبع
        $this->rose->forceFill(['tracks_stock' => false])->save();
        $this->assertSame('كل عام', GiftCardProduct::paperNote($rose->fresh(), $o->fresh()),
            'بعضُ رسالة الكرت على بندٍ عاديّ لا يتتبّع المخزون حُجب');
        $this->rose->forceFill(['tracks_stock' => true])->save();

        // وعلى الورقة: ملاحظةُ الورد تُطبع مرّةً واحدة — ورسالةُ الكرت تحت بندها لا
        foreach ($this->papers($o) as $paper => $html) {
            $this->assertSame(1, substr_count($html, 'كل عام'), "{$paper}: الملاحظةُ العاديّة غابت أو ظهرت معها رسالةُ الكرت");
        }
    }

    public function test_an_ordinary_item_note_still_prints_as_before(): void
    {
        $o = $this->giftOrder();
        $line = $o->items()->firstOrFail();
        $line->forceFill(['note' => 'بلا شريط'])->save();

        $this->assertSame('بلا شريط', GiftCardProduct::paperNote($line->fresh(), $o->fresh()));
        // والرابطُ العامّ لا يطبع ملاحظاتِ البنود أصلًا — فالفاتورةُ والإيصال
        foreach ($this->papers($o) as $paper => $html) {
            $this->assertStringContainsString('بلا شريط', $html, "{$paper}: ملاحظةُ بندٍ عاديّ سقطت");
        }

        $this->assertNull(GiftCardProduct::paperNote(new OrderItem(['note' => '  ']), $o));

        // والاسمُ ليس هويّة: صنفٌ بلا علامةٍ يحمل «كرت هدية» اسمًا تُطبع ملاحظتُه
        $named = Product::create([
            'business_id' => $this->shop->id, 'name' => GiftCard::PRODUCT_NAME, 'price' => 1, 'cost' => 0,
            'quantity' => 5, 'alert_qty' => 0, 'active' => true, 'published' => true,
        ]);
        $line->forceFill(['product_id' => $named->id, 'name' => GiftCard::PRODUCT_NAME])->save();
        $this->assertSame('بلا شريط', GiftCardProduct::paperNote($line->fresh(), $o->fresh()));

        // وعلامةُ صنفٍ في متجرٍ آخر لا تُقرأ هنا
        $elsewhere = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $elsewhere->id, 'name' => 'x', 'price' => 1, 'cost' => 0, 'quantity' => 0,
            'alert_qty' => 0, 'active' => true, 'published' => true, 'is_gift_card' => true,
        ]);
        $line->forceFill(['product_id' => $theirs->id])->save();
        $this->assertSame('بلا شريط', GiftCardProduct::paperNote($line->fresh(), $o->fresh()));
    }

    /* ═══════════ رسائلُ المشتري ═══════════ */

    public function test_buyer_messages_never_fall_back_to_the_recipient_on_a_gift(): void
    {
        $gift = $this->giftOrder();
        $this->assertSame(self::BUYER_PHONE, OrderNotice::phone($gift));

        // مشترٍ بلا بطاقة عميل: لا رقمَ — لا رقمُ المستلِم
        $gift->forceFill(['customer_id' => null])->save();
        $this->assertNull(OrderNotice::phone($gift->fresh()));

        $this->actingAs($this->owner())->post(route('admin.orders.send', $gift->number));
        $this->assertSame('danger', session('toast')['type']);
        $this->assertArrayNotHasKey('link', session('toast'), 'فاتورةُ المشتري ذهبت إلى المستلِم');

        // والطلبُ العاديّ يرجع إلى المستلِم كما كان
        $plain = $gift->fresh()->replicate(['number'])->fill(['number' => 'W-PLAIN-1', 'is_gift' => false]);
        $plain->save();
        $this->assertSame(self::RECIPIENT_PHONE, OrderNotice::phone($plain));
    }

    /* ═══════════ الشاشةُ الداخليّة ═══════════ */

    public function test_the_gift_block_shows_the_buyer_even_when_hidden_and_its_phone_to_those_who_see_customers(): void
    {
        $o = $this->giftOrder();
        $buyerPhone = $o->customer->phone;
        $this->assertNotEmpty($buyerPhone);

        $sees = fn (User $u, ?string $phone) => $this->actingAs($u)->get(route('admin.orders.show', $o->number))->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('order.is_gift', true)
                ->where('order.hide_sender', true)
                // «لا تذكر اسمي» للمستلِم وحده — التاجرُ يرى المشتري
                ->where('order.customer', 'مريم المُهدية')
                ->where('order.sender_name', 'مريم المُهدية')
                ->where('order.recipient_name', 'سارة')
                ->where('order.recipient_phone', self::RECIPIENT_PHONE)
                ->where('order.occasion_label', 'عيد ميلاد')
                ->where('order.location_label', 'الموقع: بانتظار التواصل مع المستلم')
                ->where('order.awaiting_location', true)
                ->where('order.buyer_phone', $phone));

        $sees($this->owner(), $buyerPhone);
        $sees($this->employee('sales'), $buyerPhone);
        // من يفتح المبيعات ولا يرى العملاء: الطلبُ نعم، ورقمُ المشتري لا
        $sees($this->employee('delivery'), null);

        // وطلبٌ ليس هديّة لا يُرسل رقمًا جديدًا
        $this->assertNull(GiftOrders::buyerPhone($o->replicate()->fill(['is_gift' => false]), $this->owner()));
    }

    public function test_no_new_door_to_recipient_data_for_who_cannot_open_orders(): void
    {
        $o = $this->giftOrder();
        $cashier = $this->employee('cashier');

        $this->actingAs($cashier)->get(route('admin.orders.show', $o->number))->assertForbidden();
        $this->actingAs($cashier)->post(route('admin.orders.contactRecipient', $o->number))->assertForbidden();
        $this->assertNull(GiftOrders::buyerPhone($o, $cashier));
    }
}
