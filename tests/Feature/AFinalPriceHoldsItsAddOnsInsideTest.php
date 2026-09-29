<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Business;
use App\Models\CustomOrderTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ثمنُ البند كما يقبضه الخادم — الحالاتُ نفسُها التي تحسبها السلّة.
 *
 * ═══ ولمَ هذا الملفّ ═══
 *
 * كانت السلّةُ تجمع إضافاتِ «السعر النهائيّ» فوقه (١٧٫٢٠٠ + ٠٫٢٠٠)، والخادمُ
 * لا يجمعها (`SaleLines`: `addons_total` صفرٌ في هذا الوضع). فقرأ الكاشيرُ رقمًا
 * وقبض الخادمُ غيرَه. وأُصلحت السلّة (`cartLineTotal`) ولم يُمسّ الخادم.
 *
 * فالحالاتُ في `tests/fixtures/pos-line-totals.json` يقرؤها هذا الملفّ ويقرؤها
 * `tests/js/a-final-price-holds-its-add-ons-inside.test.tsx`: يوم يفترق
 * الطرفان في حالةٍ منها يسقط أحدُهما — لا يبقى رقمان لبيعةٍ واحدة.
 *
 * وحارسُ الخادم الأصليّ باقٍ كما هو:
 * `AnArrangementIsBuiltAtTheCounterTest::test_a_final_budget_does_not_move_when_addons_are_chosen`.
 */
class AFinalPriceHoldsItsAddOnsInsideTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $cashier;

    private Addon $card;

    private Product $bouquet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->card = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'كرت',
            'price' => self::shared()['addon_price'], 'active' => true,
        ]);
        $this->bouquet = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 10, 'cost' => 4,
            'quantity' => 50, 'active' => true,
        ]);

        CustomOrderTemplate::ensureDefault($this->shop->id);
    }

    /** @return array{addon_price: float, cases: list<array<string, mixed>>} */
    private static function shared(): array
    {
        // والمسارُ من موضع الملفّ لا `base_path`: المزوّدُ يُقرأ قبل أن يقوم التطبيق
        return json_decode((string) file_get_contents(__DIR__.'/../fixtures/pos-line-totals.json'), true);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        foreach (self::shared()['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** البندُ كما ترسله السلّة لكلّ حالة */
    private function item(array $c): array
    {
        $addons = $c['addon_qty'] > 0 ? [['addon_id' => $this->card->id, 'qty' => $c['addon_qty']]] : [];

        return match ($c['kind']) {
            'standalone' => ['addon_id' => $this->card->id, 'name' => 'كرت', 'price' => $c['price'], 'qty' => $c['qty']],
            'product' => ['id' => $this->bouquet->id, 'name' => 'باقة', 'price' => $c['price'], 'qty' => $c['qty'], 'addons' => $addons],
            'custom' => [
                'name' => 'طلب مخصص', 'qty' => $c['qty'], 'addons' => $addons,
                'custom' => ['mode' => $c['mode'], 'price' => $c['price'], 'components' => []],
            ],
        };
    }

    private function sell(array $item, array $extra = [])
    {
        return $this->actingAs($this->cashier)->postJson('/pos/checkout', array_merge([
            'items' => [$item],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('c', true),
        ], $extra));
    }

    private function order(): Order
    {
        return Order::latest('id')->with('items.addons')->firstOrFail();
    }

    #[DataProvider('cases')]
    public function test_the_server_charges_what_the_till_shows(array $case): void
    {
        $this->sell($this->item($case))->assertOk();

        $this->assertEqualsWithDelta($case['line'], (float) $this->order()->subtotal, 0.0005, "«{$case['name']}» قبض الخادمُ غير ما تعرضه السلّة");
    }

    /**
     * والتوصيلُ يُضاف فوق السعر النهائيّ مرّةً — والإضافةُ لا تُضاف معه.
     *
     * ١٧٫٢٠٠ للطلب كلّه ومعه كرت، وتوصيلٌ باثنين: ١٩٫٢٠٠ قبل الضريبة لا
     * ١٩٫٤٠٠. والضريبةُ (٥٪ بإعدادات المتجر الافتراضيّة) على ١٧٫٢٠٠ — ٠٫٨٦٠
     * لا ٠٫٨٧٠.
     */
    public function test_delivery_sits_on_top_of_a_final_price_once(): void
    {
        $budget = collect(self::shared()['cases'])->firstWhere('name', 'سعرٌ نهائيّ');

        $this->sell($this->item($budget), ['delivery_fee' => 2])->assertOk();

        $order = $this->order();
        $this->assertEqualsWithDelta(17.2, (float) $order->subtotal, 0.0005);
        $this->assertEqualsWithDelta(0.86, (float) $order->tax, 0.0005, 'الضريبةُ ليست على السعر النهائيّ وحده');
        $this->assertEqualsWithDelta(19.2 + 0.86, (float) $order->total, 0.0005);
        // والكرتُ باقٍ على البند — اختيارٌ يُحفظ لا ثمنٌ يُدفع
        $this->assertCount(1, $order->items->first()->addons);
        $this->assertEqualsWithDelta(0.0, (float) $order->items->first()->addons_total, 0.0005);
    }
}
