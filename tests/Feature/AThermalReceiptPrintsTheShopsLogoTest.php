<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\DocumentTemplates;
use App\Support\InvoiceBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * شعارُ المتجر على الإيصال الحراريّ — ٥٨ و٨٠ سواء.
 *
 * ═══ وما كان ═══
 *
 * تاجرٌ رفع شعارَه فلم يره على إيصاله، لسببين معًا:
 *
 *  ١. مقبضُ «شعار المتجر» في قالب البيع كان مطفأً افتراضًا — وحدَه بين
 *     الأوراق كلِّها. فمن لم يفتح «قوالب الأوراق» لا شعارَ له.
 *  ٢. ومن أشعله أخذ رابطًا نسبيًّا (`/storage/logos/…` من قارئ النموذج)،
 *     ومحرّكُ mpdf لا جلسةَ له ولا مضيف: يسقط الرابطُ بلا صوت ويخرج
 *     الإيصالُ بلا شعار. والطابعةُ المباشرة ترسم هذا الـPDF نفسَه.
 *
 * ═══ وما يُثبَت هنا ═══
 *
 *  · متجرٌ لم يحفظ المقبضَ وله شعار → الشعارُ على الإيصال.
 *  · ومن حفظه مطفأً → لا شعار.
 *  · ومتجرٌ بلا شعار → لا `<img>` أصلًا، لا صورةً مكسورة.
 *  · والشعارُ مُضمَّنٌ (`data:`) من `InvoiceBranding::logo` — لا رابطٌ نسبيّ.
 *  · وPNG وJPEG مرفوعان من باب الرفع نفسه يصلان الـPDF صورةً فعلًا.
 *  · و٥٨ و٨٠ كلاهما.
 */
