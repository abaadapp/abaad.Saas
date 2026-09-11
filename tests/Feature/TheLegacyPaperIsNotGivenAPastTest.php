<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Demo;
use App\Support\Document\PaperSize;
use App\Support\Document\Snapshot;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ورقةٌ بلا لقطةٍ تُقرأ من الحيّ — ولا يُختلق لها ماضٍ لم يكن مخزَّنًا.
 *
 * ═══ حدُّ الانتقال ═══
 *
 * اللقطةُ (`document_snapshot`) وُلدت مع هجرة
 * `2026_09_11_100000_an_issued_paper_remembers_who_issued_it`. فكلُّ ورقةٍ
 * صدرت **بعد** تشغيلها تحمل لقطةً، وكلُّ ورقةٍ قبلها لا تحملها. ولا تاريخَ
 * يُكتب في عمود: الشرطُ يُقرأ من الصفّ نفسِه — `document_snapshot IS NULL`
 * أو `v` لا يساوي `Snapshot::VERSION`. وهو ما ترُدّه `Snapshot::stamped`.
 *
 * فلا حاجةَ لعمودٍ ثانٍ يقول «تاريخيّة» أو «حيّة»: الجوابُ في العمود القائم،
 * وعمودٌ يعيد قولَه مصدرٌ ثانٍ للحقيقة نفسِها يفترق عنها يومًا.
 *
 * ═══ وما لا يُفعل ═══
 *
 *  • لا تُملأ اللقطةُ القديمةُ من البيانات الحيّة ثمّ تُسمّى تاريخًا: ذاك
 *    اختلاقُ ماضٍ، وهو أسوأ من الاعتراف بأنّه غير محفوظ.
 *  • ولا تُكتب لقطةٌ عند **الرسم**: الرسمُ قراءةٌ لا إصدار. ولو كُتبت لصار
 *    أوّلُ فتحٍ لورقةٍ قديمة يختمها بحال اليوم — وتبدو بعدها تاريخيّةً وهي
 *    ليست كذلك.
 *
 * وهذا الملفّ يُثبت الفرقَ بين الحالين بالتنفيذ: ورقةٌ مختومةٌ لا تتحرّك،
 * وورقةٌ بلا ختمٍ تتحرّك — وكلتاهما تُطبع، ولا تُكتب لقطةٌ لواحدةٍ منهما
 * أثناء الرسم.
 */
class TheLegacyPaperIsNotGivenAPastTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'زهور الخليج', 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
            'address' => 'شارع السلطان قابوس', 'phone' => '+968 9123 4567', 'email' => 'shop@x.om',
        ]);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'a@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'paper', 'value' => 'A4']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_number', 'value' => 'OM1100234567']);
    }

    private function order(string $number, bool $stamp): Order
    {
        $attrs = [
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'branch' => $this->branch->name, 'number' => $number, 'status' => 'مكتمل',
            'customer_name' => 'شركة الواحة', 'employee_name' => 'سالم', 'payment_method' => 'نقدي',
            'subtotal' => 12.5, 'tax' => 0.625, 'total' => 13.125, 'ordered_at' => now(),
        ];

        if ($stamp) {
            $attrs[Snapshot::COLUMN] = Snapshot::capture((int) $this->business->id);
        }

        $order = Order::create($attrs);
        OrderItem::create([
            'order_id' => $order->id, 'name' => 'باقة ورد', 'price' => 12.5, 'quantity' => 1, 'total' => 12.5,
        ]);

        return $order->load('items');
    }

    private function sheet(Order $order): string
    {
        $v = DocumentTemplates::settings((int) $this->business->id, 'sale');
        $v['paper'] = PaperSize::A4;
        $v['show_vat_no'] = true;

        return DocumentRenderer::saleSheet((int) $this->business->id, $order, $v);
    }

    /** كلُّ ما تقرؤه الورقةُ حيًّا يتبدّل — الاسمُ والرقمُ الضريبيُّ والعملة */
    private function theShopChanges(): void
    {
        $this->business->update(['name' => 'زهور الخليج الدولية', 'phone' => '+968 0000 0000']);
        Setting::where('business_id', $this->business->id)
            ->where('key', 'vat_number')->update(['value' => 'OM9999999999']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'AED', 'name' => 'درهم',
            'symbol' => 'د.إ', 'rate' => 1.0, 'is_base' => true, 'active' => true,
        ]);
        Demo::flushCurrency();
    }

    /* ═══════════ الفرقُ بين الحالين، منفَّذًا ═══════════ */

    /**
     * ورقتان تُطبعان: المختومةُ لا تتحرّك، وغيرُ المختومة تتحرّك.
     *
     * وهذا هو الجوابُ الصادق عن «ماذا يقع للأوراق القديمة»: تُقرأ من المتجر
     * كما كانت تُقرأ قبل اللقطة — لا تُكسر، ولا يُدّعى لها ثباتٌ لا تملكه.
     */
    public function test_a_stamped_paper_holds_still_and_an_unstamped_one_follows_the_shop(): void
    {
        $this->actingAs($this->owner);

        $stamped = $this->order('INV-000001', stamp: true);
        $legacy = $this->order('INV-000002', stamp: false);

        $stampedBefore = $this->sheet($stamped);
        $legacyBefore = $this->sheet($legacy);

        $this->theShopChanges();

        $stampedAfter = $this->sheet($stamped->fresh('items'));
        $legacyAfter = $this->sheet($legacy->fresh('items'));

        // المختومةُ: حرفًا بحرف كما صدرت
        $this->assertSame($stampedBefore, $stampedAfter, 'الورقةُ المختومة تحرّكت');
        $this->assertStringContainsString('OM1100234567', $stampedAfter);
        $this->assertStringNotContainsString('د.إ', $stampedAfter);

        // وغيرُ المختومة: تتبع المتجر — وهو المعلوم لا المفاجئ
        $this->assertNotSame($legacyBefore, $legacyAfter, 'ورقةٌ بلا لقطةٍ يُدّعى لها ثبات');
        $this->assertStringContainsString('OM9999999999', $legacyAfter);
        $this->assertStringContainsString('زهور الخليج الدولية', $legacyAfter);
    }

    /** وكلتاهما تُطبع — الورقةُ القديمة لا تسقط لأنّها بلا لقطة */
    public function test_both_papers_still_print(): void
    {
        $this->actingAs($this->owner);

        $legacy = $this->order('INV-000003', stamp: false);

        $this->assertFalse(Snapshot::stamped($legacy));
        $this->assertStringContainsString('باقة ورد', $this->sheet($legacy));
    }

    /**
     * والرسمُ لا يختم: فتحُ ورقةٍ قديمة مرّةً أو عشرًا لا يكتب لها لقطة.
     *
     * ولو كتبها لصارت الورقةُ بعد أوّل فتحٍ «تاريخيّةً» بحال اليوم — ماضٍ
     * مختلَقٌ بصمت، وهو ما لا يقع.
     */
    public function test_printing_an_old_paper_never_stamps_it(): void
    {
        $this->actingAs($this->owner);

        $legacy = $this->order('INV-000004', stamp: false);

        $this->sheet($legacy);
        $this->sheet($legacy->fresh('items'));

        $this->assertNull($legacy->fresh()->getAttribute(Snapshot::COLUMN), 'الرسمُ كتب لقطةً');
        $this->assertFalse(Snapshot::stamped($legacy->fresh()));
    }

    /* ═══════════ والحدُّ يُقرأ من الصفّ لا من عمودٍ ثانٍ ═══════════ */

    /**
     * `stamped` هي العلامةُ كلُّها — ولا عمودَ ثانٍ يقول ما تقوله.
     *
     * صفٌّ فارغ، أو لقطةٌ بنسخةٍ لا نعرفها: كلاهما «بلا لقطة»، فتُقرأ من
     * الحيّ بقواعد اليوم بدل أن تُقرأ بقواعدَ لا تُفهم.
     */
    public function test_the_marker_is_the_column_itself(): void
    {
        $withNothing = $this->order('INV-000005', stamp: false);
        $this->assertFalse(Snapshot::stamped($withNothing));

        $withFuture = $this->order('INV-000006', stamp: true);
        $withFuture->setAttribute(Snapshot::COLUMN, ['v' => Snapshot::VERSION + 99, 'seller' => ['name' => 'س']]);
        $this->assertFalse(Snapshot::stamped($withFuture), 'لقطةٌ بنسخةٍ مجهولة قُرئت');

        $withStamp = $this->order('INV-000007', stamp: true);
        $this->assertTrue(Snapshot::stamped($withStamp));
    }

    /** وفاتورةُ عميلٍ قديمةٍ كذلك: بلا لقطة، تُطبع، ولا تُختم بالرسم */
    public function test_an_old_customer_invoice_behaves_the_same(): void
    {
        $this->actingAs($this->owner);

        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'جهة']);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create(
            $this->business->id, $customer, [],
            [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100]],
            $this->owner->id,
        ), $this->owner->id);

        $this->assertTrue(Snapshot::stamped($invoice), 'الفاتورةُ الصادرة بلا لقطة');

        // صفٌّ كما كان قبل الهجرة — بلا لقطة
        $invoice->forceFill([Snapshot::COLUMN => null])->save();
        $legacy = $invoice->fresh()->load('items');
        $this->assertFalse(Snapshot::stamped($legacy));

        $html = CustomerInvoiceController::paper(
            (int) $this->business->id, $legacy, $legacy->paidTotal(), $legacy->outstanding(), null,
        )->render();

        $this->assertStringContainsString('زهور الخليج', $html);
        $this->assertNull($legacy->fresh()->getAttribute(Snapshot::COLUMN), 'الرسمُ كتب لقطةً');
    }
}
