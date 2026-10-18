<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Business;
use App\Models\Order;
use App\Models\Setting;
use App\Support\DocumentTemplates;
use App\Support\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * الإيصالُ الطويل يخرج كلُّه — شريطًا واحدًا لا يُبتر لأنّه طويل.
 *
 * ═══ ما يُحرس هنا ═══
 *
 * زبونٌ بطلبٍ كبير يأخذ إيصالًا فيه كلُّ صنف، ثمّ المجاميع، ثمّ الرمز والتذييل.
 * والخادمُ يرسم الإيصالَ PDF يطبعه التطبيق (`mobile/PROTOCOL.md` §5):
 *
 *   - إيصالٌ يتّسع لورقة القياس (مترين) صفحةٌ واحدة بطوله تمامًا — كما كان.
 *   - وما زاد صفحاتٌ لا تتجاوز مترين، يصلها التطبيق على الورق بلا فراغٍ ولا قصّ.
 *     فلا صفحةَ تتجاوز ما تَعِد به مواصفةُ PDF (٢٠٠ بوصة).
 *   - ورأسُ جدول الأصناف مرّةً واحدة: mpdf يكرّر `<thead>` في كلّ صفحة، فيخرج
 *     «Item Price Qty Total» وسطَ الأصناف عند كلّ وصلة.
 *   - والأسماءُ الطويلة تلتفّ بين كلماتها بحجم خطّ القالب: لا كلمةَ مقطوعة،
 *     ولا جدولٌ مصغَّر. كان mpdf يُصغّر جدولَ هذه الأصناف إلى ٢٫٩ نقطة على
 *     ٥٨ مم (خطّ الورقة كان يمحو المسافات — `keep_spaces`)، فبدا الإيصالُ
 *     قصيرًا لأنّه لا يُقرأ: ألفُ صنفٍ مترين ونصفًا. وبخطٍّ يُقرأ هي خمسةَ عشر
 *     مترًا، والمئتان ثلاثة أمتار — صفحاتٌ يصلها التطبيق كما وُصف أعلاه.
 *
 * والنصُّ يُقرأ من الـPDF نفسِه (`pages()`): mpdf يكتب كلَّ حرفٍ برمزه في
 * UTF-16 وخريطتُه إلى Unicode هويّة — فما يُقرأ هنا هو ما يُرسم.
 */
class ALongReceiptPrintsWholeOnOneStripTest extends TestCase
{
    use RefreshDatabase;

    /** ارتفاعُ ورقة القياس في `MpdfDriver` — أطولُ صفحةٍ في الشريط */
    private const SHEET_MM = 2000.0;

    private const FOOTER = 'END OF RECEIPT';

    public static function sizes(): array
    {
        return [
            '58mm · 50' => [58, 50],
            '58mm · 100' => [58, 100],
            '58mm · 200' => [58, 200],
            '80mm · 50' => [80, 50],
            '80mm · 100' => [80, 100],
            '80mm · 200' => [80, 200],
        ];
    }

    public function test_a_one_item_receipt_is_one_page_exactly_as_tall_as_its_content(): void
    {
        foreach ([58, 80] as $mm) {
            [$order, $pdf] = $this->receipt($mm, 1);
            $pages = PdfText::pages($pdf);

            $this->assertCount(1, $pages, "{$mm}mm");
            $html = PdfController::saleHtml($order->business_id, $order, thermal: true)['html'];
            $this->assertEqualsWithDelta(Pdf::stripHeight($html, $mm), $pages[0]['height'], 0.2, "{$mm}mm: الشريطُ بطول محتواه كما كان");
            $this->assertEqualsWithDelta($mm, $pages[0]['width'], 0.2);
            $this->assertWhole($pages, 1, "{$mm}mm");
        }
    }

    /**
     * كلُّ صنفٍ والتذييلُ كلُّه — على صفحةٍ واحدة بطوله ما اتّسعت له ورقةُ القياس،
     * وعلى صفحاتٍ لا تتجاوزها إن لم تتّسع. وبخطّ القالب لا بخطٍّ مصغَّر.
     *
     * والخمسون والمئة صفحةٌ واحدة على العرضين؛ والمئتان بأسماءٍ تلتفّ أربعةَ
     * أسطرٍ تتجاوز المترين فتصير صفحتين.
     */
    #[DataProvider('sizes')]
    public function test_every_item_and_the_whole_footer_reach_the_strip(int $mm, int $items): void
    {
        [$order, $pdf] = $this->receipt($mm, $items);
        $pages = PdfText::pages($pdf);
        $height = Pdf::stripHeight(PdfController::saleHtml($order->business_id, $order, thermal: true)['html'], $mm);

        if ($height <= self::SHEET_MM) {
            $this->assertCount(1, $pages, "{$items} صنفًا على {$mm}mm تتّسع لورقة القياس: صفحةٌ واحدة");
            $this->assertEqualsWithDelta($height, $pages[0]['height'], 0.2, "{$mm}mm: بطول محتواه");
        } else {
            $this->assertGreaterThanOrEqual(2, count($pages), "{$mm}mm · {$items}");
        }
        if ($items <= 100) {
            $this->assertCount(1, $pages, "{$items} صنفًا على {$mm}mm: صفحةٌ واحدة");
        }
        foreach ($pages as $i => $page) {
            $this->assertLessThanOrEqual(self::SHEET_MM + 0.2, $page['height'], "{$mm}mm صفحة ".($i + 1));
            $this->assertEqualsWithDelta($mm, $page['width'], 0.2);
        }
        $this->assertWhole($pages, $items, "{$mm}mm · {$items}");
        $this->assertReadable($pdf, $mm, "{$mm}mm · {$items}");
    }

