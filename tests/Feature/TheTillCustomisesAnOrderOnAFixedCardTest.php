<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomOrderTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\CustomArrangement;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\FlowerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تخصيصُ الطلب من بطاقةٍ ثابتة — ما تُرسله النافذةُ الجديدة يُقبل ويُحفظ.
 *
 * ═══ ما تغيّر في الشاشة وما يحرسه هذا الملفّ ═══
 *
 * النافذةُ صارت تُرسل: وضعًا «نهائيًّا» بسعرٍ كتبه الكاشير، وتفاصيلَ نصّيّةً
 * في `items.*.note`، وموادَّ اختياريّةً قد تكون صفرًا. فالحارسُ هنا يرسل
 * **هذه الحمولةَ بالحرف** ويطلب أن تُقبل وأن تصل التفاصيلُ إلى لوحة
 * التجهيز وورقة الزبون — لا حمولةَ الشاشة القديمة التي كانت تُلزم بالموادّ.
 */
class TheTillCustomisesAnOrderOnAFixedCardTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $cashier;

    private Product $rose;

    private CustomOrderTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوض']);

        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'ورد أحمر', 'sku' => 'ROSE-RED',
            'price' => 2, 'cost' => 0.5, 'quantity' => 50, 'active' => true,
        ]);

        $this->template = CustomOrderTemplate::ensureDefault($this->shop->id);
    }

    /** الحمولةُ كما تكتبها النافذةُ الجديدة: نهائيٌّ، تفاصيلُ، ولا موادَّ إلّا ما أُرسل */
    private function sell(array $custom = [], ?string $note = 'عشرون وردة حمراء، تغليف أسود، شريطة ذهبية', array $extra = [])
    {
        return $this->actingAs($this->cashier)->postJson('/pos/checkout', array_merge([
            'items' => [[
                'name' => 'طلب مخصص',
                'qty' => 1,
                'note' => $note,
                'addons' => [],
                'custom' => array_merge([
                    'template_id' => $this->template->id,
                    'mode' => CustomArrangement::MODE_BUDGET,
                    'price' => 25,
                    'base_value' => null,
                    'fields' => [],
                    'components' => [],
                ], $custom),
            ]],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('c', true),
        ], $extra));
    }

    private function order(): Order
    {
        return Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail()->load('items.components');
    }

    /* ══════════════ بلا موادّ — السعرُ المكتوب هو ما يُباع به ══════════════ */

    public function test_a_custom_line_with_details_and_no_materials_sells_at_the_written_price(): void
    {
        $this->sell()->assertOk();

        $item = $this->order()->items->sole();

        $this->assertSame('طلب مخصص', $item->name);
        $this->assertEqualsWithDelta(25.0, (float) $item->price, 0.001);
        // والضريبةُ فوقه كسائر البنود — ٥٪ الافتراضيّة على ٢٥
        $this->assertEqualsWithDelta(26.25, (float) $this->order()->total, 0.001);
        $this->assertSame('عشرون وردة حمراء، تغليف أسود، شريطة ذهبية', $item->note);
        $this->assertCount(0, $item->components);
        // ولا تكلفةَ تُخترع لموادّ لم تُذكر
        $this->assertEqualsWithDelta(0.0, (float) $item->cost, 0.001);
        // ولا يَنقص الرفُّ ممّا لم يُؤخذ منه
        $this->assertEqualsWithDelta(50.0, (float) $this->rose->fresh()->quantity, 0.001);
    }

    /** والزيادةُ التي ضغطها الكاشير على السعر هي ما يُحفظ — لا المحسوب */
    public function test_the_price_the_cashier_raised_is_the_price_that_is_kept(): void
    {
        $this->sell([
            'price' => 12,
            'components' => [['product_id' => $this->rose->id, 'quantity' => 1, 'restockable' => false]],
        ])->assertOk();

        $item = $this->order()->items->sole();

        // وردةٌ واحدة بريالين — والكاشير كتب ١٢
        $this->assertEqualsWithDelta(12.0, (float) $item->price, 0.001);
        $this->assertEqualsWithDelta(0.5, (float) $item->cost, 0.001);
        $this->assertEqualsWithDelta(49.0, (float) $this->rose->fresh()->quantity, 0.001);
    }

    /* ══════════════ التفاصيلُ تصل من يجهّز ومن يستلم ══════════════ */

    public function test_the_details_reach_the_preparation_board(): void
    {
        $this->sell([], 'شريطة ذهبية وكرت باسم مريم', [
            'fulfillment_type' => FlowerOrder::PICKUP,
            'scheduled_for' => now()->addHours(3)->toDateTimeString(),
            'customer' => 'مريم',
            'customer_phone' => '96891234567',
            'customer_language' => 'ar',
        ])->assertOk();

        $orders = $this->actingAs($this->cashier)
            ->get(route('admin.preparation.index'))
            ->viewData('page')['props']['orders'];

        $notes = collect($orders)->flatMap(fn ($o) => collect($o['items'])->pluck('note'))->all();

        $this->assertContains('شريطة ذهبية وكرت باسم مريم', $notes);
    }

    public function test_the_details_are_printed_on_the_customers_paper(): void
    {
        $this->sell([], 'شريطة ذهبية وكرت باسم مريم')->assertOk();

        $html = DocumentRenderer::saleSheet(
            $this->shop->id,
            $this->order(),
            DocumentTemplates::settings($this->shop->id, 'sale'),
        );

        $this->assertStringContainsString('شريطة ذهبية وكرت باسم مريم', $html);
    }

    /* ══════════════ الحدودُ التي تقف عندها الشاشة هي حدودُ الخادم ══════════════ */

    public function test_details_longer_than_the_field_are_refused_not_cut(): void
    {
        $this->sell([], str_repeat('و', 256))->assertStatus(422)->assertJsonValidationErrors(['items.0.note']);

        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
    }

    public function test_a_zero_final_price_is_refused(): void
    {
        $this->sell(['price' => 0])->assertStatus(422);
    }
}
