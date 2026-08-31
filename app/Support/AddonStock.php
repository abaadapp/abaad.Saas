<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\OrderItemAddon;

/**
 * كم تأكل الإضافة من الرفّ — سؤالٌ واحد بجوابٍ واحد.
 *
 * الإضافة قد تكون خدمةً لا رصيد لها («كتابة اسم»)، وقد تكون قطعةً مقابل
 * قطعة («دبّ»)، وقد تكون قطعةً تأكل ثلاثًا («زيادة ثلاث وردات»). وثلاثتها
 * تمرّ من هنا: نقطة البيع، وفحص التوفّر، وتصحيح الفاتورة، والإلغاء.
 *
 * ولو تفرّق الجواب لخصم البيعُ ثلاثًا وردّ الإلغاءُ واحدة — وهو فرقٌ لا
 * يظهر إلا في جردٍ بعد شهر، ولا يُعرف حينها من أين جاء.
 */
class AddonStock
{
    /**
     * ما تستهلكه إضافةٌ واحدة من صنفها — والصفر لخدمةٍ لا بضاعة.
     *
     * والفراغ يُقرأ واحدة: كلّ إضافةٍ رُبطت بمخزونٍ قبل هذا العمود كانت
     * تُنقص واحدةً بالضبط، فالفراغ هو ذلك السلوك لا سلوكٌ جديد.
     */
    public static function each(Addon $addon): float
    {
        if (! $addon->inventory_product_id) {
            return 0.0;
        }

        $q = $addon->inventory_quantity;

        return $q === null ? 1.0 : max(0.0, (float) $q);
    }

    /**
     * لقطةُ ما أكله بندُ إضافةٍ في فاتورة — من الصفّ لا من الإضافة الحيّة.
     *
     * وللصفوف التي كُتبت قبل وجود اللقطة تُقرأ الإضافةُ الحيّة بواحدةٍ لكلّ
     * إضافة: هو ما كان النظام يفعله يوم كُتبت، فالقراءة أمينةٌ لا تخمين.
     * ولو حُذفت الإضافة فلا صنف ولا استهلاك — وهو أيضًا ما كان يقع.
     *
     * @return array{0: ?int, 1: float}  [معرّف الصنف، المستهلَك لكلّ إضافة]
     */
    public static function snapshot(OrderItemAddon $row): array
    {
        if ($row->inventory_product_id) {
            $q = $row->inventory_quantity;

            return [(int) $row->inventory_product_id, $q === null ? 1.0 : max(0.0, (float) $q)];
        }

        // صفٌّ كُتب بعد الهجرة لإضافةٍ لا مخزون لها: لقطتُه فارغةٌ عمدًا،
        // ولا يُسأل عنها اليوم. ويميّزه عن القديم أنّ العمود كُتب فراغًا
        // لا أنّه لم يكن — ولا سبيل إلى التمييز، فالقاعدة الأسلم: إن كانت
        // الإضافة اليوم بلا مخزون فلا شيء، وإن كانت فواحدةٌ كما كان
        $addon = $row->addon;

        if (! $addon || ! $addon->inventory_product_id) {
            return [null, 0.0];
        }

        return [(int) $addon->inventory_product_id, 1.0];
    }

    /**
     * يجمع استهلاك بنودِ إضافةٍ في خريطةٍ عشرية — قبل التقريب لا بعده.
     *
     * @param  iterable<OrderItemAddon>  $rows
     * @return array<int, float>  [معرّف الصنف => المستهلَك]
     */
    public static function consumedBy(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            [$pid, $each] = self::snapshot($row);
            if ($pid && $each > 0) {
                $out[$pid] = ($out[$pid] ?? 0.0) + $each * (int) $row->quantity;
            }
        }

        return $out;
    }

    /**
     * الكميات الصحيحة التي تُخصم أو تُردّ — بقاعدة الوصفة نفسها.
     *
     * الجمع قبل الرفع: إضافتان بنصف لفّةٍ لكلّ تستهلكان لفّةً واحدة لا
     * لفّتين. وانظر Recipe::units لعلّة الرفع إلى الأعلى.
     *
     * @param  array<int, float>  $consumed
     * @return array<int, int>
     */
    public static function units(array $consumed): array
    {
        $out = [];

        foreach ($consumed as $pid => $q) {
            $u = Recipe::units($q);
            if ($u !== 0) {
                $out[(int) $pid] = $u;
            }
        }

        return $out;
    }
}
