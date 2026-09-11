<?php

namespace Tests\Feature;

use App\Support\Document\PaperSize;
use App\Support\Document\Pdf\MpdfDriver;
use App\Support\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * المطبعةُ لا تطلب من الشبكة شيئًا، ولا تكتب في الترويسة ما لم يُنقَّ.
 *
 * ═══ البابانِ اللذان أُغلقا، وهذا حارسُهما ═══
 *
 * ١ — **الطلبُ من الخادم (SSRF).** mpdf يسمح افتراضًا بـ`http` و`https` في
 *     مصادر الصور. وحقلُ الشعار يقبل نصًّا يبدأ بـ`http` ويمرّره كما هو
 *     (`InvoiceBranding::logo`). فرابطٌ إلى ‎169.254.169.254 أو إلى شبكةٍ
 *     داخلية يجعل **الخادمَ** يطلبه نيابةً عمّن كتبه — بابٌ يُطرق به ما لا
 *     يُطرق من الخارج. وأُغلق بـ`whitelistStreamWrappers => []`.
 *
 * ٢ — **حقنُ ترويسة التنزيل.** اسمُ الملفّ يُبنى من رقم الورقة، ورقمُها يبدأ
 *     ببادئةٍ يكتبها التاجر. وتنقيتُها عند الحفظ تُزيل ما يكسر شرطَ `LIKE`
 *     — `%` و`_` و`\` — ولا تُزيل علامةَ التنصيص ولا السطرَ الجديد. فبادئةٌ
 *     فيها `"` تُغلق اقتباسَ `filename` ويصير ما بعدها وسائطَ أخرى.
 *
 * وهذا الملفّ لا يوسّع شيئًا: يثبّت الاثنين بالتنفيذ لا بالقراءة.
 *
 * ═══ وكيف يُقاس «لم يُجلب» ═══
 *
 * بالحجم: ورقةٌ أُدمجت فيها صورةٌ أكبرُ من ورقةٍ لم تُدمج. فتُرسم ورقةٌ
 * بصورة `data:` — وهي المصدرُ الشرعيُّ الوحيد في النظام — ويُقاس حجمُها،
 * ثمّ تُرسم بكلّ مصدرٍ ممنوع ويُقاس. والممنوعُ يُخرج ورقةً **واحدة** بحجمٍ
 * واحد: لا صورةَ فيها، ومن أيّ مصدرٍ كان.
 *
 * وبالزمن أيضًا: محاولةُ اتّصالٍ حقيقيّة بـ‎169.254.169.254 تتعلّق ثوانيَ
 * قبل أن تيأس. فورقةٌ تخرج في جزءٍ من الثانية لم تحاول.
 */
class ThePressFetchesNothingItIsNotGivenTest extends TestCase
{
    use RefreshDatabase;

    /** أصغرُ PNG صالحة — بكسلٌ واحد، تُدمج فعلًا فيكبر الملفّ */
    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function paperWith(string $src): string
    {
        return '<html><head><style>body{font-family:xbriyaz}</style></head><body>'
            .'<p>ورقة</p><img src="'.htmlspecialchars($src, ENT_QUOTES).'" width="40">'
            .'</body></html>';
    }

    private function bytes(string $src): int
    {
        return strlen(Pdf::sheet($this->paperWith($src), 'probe', PaperSize::A4)->getContent());
    }

    /* ═══════════ ١ — لا يُجلب من الشبكة شيء ═══════════ */

    /**
     * الصورةُ المضمّنة تُدمج — وإلّا لم يكن القياسُ يقيس شيئًا.
     *
     * وهو الضابطُ: بلا إثبات أنّ الدمج يقع أصلًا، يمرّ «لم يُدمج» عن محرّكٍ
     * لا يدمج صورًا بحال.
     */
    public function test_an_inline_image_really_is_embedded(): void
    {
        /*
         * والقياسُ بالاختلاف لا بالكِبَر: ورقةٌ أُدمجت فيها صورة قد تخرج
         * **أصغر** — تتبدّل بها مقاطعُ الخطّ المضمَّنة وضغطُ المجرى. فالمهمّ
         * أن تختلف عن ورقةٍ بلا صورة، وأن يتساوى الممنوعُ كلُّه معها.
         */
        $this->assertNotSame(
            $this->bytes('about:blank'),
            $this->bytes('data:image/png;base64,'.self::PIXEL),
            'المحرّكُ لا يدمج الصورَ أصلًا — فالقياسُ لا يقيس شيئًا',
        );
    }

    /**
     * ولا يُجلب شيءٌ من الشبكة ولا من القرص برابط — أيًّا كان الهدف.
     *
     * والعنوانانِ ‎169.254.169.254 و‎127.0.0.1 مقصودان: الأوّلُ خدمةُ بيانات
     * الخادم في السحابة، والثاني شبكةُ الجهاز نفسِه — وهما ما يُطرق بالـSSRF.
     */
    public function test_no_remote_or_local_url_is_ever_fetched(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'abaad-').'.png';
        file_put_contents($secret, base64_decode(self::PIXEL));

        $blocked = [
            'http' => 'http://127.0.0.1:1/logo.png',
            'localhost' => 'http://localhost:1/logo.png',
            'metadata' => 'http://169.254.169.254/latest/meta-data/',
            'https' => 'https://example.invalid/logo.png',
            'file' => 'file://'.$secret,
            'phar' => 'phar://'.$secret,
        ];

        $empty = $this->bytes('about:blank');
        $started = microtime(true);

