<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DocumentTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أوراقُ النظام وقوالبُها.
 *
 * وكانت أوراقُه تُنشأ ولا تُطبع: أمرُ شراءٍ يُرسل إلى مورّد بالهاتف، وسندُ
 * استلامٍ يُوقَّع على ورقةٍ تُكتب باليد، وسندُ نقلٍ يمشي مع البضاعة بلا
 * ورقة. فما في النظام لا يُثبت شيئًا عند خلاف.
 *
 * وثلاثةٌ تُفحص: أنّ كلّ نوعٍ **يخرج على ورق**، وأنّ المفتاح الذي يُطفئه
 * التاجر **يختفي أثرُه من الورقة** لا من الشاشة وحدها، وأنّ ورقةَ الجار **لا
 * تُفتح برقمٍ مُخمَّن**.
 */
class DocumentTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Order $order;

    private PurchaseOrder $po;

    private GoodsReceiptNote $grn;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط']);
        $muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 12, 'cost' => 5, 'quantity' => 40, 'active' => true,
        ]);

        $this->order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $muscat->id, 'branch' => 'مسقط',
            'number' => 'INV-000001', 'status' => 'مكتمل', 'customer_name' => 'زبون',
            'recipient_name' => 'المستلِم', 'recipient_phone' => '91234567',
            'delivery_address' => 'الخوير — شارع 18',
            'employee_name' => 'كاشير', 'payment_method' => 'نقدي',
            'subtotal' => 24, 'total' => 24, 'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $this->order->id, 'name' => 'باقة ورد',
            'price' => 12, 'quantity' => 2, 'total' => 24,
        ]);

        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مشتل الوادي']);

        $this->po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'number' => 'PO-000001',
            'supplier_id' => $supplier->id, 'supplier_name' => 'مشتل الوادي',
            'status' => 'مُرسل', 'total' => 50, 'notes' => 'يُسلَّم صباحًا', 'ordered_at' => now(),
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $this->po->id, 'name' => 'ورد جوري', 'cost' => 5, 'quantity' => 10,
        ]);

        $this->grn = GoodsReceiptNote::create([
            'business_id' => $this->business->id, 'branch_id' => $muscat->id,
            'supplier_id' => $supplier->id, 'purchase_order_id' => $this->po->id,
            'number' => 'GRN-000001', 'received_at' => now()->toDateString(), 'receiver' => 'أمين المخزن',
        ]);
        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $this->grn->id, 'name' => 'ورد جوري',
            'quantity' => 10, 'cost' => 5,
        ]);

        $this->actingAs($this->owner);
    }

    /* --------------------------- السجلّ مصدرٌ واحد --------------------------- */

    public function test_the_screen_list_is_built_from_the_registry(): void
    {
        /*
         * ولا تُكتب باليد في الشاشة: يُضاف نوعٌ فيظهر في الإعدادات بلا لمس
         * ملفّ الواجهة. وقائمتان تفترقان عند أوّل إضافة — يُحفظ نوعٌ ولا
         * تفتحه بطاقة، أو تُعرض بطاقةٌ لنوعٍ لا يقبله الحفظ.
         */
        $cards = collect(DocumentTemplates::all())->pluck('key')->all();

        $this->assertSame(array_keys(DocumentTemplates::TYPES), $cards);
    }

    public function test_every_registered_type_opens_its_editor(): void
    {
        foreach (array_keys(DocumentTemplates::TYPES) as $type) {
            $this->get(route('admin.settings.templates.edit', $type))->assertOk();
        }
    }

    public function test_a_type_that_does_not_exist_is_not_found(): void
    {
        $this->get(route('admin.settings.templates.edit', 'la-shay'))->assertNotFound();
        $this->post(route('admin.settings.templates.update', 'la-shay'), [])->assertNotFound();
    }

    /* ----------------------------- الحفظ والقراءة ----------------------------- */

    public function test_the_sale_paper_keeps_the_keys_merchants_already_set(): void
    {
        /*
         * وهذا أخطر ما في هذا التغيير: إعادةُ تسمية `tpl_show_logo` إلى
         * `tpl_sale_show_logo` تُعيد كلَّ متجرٍ ضبط ورقته إلى الافتراضيّ بلا
         * خطأ ولا أثر — تُطبع ورقةٌ غير التي اعتادها، ولا يعرف صاحبُها لماذا.
         */
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'tpl_show_employee'],
            ['value' => '0'],
        );

        $this->assertFalse(DocumentTemplates::settings($this->business->id, 'sale')['show_employee']);
        $this->assertSame('tpl_show_employee', DocumentTemplates::key('sale', 'show_employee'));
        $this->assertSame('paper', DocumentTemplates::key('sale', 'paper'));
    }

    public function test_each_new_type_keeps_its_own_keys(): void
    {
        // ورقةٌ تُطفأ فيها الأسعار لا تُطفئها في ورقةٍ أخرى
        $this->post(route('admin.settings.templates.update', 'delivery'), ['show_prices' => false]);
        $this->post(route('admin.settings.templates.update', 'purchase'), ['show_prices' => true]);

        $this->assertFalse(DocumentTemplates::settings($this->business->id, 'delivery')['show_prices']);
        $this->assertTrue(DocumentTemplates::settings($this->business->id, 'purchase')['show_prices']);
    }

    public function test_an_off_switch_is_read_off_and_not_as_missing(): void
    {
        /*
         * ‏«0» نصًّا صادقةٌ في PHP: علمٌ مُطفأ يُقرأ مُشغَّلًا فيُطبع ما
         * أخفاه صاحبُه — واسمُ الموظف على ورقةٍ أراد إخفاءه منها.
         */
        $this->post(route('admin.settings.templates.update', 'grn'), ['show_employee' => false]);

        $this->assertSame('0', Setting::where('business_id', $this->business->id)
            ->where('key', 'tpl_grn_show_employee')->value('value'));
        $this->assertFalse(DocumentTemplates::settings($this->business->id, 'grn')['show_employee']);
    }

    public function test_a_font_that_does_not_exist_is_refused(): void
    {
        $this->post(route('admin.settings.templates.update', 'purchase'), ['font' => 'ضخم'])
            ->assertSessionHasErrors('font');
    }

    /* ----------------------- المعاينة ترسم ما يُطبع ----------------------- */

    public function test_the_preview_is_drawn_by_the_template_that_prints(): void
    {
        $html = $this->postJson(route('admin.settings.templates.preview', 'purchase'), [
            'show_prices' => true, 'show_supplier' => true,
        ])->assertOk()->json('html');

        $this->assertStringContainsString('PO-000001', $html);
        $this->assertStringContainsString('ورد جوري', $html);
        $this->assertStringContainsString('مشتل الوادي', $html);
    }

    public function test_turning_prices_off_removes_them_from_the_paper_itself(): void
    {
        /*
         * ولا يُفحص المفتاح في القاعدة وحده: قالبٌ يحفظ الإطفاء ويطبع السعر
         * أسوأ من قالبٍ لا يُطفئ — التاجر يطمئنّ، والسائق يحمل ورقةً فيها
         * ثمنُ هديّةٍ إلى من أُهديت له.
         */
        $on = $this->postJson(route('admin.settings.templates.preview', 'delivery'), ['show_prices' => true])
            ->json('html');
        $off = $this->postJson(route('admin.settings.templates.preview', 'delivery'), ['show_prices' => false])
            ->json('html');

        $this->assertStringContainsString('ر.ع', $on);
        $this->assertStringNotContainsString('ر.ع', $off);
    }

    public function test_the_signature_line_appears_only_when_asked(): void
    {
        $on = $this->postJson(route('admin.settings.templates.preview', 'grn'), ['show_signature' => true])->json('html');
        $off = $this->postJson(route('admin.settings.templates.preview', 'grn'), ['show_signature' => false])->json('html');

        $this->assertStringContainsString('توقيع المستلِم', $on);
        $this->assertStringNotContainsString('توقيع المستلِم', $off);
    }

    public function test_the_preview_saves_nothing(): void
    {
        /*
         * من جرّب شكلًا ثمّ تركه لا يجب أن يجد ورقته قد تغيّرت — والمعاينة
         * تُطلب مع كلّ حرفٍ يُكتب، فحفظُها يعني ورقةً تتبدّل بلا أن يُحفظ.
         */
        $this->postJson(route('admin.settings.templates.preview', 'purchase'), ['show_signature' => true])->assertOk();

        $this->assertDatabaseMissing('settings', [
            'business_id' => $this->business->id, 'key' => 'tpl_purchase_show_signature',
        ]);
    }

    public function test_the_sale_preview_draws_the_real_receipt(): void
    {
        $html = $this->postJson(route('admin.settings.templates.preview', 'sale'), [
            'paper' => '80mm', 'show_employee' => true,
        ])->assertOk()->json('html');

        $this->assertStringContainsString('INV-000001', $html);
        $this->assertStringContainsString('ورد الخوير', $html);
    }

    /* ------------------------------ الورق يخرج ------------------------------ */

    public function test_every_document_comes_out_as_a_pdf(): void
    {
        $routes = [
            route('admin.orders.deliveryNote', $this->order->number),
            route('admin.purchases.pdf', $this->po->id),
            route('admin.inventory.receipts.pdf', $this->grn->id),
        ];

        foreach ($routes as $url) {
            $res = $this->get($url)->assertOk();
            $this->assertStringStartsWith('%PDF', $res->getContent(), $url);
        }
    }

    /* ------------------------------- العزل ------------------------------- */

    public function test_a_neighbours_paper_does_not_open_by_a_guessed_number(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $supplier = Supplier::create(['business_id' => $neighbour->id, 'name' => 'مورّدهم']);

        $theirs = PurchaseOrder::create([
            'business_id' => $neighbour->id, 'number' => 'PO-000099',
            'supplier_id' => $supplier->id, 'status' => 'مُرسل', 'total' => 10, 'ordered_at' => now(),
        ]);
        $this->get(route('admin.purchases.pdf', $theirs->id))->assertNotFound();
        $this->get(route('admin.inventory.receipts.pdf', 999999))->assertNotFound();
    }

    public function test_a_neighbours_template_is_not_read_as_mine(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Setting::create(['business_id' => $neighbour->id, 'key' => 'tpl_grn_show_signature', 'value' => '0']);

        $this->assertTrue(DocumentTemplates::settings($this->business->id, 'grn')['show_signature']);
    }

    /* ------------------- حفظُ الإعدادات لا يمحو القالب ------------------- */

    public function test_saving_the_general_settings_does_not_undo_a_template_edit(): void
    {
        /*
         * وكانت شاشةُ الإعدادات تحمل حقول القالب في نموذجها العامّ وترسلها
         * مع كلّ حفظٍ من أيّ تبويب. فمن عدّل ورقته في محرّرها ثمّ حفظ «بيانات
         * النشاط» أعاد القيمَ التي قرأتها الشاشة قبل تعديله — يُنسَخ القديم
         * فوق الجديد بلا خطأ ولا رسالة.
         */
        $this->post(route('admin.settings.templates.update', 'sale'), ['show_employee' => false]);

        $this->post(route('admin.settings.update'), ['shop_name' => 'ورد الخوير'])
            ->assertSessionHasNoErrors();

        $this->assertFalse(DocumentTemplates::settings($this->business->id, 'sale')['show_employee']);
    }
}