    /**
     * ألفُ صنفٍ بأسماءٍ طويلة نحو خمسةَ عشر مترًا على العرضين — أطولُ من ورقة القياس.
     * فتُقسم صفحاتٍ لا تتجاوز مترين، ولا يسقط صنفٌ ولا يتكرّر عند الوصلة.
     */
    public function test_a_receipt_longer_than_the_measuring_sheet_is_split_into_bounded_pages(): void
    {
        foreach ([58, 80] as $mm) {
            [, $pdf] = $this->receipt($mm, 1000);
            $pages = PdfText::pages($pdf);

            $this->assertGreaterThanOrEqual(2, count($pages), "{$mm}mm");
            foreach ($pages as $i => $page) {
                $this->assertLessThanOrEqual(self::SHEET_MM + 0.2, $page['height'], "{$mm}mm صفحة ".($i + 1));
                $this->assertEqualsWithDelta($mm, $page['width'], 0.2);
            }
            $this->assertWhole($pages, 1000, "{$mm}mm · 1000");
            $this->assertReadable($pdf, $mm, "{$mm}mm · 1000");
        }
    }

    /**
     * لا كلمةَ مقطوعة، ولا حجمَ خطٍّ لا يكتبه الإيصالُ نفسُه بأسماءٍ قصيرة.
     *
     * والمرجعُ الإيصالُ نفسُه بخمسة أصنافٍ اسمُ كلٍّ منها «Rose»: الرأسُ
     * والملاحظةُ والمجاميعُ والرمزُ والتذييل — ولا اسمَ يضيق عنه عمودُه، فحجومُه
     * حجومُ القالب. والجدولُ المصغَّر يكتب حجومًا مضروبةً في نسبة تصغيره.
     */
    private function assertReadable(string $pdf, int $mm, string $case): void
    {
        $this->assertSame([], PdfText::wordCuts($pdf), "{$case}: سطرٌ قُطعت فيه كلمة");

        [, $reference] = $this->receipt($mm, 5, 'Rose');
        $sizes = fn (string $p) => array_values(array_unique(array_map(fn (array $l) => round($l['size'], 2), PdfText::lines($p))));
        $this->assertSame([], array_values(array_diff($sizes($pdf), $sizes($reference))), "{$case}: حجمُ خطٍّ لا يكتبه القالب — جدولٌ مصغَّر");
    }

    /**
     * ملفّاتُ الإيصالات التي يطبعها اختبارُ iOS من أوّلها إلى قصّها
     * (`mobile/ios/.../LongReceiptTests.swift`). تُولَّد بطلبٍ صريح وحده:
     *
     *   ABAAD_WRITE_RECEIPT_FIXTURES=1 vendor/bin/phpunit --filter fixtures
     */
    public function test_fixtures_for_the_native_printers(): void
    {
        if (! getenv('ABAAD_WRITE_RECEIPT_FIXTURES')) {
            $this->markTestSkipped('ABAAD_WRITE_RECEIPT_FIXTURES=1 يكتب mobile/shared/fixtures/receipts');
        }

        $dir = base_path('mobile/shared/fixtures/receipts');
        @mkdir($dir, 0777, true);
        foreach ([58, 80] as $mm) {
            foreach ([1, 50, 100, 200, 1000] as $items) {
                [, $pdf] = $this->receipt($mm, $items);
                $this->assertWhole(PdfText::pages($pdf), $items, "{$mm}mm · {$items}");
                file_put_contents("{$dir}/receipt-{$mm}mm-{$items}.pdf", $pdf);
            }
        }
    }

    // ─── الإيصال ───

