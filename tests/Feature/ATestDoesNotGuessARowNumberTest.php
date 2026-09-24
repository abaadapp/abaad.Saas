<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * لا يكتب اختبارٌ رقمَ صفٍّ بيده — فالرقمُ ليس واحدًا على القاعدتين.
 *
 * ═══ ما وقع ═══
 *
 * `AFileSaysWhatItsScreenSaysTest` كتب `'branch_id' => 1`. وعلى SQLite —
 * قاعدةُ الفحص المحليّ — يبدأ الترقيمُ من 1 في كلّ ملفّ، فمرّ الاختبارُ
 * ومرّت 5494 حالةً خضراء. وعلى PostgreSQL — قاعدةُ الإنتاج — **لا يتراجع
 * العدّادُ مع تراجع المعاملة**: تعلو الأرقامُ بين ملفٍّ وآخر ولا يبقى صفٌّ
 * يحمل 1. فسقطت ستُّ حالاتٍ في البوّابة:
 *
 *   SQLSTATE[23503] … "stock_adjustments_branch_id_foreign"
 *   Key (branch_id)=(1) is not present in table "branches".
 *
 * واحمرارُ البوّابة يوقف النشر، فوقف: أربعُ دفعاتٍ على main والخادمُ على
 * دفعةٍ أقدمَ منها، سبعين دقيقة.
 *
 * ═══ وأخطرُ من السقوط ═══
 *
 * `orders.branch_id` **بلا مفتاحٍ أجنبيّ** — فالرقمُ المخمَّن لا يسقط
 * هناك، بل يشير إلى لا شيء بصمت. فتقرأ تقاريرُ الفروع فرعًا غيرَ الذي
 * كُتب، ولا أحدَ يعلم. عُثر على اثنين من هذا النوع وأُصلحا معه.
 *
 * ═══ فهذا الملفّ ═══
 *
 * يمشي على ملفّات الاختبار كلِّها فيمنع أن يُكتب رقمٌ في مفتاحٍ أجنبيّ.
 * ومن أراد رقمًا لا وجودَ له — «شخصٌ آخر» مثلًا — صرّح به في السطر نفسِه
 * بالعلامة أدناه، فيصير قصدًا مكتوبًا لا سهوًا.
 */
class ATestDoesNotGuessARowNumberTest extends TestCase
{
    /** مفاتيحُ صفوفٍ حقيقيّة — لا `integration_id` ولا `shift_id` الخارجيّة */
    private const KEYS = [
        'branch_id', 'business_id', 'product_id', 'customer_id',
        'supplier_id', 'order_id', 'season_id', 'category_id',
    ];

    /** من أراد رقمًا معدومًا عمدًا كتب هذا في سطره */
    private const DELIBERATE = 'رقمٌ مقصود';

    public function test_no_test_writes_a_row_number_by_hand(): void
    {
        $offenders = [];

        foreach ($this->everyTestFile() as $file) {
            foreach (file($file) as $n => $line) {
                if (str_contains($line, self::DELIBERATE)) {
                    continue;
                }

                foreach (self::KEYS as $key) {
                    if (preg_match("/'{$key}'\s*=>\s*\d+\s*[,)\]]/", $line)) {
                        $offenders[] = str_replace(base_path().'/', '', $file).':'.($n + 1).'  '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['رقمُ صفٍّ مكتوبٌ بيد — يمرّ على SQLite ويسقط أو يشير إلى لا شيء على PostgreSQL:'],
            $offenders,
        )));
    }

    /** @return list<string> */
    private function everyTestFile(): array
    {
        $out = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('tests')));

        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        sort($out);

        return $out;
    }
}
