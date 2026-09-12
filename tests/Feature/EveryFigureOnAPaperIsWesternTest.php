<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Digits;
use App\Support\Document\PaperSize;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كلُّ رقمٍ على الورقة بخانةٍ غربيّة — ولو كانت الورقة عربيّة.
 *
 * ═══ ولمَ قاعدةً لا ذوقًا ═══
 *
 * المستندُ التجاريُّ يُقرأ خارج المتجر: مورّدٌ يقابل أمرَ الشراء بعرض سعره،
 * ومحاسبٌ يُدخل رقمَ الفاتورة في دفتره، وجهةٌ حكوميّة تبحث عن المبلغ في
 * ملفّ. و«٥٠٠» تُنسخ فلا تُطابق «500»، ولا يجدها بحث.
 *
 * ═══ وما يكتبه النظامُ غربيٌّ أصلًا — والعطبُ فيما يكتبه التاجر ═══
 *
 * التواريخُ من `->format()` والمبالغُ من `number_format`: غربيّةٌ كلُّها.
 * لكنّ التاجر يكتب بيده — اسمَ صنفٍ فيه «٥٠٠ جرام»، وملاحظةً فيها تاريخ،
 * وتذييلَ ورقةٍ في «قوالب الأوراق». وهذه تبلغ الورقةَ كما كُتبت.
 *
 * فالتحويلُ على النصّ المرسوم في مخرجٍ واحد لكلّ عائلة:
 * `DocumentRenderer::html` للأربع، و`InvoiceBranding::render` لفاتورة
 * العميل. وهذا الملفّ يقيس الخمسَ من أبوابها لا من المخرج — فحارسٌ يقيس
 * الدالّةَ التي كتبها يمرّ ولو لم تُستدعَ.
 *
 * ولا يُمسّ المحفوظ: `Digits::western` تحوّل ما يُعرض. ومن كتب «٥٠٠»
 * يجدها كما كتبها في شاشة التعديل.
 */
class EveryFigureOnAPaperIsWesternTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $user;

    /** ما يكتبه التاجر بيده بخاناتٍ عربيّة — يبلغ كلَّ ورقةٍ في النظام */
    private const TYPED = 'باقة ورد ٥٠٠ جرام';

    private const NOTE = 'يُسلَّم قبل ٢٠٢٦-٠٩-٣٠';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'زهور الخليج', 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
        ]);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        $this->user = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'paper', 'value' => 'A4']);
        $this->actingAs($this->user);
    }

    /** لا خانةَ عربيّةً في هذا الرسم — ويُقال أيُّ ورقةٍ إن وُجدت */
    private function assertWestern(string $html, string $paper): void
    {
        preg_match_all('/[٠-٩۰-۹]/u', $html, $m);

        $this->assertSame(
            [],
            $m[0],
            "خاناتٌ عربيّة على «{$paper}»: ".implode(' ', array_unique($m[0])),
        );
    }

    private function order(): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'branch' => $this->branch->name, 'number' => 'INV-000001', 'status' => 'مكتمل',
            'customer_name' => 'شركة الواحة', 'employee_name' => 'سالم', 'payment_method' => 'نقدي',
            'notes' => self::NOTE,
            'subtotal' => 12.5, 'tax' => 0.625, 'total' => 13.125, 'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'name' => self::TYPED,
            'price' => 12.5, 'quantity' => 1, 'total' => 12.5,
        ]);

        return $order->load('items');
    }

    /** فاتورةُ البيع على A4 */
    public function test_the_sale_sheet_carries_no_eastern_figure(): void
    {
        $v = DocumentTemplates::settings((int) $this->business->id, 'sale');
        $v['paper'] = PaperSize::A4;

        $this->assertWestern(
            DocumentRenderer::saleSheet((int) $this->business->id, $this->order(), $v),
            'فاتورة البيع',
        );
    }

    /** والشريطُ الحراريُّ — وهو ما يأخذه الزبون بيده */
    public function test_the_thermal_strip_carries_no_eastern_figure(): void
    {
        $v = DocumentTemplates::settings((int) $this->business->id, 'sale');

        $this->assertWestern(
            DocumentRenderer::saleStrip((int) $this->business->id, $this->order(), $v, 80),
            'الإيصال الحراري',
        );
    }

    /** وسندُ التسليم — يمشي مع الشحنة بلا أسعار */
    public function test_the_delivery_note_carries_no_eastern_figure(): void
    {
        $order = $this->order();

        $this->assertWestern(
            DocumentRenderer::generic(
                (int) $this->business->id,
                'delivery',
                DocumentPaper::forDelivery($order),
                null,
                $order,
            ),
            'سند التسليم',
        );
    }

    /** وأمرُ الشراء — يمضي إلى مورّدٍ خارج المتجر */
    public function test_the_purchase_order_carries_no_eastern_figure(): void
    {
        $this->assertWestern(
            DocumentRenderer::generic(
                (int) $this->business->id,
                'purchase',
                DocumentPaper::forPurchase($this->purchaseOrder()),
            ),
            'أمر الشراء',
        );
    }

    /** وسندُ الاستلام — يُوقَّع عند باب المخزن */
    public function test_the_goods_receipt_carries_no_eastern_figure(): void
    {
        $po = $this->purchaseOrder();

        $note = GoodsReceiptNote::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $po->supplier_id, 'purchase_order_id' => $po->id,
            'number' => 'GRN-000001', 'received_at' => now()->toDateString(),
            'receiver' => 'سالم', 'notes' => self::NOTE, 'status' => 'معتمد',
        ]);
        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id, 'name' => self::TYPED,
            'quantity' => 3, 'cost' => 4.0,
        ]);

        $this->assertWestern(
            DocumentRenderer::generic(
                (int) $this->business->id,
                'grn',
                DocumentPaper::forGrn($note->load('items', 'supplier', 'branch', 'purchaseOrder')),
            ),
            'سند الاستلام',
        );
    }

    /**
     * وفاتورةُ العميل — وهي التي تمضي إلى وزارةٍ أو شركة.
     *
     * ومخرجُها غيرُ مخرج أخواتها: تُبنى في متحكّمها وتُرسم عبر
     * `InvoiceBranding::render` — فتُقاس من بابها هي.
     */
    public function test_the_customer_invoice_carries_no_eastern_figure(): void
    {
        $this->assertWestern(
            DocumentRenderer::customerInvoice((int) $this->business->id),
            'فاتورة العميل',
        );
    }

    /**
     * وتذييلُ الورقة يكتبه التاجر — وهو أشدُّ ما يحمل خاناتٍ عربيّة.
     *
     * «للاستفسار اتصل بـ٩١٢٣٤٥٦٧» سطرٌ يُكتب مرّةً ويُطبع على كلّ ورقة.
     */
    public function test_a_footer_typed_in_eastern_figures_is_converted(): void
    {
        DocumentTemplates::save((int) $this->business->id, 'purchase', [
            'footer' => 'للاستفسار: ٩١٢٣٤٥٦٧',
        ]);

        $html = DocumentRenderer::generic(
            (int) $this->business->id,
            'purchase',
            DocumentPaper::forPurchase($this->purchaseOrder()),
        );

        $this->assertStringContainsString('91234567', $html, 'التذييلُ لم يُحوَّل');
        $this->assertWestern($html, 'أمر الشراء بتذييلٍ مكتوب');
    }

    /**
     * والقيمةُ المحفوظة لا تُمسّ — التحويلُ للعرض لا للبيانات.
     *
     * من كتب «٥٠٠ جرام» في اسم صنفه يجدها كما كتبها حين يفتح شاشة التعديل.
     * وتصحيحُ ما يكتبه الناس في قاعدتهم بلا طلبهم يُفسد أكثر ممّا يُصلح.
     */
    public function test_the_stored_value_is_left_as_the_merchant_typed_it(): void
    {
        $order = $this->order();

        DocumentRenderer::saleSheet(
            (int) $this->business->id,
            $order,
            DocumentTemplates::settings((int) $this->business->id, 'sale'),
        );

        $this->assertSame(self::TYPED, $order->items->first()->fresh()->name);
        $this->assertSame(self::NOTE, $order->fresh()->notes);
    }

    /** ولا يُفسد التحويلُ ما ليس نصًّا — عددٌ يبقى عددًا و`null` يبقى فراغًا */
    public function test_the_converter_leaves_non_text_alone(): void
    {
        $this->assertSame(
            ['n' => 5, 'ok' => true, 'nothing' => null, 'text' => '500'],
            Digits::western(['n' => 5, 'ok' => true, 'nothing' => null, 'text' => '٥٠٠']),
        );

        // وشجرةٌ بعمق: بيانُ المستند بنودٌ داخل مصفوفة
        $this->assertSame(
            ['items' => [['name' => 'وردة 12']]],
            Digits::western(['items' => [['name' => 'وردة ١٢']]]),
        );
    }

    private function purchaseOrder(): PurchaseOrder
    {
        $supplier = Supplier::create([
            'business_id' => $this->business->id, 'name' => 'مشتل الربيع',
        ]);

        $po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
            'number' => 'PO-000001', 'status' => 'مسودة', 'notes' => self::NOTE,
            'items_subtotal' => 12.0, 'total' => 12.0, 'ordered_at' => now(),
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'name' => self::TYPED,
            'quantity' => 3, 'cost' => 4.0, 'line_total' => 12.0,
        ]);

        return $po->load('items', 'supplier');
    }
}
