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
            $pages = self::pages($pdf);

            $this->assertCount(1, $pages, "{$mm}mm");
            $html = PdfController::saleHtml($order->business_id, $order, thermal: true)['html'];
            $this->assertEqualsWithDelta(Pdf::stripHeight($html, $mm), $pages[0]['height'], 0.2, "{$mm}mm: الشريطُ بطول محتواه كما كان");
            $this->assertEqualsWithDelta($mm, $pages[0]['width'], 0.2);
            $this->assertWhole($pages, 1, "{$mm}mm");
        }
    }

    #[DataProvider('sizes')]
    public function test_every_item_and_the_whole_footer_reach_one_page(int $mm, int $items): void
    {
        [, $pdf] = $this->receipt($mm, $items);
        $pages = self::pages($pdf);

        $this->assertCount(1, $pages, "{$items} صنفًا على {$mm}mm تتّسع لورقة القياس: صفحةٌ واحدة كما كان");
        $this->assertLessThan(self::SHEET_MM, $pages[0]['height']);
        $this->assertWhole($pages, $items, "{$mm}mm · {$items}");
    }

    /**
     * ألفُ صنفٍ نحو ثلاثة أمتار على 80 ومترين ونصف على 58 — أطولُ من ورقة القياس.
     * فتُقسم صفحاتٍ لا تتجاوز مترين، ولا يسقط صنفٌ ولا يتكرّر عند الوصلة.
     */
    public function test_a_receipt_longer_than_the_measuring_sheet_is_split_into_bounded_pages(): void
    {
        foreach ([58, 80] as $mm) {
            [, $pdf] = $this->receipt($mm, 1000);
            $pages = self::pages($pdf);

            $this->assertGreaterThanOrEqual(2, count($pages), "{$mm}mm");
            foreach ($pages as $i => $page) {
                $this->assertLessThanOrEqual(self::SHEET_MM + 0.2, $page['height'], "{$mm}mm صفحة ".($i + 1));
                $this->assertEqualsWithDelta($mm, $page['width'], 0.2);
            }
            $this->assertWhole($pages, 1000, "{$mm}mm · 1000");
        }
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
                $this->assertWhole(self::pages($pdf), $items, "{$mm}mm · {$items}");
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
     * @return array{0: Order, 1: string}
     */
    private function receipt(int $mm, int $items): array
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
                'name' => self::english($i, $items)
                    ? "Item {$i} Premium long-stem white roses bouquet with silk ribbon and gift card"
                    : "صنف رقم {$i} باقة ورد جوري أحمر فاخرة مع تغليف ذهبي وشريط حريري طويل",
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

    // ─── قراءةُ الـPDF ───

    /**
     * صفحاتُ الـPDF بالترتيب: عرضُها وارتفاعُها بالمليمتر، ونصُّها كما رُسم.
     *
     * mpdf يكتب النصَّ سلاسلَ حرفيّة `( … )` بترميز UTF-16BE، وخريطةُ ToUnicode
     * عنده هويّة (`<0000> <FFFF> <0000>`). والمسافةُ في خطّه رمزٌ خاصّ (U+E28D).
     *
     * @return list<array{width: float, height: float, text: string}>
     */
    private static function pages(string $pdf): array
    {
        preg_match_all('#(\d+) 0 obj\s*(.*?)\s*endobj#s', $pdf, $objects, PREG_SET_ORDER);
        $byId = [];
        foreach ($objects as $object) {
            $byId[(int) $object[1]] = $object[2];
        }

        $pages = [];
        foreach ($byId as $body) {
            if (! preg_match('#/Type\s*/Page\b(?!s)#', $body)) {
                continue;
            }
            preg_match('#/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]#', $body, $box);
            preg_match('#/Contents\s+(\d+)\s+0\s+R#', $body, $contents);
            $pages[] = [
                'width' => round(((float) $box[3] - (float) $box[1]) / 72 * 25.4, 1),
                'height' => round(((float) $box[4] - (float) $box[2]) / 72 * 25.4, 1),
                'text' => self::text(self::stream($byId[(int) $contents[1]] ?? '')),
            ];
        }

        return $pages;
    }

    private static function stream(string $object): string
    {
        if (! preg_match('#^(.*?)stream\r?\n(.*)\r?\nendstream#s', $object, $m)) {
            return '';
        }

        return str_contains($m[1], '/FlateDecode') ? (string) @gzuncompress($m[2]) : $m[2];
    }

    /**
     * السلاسلُ الحرفيّة كلُّها بالترتيب، بينها مسافةٌ واحدة: خلايا الصفّ الواحد
     * نصوصٌ منفصلة (`BT … ET`)، فرأسُ الجدول يُقرأ «Item Price Qty Total».
     */
    private static function text(string $content): string
    {
        $out = '';
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $c = $content[$i];
            if ($c === 'E' && substr($content, $i, 2) === 'ET') {
                $out .= "\n";

                continue;
            }
            if ($c !== '(') {
                continue;
            }
            $bytes = '';
            $depth = 1;
            for ($i++; $i < $length && $depth > 0; $i++) {
                $c = $content[$i];
                if ($c === '\\') {
                    $next = $content[++$i] ?? '';
                    if (ctype_digit($next)) {
                        $octal = $next;
                        while (strlen($octal) < 3 && ctype_digit($content[$i + 1] ?? '')) {
                            $octal .= $content[++$i];
                        }
                        $bytes .= chr(octdec($octal) & 0xFF);
                    } else {
                        $bytes .= ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C"][$next] ?? $next;
                    }

                    continue;
                }
                if ($c === '(') {
                    $depth++;
                } elseif ($c === ')' && --$depth === 0) {
                    break;
                }
                $bytes .= $c;
            }
            $out .= mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        }

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{E28D}", ' ', $out)));
    }
}
