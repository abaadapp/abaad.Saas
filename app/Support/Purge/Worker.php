<?php

namespace App\Support\Purge;

use RuntimeException;

/**
 * أيسحب أحدٌ من الطابور؟ — يُسأل قبل أن يُصفَّ حذفٌ فيه.
 *
 * ═══ ولمَ يُسأل أصلًا ═══
 *
 * مهمّةٌ تُصفّ في طابورٍ لا عاملَ له تبقى فيه إلى الأبد. ولو كان ذلك
 * إشعارًا لتأخّر وحدَه؛ أمّا هذا فمديرُ منصّةٍ كتب اسمَ شركةٍ وأقرّ محوَها
 * ثمّ رأى «قيد التنفيذ» لا يتبدّل — لا يعرف أنّ شيئًا لم يبدأ، فيضغط
 * ثانيةً، أو يظنّ الشركةَ محذوفةً وهي قائمة.
 *
 * وطابورٌ فارغٌ ليس دليلًا على عاملٍ يسحب — بل على أنّ شيئًا لم يُصفَّ بعد.
 * فيُسأل العاملُ وحدَه. وهو منطقُ `Preflight` نفسُه، وتُرك هناك كما هو.
 *
 * ═══ وتعذُّرُ المعرفة ليس إذنًا ═══
 *
 * إن كان `shell_exec` ممنوعًا فلا سبيلَ إلى الجواب. و«لا أعرف» في بابٍ
 * لا تراجعَ فيه تُقرأ «لا» — فيُردّ الحذفُ بكلمةٍ تقول كيف يُتحقَّق يدويًّا.
 */
final class Worker
{
    /**
     * @return bool|null صحيحٌ إن كان عاملٌ يعمل، وnull إن تعذّر السؤال
     */
    public static function running(): ?bool
    {
        /*
         * وجوابٌ مضبوطٌ صراحةً يُقدَّم على السؤال.
         *
         * على خادمٍ مُنع فيه `shell_exec` لا سبيلَ إلى المعرفة، فيضبطه من
         * يعلم. وهو المفتاحُ نفسُه الذي تستعمله الاختبارات — فبوّابةٌ لا
         * يمكن اختبارُ طرفيها تُكتب مرّةً ولا يعرف أحدٌ أتعمل أم لا.
         */
        $assumed = config('purge.assume_worker');

        if ($assumed !== null) {
            return (bool) $assumed;
        }

        $out = self::shell('pgrep -f "[a]rtisan queue:(work|listen)" 2>/dev/null | wc -l');

        return $out === null || trim($out) === '' ? null : ((int) trim($out)) > 0;
    }

    /** أطابورٌ حقيقيٌّ لا `sync`؟ — و`sync` تعني تنفيذًا في الطلب نفسِه */
    public static function queued(): bool
    {
        return config('queue.default') !== 'sync';
    }

    /**
     * @throws RuntimeException إن لم يكن الطابور موثوقًا للبدء
     */
    public static function assertReady(): void
    {
        if (! self::queued()) {
            throw new RuntimeException(__('الطابور مضبوط على sync — لا تعمل المهامّ في الخلفية. لم يبدأ الحذف.'));
        }

        $running = self::running();

        if ($running === false) {
            throw new RuntimeException(__('لا عامل طابور يعمل على هذا الخادم — لم يبدأ الحذف. شغّله ثمّ أعد المحاولة.'));
        }

        if ($running === null) {
            throw new RuntimeException(__('تعذّر التحقّق من عامل الطابور على هذا الخادم — لم يبدأ الحذف. تحقّق بنفسك: pgrep -fa "artisan queue:work"'));
        }
    }

    /** تنفيذُ أمر قراءةٍ — null إن كان `shell_exec` ممنوعًا في هذه البيئة */
    private static function shell(string $cmd): ?string
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (! function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
            return null;
        }

        return @shell_exec($cmd);
    }
}
