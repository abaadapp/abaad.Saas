<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «تعديل» فوق فاتورةٍ يعني الفاتورة.
 *
 * كان في شاشة الطلب زرٌّ واحد اسمه «تعديل»، ويفتح **ورقةَ التفاصيل**: اسمُ
 * المستلم، وموعدُ التسليم، ونصُّ البطاقة، والعنوان. ومن ضغطه باحثًا عن كميّةٍ
 * أدخلها خطأً — وهو أكثرُ من يضغط زرًّا بهذا الاسم فوق جدولِ أصناف — لم يجد
 * في النموذج حرفًا واحدًا يمسّ الرقم الذي فتح الصفحة من أجله. ولم يكن لمتن
 * الفاتورة بابٌ من هذه الشاشة أصلًا: التصحيح كلُّه خلف شاشة الكاشير، فصاحبُ
 * النشاط يقرأ فاتورته ولا يملك تصحيحها إلّا أن يقف على الصندوق.
 *
 * فصارا بابين باسمين: **تعديل الفاتورة** يمسّ ما بيع وبكم وبأيّ وسيلة دُفع،
 * و**ورقة التفاصيل** تمسّ التنفيذ وحده. وما يُحرَس هنا ثلاثة:
 *
 * ١ — أنّ البابَ الجديد يُدير شيئًا: يُصحَّح البند من شاشة المبيعات فيتحرّك
 *     الرفُّ والدفترُ ويبقى الأثر — بالكتابة نفسِها التي يُصحّح بها الكاشير.
 * ٢ — أنّ حُكمَه هو حكمُ الكاشير لا أوسع: `order.edit` ويومُ البيع، يُقاسان
 *     في الخادم لا في الشاشة.
 * ٣ — أنّ البابين لم يختلطا: تعديلُ الورقة لا يمسّ ريالًا، وتصحيحُ الفاتورة
 *     لا يمسّ اسمَ المستلم.
 */
