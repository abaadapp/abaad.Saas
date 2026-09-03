<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\DocumentLink;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Pdf;
use App\Support\PosTerminal;
use App\Support\PublicDocument;
use App\Support\ReceiptTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أوراقُ النظام تخرج من مطبعةٍ واحدة.
 *
 * كانت ستّةُ مواضع تبني mpdf بيدها بهوامشَ تخصّ كلًّا منها، واثنان
 * وعشرون قالبًا يكتب كلٌّ منها `<style>` خاصًّا به: هذا أسودُ الترويسة
 * وذاك بنفسجيّها، وهذا يسمّي الخطّ `sans-serif` وذاك `dejavusans` — وهو
 * خطٌّ **بلا حرفٍ عربيّ واحد**. فتخرج من النظام الواحد أوراقٌ لا يجمعها
 * شكل، والتاجر يرسلها كلَّها باسمه.
 *
 * وهذا الملفّ يحرس أن تبقى واحدة.
 */
class PapersAreOnePressTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function order(int $items = 2, string $number = 'INV-000001'): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id, 'branch' => 'مسقط',
            'number' => $number, 'status' => 'مكتمل', 'customer_name' => 'زبون',
            'employee_name' => 'كاشير', 'payment_method' => 'نقدي',
            'subtotal' => 12 * $items, 'total' => 12 * $items, 'ordered_at' => now(),
        ]);

        for ($i = 0; $i < $items; $i++) {
            OrderItem::create([
                'order_id' => $order->id, 'name' => 'باقة ورد جوري أحمر رقم '.($i + 1),
                'price' => 12, 'quantity' => 1, 'total' => 12,
            ]);
        }

        return $order->load('items');
    }

    /* ————————————— مطبعةٌ واحدة ————————————— */

    /**
     * ولا متحكّمَ يبني mpdf بيده.
     *
     * ستّةُ مواضع كانت تفعل، ولكلٍّ هوامشُه: هذا يكتب ١٢ وذاك ١٤ وثالثٌ ١٠.
     * فالورقةُ التي تخرج من «تصدير المنتجات» لا تشبه التي تخرج من «تقرير
     * المبيعات»، ولا سطرَ في المستودع يقول لماذا.
     */
    public function test_only_one_class_builds_the_engine(): void
    {
        $offenders = [];

        foreach ($this->php(app_path()) as $file) {
            if (str_ends_with($file, 'Support/Pdf.php')) {
                continue;
            }

            if (str_contains(file_get_contents($file), 'new Mpdf(')) {
                $offenders[] = str_replace(app_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, 'ورقةٌ تُبنى خارج App\Support\Pdf');
    }

    /**
     * وكلُّ قالبٍ يرث نظامَ التصميم — لا يكتب خطَّه بيده.
     *
     * والاستثناءان هما النظامُ نفسه: ملفّا الأنماط.
     */
    public function test_no_paper_names_its_own_font(): void
    {
        $offenders = [];

        foreach (glob(resource_path('views/pdf/*.blade.php')) as $file) {
            $name = basename($file);

            // الهيكلُ نفسه ليس ورقة: هو ما ترثه الأوراق
            if ($name === 'layout.blade.php') {
                continue;
            }

            $code = file_get_contents($file);

            if (str_contains($code, 'font-family')) {
                $offenders[] = $name.' (يسمّي خطَّه)';

                continue;
            }

            $inherits = str_contains($code, "@extends('pdf.layout')")
                || str_contains($code, "@include('pdf.partials.strip-style')");

            if (! $inherits) {
                $offenders[] = $name.' (خارج النظام)';
            }
        }

        $this->assertSame([], $offenders);
    }

    /** ولا خطَّ لاتينيًّا يُسمّى لورقةٍ عربية */
    public function test_the_arabic_paper_is_not_set_in_a_latin_font(): void
    {
        /*
         * والتعليقُ يُنزع قبل الفحص: هو يحكي عن الخطّ الذي رُفع، والفحصُ
         * عن الخطّ الذي يُرسم. ولولا نزعُه لَمنع شرحَ ما جرى.
         */
        $css = preg_replace('/\{\{--.*?--\}\}/su', '', file_get_contents(
            resource_path('views/pdf/partials/style.blade.php')
        ));

        $this->assertStringContainsString('xbriyaz', $css);
        $this->assertStringNotContainsString('dejavusans', $css);
    }

    /* ————————————— الشريط الحراريّ ————————————— */

    /**
     * الشريطُ يطول بطول فاتورته.
     *
     * كان مقاسُه مثبَّتًا `[80, 200]` — ورقةً بارتفاع عشرين سنتيمترًا. فإيصالٌ
     * بأربعين صنفًا يُقسَم صفحتين على طابعةٍ لا تعرف الصفحات: يخرج نصفُه،
     * ثمّ يقفز الورق، ثمّ يخرج نصفُه الثاني بلا ترويسةٍ ولا مجموع.
     */
    public function test_the_strip_grows_with_what_is_printed(): void
    {
        $short = Pdf::stripHeight($this->receiptHtml($this->order(2)), 80);
        $long = Pdf::stripHeight($this->receiptHtml($this->order(40, 'INV-000002')), 80);

        $this->assertGreaterThan($short, $long, 'إيصالٌ بأربعين صنفًا لا يطول عن إيصالٍ بصنفين');
        $this->assertGreaterThan(200, $long, 'إيصالٌ طويل ما زال يُحشر في ورقةٍ بارتفاعٍ ثابت');
    }

    /** ولا يقصر عن حدٍّ يُمسك: قصاصةٌ بطول ثلاثة سنتيمترات لا تُقصّ ولا تُقرأ */
    public function test_the_shortest_strip_is_still_a_paper(): void
    {
        $this->assertGreaterThanOrEqual(60, Pdf::stripHeight('<div>x</div>', 80));
    }

    /**
     * وطابعةُ هذا الصندوق تغلب قالب المتجر.
     *
     * القالب إعدادٌ واحد للمتجر كلّه، والصناديق تختلف: صندوق المدخل بورق ٨٠
     * وصندوق التغليف بورق ٥٨. وورقٌ لا يطابق الطابعة يخرج مقصوصًا من
     * الحافة، ويُكتشف بعد أن يأخذه الزبون.
     */
    public function test_the_till_printer_beats_the_shop_template(): void
    {
        $order = $this->order();

        $raw = 'terminal-secret';
        $device = PosDevice::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'name' => 'صندوق التغليف', 'token_hash' => hash('sha256', $raw), 'status' => 'نشط',
        ]);
        PosPeripheral::create([
            'business_id' => $this->business->id, 'pos_device_id' => $device->id,
            'type' => PosPeripheral::PRINTER, 'name' => 'طابعة ضيّقة',
            'paper_width' => 58, 'active' => true,
        ]);

        $wide = $this->actingAs($this->owner)->get(route('admin.orders.pdf', $order->number));
        $wide->assertOk();

        $narrow = $this->withCookie(PosTerminal::COOKIE, $device->id.'|'.$raw)
            ->actingAs($this->owner)->get(route('admin.orders.pdf', $order->number));
        $narrow->assertOk();

        $this->assertSame(80.0, $this->pageWidth($wide->getContent()));
        $this->assertSame(58.0, $this->pageWidth($narrow->getContent()));
    }

    /* ————————————— الرمز والورقة أونلاين ————————————— */

    /** طباعةُ الإيصال تفتح له بابًا أونلاين، ورمزُه يحمل عنوانَه */
    public function test_printing_a_receipt_opens_its_online_copy(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner)->get(route('admin.orders.pdf', $order->number))->assertOk();

        $link = DocumentLink::where('linkable_id', $order->id)->first();

        $this->assertNotNull($link, 'الإيصال طُبع بلا رابطٍ إلى نسخته الحيّة');
        $this->assertSame($this->business->id, $link->business_id);
        $this->assertSame(22, strlen($link->token));

        $this->get(route('paper.show', $link->token))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee('موثّقة عبر أبعاد');

        /*
         * والرمزُ المطبوع يحمل العنوان نفسه.
         *
         * صفٌّ في القاعدة ورمزٌ على الورقة شيئان: يُكتب الصفّ ويبقى الرمز
         * فارغًا، فيمسحه الزبون ولا يجد شيئًا — ولا يشتكي أحد لأنّ الاختبار
         * كان يسأل عن الصفّ وحده.
         */
        $printed = view('pdf.receipt', [
            'order' => $order,
            'tpl' => ReceiptTemplate::forBusiness($this->business->id),
            'qr' => '', 'paperUrl' => PublicDocument::url($order), 'customerTax' => null,
            'googleReview' => null, 'width' => 80,
        ])->render();

        $this->assertStringContainsString(
            '<barcode code="'.route('paper.show', $link->token).'"',
            $printed,
        );
    }

    /**
     * ورمزٌ واحد لورقةٍ واحدة مهما طُبعت.
     *
     * بلا ذلك تصنع كلُّ طباعةٍ رمزًا جديدًا: تُطبع الفاتورة ثلاث مرّات فتحمل
     * النسخُ الثلاث ثلاثةَ عناوين لشيءٍ واحد.
     */
    public function test_a_paper_keeps_one_address_however_often_it_prints(): void
    {
        $order = $this->order();

        $first = PublicDocument::url($order);
        PublicDocument::url($order);
        $third = PublicDocument::url($order);

        $this->assertSame($first, $third);
        $this->assertSame(1, DocumentLink::where('linkable_id', $order->id)->count());
    }

    /**
     * وأوراقُ المورّدين بلا بابٍ عامّ.
     *
     * فيها تكلفةُ البضاعة، ورابطٌ لا يحرسه إلا كونُه غير مخمَّن يضع هامشَ
     * ربح التاجر خلف قصاصةٍ تُصوَّر بهاتف. وقرارُ المالك صريح: أوراق الزبون
     * وحدها.
     */
    public function test_supplier_papers_get_no_public_door(): void
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مشتل']);
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'number' => 'PO-000001',
            'supplier_id' => $supplier->id, 'supplier_name' => 'مشتل',
            'status' => 'مُرسل', 'total' => 50, 'ordered_at' => now(),
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'name' => 'ورد جوري', 'cost' => 5, 'quantity' => 10,
        ]);

        $this->actingAs($this->owner)->get(route('admin.purchases.pdf', $po->id))->assertOk();

        $this->assertSame(0, DocumentLink::count(), 'أمرُ شراءٍ فُتح له بابٌ عامّ');
    }

    /** والرمزُ لا يُخمَّن: عنوانٌ لا يقابله شيءٌ يُردّ لا يُخترع */
    public function test_a_guessed_address_opens_nothing(): void
    {
        $this->get('/i/'.str_repeat('a', 22))->assertNotFound();
    }

    /** ولا يُبنى رمزٌ لطلبٍ لم يُحفظ — معاينةُ المحرّر لا تصنع صفوفًا */
    public function test_an_unsaved_paper_has_no_address(): void
    {
        $this->assertNull(PublicDocument::url(new Order(['business_id' => $this->business->id])));
        $this->assertNull(PublicDocument::url(null));
        $this->assertSame(0, DocumentLink::count());
    }

    /* ————————————— الترويسة ————————————— */

    /** والرقمُ الضريبيّ يُقرأ من موضعٍ واحد، ويظهر في ترويسة الورقة */
    public function test_the_tax_number_reaches_the_header(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_number', 'value' => 'OM1100']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'tpl_show_vat_no', 'value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'tpl_paper', 'value' => 'A4']);

        $html = view('pdf.invoice', [
            'order' => $this->order(),
            'tpl' => ReceiptTemplate::forBusiness($this->business->id),
            'qr' => '', 'paperUrl' => '', 'customerTax' => null, 'googleReview' => null,
        ])->render();

        $this->assertStringContainsString('OM1100', $html);
        $this->assertStringContainsString('فاتورة ضريبية', $html);
    }

    /* ————————————— أدوات ————————————— */

    private function receiptHtml(Order $order): string
    {
        return view('pdf.receipt', [
            'order' => $order,
            'tpl' => ReceiptTemplate::forBusiness($this->business->id),
            'qr' => '', 'paperUrl' => '', 'customerTax' => null, 'googleReview' => null,
            'width' => 80,
        ])->render();
    }

    /**
     * عرضُ صفحة الملفّ بالمليمتر — من `MediaBox` وهي بالنقطة.
     *
     * والفحص على الملفّ نفسه لا على ما مرّرناه للمحرّك: هذا يسأل «ماذا خرج؟»
     * وذاك يسأل «ماذا طلبنا؟» — والثاني يمرّ ولو لم يقرأه المحرّك.
     */
    private function pageWidth(string $pdf): float
    {
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(1, preg_match('/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)/', $pdf, $m),
            'لم يُقرأ مقاسُ الصفحة من الملفّ');

        return round(((float) $m[1]) * 25.4 / 72, 0);
    }

    /** @return array<int, string> */
    private function php(string $dir): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
