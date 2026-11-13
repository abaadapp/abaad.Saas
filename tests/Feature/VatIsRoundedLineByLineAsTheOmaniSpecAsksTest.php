<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Support\Baisa;
use App\Support\CustomerInvoices;
use App\Support\VatLines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ضريبةُ القيمة المضافة سطرًا سطرًا — كما تطلبها PINT-OM، في الصندوق والفاتورة معًا.
 *
 * ═══ ما يحرسه ═══
 *
 *   - ضريبةُ كلّ سطرٍ لا تبعد عن «النسبة × صافي السطر» بأكثر من نصف بيسة
 *     (والمواصفةُ تسمح ببيسة: IBR-168-OM).
 *   - ضريبةُ المستند مجموعُ ضرائب أسطره بالضبط (ALIGNED-IBRP-S-09-OM).
 *   - لا مبلغَ بأكثر من ثلاث منازل (IBR-DEC-03-OM).
 *   - خصمُ المستند يُوزَّع على الأسطر بالبيسات فيجمع إليه بالضبط، ولا يتجاوز
 *     سطرًا، والمعفى يأخذ نصيبَه ولا ضريبةَ عليه.
 *   - الصندوقُ (`VatLines::document`) وفاتورةُ العميل (`CustomerInvoices::compute`)
 *     ومرآتاهما في الواجهة (`vat-lines.ts` و`invoice-totals.ts`) بالأرقام نفسِها:
 *     الحالاتُ في `tests/fixtures/vat-lines-random-cases.json` يقرؤها
 *     `tests/js/vat-lines.test.ts` أيضًا. وتُعاد كتابتُها بـ`VAT_REGENERATE_CASES=1`.
 */
