<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\GoodsReceiptNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Document\PaperSize;
use App\Support\Document\Snapshot;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\Money;
use App\Support\Pdf;
use App\Support\PublicDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الورقةُ يُوثَق بها ماليًّا وأمنيًّا — لا شكلًا وحدَه.
 *
 * وهذه المخاطرُ لا يكشفها جمالُ الورقة ولا عددُ الاختبارات: ورقةٌ تُعاد
 * طباعتُها فتقول غيرَ ما قالت، ورقمٌ يتكرّر تحت ازدحام، وتاجرٌ يفتح ورقةَ
 * جاره برقمها.
 */
class ADocumentCanBeTrustedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        [$this->business, $this->branch, $this->owner] = $this->tenant('زهور الخليج', 'a@abaadapp.om');
    }

    /** @return array{0: Business, 1: Branch, 2: User} */
    private function tenant(string $name, string $email): array
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
            'address' => 'شارع السلطان قابوس', 'phone' => '+968 9123 4567', 'email' => 'shop@x.om',
        ]);
        $branch = Branch::create(['business_id' => $b->id, 'name' => 'الفرع الرئيسي']);
        $user = User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Setting::create(['business_id' => $b->id, 'key' => 'paper', 'value' => 'A4']);

        return [$b, $branch, $user];
    }

    private function order(Business $b, Branch $br, string $number = 'INV-000001', int $items = 1, bool $stamp = true): Order
    {
        $attrs = [
            'business_id' => $b->id, 'branch_id' => $br->id, 'branch' => $br->name,
            'number' => $number, 'status' => 'مكتمل', 'customer_name' => 'شركة الواحة',
            'employee_name' => 'سالم', 'payment_method' => 'نقدي',
            'subtotal' => 12.5 * $items, 'tax' => 0.625 * $items, 'total' => 13.125 * $items,
            'ordered_at' => now(),
        ];

        if ($stamp) {
            $attrs[Snapshot::COLUMN] = Snapshot::capture((int) $b->id);
        }

        $order = Order::create($attrs);

        for ($i = 0; $i < $items; $i++) {
            OrderItem::create([
                'order_id' => $order->id, 'name' => 'باقة ورد '.($i + 1),
                'price' => 12.5, 'quantity' => 1, 'total' => 12.5,
            ]);
        }

        return $order->load('items');
    }

    private function sheet(Order $order, ?int $bid = null): string
    {
        $bid ??= (int) $this->business->id;
        $v = DocumentTemplates::settings($bid, 'sale');
        $v['paper'] = PaperSize::A4;
        $v['show_vat_no'] = true;

        return DocumentRenderer::saleSheet($bid, $order, $v);
    }

    /* ═══════════ §4 — الورقةُ الصادرة لا تُعاد كتابتُها ═══════════ */

    /**
     * ورقةٌ صدرت تبقى كما صدرت — ولو تبدّل المتجرُ كلُّه بعدها.
     *
     * وهو ما كان يقع: بنودُ الفاتورة ملقوطة، لكنّ هويّةَ البائع والعملةَ
     * كانتا تُقرآن حيّتين. فورقةٌ صدرت بـ«12.500 OMR» تُعاد طباعتُها
     * بـ«12.50 د.إ» ورقمٍ ضريبيٍّ لم يكن قائمًا يومَ البيع.
     */
    public function test_an_issued_paper_does_not_change_when_the_shop_does(): void
    {
        $this->actingAs($this->owner);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_number', 'value' => 'OM1100234567']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '1']);

        $product = Product::create(['business_id' => $this->business->id, 'name' => 'باقة ورد 1', 'price' => 12.5, 'active' => true]);
        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة الواحة', 'phone' => '99887766']);

        $order = $this->order($this->business, $this->branch);
        $before = $this->sheet($order);

        // ═══ ثمّ يتبدّل كلُّ ما تقرؤه الورقة حيًّا ═══
        $product->update(['name' => 'اسمٌ جديد تمامًا', 'price' => 999.999]);
        $customer->update(['name' => 'اسمٌ بعد الدمج']);
        $this->business->update(['name' => 'زهور الخليج الدولية', 'address' => 'عنوانٌ آخر', 'phone' => '+968 0000 0000', 'email' => 'new@x.om']);
        Setting::where('business_id', $this->business->id)->where('key', 'vat_number')->update(['value' => 'OM9999999999']);
        $this->branch->update(['name' => 'فرعٌ أُعيدت تسميتُه']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'AED', 'name' => 'درهم',
            'symbol' => 'د.إ', 'rate' => 1.0, 'is_base' => true, 'active' => true,
        ]);

        $after = $this->sheet($order->fresh('items'));

        $this->assertSame($before, $after, 'الورقةُ الصادرة تغيّرت بعد إصدارها');
        $this->assertStringContainsString('OM1100234567', $after, 'الرقمُ الضريبيُّ ليس رقمَ يومها');
        $this->assertStringNotContainsString('OM9999999999', $after);
        $this->assertStringNotContainsString('د.إ', $after, 'المبالغُ أُعيد وسمُها بعملةٍ أخرى');
    }

    /**
     * وورقةٌ بلا لقطة تُقرأ من الحيّ — ولا يُختلق لها تاريخ.
     *
     * صفوفُ ما قبل هذه البنية موجودة، والصدقُ فيها أن تُقرأ كما كانت تُقرأ
     * لا أن تُختم اليومَ بحالٍ لم يكن حالَها يومَ صدرت.
     */
    public function test_a_paper_from_before_the_stamp_still_prints(): void
    {
        $this->actingAs($this->owner);

        $order = $this->order($this->business, $this->branch, 'INV-000009', stamp: false);

        $this->assertFalse(Snapshot::stamped($order));
        $this->assertStringContainsString('زهور الخليج', $this->sheet($order), 'ورقةٌ بلا لقطةٍ لا تُرسم');
    }

    /** ولا يُعاد ختمُ ما خُتم — وإلّا كُتب عليها حالُ اليوم بدل حال يومها */
    public function test_a_stamp_is_never_written_twice(): void
    {
        $order = $this->order($this->business, $this->branch);
        $first = $order->getAttribute(Snapshot::COLUMN);

        $this->business->update(['name' => 'اسمٌ آخر']);
        Snapshot::stamp($order, (int) $this->business->id);

        $this->assertSame($first, $order->getAttribute(Snapshot::COLUMN), 'اللقطةُ أُعيد ختمُها');
    }

    /** وفاتورةُ العميل تُختم لحظةَ الإصدار لا لحظةَ الكتابة */
    public function test_a_customer_invoice_is_stamped_when_it_is_issued(): void
    {
        $this->actingAs($this->owner);

        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'جهة', 'phone' => '9111']);
        $invoice = CustomerInvoices::create($this->business->id, $customer, [], [
            ['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100],
        ]);

        $this->assertFalse(Snapshot::stamped($invoice), 'المسودّةُ خُتمت قبل أن تصدر');

        $issued = CustomerInvoices::issue($invoice);

        $this->assertTrue(Snapshot::stamped($issued), 'الفاتورةُ الصادرة بلا لقطة');
    }

    /* ═══════════ §15 — ورقةُ جارك ليست ورقتَك ═══════════ */

    /** تاجرٌ لا يفتح ورقةَ جاره برقمها — على كلّ بابٍ يطبع */
    public function test_a_merchant_cannot_open_a_neighbours_paper(): void
    {
        [$other, $otherBranch] = $this->tenant('متجر الجار', 'b@abaadapp.om');
        $theirs = $this->order($other, $otherBranch, 'INV-000777');

        $po = PurchaseOrder::create([
            'business_id' => $other->id, 'branch_id' => $otherBranch->id, 'number' => 'PO-000777',
            'status' => 'مسودة', 'total' => 10, 'items_subtotal' => 10,
        ]);
        $grn = GoodsReceiptNote::create([
            'business_id' => $other->id, 'branch_id' => $otherBranch->id,
            'number' => 'GRN-000777', 'received_at' => now(),
        ]);
        $theirCustomer = Customer::create(['business_id' => $other->id, 'name' => 'جهة الجار', 'phone' => '9113']);
        $invoice = CustomerInvoice::create([
            'business_id' => $other->id, 'customer_id' => $theirCustomer->id,
            'number' => 'CINV-000777', 'status' => CustomerInvoice::ISSUED,
            'subtotal' => 10, 'discount_total' => 0, 'tax_total' => 0, 'total' => 10, 'issued_at' => now(),
        ]);

        $this->actingAs($this->owner);

        $doors = [
            'إيصال الطلب' => route('admin.orders.pdf', $theirs->number),
            'الفاتورة الضريبية' => route('admin.orders.taxInvoice', $theirs->number),
            'سند التسليم' => route('admin.orders.deliveryNote', $theirs->number),
            'أمر الشراء' => route('admin.purchases.pdf', $po->id),
            'سند الاستلام' => route('admin.inventory.receipts.pdf', $grn->id),
            'فاتورة العميل' => route('admin.customerInvoices.show', $invoice->id),
        ];

        foreach ($doors as $what => $url) {
            $this->get($url)->assertStatus(404, $what.': بابٌ يفتح ورقةَ متجرٍ آخر');
        }
    }

    /** ورقمٌ لا وجودَ له لا يكشف شيئًا — لا خطأ خادمٍ ولا أثرَ وجود */
    public function test_an_unknown_document_says_nothing(): void
    {
        $this->actingAs($this->owner);

        $this->get(route('admin.orders.pdf', 'INV-999999'))->assertStatus(404);
        $this->get('/i/'.str_repeat('z', 22))->assertStatus(404);
    }

    /* ═══════════ §6 — رقمٌ واحدٌ لورقةٍ واحدة ═══════════ */

    /**
     * رقمانِ لا يلتقيان تحت ازدحام — والفهرسُ هو الحارسُ الأخير.
     *
     * ولا يُحاكى التزامنُ بخيوط: عمليّةُ PHP واحدة. فيُختبر ما يقع فعلًا —
     * محاولةُ كتابة الرقم نفسِه مرّتين — ويُثبَت أنّ القاعدةَ تردّها.
     */
    public function test_two_papers_never_share_a_number(): void
    {
        $this->order($this->business, $this->branch, 'INV-000100');

        $this->expectException(QueryException::class);

        $this->order($this->business, $this->branch, 'INV-000100');
    }

    /** ولكلّ متجرٍ تسلسلُه: الرقمُ نفسُه في متجرين ليس تصادمًا */
    public function test_the_sequence_belongs_to_the_shop_not_the_platform(): void
    {
        [$other, $otherBranch] = $this->tenant('متجر آخر', 'c@abaadapp.om');

        $mine = $this->order($this->business, $this->branch, 'INV-000200');
        $theirs = $this->order($other, $otherBranch, 'INV-000200');

        $this->assertSame($mine->number, $theirs->number);
        $this->assertNotSame($mine->business_id, $theirs->business_id);
    }

    /** وفاتورةُ العميل تُرقَّم تحت قفل، ولا يُعاد ترقيمُ ما رُقِّم */
    public function test_issuing_twice_does_not_spend_two_numbers(): void
    {
        $this->actingAs($this->owner);

        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'جهة', 'phone' => '9112']);
        $invoice = CustomerInvoices::create($this->business->id, $customer, [], [
            ['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 50],
        ]);

        $first = CustomerInvoices::issue($invoice)->number;
        $again = CustomerInvoices::issue($invoice->fresh())->number;

        $this->assertSame($first, $again, 'الإصدارُ مرّتين أنفق رقمين');
        $this->assertSame(1, CustomerInvoice::where('business_id', $this->business->id)->whereNotNull('number')->count());
    }

    /* ═══════════ §7 — المالُ حتميّ ═══════════ */

    /** الأرقامُ الكاسرةُ للعائم تخرج كما تُقرأ — لا 0.30000000000000004 */
    public function test_the_arithmetic_does_not_drift(): void
    {
        $this->actingAs($this->owner);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $cases = [
            [[['quantity' => 1, 'unit_price' => 0.1], ['quantity' => 1, 'unit_price' => 0.2]], 0.3],
            [[['quantity' => 1000, 'unit_price' => 0.125]], 125.0],
            [[['quantity' => 3, 'unit_price' => 0.333]], 0.999],
            [[['quantity' => 9999, 'unit_price' => 99999.999]], 999899990.001],
        ];

        foreach ($cases as [$lines, $expected]) {
            $r = CustomerInvoices::compute($this->business->id, $lines, 0.0);
            $this->assertSame($expected, $r['total'], 'الحسابُ انزلق');
        }
    }

    /**
     * ومجموعُ البنود يساوي مجموعَ الرأس — دائمًا.
     *
     * وكان خصمٌ يفوق بندَه يُقصَر في السطر ويُجمَع كاملًا في الرأس: بندٌ
     * بعشرةٍ وخصمٌ بخمسةٍ وعشرين يُخرج سطرًا صفرًا وإجماليًّا سالبًا بخمسة
     * عشر. وخصمٌ سالبٌ كان يضخّم الإجماليَّ بلا سطرٍ يقابله.
     */
    public function test_the_lines_always_add_up_to_the_head(): void
    {
        $this->actingAs($this->owner);

        foreach ([25, -5, 0, 4.5] as $discount) {
            $r = CustomerInvoices::compute($this->business->id, [
                ['quantity' => 1, 'unit_price' => 10, 'discount' => $discount],
            ], 5.0);

            $this->assertSame(
                round(array_sum(array_column($r['items'], 'line_total')), 3),
                $r['total'],
                'بنودٌ لا تجمع إلى مجموعها عند خصمٍ = '.$discount,
            );
            $this->assertGreaterThanOrEqual(0.0, $r['total'], 'إجماليٌّ سالب');
            $this->assertGreaterThanOrEqual(0.0, $r['discount']);
        }
    }

    /** والعملةُ تُكتب بمنازلها هي — ثلاثٌ للريال واثنتان للدرهم وصفرٌ للين */
    public function test_each_currency_keeps_its_own_places(): void
    {
        $this->assertSame('5.250 ر.ع', Money::format(5.25, ['code' => 'OMR', 'symbol' => 'ر.ع', 'decimals' => 3]));
        $this->assertSame('5.25 د.إ', Money::format(5.25, ['code' => 'AED', 'symbol' => 'د.إ', 'decimals' => 2]));
        $this->assertSame('¥ 1,234', Money::format(1234.0, ['code' => 'JPY', 'symbol' => '¥', 'decimals' => 0, 'before' => true]));
    }

    /* ═══════════ §16 — نصُّ المستخدم نصٌّ لا شفرة ═══════════ */

    /** اسمُ صنفٍ فيه وسمٌ لا يصير وسمًا على الورقة */
    public function test_a_product_name_cannot_become_markup(): void
    {
        $this->actingAs($this->owner);

        $order = $this->order($this->business, $this->branch, 'INV-000300');
        $order->items->first()->update(['name' => '<script>alert(1)</script><b>x</b>']);
        $order->update(['customer_name' => '</td></tr><script>x</script>']);

        $html = $this->sheet($order->fresh('items'));

        $this->assertStringNotContainsString('<script>', $html, 'شفرةٌ من اسم صنفٍ بلغت الورقة');
        $this->assertStringContainsString('&lt;script&gt;', $html, 'النصُّ لم يُهرَّب');
    }

    /* ═══════════ §14 — اسمُ الملفّ لا يدخل الترويسة كما جاء ═══════════ */

    /** بادئةُ رقمٍ يكتبها التاجر لا تكسر ترويسةَ التنزيل */
    public function test_a_merchant_prefix_cannot_break_the_download_header(): void
    {
        $this->actingAs($this->owner);

        $response = Pdf::sheet('<p>x</p>', 'invoice-A"; evil=1'."\r\n".'X: y', PaperSize::A4);
        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertSame('inline; filename="invoice-A-evil-1-X-y.pdf"', $disposition);
        $this->assertStringNotContainsString('"', substr($disposition, 19, -5));
    }

    /* ═══════════ §21 — والحالاتُ التي تُنسى ═══════════ */

    /** مئةُ صنفٍ تخرج في صفحاتٍ متعدّدة بلا قصّ */
    public function test_a_hundred_lines_still_print(): void
    {
        $this->actingAs($this->owner);

        $order = $this->order($this->business, $this->branch, 'INV-000400', items: 100);
        $pdf = Pdf::sheet($this->sheet($order), 'x', PaperSize::A4)->getContent();

        $pages = substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1, $pages);
    }

    /** والمعاينةُ من الرسم نفسِه الذي يُطبع — لا من ثانٍ */
    public function test_the_preview_is_the_printed_paper(): void
    {
        $this->actingAs($this->owner);

        $order = $this->order($this->business, $this->branch, 'INV-000500');
        $v = DocumentTemplates::settings($this->business->id, 'sale');
        $v['paper'] = PaperSize::A4;

        $a = DocumentRenderer::saleSheet($this->business->id, $order, $v);
        $b = DocumentRenderer::saleSheet($this->business->id, $order->fresh('items'), $v);

        $this->assertSame($a, $b, 'رسمان مختلفان لورقةٍ واحدة');
        $this->assertStringContainsString('@media screen', $a, 'المعاينةُ بلا صندوقِ ورقة');
        $this->assertStringContainsString('@media print', $a, 'الطبعُ بلا مقاسِ صفحة');
    }

    /** ورابطُ الزبون يقرأ البيانَ نفسَه ولا يكشف ما لا يخصّه */
    public function test_the_public_link_shows_the_same_paper(): void
    {
        $this->actingAs($this->owner);

        $order = $this->order($this->business, $this->branch, 'INV-000600');
        $token = PublicDocument::token($order);

        $page = $this->get('/i/'.$token);
        $page->assertOk();
        $page->assertSee('INV-000600', false);
    }
}