        foreach ($blocked as $tag => $src) {
            $this->assertSame($empty, $this->bytes($src), "المحرّكُ جلب «{$tag}»");
        }

        // ولا محاولةَ اتّصال: ستُّ أوراقٍ في ثانيتين لا تسع مهلةَ شبكةٍ واحدة
        $this->assertLessThan(
            2.0,
            microtime(true) - $started,
            'الورقةُ تأخّرت — يبدو أنّ اتّصالًا حقيقيًّا حُووِل',
        );

        @unlink($secret);
    }

    /** والقفلُ مكتوبٌ في المحرّك — لا يُزال بسطرٍ يُنسى */
    public function test_the_engine_whitelists_no_stream_wrapper(): void
    {
        $base = new ReflectionMethod(MpdfDriver::class, 'base');
        $base->setAccessible(true);

        $config = $base->invoke(null);

        $this->assertArrayHasKey('whitelistStreamWrappers', $config);
        $this->assertSame([], $config['whitelistStreamWrappers']);
    }

    /** وصورةٌ معطوبةٌ لا تُسقط الورقة — الشعارُ زينةٌ فيها لا شرط */
    public function test_a_broken_image_does_not_take_the_paper_down(): void
    {
        $this->assertGreaterThan(0, $this->bytes('data:image/png;base64,هذا-ليس-صورة'));
        $this->assertGreaterThan(0, $this->bytes('data:image/svg+xml;base64,PHN2Zz48L3N2'));
        $this->assertGreaterThan(0, $this->bytes('/nonexistent/path/to/logo.png'));
    }

    /* ═══════════ ٢ — اسمُ الملفّ لا يدخل الترويسة كما جاء ═══════════ */

    /** @return array{ascii: string, utf8: string} */
    private function names(string $raw): array
    {
        $ascii = new ReflectionMethod(MpdfDriver::class, 'filename');
        $utf8 = new ReflectionMethod(MpdfDriver::class, 'original');
        $ascii->setAccessible(true);
        $utf8->setAccessible(true);

        return ['ascii' => $ascii->invoke(null, $raw), 'utf8' => $utf8->invoke(null, $raw)];
    }

    /** تنصيصٌ أو سطرٌ جديد لا يخرج من الاسم إلى الترويسة */
    public function test_a_quote_or_a_newline_cannot_escape_the_header(): void
    {
        foreach (['inv"-0001', "inv\r\nSet-Cookie: a=b", "inv\n\nX-Evil: 1", 'inv;x=1'] as $raw) {
            $n = $this->names($raw);

            $this->assertStringNotContainsString('"', $n['ascii']);
            $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $n['ascii']);
            // والمرمَّزُ لا تنصيصَ فيه ولا سطر بعد الترميز
            $this->assertStringNotContainsString('"', rawurldecode(rawurlencode($n['utf8'])) === $n['utf8'] ? rawurlencode($n['utf8']) : '');
            $this->assertDoesNotMatchRegularExpression('/[\r\n]/', rawurlencode($n['utf8']));
        }
    }

    /** ولا فاصلَ مسارٍ يقترح على المتصفّح مكانًا غير مجلّد التنزيلات */
    public function test_a_path_separator_never_survives(): void
    {
        foreach (['../../etc/passwd', '..\\..\\windows', '/absolute/name'] as $raw) {
            $n = $this->names($raw);

            foreach ($n as $value) {
                $this->assertStringNotContainsString('/', $value);
                $this->assertStringNotContainsString('\\', $value);
                $this->assertStringNotContainsString('..', $value);
            }
        }
    }

    /**
     * واسمٌ عربيٌّ يبقى عربيًّا — لا يُمحى ولا يكسر الترويسة.
     *
     * التنقيةُ اللاتينيّة وحدها كانت تُسقط كلَّ حرفٍ عربيّ، فبادئةُ «فاتورة-»
     * تنزل ملفًّا اسمُه `document`. و`filename*` بترميز RFC 5987 يحمل الاسمَ
     * كما هو مرمَّزًا بالنسبة المئويّة — فلا تنصيصَ فيه ولا سطرَ جديد.
     */
    public function test_an_arabic_name_survives_encoded(): void
    {
        $n = $this->names('فاتورة-INV-000042');

        $this->assertSame('فاتورة-INV-000042', $n['utf8'], 'الاسمُ العربيّ ضاع');
        $this->assertSame('INV-000042', $n['ascii'], 'البديلُ اللاتينيّ ليس آمنًا');

        $encoded = rawurlencode($n['utf8'].'.pdf');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._~%-]+$/', $encoded);
        $this->assertStringContainsString('%D9%81', $encoded);
    }

    /** واسمٌ لا يبقى منه شيءٌ يُسمّى `document` — لا يُترك فارغًا */
    public function test_a_name_emptied_by_cleaning_gets_one(): void
    {
        foreach (['', '   ', '...', '///', '"""'] as $raw) {
            $this->assertSame('document', $this->names($raw)['ascii']);
        }
    }

    /** والترويسةُ نفسُها تحمل الشكلين معًا */
    public function test_the_header_carries_both_forms(): void
    {
        $response = Pdf::sheet($this->paperWith('about:blank'), 'فاتورة"x', PaperSize::A4);
        $header = $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('filename="x.pdf"', $header);
        $this->assertStringContainsString("filename*=UTF-8''", $header);
        $this->assertSame(1, substr_count($header, 'filename="'), 'الترويسةُ فيها اسمان مقتبسان');
    }
}
