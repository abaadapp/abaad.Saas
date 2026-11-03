<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * أيتّسع الخادمُ الآن لصورةٍ قديمةٍ أخرى؟ — يُسأل قبل كلّ صورةٍ في الجولة.
 *
 * `ImageMemory` يحرس فكَّ صورةٍ واحدة: يبقى بعدها مئتا ميغابايت. وهذا يحرس
 * الجولةَ كلَّها بحدودٍ أبعد منه، فلا تأخذ معالجةُ صورٍ لا ينتظرها أحد من
 * البيع وواتساب والدفع ما يحتاجونه:
 *
 *  • **الذاكرةُ المتاحة** (`MemAvailable`) لا تقلّ عن `gate_available_mb`.
 *  • **الحِملُ** في الدقيقة الأخيرة لا يزيد على `gate_max_load` — رقمُ
 *    `/proc/loadavg` كما هو: الخادمُ بمعالجٍ واحد، و٠٫٨ حملُه كلُّه تقريبًا.
 *  • **القرص**: يبقى فيه `gate_min_disk_mb` بعد النسخ.
 *  • **الطوابير**: رسائلُ واتساب وإشعاراتُ الدفع الجاهزةُ للعامل لا تزيد على
 *    `gate_max_queue` — طابورٌ يتراكم يعني عاملًا مشغولًا، فلا يُزاد عليه.
 *
 * وما لا يُقرأ يُعدّ مانعًا لا إذنًا: خادمٌ لا يقول ذاكرتَه أو حِملَه لا
 * تُعالج عليه الصور القديمة.
 *
 * ═══ والرفعُ يسأله ولا ينتظره ═══
 *
 * صورةٌ يرفعها تاجرٌ تُصنع نسخها معها ما دام الخادمُ يتّسع (`ImageVariants::makeQuietly`).
 * وإن لم يتّسع حُفظ أصلُها ونجح الرفع، وتنتظر نسخُها جولةً قصيرةً بعد دقائق
 * (`ImageState::wait`) — لا يتأخّر الرفعُ ولا يُردّ.
 *
 * ═══ ومفتاحُ إيقافٍ عامّ ═══
 *
 * `images:optimize --halt` يوقف كلَّ معالجةٍ للصور قبل الصورة التالية — الجولاتِ
 * والرفعَ معًا (يُحفظ الأصلُ وتنتظر نسخه) — بلا تعديلٍ في البيئة ولا نشر. و`--resume`
 * يعيدها. وهو في الذاكرة المؤقّتة المشتركة فتراه كلُّ عمليّة.
 */
final class ImageGate
{
    /** مفتاحُ الإيقاف العامّ في الذاكرة المؤقّتة المشتركة */
    public const HALT = 'images:halt';

    /** متى أُوقفت المعالجة — أو `null` */
    public static function halted(): ?string
    {
        try {
            $at = Cache::get(self::HALT);
        } catch (\Throwable) {
            // ‏ذاكرةٌ لا تُقرأ لا تُعدّ إيقافًا — الحرّاسُ الآخرون باقون
            return null;
        }

        return is_string($at) && $at !== '' ? $at : null;
    }

    /**
     * سببُ التوقّف وأرقامُه — أو `null` فتُعالج الصورةُ التالية.
     *
     * @return array{reason: string, value: float|int|null, limit: float|int}|null
     */
    public static function blocked(): ?array
    {
        if (self::halted() !== null) {
            return ['reason' => 'halted', 'value' => null, 'limit' => 0];
        }

        if (! config('images.batch_gate', true)) {
            return null;
        }

        $min = max(0, (int) config('images.gate_available_mb', 350));
        $available = ImageMemory::available();

        if ($available === null || $available < $min * 1048576) {
            return ['reason' => 'low_memory', 'value' => $available === null ? null : (int) round($available / 1048576), 'limit' => $min];
        }

        $max = (float) config('images.gate_max_load', 0.8);
        $load = self::load();

        if ($load === null || $load > $max) {
            return ['reason' => 'busy_cpu', 'value' => $load, 'limit' => $max];
        }

        $disk = max(0, (int) config('images.gate_min_disk_mb', 1024));
        $free = @disk_free_space(storage_path('app/public'));

        if ($free === false || $free < $disk * 1048576) {
            return ['reason' => 'low_disk', 'value' => $free === false ? null : (int) round($free / 1048576), 'limit' => $disk];
        }

        $queue = max(0, (int) config('images.gate_max_queue', 20));
        $waiting = self::waiting();

        if ($waiting !== null && $waiting > $queue) {
            return ['reason' => 'queue_busy', 'value' => $waiting, 'limit' => $queue];
        }

        return null;
    }

    /** حِملُ الدقيقة الأخيرة — أو `null` حيث لا يُقرأ */
    public static function load(): ?float
    {
        $file = (string) config('images.loadavg', '/proc/loadavg');
        $text = is_readable($file) ? @file_get_contents($file) : false;

        return is_string($text) && preg_match('/^\s*(\d+(?:\.\d+)?)\s/', $text, $m) ? (float) $m[1] : null;
    }

    /**
     * الأعمالُ الجاهزةُ للعامل ولم يأخذها بعد — في طابور القاعدة وحده.
     *
     * وطابورٌ آخر (`sync` في الاختبار) لا ينتظر فيه شيء: `null`. وسؤالٌ فشل
     * يُعدّ طابورًا لا يُعرف — فيُعدّ ممتلئًا.
     */
    private static function waiting(): ?int
    {
        $connection = (string) config('queue.default');

        if (config('queue.connections.'.$connection.'.driver') !== 'database') {
            return null;
        }

        try {
            return DB::connection(config('queue.connections.'.$connection.'.connection'))
                ->table((string) config('queue.connections.'.$connection.'.table', 'jobs'))
                ->whereNull('reserved_at')->where('available_at', '<=', time())->count();
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
    }
}
