<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderEdit;
use Tests\TestCase;

/**
 * مفتاحُ ترجمةٍ لا يضيع في دمج.
 *
 * `lang/en.json` يكتب فيه كلُّ فرعٍ في آخره، فيتعارض الفرعان عند الدمج
 * (كما تعارض فرعُ مبيعات البوتيك مع أتمتة واتساب #259). وحلٌّ خاطئ يُبقي
 * المفتاحَ مرّتين بقيمتين — و`json_decode` يأخذ الأخيرة صامتًا — أو يُسقط
 * كتلةَ أحد الطرفين. و`TranslationCoverageTest` يلتقط النصَّ الحرفيّ في
 * `t('…')` وحده، لا ما يمرّ في متغيّر كوسوم الأنواع والحالات.
 */
class ATranslationIsNeverLostInAMergeTest extends TestCase
{
    private function raw(): string
    {
        return (string) file_get_contents(base_path('lang/en.json'));
    }

    /** @return array<string, string> */
    private function english(): array
    {
        $json = json_decode($this->raw(), true);
        $this->assertIsArray($json, 'lang/en.json ليس JSON صالحًا — دمجٌ ترك علامات تعارض؟');

        return $json;
    }

    public function test_the_english_file_is_whole_and_carries_no_merge_marks(): void
    {
        $this->assertDoesNotMatchRegularExpression('/^(<{7}|={7}|>{7})/m', $this->raw(), 'علاماتُ تعارضٍ بقيت في الملف');
        $this->assertGreaterThan(10000, count($this->english()), 'الملفُّ نقص آلافَ المفاتيح — حلُّ تعارضٍ أخذ طرفًا واحدًا؟');
    }

    public function test_no_key_is_written_twice(): void
    {
        preg_match_all('/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:/m', $this->raw(), $m);
        $keys = array_map(fn ($k) => json_decode('"'.$k.'"'), $m[1]);
        $twice = array_keys(array_filter(array_count_values($keys), fn ($n) => $n > 1));

        $this->assertSame([], $twice, 'مفتاحٌ مكتوبٌ مرّتين — إحدى ترجمتيه تضيع صامتة');
        $this->assertCount(count($this->english()), $keys, 'سطرٌ لم يُقرأ مفتاحًا — شكلُ الملف تغيّر');
    }

    public function test_every_label_the_boutique_sales_panel_passes_through_a_variable_is_translated(): void
    {
        $src = (string) file_get_contents(resource_path('js/Pages/Admin/Boutiques/SalesPanel.tsx'));
        $labels = [];
        foreach (['TYPE_LABEL', 'SETTLE_LABEL', 'STATE_LABEL'] as $map) {
            $this->assertSame(1, preg_match('/const '.$map.'[^=]*=\s*\{(.*?)\};/s', $src, $block), $map.' لم يُعثر عليه');
            preg_match_all("/:\s*'([^']+)'/", $block[1], $m);
            $this->assertNotEmpty($m[1]);
            $labels = [...$labels, ...$m[1]];
        }
        // و`t(e.kind)` و`t(row.order_status)`: أنواعُ التعديل وحالاتُ الطلب كما تُكتب في القاعدة
        $labels = [...$labels, OrderEdit::LINE, OrderEdit::ADDON, OrderEdit::REPLACE_LINE, OrderEdit::CANCEL, Order::CANCELLED, 'مكتمل'];

        $en = $this->english();
        $missing = array_values(array_filter(array_unique($labels), fn ($l) => ! isset($en[$l]) || trim((string) $en[$l]) === ''));
        $this->assertSame([], $missing, 'وسمٌ يمرّ في متغيّر بلا ترجمة إنجليزية');
    }

    public function test_the_keys_of_every_branch_that_met_this_file_survive(): void
    {
        $en = $this->english();
        foreach ([
            // من فرع مبيعات البوتيك
            // (وتنبيهُ التقريب صار خبرًا عن الأوراق القديمة وحدها: الحسابُ واحدٌ في كلّ موضع)
            'نصيب كل جهة من بنود الفاتورة', 'بيع منفرد',
            'في الشهر :n ورقة صدرت قبل توحيد التقريب، مجموعها يختلف عن جمع بنودها بـ :d. الورقة كما صدرت، والجديدة تطابق بنودها بالبيسة.',
            // ومن مستحقّ فواتير العملاء
            'بانتظار التحصيل', 'حصص البوتيكات في هذه الفاتورة',
        ] as $key) {
            $this->assertArrayHasKey($key, $en, 'مفتاحُ مبيعات البوتيك ضاع: '.$key);
        }

        // ومن كلّ طرفٍ التقى بهذا الفرع في الملف: كلُّ نصٍّ حرفيٍّ في شاشته (#259 وواتساب، #260 وإنستغرام)
        foreach (['WhatsappAutomations', 'Instagram'] as $screen) {
            $src = (string) file_get_contents(resource_path("js/Pages/Admin/Integrations/{$screen}.tsx"));
            preg_match_all("/\bt\(\s*'((?:[^'\\\\]|\\\\.)+)'/", $src, $m);
            $this->assertNotEmpty($m[1], $screen.': لم تُقرأ نصوصُه — الاختبارُ لا يقيس شيئًا');
            $lost = array_values(array_filter(array_unique($m[1]), fn ($k) => ! isset($en[stripslashes($k)])));
            $this->assertSame([], $lost, $screen.': مفتاحٌ ضاع في الدمج');
        }
    }
}