    /**
     * طلبٌ بـ$items صنفًا كما يبيعه متجرٌ حقيقيّ: أسماءٌ عربيّةٌ وإنجليزيّةٌ طويلة
     * تلتفّ أسطرًا، وكميّات وأسعار، وملاحظةٌ لكلّ خامس، وملاحظةُ طلب.
     *
     * والأصنافُ التي تُعدّ إنجليزيّة: الأوّلُ والأوسطُ والأخيرُ وكلُّ زوجيّ — فيُقرأ
     * رقمُها من الـPDF كما هو («Item 500 Premium»).
     *
     * و`$name` يسمّي الأصنافَ كلَّها باسمٍ واحد — مرجعُ حجوم الخطّ (`assertReadable`).
     *
     * @return array{0: Order, 1: string}
     */
    private function receipt(int $mm, int $items, ?string $name = null): array
    {
        $business = Business::create([
            'name' => 'متجر الإيصال الطويل', 'type' => 'عام', 'status' => 'نشط',
            'order_notes_and_edits_enabled' => true,
        ]);
        foreach (['strip' => "{$mm}mm", 'footer' => 'شكرًا لزيارتكم — '.self::FOOTER, 'footer_en' => 'Thank you — '.self::FOOTER] as $field => $value) {
            Setting::create(['business_id' => $business->id, 'key' => DocumentTemplates::key('sale', $field), 'value' => $value]);
        }

        $order = Order::create([
            'business_id' => $business->id, 'number' => "INV-{$mm}-{$items}", 'total' => 0, 'subtotal' => 0,
            'status' => 'مكتمل', 'is_held' => false, 'ordered_at' => now(),
            'notes' => 'يرجى التغليف بعناية — Please gift wrap carefully',
        ]);

        $total = 0.0;
        $rows = [];
        for ($i = 1; $i <= $items; $i++) {
            $quantity = $i % 3 + 1;
            $rows[] = [
                'order_id' => $order->id,
                'name' => $name ?? (self::english($i, $items)
                    ? "Item {$i} Premium long-stem white roses bouquet with silk ribbon and gift card"
                    : "صنف رقم {$i} باقة ورد جوري أحمر فاخرة مع تغليف ذهبي وشريط حريري طويل"),
                'quantity' => $quantity,
                'price' => 12.345,
                'total' => 12.345 * $quantity,
                'note' => $i % 5 === 0 ? "ملاحظة البند {$i}: بدون بطاقة" : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $total += 12.345 * $quantity;
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $order->items()->insert($chunk);
        }
        $order->update(['total' => $total, 'subtotal' => $total]);
        $order = $order->fresh('items');

        return [$order, PdfController::thermal($business->id, $order)->getContent()];
    }

    private static function english(int $i, int $items): bool
    {
        return $i === 1 || $i === $items || $i === intdiv($items + 1, 2) || $i % 2 === 0;
    }

    /**
     * كلُّ صنفٍ إنجليزيٍّ مرّةً واحدة بالضبط، ثمّ الرأسُ مرّة، والمجاميعُ والرمزُ والتذييل.
     *
     * @param  list<array{width: float, height: float, text: string}>  $pages
     */
    private function assertWhole(array $pages, int $items, string $case): void
    {
        $text = implode("\n", array_column($pages, 'text'));

        $missing = [];
        $twice = [];
        for ($i = 1; $i <= $items; $i++) {
            if (! self::english($i, $items)) {
                continue;
            }
            $seen = substr_count($text, "Item {$i} Premium");
            if ($seen === 0) {
                $missing[] = $i;
            } elseif ($seen > 1) {
                $twice[] = $i;
            }
        }
        $this->assertSame([], $missing, "{$case}: أصنافٌ غابت عن الإيصال");
        $this->assertSame([], $twice, "{$case}: أصنافٌ تكرّرت عند وصلة");

        $this->assertStringContainsString('Item 1 Premium', $text, "{$case}: الأوّل");
        $this->assertStringContainsString('Item '.intdiv($items + 1, 2).' Premium', $text, "{$case}: الأوسط");
        $this->assertStringContainsString("Item {$items} Premium", $text, "{$case}: الأخير");

        $this->assertSame(1, substr_count($text, 'Item Price Qty Total'), "{$case}: رأسُ الجدول مرّةً واحدة");
        $this->assertSame(1, substr_count($text, 'Subtotal'), "{$case}: المجموع الفرعي");
        $this->assertSame(1, substr_count($text, 'Payment method'), "{$case}: وسيلةُ الدفع بعد الإجمالي");
        $this->assertSame(1, substr_count($text, 'Scan to view the invoice'), "{$case}: رمزُ QR");
        $this->assertSame(1, substr_count($text, self::FOOTER), "{$case}: التذييل");

        // والترتيب: الأصنافُ، ثمّ المجاميع، ثمّ الرمز، ثمّ التذييل آخرًا
        $this->assertLessThan(strpos($text, 'Subtotal'), strpos($text, "Item {$items} Premium"), $case);
        $this->assertLessThan(strpos($text, 'Scan to view the invoice'), strpos($text, 'Subtotal'), $case);
        $this->assertLessThan(strpos($text, self::FOOTER), strpos($text, 'Scan to view the invoice'), $case);
    }
}
