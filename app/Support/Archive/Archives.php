<?php

namespace App\Support\Archive;

use App\Jobs\BuildBusinessArchive;
use App\Models\BusinessArchive;
use App\Support\Activity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * طلبُ أرشيفٍ وحذفُه — بابٌ واحدٌ يدخل منه المجدولُ والزرّ.
 *
 * ═══ ولمَ بابٌ واحد ═══
 *
 * المولّدان اثنان: مجدولٌ أوّلَ كلّ شهر، وزرٌّ في شاشة الإعدادات. وقاعدةُ
 * «لا يُؤرشَف إلّا شهرٌ أُغلق» تُكتب في الموضعين أو في موضعٍ واحد — واثنان
 * يفترقان يومًا: يُشدّد أحدُهما ويُرخي الآخر، فيمرّ من الزرّ ما يردّه
 * المجدول.
 *
 * ═══ والتكرارُ يُمنع في القاعدة لا في الفحص ═══
 *
 * `firstOrCreate` تسأل ثمّ تكتب، وبين السؤال والكتابة يمرّ طلبٌ آخر. وهو
 * ليس فرضًا نظريًّا: التاجر يضغط الزرَّ مرّتين لأنّ الأولى لم تُظهر شيئًا
 * بعد، والمجدولُ قد يعمل في اللحظة نفسها.
 *
 * فالإدراجُ يُحاوَل، وانكسارُ الفهرس الفريد يُلتقط ويُقرأ «هذا موجود» —
 * وهو الوحيد الذي لا يمرّ منه اثنان.
 */
final class Archives
{
    /**
     * يطلب أرشيفَ شهرٍ — ويردّ الصفّ، قائمًا كان أو جديدًا.
     *
     * @param  int|null  $userId  من طلبه بيده، أو `null` للمجدول
     *
     * @throws RuntimeException بلغةٍ تُعرض للتاجر كما هي
     */
    public static function request(int $businessId, Period $period, ?int $userId = null): BusinessArchive
    {
        if (! Policy::enabled()) {
            throw new RuntimeException(__('الأرشيف الشهري غير مفعّل في هذه المنصّة.'));
        }

        /*
         * وشهرٌ لم ينتهِ لا يُسمّى أرشيفًا.
         *
         * أرشيفُ شهرٍ يُقدَّم إلى محاسبٍ أو جهةٍ مراجِعة على أنّه الشهرُ
         * كلُّه. وشهرٌ جارٍ يُعطي ملفًّا ناقصًا يحمل اسمًا كاملًا — فيُبنى
         * عليه إقرارٌ ضريبيٌّ بنصف الشهر.
         */
        if (! $period->isClosed()) {
            throw new RuntimeException(__('لا يُؤرشَف إلّا شهرٌ انتهى — الشهرُ الجاري لم ينتهِ بعد.'));
        }

        $existing = BusinessArchive::where('business_id', $businessId)
            ->where('year', $period->year)
            ->where('month', $period->month)
            ->first();

        if ($existing) {
            return self::revive($existing, $userId);
        }

        try {
            $archive = BusinessArchive::create([
                'business_id' => $businessId,
                'year' => $period->year,
                'month' => $period->month,
                'status' => BusinessArchive::PENDING,
                'archive_version' => Builder::VERSION,
                'generated_by' => $userId,
            ]);
        } catch (QueryException $e) {
            /*
             * سبقنا إليه غيرُنا — وهو نجاحٌ لا فشل.
             *
             * والصفُّ يُقرأ ويُردّ: من ضغط الزرَّ يريد أرشيفَ أغسطس، وقد
             * صار في الطريق. ورسالةُ خطأٍ هنا تجعله يظنّ أنّ شيئًا لم يقع.
             */
            $raced = BusinessArchive::where('business_id', $businessId)
                ->where('year', $period->year)
                ->where('month', $period->month)
                ->first();

            if (! $raced) {
                throw $e;
            }

            return $raced;
        }

        Activity::log('backup', __('طُلب أرشيف بيانات :period', ['period' => $period->key()]), [
            'business_id' => $businessId,
            'subject_type' => BusinessArchive::class,
            'subject_id' => $archive->id,
        ]);

        BuildBusinessArchive::dispatch($archive->id);

        return $archive;
    }

