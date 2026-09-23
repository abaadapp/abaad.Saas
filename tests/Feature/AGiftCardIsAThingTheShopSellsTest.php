<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\GiftCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * كرتُ الهدية — صنفٌ يُباع، ومستلِمٌ غيرُ المشتري.
 *
 * والحارسُ الأهمّ أنّ الكرتَ يمرّ في المسار القائم كلِّه بلا استثناء:
 * يدخل المجموعَ والضريبةَ وبندَ الطلب وتقريرَ الأصناف — ولا يُخصم من رفّ.
 */
class AGiftCardIsAThingTheShopSellsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Product $bouquet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');
        Storage::fake('local');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->settings();

        $this->bouquet = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $over = []): void
    {
        MarketingSettings::save($this->shop->id, 'website', $over + [
            'store_on' => '1', 'store_pay_cod' => '1',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م',
            'store_gift_card' => '1', 'store_gift_card_price' => '0.5',
        ]);
    }

    private function order(array $over = []): array
    {
        return $over + [
            'items' => [['id' => $this->bouquet->id, 'qty' => 1]],
            'fulfil' => 'delivery', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'area' => 'الخوير', 'address' => 'شارع ١٨', 'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ];
    }

    private function place(array $over = [])
    {
        return $this->postJson('/s/ribbon/checkout', $this->order($over));
    }

    /* ═══════════ الثمن ═══════════ */

    /** الكرتُ يدخل المجموعَ — ويُرى في التسعير قبل أن يُطلب */
    public function test_the_card_is_priced_before_it_is_bought(): void
    {
        $bare = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $this->bouquet->id, 'qty' => 1]], 'fulfil' => 'pickup'])
            ->assertOk()->json();

        $with = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $this->bouquet->id, 'qty' => 1]], 'fulfil' => 'pickup', 'gift_card' => true])
            ->assertOk()->json();

        $this->assertSame(20.0, (float) $bare['subtotal']);
        $this->assertSame(20.5, (float) $with['subtotal'], 'الكرتُ لم يدخل المجموع');
        $this->assertSame(20.5, (float) $with['total']);

        // ويُرى سطرًا باسمه — لا رقمًا يقفز في المجموع بلا تفسير
        $this->assertContains(GiftCard::PRODUCT_NAME, array_column($with['lines'], 'name'));
    }

    /** وهو بندٌ في الفاتورة — يُعدّ في تقرير الأصناف كأيّ صنف */
    public function test_the_card_is_a_line_on_the_invoice(): void
    {
        $this->place(['gift_card' => true, 'card' => 'كل عام وأنتِ بخير'])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->firstOrFail();
        $line = $order->items->firstWhere('name', GiftCard::PRODUCT_NAME);

        $this->assertNotNull($line, 'الكرتُ لم يُكتب بندًا');
        $this->assertSame(0.5, (float) $line->total);
        $this->assertSame(1, (int) $line->quantity);
        // وبمعرّف صنفٍ حقيقيّ — وإلّا سقط من تقرير الأصناف (يقرأ product_id)
        $this->assertNotNull($line->product_id);
        $this->assertSame(20.5, (float) $order->total);
    }

    /** ولا رفَّ له يُخصم — يُباع مئةً ولا ينفد */
    public function test_the_card_never_runs_out(): void
    {
        $card = GiftCard::product($this->shop->id);
        $this->assertSame(0, (int) $card->quantity, 'الصنفُ يُنشأ برصيدٍ صفر — وهو المقصود');

        for ($i = 0; $i < 3; $i++) {
            $this->place(['gift_card' => true])->assertOk();
        }

        $this->assertSame(0, (int) $card->fresh()->quantity, 'خُصم من رفٍّ لا وجود له');
        // والباقةُ تُخصم كما تُخصم — فالاستثناءُ للكرت وحده لا للطلب كلِّه
        $this->assertSame(7, (int) $this->bouquet->fresh()->quantity);
    }

    /** وصنفُه مُطفأٌ وغيرُ منشور — لا يُشترى وحدَه من الشبكة */
    public function test_the_card_is_not_on_the_shelf(): void
    {
        $card = GiftCard::product($this->shop->id);

        $this->assertFalse((bool) $card->active);
        $this->assertFalse((bool) $card->published);

        // ومن أضافه إلى سلّته بمعرّفه يُردّ — فحصُ النشر قائمٌ كما كان
        // ومن أضافه إلى سلّته بمعرّفه يُردّ عند أوّل بوّابة — قبل النشر أصلًا
        $errors = $this->postJson('/s/ribbon/checkout', $this->order(['items' => [['id' => $card->id, 'qty' => 1]]]))
            ->assertStatus(422)->json('errors');

        $this->assertSame(['«كرت هدية» موقوف عن البيع.'], $errors['items.0.id'] ?? null);

        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
    }

    /** ويُنشأ مرّةً واحدةً مهما بيع */
    public function test_one_card_product_per_shop(): void
    {
        $this->place(['gift_card' => true])->assertOk();
        $this->place(['gift_card' => true])->assertOk();

        $this->assertSame(1, Product::where('business_id', $this->shop->id)
            ->where('name', GiftCard::PRODUCT_NAME)->count());
    }

    /* ═══════════ الترتيب ═══════════ */

    /** المحاذاةُ تُحفظ كما اختارها — فيُكتب الكرتُ كما رُتّب */
    public function test_the_text_keeps_the_shape_its_buyer_gave_it(): void
    {
        $this->place(['gift_card' => true, 'card' => "كل عام\nوأنتِ بخير", 'card_align' => 'center'])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->firstOrFail();

        $this->assertSame('center', $order->card_align);
        // والأسطرُ جزءٌ ممّا كتب — بيتُ شعرٍ في سطرين لا يُطوى إلى سطر
        $this->assertSame("كل عام\nوأنتِ بخير", $order->card_message);
    }

    /** ومحاذاةٌ لم تُكتب تُردّ إلى اليمين لا تُترك فارغة */
    public function test_an_unknown_alignment_is_refused(): void
    {
        $this->place(['gift_card' => true, 'card' => 'مرحبًا', 'card_align' => 'justify'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['card_align']]);

        $this->assertSame('right', GiftCard::align(null));
        $this->assertSame('right', GiftCard::align('عبث'));
        $this->assertSame('center', GiftCard::align('center'));
    }

    /** وطلبٌ بلا كرتٍ لا يحمل محاذاةً — فلا تُقرأ في التجهيز كأنّ ثَمّ كرتًا */
    public function test_an_order_without_a_card_carries_no_alignment(): void
    {
        $this->place(['card_align' => 'center'])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->firstOrFail();
        $this->assertNull($order->card_align);
        $this->assertNull($order->card_file);
    }

    /* ═══════════ الملفّ ═══════════ */

    /** الملفُّ يُرفع وحدَه، ويُثبَّت للطلب حين يُتمّ */
    public function test_an_attached_file_follows_its_order(): void
    {
        $up = $this->postJson('/s/ribbon/gift-card', ['file' => UploadedFile::fake()->image('خطّي.png')])
            ->assertOk()->json();

        $this->place(['gift_card' => true, 'card_file' => $up['token'], 'card_file_name' => $up['name']])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->firstOrFail();

        $this->assertNotNull($order->card_file);
        Storage::disk('local')->assertExists($order->card_file);
        // ولم يبقَ معلَّقًا: نسخةٌ تبقى تُكنس بعد يوم فيفقد الطلبُ مرفقَه
        Storage::disk('local')->assertMissing('gift-cards/pending/'.$up['token']);
    }

    /** ولا يُقرأ برابطٍ يُخمَّن — بابُه يسأل عن صاحب الطلب */
    public function test_the_file_is_not_read_by_a_guessed_link(): void
    {
        $up = $this->postJson('/s/ribbon/gift-card', ['file' => UploadedFile::fake()->image('c.png')])->assertOk()->json();
        $this->place(['gift_card' => true, 'card_file' => $up['token'], 'card_file_name' => 'c.png'])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->firstOrFail();

        // زائرٌ بلا حساب
        $this->get(route('admin.orders.giftcard', $order->id))->assertRedirect();

        // وجارٌ من متجرٍ آخر — الطلبُ ليس طلبَه فلا وجودَ له عنده
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط', 'site_slug' => 'jar']);
        $other = User::create(['business_id' => $neighbour->id, 'name' => 'جار', 'email' => 'jar@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->actingAs($other)->get(route('admin.orders.giftcard', $order->id))->assertNotFound();

        // وصاحبُه يفتحه
        $this->actingAs(User::where('business_id', $this->shop->id)->firstOrFail())
            ->get(route('admin.orders.giftcard', $order->id))->assertOk();
    }

    /** ورمزٌ يأتي من عند المرسِل لا يُقرأ مسارًا */
    public function test_a_forged_token_reaches_nothing(): void
    {
        $this->place(['gift_card' => true, 'card_file' => '../../../.env'])->assertOk();

        $this->assertNull(Order::where('business_id', $this->shop->id)->firstOrFail()->card_file);
    }

    /** وما لا يُقبل نوعًا يُردّ */
    public function test_an_unsupported_file_is_refused(): void
    {
        $this->postJson('/s/ribbon/gift-card', ['file' => UploadedFile::fake()->create('خبيث.exe', 10)])
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'نوع الملف غير مدعوم — صورة أو PDF.');
    }

    /** وما رُفع ولم يُتمّ صاحبُه طلبَه يُكنس */
    public function test_a_file_nobody_finished_is_swept(): void
    {
        $up = $this->postJson('/s/ribbon/gift-card', ['file' => UploadedFile::fake()->image('a.png')])->assertOk()->json();
        Storage::disk('local')->assertExists('gift-cards/pending/'.$up['token']);

        Carbon::setTestNow(now()->addDays(2));
        GiftCard::sweep();

        Storage::disk('local')->assertMissing('gift-cards/pending/'.$up['token']);
    }

    /* ═══════════ المستلِم ═══════════ */

    /** المستلِمُ من كتبه الزبون — لا المشتري دائمًا */
    public function test_the_recipient_is_who_the_buyer_named(): void
    {
        $this->place(['recipient_name' => 'أمّي', 'recipient_phone' => '96899220002'])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->firstOrFail();

        $this->assertSame('أمّي', $order->recipient_name);
        $this->assertSame('96899220002', $order->recipient_phone);
        // واسمُ المُرسِل هو المشتري — فيُطبع على الكرت بلا أن يُسأل عنه
        $this->assertSame('مريم', $order->sender_name);
    }

    /** ومن لم يكتب مستلِمًا فهو نفسُه — لا يبقى الطلبُ بلا من يُسلَّم إليه */
    public function test_a_buyer_who_names_nobody_is_the_recipient(): void
    {
        $this->place()->assertOk();

        $order = Order::where('business_id', $this->shop->id)->firstOrFail();

        $this->assertSame('مريم', $order->recipient_name);
        $this->assertSame('96899110001', $order->recipient_phone);
    }

    /* ═══════════ البابُ مغلقٌ على من أطفأه ═══════════ */

    /** ومتجرٌ أطفأ الكرتَ يردّ من طلبه — الشاشةُ تُخفيه والخادمُ يردّه */
    public function test_a_shop_that_turned_it_off_refuses_it(): void
    {
        $this->settings(['store_gift_card' => '0']);

        $this->place(['gift_card' => true, 'card' => 'مرحبًا'])
            ->assertStatus(422)->assertJsonPath('errors.gift_card.0', 'كرت الهدية غير متاح في هذا المتجر.');

        $this->postJson('/s/ribbon/gift-card', ['file' => UploadedFile::fake()->image('a.png')])
            ->assertStatus(422);

        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());

        /*
         * ═══ والتسعيرُ بابٌ ثانٍ لا يمرّ بالتحقّق ═══
         *
         * `quote` تُستدعى في كلّ تبديلٍ على الشاشة ولا تُشغّل قواعدَ
         * التحقّق. فلو اكتفى المنعُ بها لَسعّرت متجرًا أطفأ الكرتَ خمسَ
         * مئةِ بيسةٍ زائدة، ورأى الزبونُ في الزرّ مبلغًا لا يُطالَب به —
         * ثمّ يُردّ طلبُه عند الضغط بلا أن يفهم من أين جاء الرقم.
         *
         * فالمنعُ في `GiftCard::wanted` نفسِها، والبابان يقرآن منها.
         */
        $quoted = $this->postJson('/s/ribbon/quote', [
            'items' => [['id' => $this->bouquet->id, 'qty' => 1]], 'fulfil' => 'pickup', 'gift_card' => true,
        ])->assertOk()->json();

        $this->assertSame(20.0, (float) $quoted['subtotal'], 'سُعِّر كرتٌ في متجرٍ أطفأه');
        $this->assertNotContains(GiftCard::PRODUCT_NAME, array_column($quoted['lines'], 'name'));
    }

    /** والثمنُ فارغًا يعني الافتراضيّ — وصفرًا صريحًا يعني مجّانًا */
    public function test_an_empty_price_is_not_a_free_card(): void
    {
        /*
         * والرقمُ مكتوبٌ هنا صراحةً لا `GiftCard::DEFAULT_PRICE`.
         *
         * توكيدٌ يقرأ الثابتَ يقارنه بنفسه: من بدّله إلى صفرٍ بدّل طرفَي
         * المقارنة معًا فمرّ — وهو بالضبط ما نجا من الطفرات أوّل مرّة.
         * و«خمسُ مئةِ بيسة» رقمٌ اختاره صاحبُ المحلّ، فيُكتب كما قاله.
         */
        $this->settings(['store_gift_card_price' => '']);
        $this->assertSame(0.5, GiftCard::price($this->shop->id));

        $this->settings(['store_gift_card_price' => '0']);
        $this->assertSame(0.0, GiftCard::price($this->shop->id));

        $this->settings(['store_gift_card_price' => '1.25']);
        $this->assertSame(1.25, GiftCard::price($this->shop->id));
    }

    /* ═══════════ والدفترُ يقرؤه ═══════════ */

    /** ثمنُه إيرادٌ كأيّ بيع — ولا قيدَ تكلفةٍ له */
    public function test_the_card_is_revenue_like_any_sale(): void
    {
        $this->place(['gift_card' => true])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->firstOrFail();
        $card = $order->items->firstWhere('name', GiftCard::PRODUCT_NAME);

        $this->assertSame(0.0, (float) $card->cost, 'كُتبت له تكلفةٌ لا يدفعها المحلّ');
        // والطلبُ كلُّه في الدفتر بمجموعه — الباقةُ والكرتُ معًا
        $this->assertSame(20.5, (float) $order->total);
    }

    /** وبيعُه يُعدّ في تقرير الأصناف */
    public function test_the_shop_can_count_how_many_it_sold(): void
    {
        $this->place(['gift_card' => true])->assertOk();
        $this->place(['gift_card' => true])->assertOk();
        $this->place()->assertOk();

        $card = GiftCard::product($this->shop->id);
        $sold = OrderItem::whereIn('order_id', Order::where('business_id', $this->shop->id)->sold()->select('id'))
            ->where('product_id', $card->id)->sum('quantity');

        $this->assertSame(2, (int) $sold, 'لا يُعرف كم كرتًا بيع');
    }
}
