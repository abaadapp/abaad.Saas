<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\SalesChannel;
use App\Support\Store\GiftCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * كرتُ الهدية يُكتب أو يُرفع — لا الاثنان.
 *
 * ═══ العطبُ الذي يحرسه هذا ═══
 *
 * صندوقُ الكرت في إتمام الطلب كان يعرض خانةَ كتابةٍ **ورفعَ ملفٍّ معًا**،
 * والخادمُ يقبلهما معًا ويكتبهما على الطلب: `card_message` و`card_file`.
 *
 * فمن يطبع الكرتَ يقرأ سطرًا في الطلب وملفًّا مرفقًا يقولان شيئين
 * مختلفين، ولا شيءَ يقول أيُّهما المقصود. فيختار بظنّه — أو يطبع الاثنين
 * على كرتٍ واحد.
 *
 * ═══ والفراغُ يبقى مقبولًا ═══
 *
 * من يشتري الكرتَ ليكتبه بيده في المحلّ يطلبه بلا نصٍّ ولا ملفّ — وهو
 * حالٌ موصوفٌ في `GiftCard` من قبل. **الممنوعُ الجمعُ لا الترك.**
 */
class AGiftCardIsWrittenOrUploadedNotBothTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');
        Storage::fake('local');

        $this->business = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->business->id);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $this->business->id, 'name' => 'سعود', 'email' => 'r@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->business->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '0',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => "9 ص – 12 م\n4 م – 8 م",
            'store_gift_card' => '1', 'store_gift_card_price' => '1.500',
        ]);

        $this->product = Product::create(['business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 20, 'cost' => 8, 'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** يرفع ملفًّا ويأخذ رمزَه كما تفعل الشاشة */
    private function uploadedToken(): string
    {
        $r = $this->postJson('/s/ribbon/gift-card', [
            'file' => UploadedFile::fake()->image('card.jpg'),
        ])->assertOk();

        return (string) $r->json('token');
    }

    private function checkout(array $extra = [])
    {
        return $this->postJson('/s/ribbon/checkout', array_merge([
            'items' => [['id' => $this->product->id, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-03', 'slot' => '9 ص – 12 م', 'gift_card' => true,
        ], $extra));
    }

    private function lastOrder(): Order
    {
        return Order::where('channel', SalesChannel::WEBSITE)->orderByDesc('id')->firstOrFail();
    }

    /* ═════════════ الجمعُ يُردّ ═════════════ */

    public function test_writing_and_uploading_together_is_refused(): void
    {
        $token = $this->uploadedToken();

        $this->checkout(['card' => 'كل عام وأنتِ بخير', 'card_file' => $token, 'card_file_name' => 'card.jpg'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('card');

        $this->assertSame(0, Order::where('channel', SalesChannel::WEBSITE)->count(),
            'طلبٌ مضى بنصٍّ وملفٍّ معًا — ولا يُعرف أيُّهما يُطبع');
    }

    /** والردُّ يقول ما يفعل — لا «غير صالح» */
    public function test_the_refusal_names_the_choice(): void
    {
        $token = $this->uploadedToken();

        $this->checkout(['card' => 'كل عام وأنتِ بخير', 'card_file' => $token, 'card_file_name' => 'card.jpg'])
            ->assertJsonPath('errors.card.0', 'اختر طريقةً واحدة للكرت: رسالةً تكتبها أو ملفًّا ترفعه.');
    }

    /* ═════════════ وكلُّ واحدةٍ وحدَها تمضي ═════════════ */

    public function test_a_written_message_alone_passes(): void
    {
        $this->checkout(['card' => 'كل عام وأنتِ بخير'])->assertOk();

        $order = $this->lastOrder();
        $this->assertSame('كل عام وأنتِ بخير', $order->card_message);
        $this->assertNull($order->card_file);
    }

    public function test_an_uploaded_file_alone_passes(): void
    {
        $this->checkout(['card_file' => $this->uploadedToken(), 'card_file_name' => 'card.jpg'])->assertOk();

        $order = $this->lastOrder();
        $this->assertNotNull($order->card_file, 'الملفُّ لم يُربط بالطلب');
        $this->assertSame('card.jpg', $order->card_file_name);
        $this->assertNull($order->card_message);
    }

    /** والكرتُ الفارغ يبقى مقبولًا: يُكتب بيده في المحلّ */
    public function test_a_blank_card_is_still_allowed(): void
    {
        $this->checkout()->assertOk();

        $order = $this->lastOrder();
        $this->assertNull($order->card_message);
        $this->assertNull($order->card_file);
    }

    /* ═════════════ والشاشةُ تعرضها اختيارًا لا حقلين ═════════════ */

    /**
     * والمقياسُ على الصفحة كما تُرسَل — لا على نيّةٍ في الكود.
     *
     * فالخادمُ يردّ الجمعَ، لكنّ شاشةً تعرض الحقلين معًا تدعو إليه ثمّ تردّ
     * من لبّاها. والاختيارُ يُعرض قبل أن يُملأ شيء.
     */
    public function test_the_screen_offers_a_choice_not_two_fields(): void
    {
        $html = $this->get('/s/ribbon/checkout')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="rb-card-way-text"', $html);
        $this->assertStringContainsString('data-testid="rb-card-way-file"', $html);
    }

    /** وصندوقُ الملفّ مطويٌّ حتّى تُختار طريقتُه — فلا يُملأ الاثنان */
    public function test_the_file_side_starts_folded(): void
    {
        $html = $this->get('/s/ribbon/checkout')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-rb-cardfilebox[^>]*style="display:none/',
            $html,
            'رفعُ الملفّ معروضٌ مع الكتابة — فيُملأ الاثنان ثمّ يُردّ الطلب',
        );
    }

    /** والكتابةُ هي المختارةُ ابتداءً: أكثرُ ما يُطلب، ولا يُجبَر على اختيار */
    public function test_writing_is_the_default_way(): void
    {
        $html = $this->get('/s/ribbon/checkout')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/class="rb-pill on" data-v="text"/',
            $html,
        );
    }

    /* ═════════════ وثمنُه يكتبه صاحبُ المحلّ ═════════════ */

    /** وسطرُ الكرت في الفاتورة بالثمن الذي كتبه — لا بافتراضٍ */
    public function test_the_card_is_billed_at_the_price_the_merchant_set(): void
    {
        $this->checkout(['card' => 'مبروك'])->assertOk();

        $line = $this->lastOrder()->items()->where('name', GiftCard::PRODUCT_NAME)->firstOrFail();
        $this->assertEquals(1.5, (float) $line->price, 'الكرتُ بِيع بغير ثمنه المكتوب');
    }

    /** ومن لم يطلب كرتًا لا يُحاسَب عليه */
    public function test_no_card_no_line(): void
    {
        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $this->product->id, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ])->assertOk();

        $this->assertSame(0, $this->lastOrder()->items()->where('name', GiftCard::PRODUCT_NAME)->count());
    }
}
