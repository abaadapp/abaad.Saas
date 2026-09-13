<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Document\PaperSize;
use App\Support\DocumentTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * للبيعة ورقتان — فاتورةٌ تُرسَل وإيصالٌ يُسلَّم. ولا تُلغي إحداهما الأخرى.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * `paper` كان مفتاحًا واحدًا يحكم **ما يخرج** من باب الطباعة، وافتراضُه
 * `80mm`. فأكثرُ المتاجر لم تكن تملك فاتورةَ A4 إطلاقًا: لا معاينةً في شاشة
 * الطلب، ولا طباعةً، ولا ملفًّا يُرسَل إلى شركةٍ تطلب فاتورةً بمشترياتها.
 *
 * والوجهُ الوحيدُ على A4 كان **الفاتورةَ الضريبية**، وتلك تشترط تسجيلًا
 * ضريبيًّا واسمَ متجرٍ مؤكَّدًا. فتاجرٌ غيرُ مسجَّلٍ على ٨٠مم لا ورقةَ له
 * تُرسَل — ولا سطرَ في الشاشة يقول له لماذا.
 *
 * ═══ وما يحرسه ═══
 *
 *  ١. أنّ فاتورةَ A4 هي الافتراضيّ، ولا تشترط تسجيلًا ضريبيًّا.
 *  ٢. أنّ الإيصال الحراريَّ بابٌ ثانٍ لا بديلٌ عنها، وأنّ الصندوق يطبعه هو.
 *  ٣. أنّ عرضَ الطابعة القديم لا يُمحى حين انقسم الحقلان.
 */
class TheSaleIsAnInvoiceBeforeItIsAReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* ═══════════════════ الفاتورةُ هي الأصل ═══════════════════ */

    /** والافتراضيُّ ورقةٌ مقصوصة — لا شريطٌ حراريّ */
    public function test_the_default_sale_paper_is_a_sheet(): void
    {
        $values = DocumentTemplates::settings($this->business->id, 'sale');

        $this->assertSame(PaperSize::A4, $values['paper']);
        $this->assertFalse(PaperSize::isStrip($values['paper']));
        $this->assertSame(PaperSize::T80, $values['strip']);
    }

    /**
     * ومتجرٌ غيرُ مسجَّلٍ ضريبيًّا يُخرج فاتورتَه.
     *
     * والفاتورةُ الضريبية بابٌ آخر بشرطه — وليست الطريقَ الوحيد إلى A4.
     */
    public function test_an_unregistered_shop_still_prints_an_a4_invoice(): void
    {
        $order = $this->order();

        $this->assertDatabaseMissing('settings', [
            'business_id' => $this->business->id, 'key' => 'vat_number',
        ]);

        $paper = $this->actingAs($this->owner)->get(route('admin.orders.pdf', $order->number));

        $paper->assertOk();
        $this->assertSame('application/pdf', $paper->headers->get('content-type'));
        $this->assertSame(210.0, $this->pageWidth($paper->getContent()));
    }

    /** وشاشةُ الطلب تعرض الفاتورة إلى جانبه — لا الشريط */
    public function test_the_order_screen_shows_the_invoice_beside_it(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner)
            ->get(route('admin.orders.show', $order->number))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('paper.size', PaperSize::A4)
                ->where('paper.html', fn (string $html) => str_contains($html, 'table.items')));
    }

    /* ═══════════════════ والإيصالُ بابٌ ثانٍ ═══════════════════ */

    /** ويخرج شريطًا بعرض ورقه — من الباب الذي يخصّه */
    public function test_the_till_receipt_is_a_door_of_its_own(): void
    {
        $order = $this->order();

        $strip = $this->actingAs($this->owner)->get(route('admin.orders.receipt', $order->number));

        $strip->assertOk();
        $this->assertSame(80.0, $this->pageWidth($strip->getContent()));
    }

    /**
     * وصندوقُ البيع يطبع شريطَه لا فاتورةَ A4.
     *
     * فاتورةٌ بعرض ٢١ سنتيمترًا على طابعةٍ حراريّةٍ تخرج مقصوصةً من الحافّة
     * — أو لا تخرج. والزبونُ واقفٌ ينتظر ورقته.
     */
    public function test_the_till_prints_the_strip(): void
    {
        $order = $this->order();

        $receipt = $this->actingAs($this->owner)->get(route('pos.receipt.pdf', $order->number));

        $receipt->assertOk();
        $this->assertSame(80.0, $this->pageWidth($receipt->getContent()));
    }

    /* ═══════════════════ ولا إعدادَ يُمحى ═══════════════════ */

    /**
     * ومن ضبط صندوقَه على ٥٨ يبقى على ٥٨ — ويكسب فاتورةً لم تكن له.
     *
     * انقسامُ الحقلين جعل `paper = 58mm` قيمةً مرفوضة. ولو تُركت لسقطت إلى
     * ٨٠ الافتراضيّ بلا خطأٍ ولا أثر: يخرج إيصالُه مقصوصًا من الحافّة ولا
     * شيءَ يقول له إنّ إعدادَه تبدّل.
     */
    public function test_an_old_strip_width_is_inherited_not_erased(): void
    {
        Setting::create([
            'business_id' => $this->business->id, 'key' => 'paper', 'value' => '58mm',
        ]);

        $values = DocumentTemplates::settings($this->business->id, 'sale');

        $this->assertSame('58mm', $values['strip'], 'عرضُ الطابعة القديم ضاع');
        $this->assertSame(PaperSize::A4, $values['paper'], 'ولم تُعطَ فاتورةٌ مكانه');

        $order = $this->order();

        $this->assertSame(
            58.0,
            $this->pageWidth($this->actingAs($this->owner)
                ->get(route('admin.orders.receipt', $order->number))->getContent()),
        );
    }

    /** وأوّلُ حفظٍ يثبّت الجواب في مفتاحه — فلا يبقى معلَّقًا بالقديم */
    public function test_saving_settles_the_strip_in_its_own_key(): void
    {
        Setting::create([
            'business_id' => $this->business->id, 'key' => 'paper', 'value' => '58mm',
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.settings.templates.update', 'sale'), ['strip' => '58mm'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', [
            'business_id' => $this->business->id, 'key' => 'tpl_strip', 'value' => '58mm',
        ]);
    }

    /** ولا يُقبل شريطٌ في حقل الفاتورة ولا ورقةٌ مقصوصةٌ في حقل الشريط */
    public function test_each_field_takes_only_its_own_sizes(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.settings.templates.update', 'sale'), ['paper' => '80mm'])
            ->assertSessionHasErrors('paper');

        $this->actingAs($this->owner)
            ->post(route('admin.settings.templates.update', 'sale'), ['strip' => 'A4'])
            ->assertSessionHasErrors('strip');
    }

    /* ————————————————— أدوات ————————————————— */

    private function order(string $number = 'INV-000001'): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id, 'branch' => 'مسقط',
            'number' => $number, 'status' => 'مكتمل', 'customer_name' => 'زبون',
            'employee_name' => 'كاشير', 'payment_method' => 'نقدي',
            'subtotal' => 24, 'total' => 24, 'ordered_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'name' => 'باقة ورد جوري', 'price' => 12, 'quantity' => 2, 'total' => 24,
        ]);

        return $order->load('items');
    }

    /** عرضُ الصفحة بالمليمتر من ملفّ الـPDF نفسِه */
    private function pageWidth(string $pdf): float
    {
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(1, preg_match('/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)/', $pdf, $m),
            'لم يُقرأ مقاسُ الصفحة من الملفّ');

        return round(((float) $m[1]) * 25.4 / 72, 0);
    }
}