    /**
     * صفٌّ قائم: يُعاد إطلاقُه إن كان قد سقط، ويُترك إن كان حيًّا أو جاهزًا.
     *
     * وهذه هي «الضغطةُ الثانية»: من ضغط مرّتين لا يُنشئ أرشيفين، ومن ضغط
     * على أرشيفٍ فشل يُعيد المحاولة — وهو ما يتوقّعه من زرٍّ يقول «أنشئ».
     */
    private static function revive(BusinessArchive $archive, ?int $userId): BusinessArchive
    {
        if ($archive->status !== BusinessArchive::FAILED) {
            return $archive;
        }

        $archive->update([
            'status' => BusinessArchive::PENDING,
            'failure_reason' => null,
            'started_at' => null,
            'completed_at' => null,
            'generated_by' => $userId ?? $archive->generated_by,
        ]);

        BuildBusinessArchive::dispatch($archive->id);

        return $archive->refresh();
    }

    /**
     * يحذف ملفّاتِ ما مضت مدّتُه، ويردّ عددَها.
     *
     * ═══ والصفُّ يبقى، والملفُّ يذهب ═══
     *
     * حذفُ الصفّ يجعل الشاشة تنسى أنّ أرشيفَ أغسطس كان — فيظنّ التاجر أنّ
     * النظام لم يُنشئه قطُّ، ويشكو. وبقاؤه بحالة «منتهي» يقول الحقيقة: كان،
     * ومضت مدّتُه، ويُعاد إنشاؤه بضغطة.
     *
     * والمساحةُ هي المقصودة، وهي في الملفّ لا في الصفّ.
     */
    public static function prune(?int $months = null): int
    {
        $months ??= Policy::retentionMonths();

        if ($months <= 0) {
            return 0;
        }

        $gone = 0;

        $expired = BusinessArchive::where('status', BusinessArchive::READY)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->cursor();

        foreach ($expired as $archive) {
            if ($archive->storage_path && $archive->storage_disk) {
                try {
                    Storage::disk($archive->storage_disk)->delete($archive->storage_path);
                } catch (\Throwable) {
                    /*
                     * قرصٌ بعيد لا يُجيب لا يوقف تنظيفَ الباقي.
                     *
                     * والصفُّ لا يُقلب «منتهيًا» حينها: قلبُه يُخفي ملفًّا
                     * باقيًا يُحسب من المساحة ولا يعود شيءٌ يحذفه.
                     */
                    continue;
                }
            }

            $archive->update([
                'status' => BusinessArchive::EXPIRED,
                'storage_path' => null,
                'file_size' => null,
                'checksum' => null,
            ]);

            Activity::log('backup', __('انتهت مدّة أرشيف :period وحُذف ملفّه', [
                'period' => $archive->periodKey(),
            ]), [
                'business_id' => $archive->business_id,
                'subject_type' => BusinessArchive::class,
                'subject_id' => $archive->id,
            ]);

            $gone++;
        }

        return $gone;
    }

    /**
     * يُزيل مساحاتِ عملٍ خلّفتها وظائفُ ماتت.
     *
     * `Builder` يمحو مساحتَه في `finally` — إلّا حين تُقتل العمليّةُ كلُّها
     * (مهلةٌ انتهت، أو الذاكرةُ نفدت، أو أُعيد تشغيل العامل). فمجلّدٌ بمئة
     * ميجابايت يبقى على القرص لا يقرؤه شيء، ويتكرّر مع كلّ موت.
     *
     * والحدُّ يومٌ: أطولُ من أيّ بناءٍ ممكن (سقفُه ساعة)، فلا يُحذف تحت يد
     * وظيفةٍ تعمل.
     */
    public static function sweepWorkspaces(): int
    {
        $root = Storage::disk('local')->path('archive-workspace');

        if (! is_dir($root)) {
            return 0;
        }

        $gone = 0;
        $cutoff = now()->subDay()->getTimestamp();

        foreach ((array) glob($root.'/*') as $path) {
            if (! is_string($path) || filemtime($path) > $cutoff) {
                continue;
            }

            is_dir($path)
                ? File::deleteDirectory($path)
                : @unlink($path);

            $gone++;
        }

        return $gone;
    }
}
