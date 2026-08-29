<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\BackupService;
use App\Support\FlowerOrder;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * طلب الورد: مشترٍ، ومستلِمٌ آخر، وموعدٌ في المستقبل.
 *
 * محلّ الورد يبيع شيئين في بيعةٍ واحدة، والفاتورة كانت تعرف الأوّل وحده —
 * فبقي الباقي يُكتب في «ملاحظات» نصًّا حرًّا لا يُرشَّح ولا يُرتَّب عليه.
 *
 * وأهمّ ما يُحرَس هنا شيئان: أنّ بيعة المنضدة لم تُثقَل بحقولٍ لا تخصّها،
 * وأنّ التوصيل لا يمرّ بلا مستلِمٍ وعنوان.
 */
class FlowerOrderTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 25, 'cost' => 10,
            'quantity' => 100, 'alert_qty' => 2,
        ]);

        $this->actingAs($this->owner);
        session(['current_branch' => Branch::where('business_id', $this->business->id)->value('id')]);
    }

    /** @param  array<string, mixed>  $extra */
    private function sell(array $extra = [], int $qty = 1)
    {
        return $this->postJson(route('pos.checkout'), array_merge([
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => $qty]],
            'payment_method' => 'نقدي',
        ], $extra));
    }

    private function lastOrder(): Order
    {
        return Order::where('business_id', $this->business->id)->latest('id')->firstOrFail();
    }

    /* ------------------------- بيعة المنضدة تبقى سريعة ------------------------- */

    /**
     * بلا حقلٍ واحد من حقول الورد — وهي أكثر بيعات اليوم.
     *
     * لو صار أيٌّ منها إلزاميًّا لَملأه الكاشير بأيّ شيء ليتخلّص منه، فتصير
     * البيانات أسوأ من غيابها.
     */
    public function test_a_walk_in_sale_needs_none_of_the_new_fields(): void
    {
        $this->sell()->assertOk()->assertJson(['ok' => true]);

        $order = $this->lastOrder();
        $this->assertNull($order->scheduled_for);
        $this->assertNull($order->recipient_name);
        // بيعةٌ دُفعت وأُخذت في اللحظة نفسها: مكتملة، ولا شيء فيها يُجهَّز
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
    }

    /* ------------------------------- الاستلام ------------------------------- */

    public function test_a_pickup_order_keeps_its_details(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::PICKUP,
            'recipient_name' => 'سارة',
            'recipient_phone' => '91234567',
            'scheduled_for' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'occasion_type' => 'birthday',
        ])->assertOk();

        $order = $this->lastOrder();
        $this->assertSame(FlowerOrder::PICKUP, $order->fulfillment_type);
        $this->assertSame('سارة', $order->recipient_name);
        $this->assertSame('birthday', $order->occasion_type);
        $this->assertNotNull($order->scheduled_for);
    }

    /** والاستلام لا يُسأل عن عنوان */
    public function test_a_pickup_order_needs_no_address(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::PICKUP,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertOk();
    }

    /* ------------------------------- التوصيل ------------------------------- */

    public function test_a_delivery_order_is_refused_without_a_recipient(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'delivery_address' => 'الخوير، شارع ١٨',
        ])->assertStatus(422)->assertJsonValidationErrors(['recipient_name', 'recipient_phone']);

        $this->assertSame(0, Order::where('business_id', $this->business->id)->count());
    }

    public function test_a_delivery_order_is_refused_without_an_address(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة',
            'recipient_phone' => '91234567',
        ])->assertStatus(422)->assertJsonValidationErrors(['delivery_address']);
    }

    public function test_a_complete_delivery_order_is_accepted(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة',
            'recipient_phone' => '+968 9123 4567',
            'delivery_address' => 'الخوير، شارع ١٨',
            'delivery_notes' => 'الباب الأزرق',
            'delivery_fee' => 3,
            'scheduled_for' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertOk();

        $order = $this->lastOrder();
        $this->assertSame('الخوير، شارع ١٨', $order->delivery_address);
        $this->assertSame('الباب الأزرق', $order->delivery_notes);
        $this->assertEquals(3, (float) $order->delivery_fee);
    }

    /** ورسوم توصيلٍ صفر ليست غيابًا: استلامٌ مجّانيّ يُسجَّل صفرًا */
    public function test_a_zero_delivery_fee_is_accepted(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة', 'recipient_phone' => '91234567',
            'delivery_address' => 'صحار', 'delivery_fee' => 0,
        ])->assertOk();

        $this->assertEquals(0, (float) $this->lastOrder()->delivery_fee);
    }

    /* -------------------------- المشتري غير المستلِم -------------------------- */

    public function test_the_buyer_and_the_recipient_are_two_people(): void
    {
        $this->sell([
            'customer' => 'محمد',
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة', 'recipient_phone' => '91234567',
            'delivery_address' => 'مسقط',
        ])->assertOk();

        $order = $this->lastOrder();
        $this->assertSame('محمد', $order->customer_name);
        $this->assertSame('سارة', $order->recipient_name);
    }

    /** ولا يُنشأ للمستلِم عميل: من يُهدى إليه مرّةً ليس زبونًا */
    public function test_no_customer_row_is_created_for_the_recipient(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة المستلِمة', 'recipient_phone' => '91234567',
            'delivery_address' => 'مسقط',
        ])->assertOk();

        $this->assertFalse(
            Customer::where('name', 'سارة المستلِمة')->exists(),
            'أُنشئ عميلٌ للمستلِم فتضخّمت قائمة العملاء بمن لم يشترِ'
        );
    }

    /* ------------------------------ بطاقة الإهداء ------------------------------ */

    public function test_the_card_and_its_sender_are_kept(): void
    {
        $this->sell([
            'card_message' => 'كل عام وأنتِ بخير',
            'sender_name' => 'محمد',
        ])->assertOk();

        $order = $this->lastOrder();
        $this->assertSame('كل عام وأنتِ بخير', $order->card_message);
        $this->assertSame('محمد', $order->sender_name);
        $this->assertFalse($order->hide_sender);
    }

    /**
     * وإخفاء المُهدي يُطاع في مخرَج المستلِم لا في الشاشة وحدها.
     *
     * من أخفى اسمه ثم قرأه المستلِمُ على البطاقة لم يُخدَع في ميزة — بل في
     * سرٍّ ائتمن النظامَ عليه.
     */
    public function test_a_hidden_sender_is_absent_from_what_the_recipient_reads(): void
    {
        $this->sell([
            'card_message' => 'من محبّك',
            'sender_name' => 'محمد',
            'hide_sender' => true,
        ])->assertOk();

        $order = $this->lastOrder();
        $this->assertTrue($order->hide_sender);
        $this->assertSame('محمد', $order->sender_name, 'الاسم يبقى محفوظًا للموظّف');

        $card = FlowerOrder::cardForRecipient($order);
        $this->assertNull($card['sender'], 'اسم المُهدي وصل إلى المستلِم رغم طلب إخفائه');
        $this->assertSame('من محبّك', $card['message']);
    }

    /* ------------------------------- الموعد ------------------------------- */

    /** طلبٌ لموعدٍ قادم يدخل مسار التجهيز لا يُغلَق فور دفعه */
    public function test_a_future_order_starts_as_pending_not_completed(): void
    {
        $this->sell([
            'scheduled_for' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ])->assertOk();

        $this->assertSame(OrderStatus::PENDING, $this->lastOrder()->status);
    }

    /** و`ordered_at` غير `scheduled_for`: أحدهما التسجيل والآخر التنفيذ */
    public function test_the_order_time_and_the_delivery_time_are_two_columns(): void
    {
        $when = now()->addDays(5)->startOfHour();
        $this->sell(['scheduled_for' => $when->format('Y-m-d H:i:s')])->assertOk();

        $order = $this->lastOrder();
        $this->assertTrue($order->ordered_at->isToday());
        $this->assertSame($when->format('Y-m-d H:i'), $order->scheduled_for->format('Y-m-d H:i'));
    }

    /* ------------------------------- التحقّق ------------------------------- */

    public function test_an_unknown_occasion_is_refused(): void
    {
        $this->sell(['occasion_type' => 'لا-مناسبة'])->assertStatus(422);
    }

    public function test_an_invalid_schedule_is_refused(): void
    {
        $this->sell(['scheduled_for' => 'ليس تاريخًا'])->assertStatus(422);
    }

    /**
     * والهاتف الدوليّ يمرّ.
     *
     * الزبون يُهدي إلى دبي وإلى الرياض، ونمطٌ عمانيّ ضيّق يجعل الكاشير يكتب
     * الرقم في «ملاحظات» — فيخرج من كل ترشيحٍ وكل تقرير.
     */
    public function test_international_numbers_are_accepted(): void
    {
        foreach (['91234567', '+968 9123 4567', '+971-50-123-4567', '(966) 512345678'] as $phone) {
            $this->sell([
                'fulfillment_type' => FlowerOrder::DELIVERY,
                'recipient_name' => 'سارة', 'recipient_phone' => $phone,
                'delivery_address' => 'عنوان',
            ])->assertOk("رُفض رقمٌ صحيح: {$phone}");
        }
    }

    public function test_a_nonsense_phone_is_refused(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة', 'recipient_phone' => 'اتّصل بي',
            'delivery_address' => 'عنوان',
        ])->assertStatus(422)->assertJsonValidationErrors('recipient_phone');
    }

    public function test_an_overlong_card_message_is_refused(): void
    {
        $this->sell(['card_message' => str_repeat('ا', FlowerOrder::CARD_MAX + 1)])->assertStatus(422);
    }

    /* --------------------------- ما لا يجوز أن ينكسر --------------------------- */

    /** الأسعار من القاعدة لا من الطلب — والحقول الجديدة لم تفتح ثغرة */
    public function test_prices_still_come_from_the_database(): void
    {
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 2, 'price' => 1]],
            'payment_method' => 'نقدي',
            'recipient_name' => 'سارة',
        ])->assertOk();

        // ٢٥ × ٢ لا ١ × ٢
        $this->assertEquals(50, (float) $this->lastOrder()->subtotal);
    }

    /** والمخزون ينقص كما كان */
    public function test_stock_still_moves(): void
    {
        $this->sell(['recipient_name' => 'سارة'], qty: 3)->assertOk();

        $this->assertEquals(97, (int) $this->product->fresh()->quantity);
    }

    /**
     * وتفاصيل الطلب تنجو من النسخة الاحتياطية واستعادتها.
     *
     * النسخة تُصدَّر بـ`toArray()` والاستعادة تُنشئ بـ`create()`، فالأعمدة
     * الجديدة تمرّ بلا تعديلٍ في المصدّر. لكنّ «تمرّ تلقائيًّا» فرضٌ لا يُوثق
     * به في شيءٍ يُستعاد يوم يُفقد كلّ شيء: يوم تُقرأ النسخة لا يبقى ما
     * يُقارَن به.
     */
    public function test_the_details_survive_a_backup_and_restore(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة', 'recipient_phone' => '91234567',
            'delivery_address' => 'الخوير', 'card_message' => 'كل عام وأنتِ بخير',
            'sender_name' => 'محمد', 'hide_sender' => true,
            'occasion_type' => 'birthday',
            'scheduled_for' => '2026-09-03 18:30:00',
        ])->assertOk();

        $dump = BackupService::payload($this->business->id);
        $row = collect($dump['orders'])->firstOrFail();

        foreach ([
            'recipient_name' => 'سارة',
            'delivery_address' => 'الخوير',
            'card_message' => 'كل عام وأنتِ بخير',
            'occasion_type' => 'birthday',
        ] as $field => $value) {
            $this->assertSame($value, $row[$field] ?? null,
                "«{$field}» لم يدخل النسخة الاحتياطية");
        }
        $this->assertTrue((bool) $row['hide_sender'], 'إخفاء المُهدي لم يدخل النسخة');
        $this->assertStringContainsString('2026-09-03', (string) $row['scheduled_for']);
    }

    /** والرفع المكرَّر لا يُنشئ طلبًا ثانيًا */
    public function test_the_same_upload_twice_makes_one_order(): void
    {
        $payload = ['client_uuid' => 'abc-123', 'recipient_name' => 'سارة'];
        $this->sell($payload)->assertOk();
        $this->sell($payload)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, Order::where('business_id', $this->business->id)->count());
    }
}
