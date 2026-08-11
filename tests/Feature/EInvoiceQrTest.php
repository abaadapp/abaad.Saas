<?php

namespace Tests\Feature;

use App\Support\EInvoice;
use Tests\TestCase;

/**
 * رمز الفوترة: إمّا أن يكون صحيحًا، أو ألّا يكون.
 *
 * الصيغة TLV ثم Base64 على معيار ZATCA الخليجي. وعطبُها لا يُرى بالعين —
 * يُطبع مربّعٌ يبدو سليمًا، ولا يكتشفه أحد لأن لا أحد يمسحه. فالفحص هنا
 * يفكّ الرمز ويقرأ حقوله بدل أن يقنع بوجوده.
 */
class EInvoiceQrTest extends TestCase
{
    /** @return array<int, array{0: int, 1: string}> */
    private function decode(string $payload): array
    {
        $raw = base64_decode($payload, true);
        $this->assertNotFalse($raw, 'ليس Base64 صالحًا');

        $out = [];
        $i = 0;
        while ($i < strlen($raw)) {
            $tag = ord($raw[$i]);
            $len = ord($raw[$i + 1]);
            $out[$tag] = substr($raw, $i + 2, $len);
            $i += 2 + $len;
        }

        return $out;
    }

    private function order(): object
    {
        return new class
        {
            public $ordered_at;

            public $total = 26.25;

            public $tax = 1.25;

            public function __construct()
            {
                $this->ordered_at = \Illuminate\Support\Carbon::parse('2026-08-11 10:00:00');
            }
        };
    }

    public function test_the_five_fields_come_out_as_they_went_in(): void
    {
        $fields = $this->decode(EInvoice::qrPayload(
            'متجر الورود', 'OM1234567', '2026-08-11T10:00:00+04:00', '26.250', '1.250',
        ));

        $this->assertSame('متجر الورود', $fields[1]);
        $this->assertSame('OM1234567', $fields[2]);
        $this->assertSame('2026-08-11T10:00:00+04:00', $fields[3]);
        $this->assertSame('26.250', $fields[4]);
        $this->assertSame('1.250', $fields[5]);
    }

    public function test_no_tax_number_means_no_code_at_all(): void
    {
        /*
         * الوسم الثاني إلزاميّ، ورمزٌ حقلُه الضريبي فارغ باطلٌ بالتأكيد —
         * وأسوأ من غيابه، لأن تحته سطرًا يقول «رمز الفوترة الإلكترونية»
         * فيظنّ التاجر نفسه ممتثلًا. والورقة نفسها لا تقول «فاتورة ضريبية»
         * بلا رقم: كان الشرط قائمًا للعنوان ومفقودًا للرمز.
         */
        $this->assertSame('', EInvoice::forOrder($this->order(), ['number' => ''], ['name' => 'متجري']));
        $this->assertSame('', EInvoice::forOrder($this->order(), ['number' => '   '], ['name' => 'متجري']));
        $this->assertSame('', EInvoice::forOrder($this->order(), [], ['name' => 'متجري']));
    }

    public function test_a_tax_number_produces_a_code(): void
    {
        $qr = EInvoice::forOrder($this->order(), ['number' => 'OM1234567'], ['name' => 'متجري']);

        $this->assertNotSame('', $qr);
        $this->assertSame('OM1234567', $this->decode($qr)[2]);
    }

    public function test_a_very_long_name_does_not_wreck_the_code(): void
    {
        /*
         * الطول بايتٌ واحد والحرف العربي بايتان، فاسمٌ يتجاوز ١٢٧ حرفًا كان
         * يجعل chr تلتفّ على ٢٥٦ فتُكتب صفرًا — فينهار الوسم وما بعده،
         * ويُطبع مربّعٌ يبدو سليمًا وهو خردة. والأهمّ أن بقيّة الحقول تنجو:
         * الرقم الضريبي والمبلغ يبقيان مقروءين مهما طال الاسم.
         */
        $long = str_repeat('متجر الورود والهدايا ', 40);
        $this->assertGreaterThan(255, strlen($long));

        $fields = $this->decode(EInvoice::qrPayload($long, 'OM1234567', '2026-08-11T10:00:00+04:00', '26.250', '1.250'));

        $this->assertSame('OM1234567', $fields[2]);
        $this->assertSame('26.250', $fields[4]);
        $this->assertLessThanOrEqual(255, strlen($fields[1]));
    }

    public function test_the_cut_does_not_split_a_letter_in_half(): void
    {
        // نصفُ حرفٍ عربيّ يكسر ترميز القارئ، فيُقرأ الاسم كلّه رموزًا
        $fields = $this->decode(EInvoice::qrPayload(
            str_repeat('م', 200), 'OM1234567', '2026-08-11T10:00:00+04:00', '26.250', '1.250',
        ));

        $this->assertSame($fields[1], mb_convert_encoding($fields[1], 'UTF-8', 'UTF-8'));
    }

    public function test_it_no_longer_warns_on_php_85(): void
    {
        /*
         * chr بقيمةٍ فوق ٢٥٥ صار مهجورًا في PHP 8.5: سطرُ تحذيرٍ في كل طلب
         * طباعة، يملأ السجلّ ويخفي ما يستحقّ القراءة فيه.
         */
        set_error_handler(fn ($n, $msg) => throw new \RuntimeException($msg), E_ALL);

        try {
            EInvoice::qrPayload(str_repeat('م', 300), 'OM1', 'x', '1', '0');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue(true);
    }
}
