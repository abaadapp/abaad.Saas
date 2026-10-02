<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ملاحظةُ الطلب العامّة تصل طاولةَ التجهيز — كما كُتبت.
 *
 * ═══ الحال التي أنشأت هذا الملفّ ═══
 *
 * `orders.notes` تُقرأ في شاشة المبيعات تحت «ملاحظات الطلب»، ولم تكن
 * تُرسَل إلى لوحة التجهيز. فمن يجهّز لا يرى «جهّزها قبل الخامسة» ولا
 * «اكتب JOHN على الكرت» — ويخرج الطلبُ بلا ما طُلب فيه.
 *
 * ═══ وما يُحرَس ═══
 *
 *  · النصُّ يصل كما كُتب، عربيًّا أو إنجليزيًّا، ولا تغيّره لغةُ الواجهة.
 *  · والملاحظاتُ الأخرى باقيةٌ على حالها: التوصيلُ والداخليّةُ وملاحظةُ
 *    البند والكرت.
 *  · ولا مالَ يصل الطاولة.
 */
class ThePrepBenchReadsTheOrderNotesAsWrittenTest extends TestCase
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
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'notes@abaad.test',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->travelTo(today()->setTime(9, 0));
        $this->actingAs($this->owner);
    }

    private function order(array $extra = [], ?string $itemNote = null): Order
    {
        $order = Order::create(array_merge([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'INV-'.uniqid(), 'status' => OrderStatus::CONFIRMED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3),
        ], $extra));

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'باقة',
            'price' => 25, 'cost' => 10, 'quantity' => 1, 'total' => 25, 'note' => $itemNote,
        ]);

        return $order;
    }

    /** بطاقةُ الطلب كما تصل اللوحة */
    private function card(Order $order): array
    {
        $cards = collect($this->get(route('admin.preparation.index'))->viewData('page')['props']['orders']);

        return $cards->firstWhere('number', $order->number);
    }

    /** ١ — العربيّةُ كما كُتبت */
    public function test_an_arabic_order_note_reaches_the_bench_as_written(): void
    {
        $order = $this->order(['notes' => 'الرجاء تجهيزها بعناية']);

        $this->assertSame('الرجاء تجهيزها بعناية', $this->card($order)['order_notes']);
    }

    /** ٢ — والإنجليزيّةُ كما كُتبت */
    public function test_an_english_order_note_reaches_the_bench_as_written(): void
    {
        $order = $this->order(['notes' => 'Please write JOHN on the card']);

        $this->assertSame('Please write JOHN on the card', $this->card($order)['order_notes']);
    }

    /** ٣ — ولغةُ الواجهة لا تُترجم نصَّ صاحبه */
    public function test_the_interface_language_does_not_translate_the_note(): void
    {
        $arabic = $this->order(['notes' => 'ملاحظات']);   // نصٌّ له ترجمةٌ في المعجم عمدًا
        $english = $this->order(['notes' => 'Notes']);

        foreach (['ar', 'en'] as $locale) {
            $this->post(route('admin.language.update'), ['locale' => $locale]);

            $this->assertSame('ملاحظات', $this->card($arabic)['order_notes'], "تُرجمت الملاحظة بواجهة {$locale}");
            $this->assertSame('Notes', $this->card($english)['order_notes'], "تُرجمت الملاحظة بواجهة {$locale}");
        }
    }

    /** ٧ — وطلبٌ بلا ملاحظة يصل `null` لا فراغًا يُرسم */
    public function test_an_order_without_a_note_sends_null(): void
    {
        $order = $this->order();

        $this->assertNull($this->card($order)['order_notes']);
    }

    /** ٨ — والملاحظاتُ الأخرى على حالها، كلٌّ في حقله */
    public function test_the_other_notes_stay_where_they_were(): void
    {
        $order = $this->order([
            'notes' => 'عامّة',
            'delivery_notes' => 'اتصل قبل الوصول',
            'internal_notes' => 'VIP customer',
            'card_message' => 'كل عام وأنت بخير',
            'customer_name' => 'سارة',
            'recipient_name' => 'نورة',
            'sender_name' => 'أحمد',
        ], itemNote: 'بلا ورد أحمر');

        $card = $this->card($order);

        $this->assertSame('عامّة', $card['order_notes']);
        $this->assertSame('اتصل قبل الوصول', $card['delivery_notes']);
        $this->assertSame('VIP customer', $card['internal_notes']);
        $this->assertSame('كل عام وأنت بخير', $card['card_message']);
        $this->assertSame('بلا ورد أحمر', $card['items'][0]['note']);
        $this->assertSame('سارة', $card['customer']);
        $this->assertSame('نورة', $card['recipient']);
        $this->assertSame('أحمد', $card['sender']);
    }

    /**
     * ٩ — وما كلّف المتجرَ لا يصل الطاولة.
     *
     * والإجماليُّ وسعرُ البند صارا عليها: من يجهّز يسلّم ويُسأل عمّا يُحصَّل
     * (قرارُ المالك 2026-10-02). والتكلفةُ ومكوّناتُ الفاتورة لا.
     */
    public function test_no_cost_reaches_the_bench(): void
    {
        $card = $this->card($this->order(['notes' => 'x']));

        foreach (['cost', 'subtotal', 'tax', 'discount', 'profit'] as $money) {
            $this->assertArrayNotHasKey($money, $card, "وصل «{$money}» إلى البطاقة");
            $this->assertArrayNotHasKey($money, $card['items'][0], "وصل «{$money}» إلى البند");
        }
    }
}