class VatIsRoundedLineByLineAsTheOmaniSpecAsksTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../fixtures/vat-lines-random-cases.json';

    private const RATES = [5, 5, 5, 0, 2.5, 10];

    public function test_each_line_is_within_half_a_baisa_and_the_document_is_their_sum(): void
    {
        mt_srand(5131);

        for ($n = 0; $n < 2000; $n++) {
            $case = $this->tillCase();
            $lines = $this->tillRows($case);
            $doc = VatLines::document($lines, Baisa::of($case['discount']), $case['inclusive']);

            $this->assertSame(array_sum(array_column($doc['lines'], 'tax')), $doc['tax'], "حالة $n: المستندُ غيرُ مجموع أسطره");
            $this->assertSame(min(Baisa::of($case['discount']), array_sum(array_column($lines, 'net'))), array_sum(array_column($doc['lines'], 'discount')), "حالة $n: الحصصُ لا تجمع إلى الخصم");

            foreach ($doc['lines'] as $i => $line) {
                $rate = $lines[$i]['rate'];
                $this->assertGreaterThanOrEqual(0, $line['taxable'], "حالة $n: خصمٌ فوق سطره");
                $exact = $case['inclusive'] ? $line['taxable'] * $rate / (100 + $rate) : $line['taxable'] * $rate / 100;
                $this->assertLessThanOrEqual(0.5 + 1e-6, abs($line['tax'] - $exact), "حالة $n سطر $i: خارج نصف بيسة");
                if ($rate == 0) {
                    $this->assertSame(0, $line['tax'], 'المعفى بلا ضريبة');
                }
            }
        }
    }

    /** خمسة بالمئة على ٢٥٫٧١ = ١٫٢٨٥٥ ← ١٫٢٨٦؛ وعلى ١٠٥ شاملةً = ٥ */
    public function test_a_half_baisa_rounds_up_and_inclusive_is_extracted(): void
    {
        $this->assertSame(1286, VatLines::lineTax(25710, 5, false));
        $this->assertSame(1285, VatLines::lineTax(25700, 5, false));
        $this->assertSame(5000, VatLines::lineTax(105000, 5, true));
        $this->assertSame(0, VatLines::lineTax(105000, 0, true));
        $this->assertSame(0, VatLines::lineTax(0, 5, false));
        // ٢٫٥٪ على ٠٫٠٢٠ = ٠٫٠٠٠٥ ← ٠٫٠٠١
        $this->assertSame(1, VatLines::lineTax(20, 2.5, false));
    }

    public function test_the_shared_random_cases_are_what_the_server_computes(): void
    {
        $shop = Business::create(['name' => 'ضريبة الأسطر', 'type' => 'عام', 'status' => 'نشط']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_rate', 'value' => '5']);

        if (getenv('VAT_REGENERATE_CASES') === '1') {
            mt_srand(20261113);
            $till = [];
            for ($n = 0; $n < 300; $n++) {
                $case = $this->tillCase();
                $doc = VatLines::document($this->tillRows($case), Baisa::of($case['discount']), $case['inclusive']);
                $till[] = $case + ['expect' => ['tax' => Baisa::toMoney($doc['tax']), 'shares' => array_column($doc['lines'], 'discount')]];
            }
            $invoice = [];
            for ($n = 0; $n < 200; $n++) {
                $case = $this->invoiceCase();
                $invoice[] = $case + ['expect' => $this->invoiceTotals($shop, $case)];
            }
            // حالةٌ في كلّ سطر — كملفّ النقاط العشوائيّ: الفرقُ في المراجعة سطرٌ لا صفحة
            $flag = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
            $rows = fn (array $cases) => implode(",\n", array_map(fn ($c) => json_encode($c, $flag), $cases));
            file_put_contents(self::FIXTURE, "{\n\"_about\": ".json_encode('ضريبةُ القيمة المضافة سطرًا سطرًا (PINT-OM) — حالاتٌ عشوائيّةٌ ببذرةٍ ثابتة يقرؤها VatIsRoundedLineByLineAsTheOmaniSpecAsksTest (PHP) و tests/js/vat-lines.test.ts (الواجهة). till: SaleLines::taxFor / tillTax؛ invoice: CustomerInvoices::compute / invoiceTotals. تُعاد كتابتُها بـ VAT_REGENERATE_CASES=1.', $flag)
                .",\n\"till\": [\n".$rows($till)."\n],\n\"invoice\": [\n".$rows($invoice)."\n]\n}\n");
        }

        $shared = json_decode((string) file_get_contents(self::FIXTURE), true);
        $this->assertCount(300, $shared['till']);
        $this->assertCount(200, $shared['invoice']);

        foreach ($shared['till'] as $n => $case) {
            $doc = VatLines::document($this->tillRows($case), Baisa::of($case['discount']), $case['inclusive']);
            $this->assertSame($case['expect']['tax'], Baisa::toMoney($doc['tax']), "الصندوق $n");
            $this->assertSame($case['expect']['shares'], array_column($doc['lines'], 'discount'), "حصص الصندوق $n");
        }

        foreach ($shared['invoice'] as $n => $case) {
            $this->assertSame($case['expect'], $this->invoiceTotals($shop, $case), "الفاتورة $n");
        }
    }

    /** @return array{lines: list<array{price: float, qty: int, addons: float, rate: float}>, discount: float, inclusive: bool} */
    private function tillCase(): array
    {
        $lines = [];
        for ($i = 0, $k = mt_rand(1, 5); $i < $k; $i++) {
            $lines[] = [
                'price' => mt_rand(1, 60000) / 1000,
                'qty' => mt_rand(1, 6),
                'addons' => mt_rand(0, 3) === 0 ? mt_rand(1, 5000) / 1000 : 0.0,
                'rate' => (float) self::RATES[mt_rand(0, count(self::RATES) - 1)],
            ];
        }
        $gross = array_sum(array_map(fn ($l) => Baisa::of($l['price']) * $l['qty'] + Baisa::of($l['addons']), $lines));

        return ['lines' => $lines, 'discount' => mt_rand(0, $gross) / 1000, 'inclusive' => mt_rand(0, 1) === 1];
    }

    /** صفوفُ `VatLines::document` كما يبنيها `SaleLines::taxFor` */
    private function tillRows(array $case): array
    {
        return array_map(fn ($l) => [
            'net' => Baisa::of((float) $l['price']) * (int) $l['qty'] + Baisa::of((float) $l['addons']),
            'rate' => (float) $l['rate'],
        ], $case['lines']);
    }

    /** @return array{lines: list<array{quantity: float, unit_price: float, discount: float, tax_rate: float}>, inclusive: bool} */
    private function invoiceCase(): array
    {
        $lines = [];
        for ($i = 0, $k = mt_rand(1, 5); $i < $k; $i++) {
            $qty = mt_rand(0, 2) === 0 ? mt_rand(1, 5000) / 1000 : (float) mt_rand(1, 9);
            $price = mt_rand(0, 80000) / 1000;
            $lines[] = [
                'quantity' => $qty,
                'unit_price' => $price,
                'discount' => mt_rand(0, 2) === 0 ? mt_rand(0, (int) ($qty * $price * 1000)) / 1000 : 0.0,
                'tax_rate' => (float) self::RATES[mt_rand(0, count(self::RATES) - 1)],
            ];
        }

        return ['lines' => $lines, 'inclusive' => mt_rand(0, 1) === 1];
    }

    /** @return array{subtotal: float, discount: float, tax: float, total: float} */
    private function invoiceTotals(Business $shop, array $case): array
    {
        Setting::updateOrCreate(['business_id' => $shop->id, 'key' => 'tax_mode'], ['value' => $case['inclusive'] ? 'inclusive' : 'exclusive']);
        $t = CustomerInvoices::compute($shop->id, array_map(fn ($l) => $l + ['description' => 'بند'], $case['lines']));

        return ['subtotal' => $t['subtotal'], 'discount' => $t['discount'], 'tax' => $t['tax'], 'total' => $t['total']];
    }
}