class TheInvoiceAndItsSheetAreTwoDoorsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 4,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
    }

    /** بيعةٌ حقيقيّة من الصندوق — لا صفٌّ يُركَّب باليد */
    private function sell(int $qty = 3): Order
    {
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => $qty]],
            'payment_method' => 'نقدي',
            'customer' => 'خالد',
        ])->assertOk();

        return Order::where('business_id', $this->business->id)->latest('id')->firstOrFail();
    }

    /** موظّفٌ بدورٍ بعينه وصلاحياتٍ مكتوبةٍ بيدها */
    private function staff(string $role, ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'موظف', 'email' => $role.'@abaad.om',
            'password' => bcrypt('password12345'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    /* ==================== البابُ يُدير شيئًا ==================== */

    /**
     * تُصحَّح الكميّة من شاشة المبيعات — والفاتورةُ والرفُّ والأثر تتحرّك معًا.
     *
     * وهذا هو الفعلُ الذي لم يكن له بابٌ من هنا أصلًا.
     */
    public function test_a_line_is_corrected_from_the_sales_screen(): void
    {
        $order = $this->sell(3);
        $item = $order->items()->firstOrFail();
        $stockAfterSale = (int) $this->product->fresh()->quantity;

        $this->put(route('admin.orders.items.update', [$order->number, $item->id]), [
            'quantity' => 1,
            'reason' => 'أدخلتُ الكمية خطأً',
        ])->assertRedirect();

        $this->assertSame(1, (int) $item->fresh()->quantity);
        // وقطعتان تعودان إلى الرفّ — لا الفاتورةُ وحدها تُكتب
        $this->assertSame($stockAfterSale + 2, (int) $this->product->fresh()->quantity);
        // والأثرُ يبقى باسم من صحّح وبسببه
        $edit = OrderEdit::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('أدخلتُ الكمية خطأً', $edit->reason);
        $this->assertSame(3, (int) $edit->qty_before);
        $this->assertSame(1, (int) $edit->qty_after);
    }

    /** وتُصحَّح وسيلةُ الدفع من الشاشة نفسها */
    public function test_the_payment_method_is_corrected_from_the_sales_screen(): void
    {
        $order = $this->sell();

        $this->put(route('admin.orders.payment.update', $order->number), [
            'payment_method' => 'بطاقة',
            'reason' => 'دفع بالبطاقة وسجّلتُها نقدًا',
        ])->assertRedirect();

        $this->assertSame('بطاقة', $order->fresh()->payment_method);
    }

    /**
     * وسببٌ مكتوبٌ شرطُ التصحيح — لا يُصحَّح رقمٌ بلا جوابٍ عن «لماذا؟».
     *
     * وحارسُه في الخادم لا في النموذج: من نادى المسار دون الشاشة يُردّ.
     */
    public function test_a_correction_without_a_reason_is_refused(): void
    {
        $order = $this->sell(3);
        $item = $order->items()->firstOrFail();

        $this->put(route('admin.orders.items.update', [$order->number, $item->id]), [
            'quantity' => 1,
            'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->assertSame(3, (int) $item->fresh()->quantity);
    }

    /* ==================== وحكمُه حكمُ الكاشير لا أوسع ==================== */

    /**
     * من لا يملك `order.edit` يُردّ — ولو ملك قسم المبيعات كلَّه.
     *
     * وهذا هو الخطرُ الذي يجرّه بابٌ جديد: قسمُ «المبيعات» يُمنح لمن يتابع
     * الطلبات، وصلاحيةُ إعادة كتابة فاتورةٍ صدرت شيءٌ آخر.
     */
    public function test_the_sales_section_alone_does_not_rewrite_an_issued_invoice(): void
    {
        $order = $this->sell(3);
        $item = $order->items()->firstOrFail();

        $this->actingAs($this->staff('cashier', ['orders']))
            ->put(route('admin.orders.items.update', [$order->number, $item->id]), [
                'quantity' => 1,
                'reason' => 'محاولةُ من لا يملكها',
            ])->assertSessionHasErrors('permission');

        $this->assertSame(3, (int) $item->fresh()->quantity);
    }

    /** ولا يراه في شاشته أصلًا: بابٌ لا يُعرض خيرٌ من بابٍ يُقال لصاحبه «ليست لك» */
    public function test_the_door_is_not_drawn_for_who_may_not_open_it(): void
    {
        $order = $this->sell();

        $this->actingAs($this->staff('cashier', ['orders']))
            ->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($page) => $page
                ->where('invoiceEdit.can', false)
                ->where('invoiceEdit.reason', null));
    }

    /** ومن يملكها ويومُ فاتورته قائم يراه مفتوحًا */
    public function test_the_door_is_open_on_the_day_of_the_sale(): void
    {
        $order = $this->sell();

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($page) => $page->where('invoiceEdit.can', true));
    }

    /**
     * وفاتورةُ أمسٍ تُقرأ ولا تُصحَّح — والزرُّ يقول لمَ قبل الضغط.
     *
     * وطرفا الحدّ يُحرسان معًا: الشاشةُ تقوله، والخادمُ يردّ من نادى المسار.
     */
    public function test_yesterdays_invoice_says_why_before_the_click_and_refuses_after(): void
    {
        $order = $this->sell(3);
        $item = $order->items()->firstOrFail();
        $order->forceFill(['ordered_at' => now()->subDay()])->save();

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($page) => $page
                ->where('invoiceEdit.can', false)
                // ونصٌّ مكتوب لا `null`: زرٌّ معطَّلٌ بلا سببٍ بابٌ معروضٌ لا يُفتح
                ->where('invoiceEdit.reason', 'تعديل الفاتورة — انتهى يومها'));

        $this->put(route('admin.orders.items.update', [$order->number, $item->id]), [
            'quantity' => 1,
            'reason' => 'محاولةٌ بعد اليوم',
        ])->assertSessionHasErrors('quantity');

        $this->assertSame(3, (int) $item->fresh()->quantity);
    }

    /** وفاتورةُ متجرٍ آخر لا تُفتح بمعرّفٍ مُخمَّن */
    public function test_another_shops_invoice_is_not_corrected(): void
    {
        $order = $this->sell(3);
        $item = $order->items()->firstOrFail();

        $other = Business::create(['name' => 'محل آخر', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)
            ->put(route('admin.orders.items.update', [$order->number, $item->id]), [
                'quantity' => 1,
                'reason' => 'من متجرٍ آخر',
            ])->assertNotFound();

        $this->assertSame(3, (int) $item->fresh()->quantity);
    }

    /* ==================== والبابان لم يختلطا ==================== */

    /**
     * ورقةُ التفاصيل لا تمسّ ريالًا.
     *
     * وهذا ما كان الزرُّ الواحد يَعِد به ولا يقوله: من فتحه باحثًا عن المبلغ
     * لم يكن ليجده مهما ملأ من حقول.
     */
    public function test_the_detail_sheet_moves_no_money(): void
    {
        $order = $this->sell(3);
        $before = (float) $order->total;

        $this->put(route('admin.orders.details.update', $order->number), [
            'fulfillment_type' => 'delivery',
            'recipient_name' => 'سارة',
            'recipient_phone' => '99887766',
            'delivery_address' => 'الخوض',
            // وطلبٌ يُجهَّز له موعد — وإلّا سقط من لوحة التجهيز صامتًا
            'scheduled_for' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('سارة', $order->recipient_name);
        $this->assertSame($before, (float) $order->total);
    }

    /** وتصحيحُ الفاتورة لا يمسّ ورقتَها */
    public function test_correcting_the_invoice_leaves_the_sheet_alone(): void
    {
        $order = $this->sell(3);
        $order->forceFill(['recipient_name' => 'سارة', 'card_message' => 'كل عام وأنتِ بخير'])->save();
        $item = $order->items()->firstOrFail();

        $this->put(route('admin.orders.items.update', [$order->number, $item->id]), [
            'quantity' => 1,
            'reason' => 'أدخلتُ الكمية خطأً',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('سارة', $order->recipient_name);
        $this->assertSame('كل عام وأنتِ بخير', $order->card_message);
    }

    /* ==================== وما تحتاجه الشاشة ليُنادى الباب ==================== */

    /**
     * الحمولةُ تحمل معرّفَ كلّ بند ووسائلَ الدفع المأذونة.
     *
     * وبلا المعرّف لا يُبنى المسار، فيبقى القلمُ مرسومًا لا يُدير شيئًا.
     */
    public function test_the_payload_carries_what_the_pencil_needs(): void
    {
        $order = $this->sell();

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($page) => $page
                ->has('order.items.0.id')
                ->has('order.payment_methods'));
    }

    /**
     * وزرّان في الترويسة لا زرّ.
     *
     * وهذا حارسٌ يقرأ مصدرًا — ويُقال إنّه ضعيف: وجودُ زرٍّ بعينه في ترويسة
     * صفحةٍ تركيبُ صفحةٍ لا سلوكُ مكوّن، ولا يُثبَت في jsdom إلّا بتركيب
     * `AdminLayout` كلِّه. والوجهتان تحته بابان قائمان لا اسمان مخترَعان —
     * وذلك ما تحرسه بقيّةُ هذا الملفّ.
     */
    public function test_the_header_offers_two_doors_not_one(): void
    {
        $screen = file_get_contents(base_path('resources/js/Pages/Admin/Orders/Show.tsx'));

        $this->assertStringContainsString("{t('تعديل الفاتورة')}", $screen);
        $this->assertStringContainsString("{t('ورقة التفاصيل')}", $screen);
        // والقديمُ المبهم ذهب: «تعديل» وحدها فوق فاتورةٍ تَعِد بما لا تفتح
        $this->assertStringNotContainsString("{t('تعديل')}", $screen);
    }
}
