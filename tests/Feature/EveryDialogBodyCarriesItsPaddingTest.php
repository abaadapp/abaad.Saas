<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * جسمُ النافذة يحمل حشوه — لأنّ النافذة لا تحمله عنه.
 *
 * ═══ العطب ═══
 *
 * `DialogHeader` يحمل `p-5 pb-3` و`DialogFooter` يحمل `p-5 pt-3`، و**`DialogContent`
 * لا حشوَ فيه**. فما يُكتب بين الرأس والقاع بلا `px-5 pb-5` تلتصق حقولُه
 * بحافّتَي النافذة — لا هامشَ بينها وبين حدّها.
 *
 * وهي قاعدةٌ يعرفها من كتب أكثر النوافذ (`space-y-4 px-5 pb-5` في ثلاث عشرة
 * نافذة) ولا يعرفها من كتب الباقي: فنافذتا «عميل جديد» و«مورّد جديد» —
 * وهما ما يُفتح وسط كتابة فاتورةٍ أو أمرِ شراء — كانتا ملتصقتين.
 * ونافذتا الاستلام والحذف في أمر الشراء، ونافذةُ رفض الاستلام، وقيدُ اليومية.
 *
 * ═══ ولماذا اختبارٌ لا مراجعة ═══
 *
 * «قائمةٌ تُكتب باليد تنسى التاليَ دائمًا»: ستُّ نوافذ نسيتها، والسابعةُ
 * تُكتب غدًا. فيُقرأ المصدرُ ويُسأل عن الوسم الذي يلي `</DialogHeader>`.
 */
class EveryDialogBodyCarriesItsPaddingTest extends TestCase
{
    /** ما يُقبل حشوًا — والقاعُ يحمل حشوَه بنفسه */
    private const PADDED = ['px-5', 'p-5', 'p-6', 'px-6'];

    public function test_no_dialog_body_touches_the_window_edge(): void
    {
        $offenders = [];

        foreach ($this->screens() as $file => $source) {
            foreach ($this->bodiesAfterHeaders($source) as [$line, $tag, $head]) {
                // والقاعُ يحمل حشوَه: نافذةٌ رأسُها يليه قاعُها لا جسمَ لها
                if (str_starts_with($tag, '<DialogFooter')) {
                    continue;
                }

                /*
                 * ومكوّنٌ يملك تخطيطه: `<MovementForm />` تحشو نفسها بـ`px-5 pb-5`
                 * في ملفّها. وإلزامُ من يستدعيها بغلافٍ محشوٍّ يحشوها مرّتين.
                 * والحرفُ الكبير يفصل المكوّن عن وسم HTML.
                 */
                if (preg_match('/^<[A-Z]/', $tag)) {
                    continue;
                }

                /*
                 * والحشوُ يُقبل على الغلاف أو على أوّل ابنٍ له.
                 *
                 * `PaymentDialog` تلفّ جسمَها بعمودٍ مرنٍ (`flex-1 flex-col`)
                 * ليمرّ ما بداخله تحت اليد، والحشوُ على الابن. وإلزامُ الغلاف
                 * به يكسر التمرير — فيُقرأ صدرُ الجسم لا وسمُه وحده.
                 */
                foreach (self::PADDED as $needle) {
                    if (str_contains($head, $needle)) {
                        continue 2;
                    }
                }

                $offenders[] = $file.':'.$line.' → '.$this->squeeze($tag);
            }
        }

        $this->assertSame([], $offenders, "جسمُ نافذةٍ بلا حشو — يلتصق بحافّتها:\n".implode("\n", $offenders));
    }

    /** والقاعدةُ مطبَّقةٌ فعلًا لا مكتوبةً في فراغ: النوافذُ موجودة */
    public function test_the_scan_actually_reaches_dialogs(): void
    {
        $seen = 0;

        foreach ($this->screens() as $source) {
            $seen += count($this->bodiesAfterHeaders($source));
        }

        // ولو انكسر المسحُ لصار الاختبارُ أخضرَ على لا شيء
        $this->assertGreaterThan(30, $seen, 'المسحُ لم يجد نوافذَ — القراءةُ معطوبة لا الشاشات سليمة');
    }

    /** @return array<string, string> */
    private function screens(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/js'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() === 'tsx') {
                $out[str_replace(base_path().'/', '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    /**
     * الوسمُ الذي يلي كلَّ `</DialogHeader>` — كاملًا ولو امتدّ أسطرًا.
     *
     * و`>` داخل `onSubmit={(e) => …}` ليست نهايةَ الوسم: يُتتبَّع عمقُ
     * الأقواس، ولا يُقفل إلّا على عمق صفر. وبلا ذلك يُقرأ نصفُ الوسم فيُظنّ
     * بلا حشوٍ وهو يحمله.
     *
     * ويُسقط تعليقُ JSX قبل القراءة: تعليقٌ فيه ذكرُ وسمٍ — كالذي يقول
     * «النموذج هنا ليس نموذجًا متداخلًا» — كان يُقرأ وسمًا فيُتّهم ملفٌّ سليم.
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function bodiesAfterHeaders(string $source): array
    {
        $out = [];
        $offset = 0;
        $source = preg_replace('/\{\/\*.*?\*\/\}/s', '', $source) ?? $source;

        while (($at = strpos($source, '</DialogHeader>', $offset)) !== false) {
            $offset = $at + 15;
            $rest = ltrim(substr($source, $offset));

            if (! str_starts_with($rest, '<')) {
                // تعليقٌ أو نصٌّ قبل الوسم — يُتخطّى إلى أوّل وسم
                $next = strpos($rest, '<');
                if ($next === false) {
                    continue;
                }
                $rest = substr($rest, $next);
            }

            $depth = 0;
            $tag = '';

            for ($i = 0; $i < strlen($rest); $i++) {
                $c = $rest[$i];
                $tag .= $c;

                if ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;
                } elseif ($c === '>' && $depth === 0) {
                    break;
                }
            }

            // وصدرُ الجسم معه: الغلافُ وأوّلُ ابنٍ له
            $out[] = [
                substr_count(substr($source, 0, $at), "\n") + 1,
                ltrim($tag),
                // الوسمُ كاملًا ومعه صدرُ ما بداخله: `onSubmit` قد يطول قبل `className`
                substr($rest, 0, strlen($tag) + 400),
            ];
        }

        return $out;
    }

    private function squeeze(string $tag): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $tag)), 0, 80);
    }
}
