<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\FlowerOrder;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الطلبُ الذي يُنفَّذ لاحقًا له موعد — وإلّا اختفى.
 *
 * ═══ ما كان يقع ═══
 *
 * `Order::awaitingPreparation` تشترط الموعد لدخول اللوحة، وصندوقُ البيع يكتب
 * «مكتمل» لكلّ بيعةٍ بلا موعد. فطلبُ توصيلٍ حُفظ بلا موعد يُولد مغلقًا ولا
 * يُرى على لوحة التجهيز أبدًا: له مستلِمٌ وهاتفٌ وعنوان، ولا أحد يصنعه.
 *
 * وشاشةُ الصندوق كانت ترفضه («موعد التسليم مطلوب للطلبات التي تُجهَّز»)
 * والخادمُ يقبله. وقاعدةٌ مكتوبةٌ في الشاشة وحدها ليست قاعدة — هي عادةُ
 * متصفّحٍ واحد، يتخطّاها كلّ ما لا يمرّ من ذلك المتصفّح.
 *
 * وأخطرُ منه بابُ التعديل: طلبٌ قائمٌ على اللوحة يُفرَّغ موعدُه من «تفاصيل
 * الطلب» فيسقط منها صامتًا، ويبقى «قيد التجهيز» لا يجهّزه أحد.
 *
 * ═══ وملاحظةُ السطر تُقرأ ═══
 *
 * «بلا ورد أحمر» يكتبها الكاشير على الصنف، ويرسلها الخادم إلى اللوحة، ولا
 * ترسمها البطاقة. الاسمُ يقول ماذا يُصنَع، والملاحظةُ تقول كيف.
 */
class AnOrderThatIsPreparedHasADateTest extends TestCase
{
    use RefreshDatabase;

    private const BOARD = 'resources/js/Pages/Admin/Preparation/Index.tsx';

    private const DIALOG = 'resources/js/Pages/Pos/partials/PaymentDialog.tsx';

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->actingAs($this->owner);
    }

    /** @param  array<string, mixed>  $extra */
    private function sell(array $extra = [])
    {
        return $this->postJson(route('pos.checkout'), array_merge([
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 1]],
            'payment_method' => 'نقدي',
            'customer' => 'خالد المشتري',
        ], $extra));
    }

    /** طلبٌ حيٌّ كامل — يُستعمل لبابِ التعديل */
    private function scheduled(): Order
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة',
            'recipient_phone' => '91234567',
            'delivery_address' => 'الخوير، شارع ١٨',
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertOk();

        return Order::where('business_id', $this->business->id)->latest('id')->firstOrFail();
    }

    /* ------------------------------ بابُ الصندوق ------------------------------ */

    public function test_a_delivery_order_without_a_date_is_refused(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة',
            'recipient_phone' => '91234567',
            'delivery_address' => 'الخوير، شارع ١٨',
        ])->assertStatus(422)->assertJsonValidationErrors('scheduled_for');

        $this->assertSame(0, Order::where('business_id', $this->business->id)->count());
    }

    /** والاستلام من المحلّ مثلُه: هو أيضًا لا يُصنع في لحظته */
    public function test_a_pickup_order_without_a_date_is_refused(): void
    {
        $this->sell(['fulfillment_type' => FlowerOrder::PICKUP])
            ->assertStatus(422)->assertJsonValidationErrors('scheduled_for');
    }

    /**
     * وبيعةُ المنضدة تبقى ثلاث نقرات.
     *
     * أخطرُ ما في قاعدةٍ كهذه أن تتسرّب إلى كلّ بيعة: من يبيع عبوة ماءٍ لا
     * يُسأل عن موعد تسليمها.
     */
    public function test_a_counter_sale_needs_no_date(): void
    {
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 1]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        $this->assertSame(OrderStatus::COMPLETED,
            Order::where('business_id', $this->business->id)->latest('id')->value('status'));
    }

    /* ------------------------------ بابُ التعديل ------------------------------ */

    public function test_the_date_cannot_be_emptied_out_of_a_live_order(): void
    {
        $order = $this->scheduled();

        $this->put(route('admin.orders.details.update', $order->number), [
            'scheduled_for' => '',
        ])->assertSessionHasErrors('scheduled_for');

        $this->assertNotNull($order->fresh()->scheduled_for, 'الطلبُ فقد موعدَه فسقط من اللوحة');
    }

    /** ولا يسقط الطلب من اللوحة بعد المحاولة */
    public function test_the_order_is_still_on_the_board_after_the_refusal(): void
    {
        $order = $this->scheduled();

        $this->put(route('admin.orders.details.update', $order->number), ['scheduled_for' => '']);

        $numbers = collect($this->board())->pluck('number')->all();
        $this->assertContains($order->number, $numbers);
    }

    /** وتصحيحُ عنوانٍ لا يُطالَب بموعدٍ جديد: المحفوظ هو المعتبَر */
    public function test_a_correction_that_does_not_touch_the_date_still_saves(): void
    {
        $order = $this->scheduled();

        $this->put(route('admin.orders.details.update', $order->number), [
            'delivery_address' => 'الخوير، شارع 20',
        ])->assertSessionHasNoErrors();

        $this->assertSame('الخوير، شارع 20', $order->fresh()->delivery_address);
    }

    /* ------------------------------ حارسان لا يفترقان ------------------------------ */

    /**
     * الشاشةُ والخادمُ يقولان الجملة نفسها.
     *
     * وهي كانت مكتوبةً في الشاشة وحدها — فحُذفت من الشاشة يومًا لَما بقي
     * لها أثر، أو بقيت في الشاشة والخادم يقبل، وهو ما وقع.
     */
    public function test_the_screen_and_the_server_refuse_with_the_same_words(): void
    {
        $refusal = 'موعد التسليم مطلوب للطلبات التي تُجهَّز.';

        $this->assertStringContainsString($refusal, file_get_contents(base_path(self::DIALOG)));

        // والخادمُ يترجمها كغيرها — فالمقارنة على المصدر المترجَم لا على النصّ
        $errors = FlowerOrder::afterValidation(['fulfillment_type' => FlowerOrder::PICKUP]);
        $this->assertSame(__($refusal), $errors['scheduled_for'] ?? null);
    }

    /* ------------------------------ ملاحظةُ السطر ------------------------------ */

    public function test_the_line_note_reaches_the_board(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::PICKUP,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
            'items' => [[
                'id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 1,
                'note' => 'بلا ورد أحمر',
            ]],
        ])->assertOk();

        $this->assertSame('بلا ورد أحمر', $this->board()[0]['items'][0]['note']);
    }

    /** ويرسمها من يقف عند الطاولة — لا تصل الشاشةَ لتُطرح */
    public function test_the_board_draws_the_line_note(): void
    {
        $this->assertStringContainsString('{i.note && (', file_get_contents(base_path(self::BOARD)));
    }

    /** @return array<int, array<string, mixed>> */
    private function board(): array
    {
        return $this->get(route('admin.preparation.index'))->viewData('page')['props']['orders'];
    }
}