class AThermalReceiptPrintsTheShopsLogoTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');

        $this->shop = Business::create(['name' => 'ROSE', 'type' => 'Flowers', 'status' => 'نشط', 'city' => 'Muscat']);
        $branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'Khuwair']);

        $this->order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $branch->id, 'branch' => 'Khuwair',
            'number' => 'INV-000501', 'status' => 'مكتمل', 'customer_name' => 'Customer',
            'employee_name' => 'Cashier', 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 12, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 12,
            'ordered_at' => now(),
        ]);
        OrderItem::create(['order_id' => $this->order->id, 'name' => 'Rose Bouquet', 'price' => 12, 'quantity' => 1, 'total' => 12]);
        $this->order->load('items');
    }

    /** شعارٌ حقيقيٌّ من باب الرفع نفسه — `InvoiceBranding::storeLogo` */
    private function upload(string $format): void
    {
        $file = UploadedFile::fake()->image('logo.'.$format, 160, 48);
        InvoiceBranding::storeLogo($this->shop, $file);
        $this->order->unsetRelation('business');
    }

    private function width(int $mm): void
    {
        DocumentTemplates::save($this->shop->id, 'sale', ['strip' => $mm.'mm']);
    }

    /** الإيصالُ كما يُطبع — HTML من الوصفة الواحدة */
    private function html(): string
    {
        return PdfController::saleHtml($this->shop->id, $this->order, thermal: true)['html'];
    }

    /** والـPDF نفسُه كما تأخذه الطابعة */
    private function pdf(): string
    {
        return (string) PdfController::thermal($this->shop->id, $this->order)->getContent();
    }

    /** عرضُ أوّل صفحة بالمليمتر من `MediaBox` */
    private static function pageWidthMm(string $pdf): float
    {
        preg_match('#/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+[\d.]+\s*\]#', $pdf, $box);

        return round(((float) ($box[1] ?? 0)) * 25.4 / 72, 1);
    }

    /** الصورُ في الـPDF — كائناتُ XObject من نوع Image */
    private static function images(string $pdf): int
    {
        return preg_match_all('#/Subtype\s*/Image\b#', $pdf);
    }

    /** @return array<string, array{int, string, string}> */
    public static function logos(): array
    {
        return [
            '58mm · PNG' => [58, 'png', 'image/png'],
            '58mm · JPEG' => [58, 'jpg', 'image/jpeg'],
            '80mm · PNG' => [80, 'png', 'image/png'],
            '80mm · JPEG' => [80, 'jpg', 'image/jpeg'],
        ];
    }

    /** @return array<string, array{int}> */
    public static function widths(): array
    {
        return ['58mm' => [58], '80mm' => [80]];
    }

    public function test_the_logo_knob_is_on_until_the_shop_turns_it_off(): void
    {
        $this->assertTrue(DocumentTemplates::defaults('sale')['show_logo']);
        $this->assertTrue(DocumentTemplates::settings($this->shop->id, 'sale')['show_logo']);

        DocumentTemplates::save($this->shop->id, 'sale', ['show_logo' => false]);
        $this->assertFalse(DocumentTemplates::settings($this->shop->id, 'sale')['show_logo']);
    }

    /**
     * متجرٌ لم يلمس المقبضَ وله شعار: الشعارُ على الإيصال، مُضمَّنًا.
     *
     * والصورةُ في الـHTML هي ما تردّه `InvoiceBranding::logo` بعينه — بايتاتُ
     * الملفّ المرفوع مرمَّزةً بنوعه — ولا `/storage/` في الإيصال كلِّه. ثمّ
     * الـPDF نفسُه يحمل صورةً: فالمحرّكُ قرأها، لا أنّ وسمًا كُتب وحسب.
     */
    #[DataProvider('logos')]
    public function test_a_saved_logo_prints_on_the_receipt_by_default(int $mm, string $format, string $mime): void
    {
        $this->width($mm);
        $this->upload($format);

        $stored = (string) DB::table('businesses')->where('id', $this->shop->id)->value('logo');
        $printable = (string) InvoiceBranding::logo($this->shop->id);
        $this->assertSame('data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($stored)), $printable);

        $html = $this->html();
        $this->assertSame(1, substr_count($html, '<img'), 'صورةٌ واحدة: الشعار');
        $this->assertStringContainsString('<img src="'.$printable.'"', $html);
        $this->assertStringNotContainsString('/storage/', $html);
        $this->assertStringNotContainsString($stored, $html);

        $pdf = $this->pdf();
        $this->assertSame((float) $mm, self::pageWidthMm($pdf));
        $this->assertGreaterThan(0, self::images($pdf), "{$mm}mm · {$format}: the logo is drawn into the PDF");
    }

    /** ومن حفظ «مطفأ» يبقى مطفأً — على ٥٨ و٨٠ */
    #[DataProvider('widths')]
    public function test_a_shop_that_turned_the_logo_off_prints_none(int $mm): void
    {
        $this->width($mm);
        $this->upload('png');
        DocumentTemplates::save($this->shop->id, 'sale', ['show_logo' => false]);

        $this->assertStringNotContainsString('<img', $this->html());

        $pdf = $this->pdf();
        $this->assertSame((float) $mm, self::pageWidthMm($pdf));
        $this->assertSame(0, self::images($pdf));
    }

    /** ومتجرٌ بلا شعار: لا وسمَ صورةٍ ولا صورةً مكسورة — والمقبضُ مُشعَل */
    #[DataProvider('widths')]
    public function test_a_shop_without_a_logo_prints_no_image(int $mm): void
    {
        $this->width($mm);
        $this->assertNull(InvoiceBranding::logo($this->shop->id));

        $this->assertStringNotContainsString('<img', $this->html());

        $pdf = $this->pdf();
        $this->assertSame((float) $mm, self::pageWidthMm($pdf));
        $this->assertSame(0, self::images($pdf));
    }

    /** وشعارٌ أُزيل يغيب عن الإيصال التالي — لا يبقى رابطٌ لملفٍّ مُحي */
    #[DataProvider('widths')]
    public function test_a_removed_logo_leaves_the_receipt(int $mm): void
    {
        $this->width($mm);
        $this->upload('png');
        $this->assertStringContainsString('<img', $this->html());

        InvoiceBranding::storeLogo($this->shop->refresh(), null, remove: true);
        $this->order->unsetRelation('business');

        $this->assertStringNotContainsString('<img', $this->html());
    }
}
