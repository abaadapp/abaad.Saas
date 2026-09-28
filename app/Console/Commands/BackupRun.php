<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Support\Archive\Policy as ArchivePolicy;
use App\Support\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * نسخ احتياطي تلقائي لكل المتاجر إلى storage/app/private/backups.
 * يُجدول يوميًا في routes/console.php — ويتطلب cron على الخادم (انظر README: النشر).
 * تشغيل يدوي: php artisan backup:run
 *
 * ثلاثة أشياء كانت ناقصة، وكلّها من نوعٍ واحد: عطبٌ لا يصرخ.
 *
 * ١) لا تحقّق: يُكتب الملف ولا يُقرأ. قرصٌ امتلأ أو ترميزٌ انكسر يُنتج ملفًا
 *    بحجمٍ معقول لا يُفتح — ولا يُكتشف إلا يوم الاستعادة، وهو آخر يومٍ يصلح
 *    للاكتشاف. فصار كل ملفٍ يُقرأ بعد كتابته ويُعدّ ما فيه.
 *
 * ٢) لا احتفاظ: نسخةٌ لكل متجرٍ كل يوم إلى الأبد. مئة متجرٍ بمليون سطر تملأ
 *    القرص في أشهر، ثم يتوقّف النسخ لأن لا مكان — أي أن النسخ الاحتياطي نفسه
 *    هو ما يقتل النسخ الاحتياطي.
 *
 * ٣) لا أثر لآخر تشغيل: مجدولٌ يعمل منذ شهور، ولا سبيل لمعرفة أنه توقّف إلا
 *    بالبحث في القرص. فصار يكتب بصمةً تقرؤها abaad:preflight.
 *
 * ═══ ورابعٌ: النسخةُ على القرص الذي تنسخه ═══
 *
 * كلُّ ما سبق يكتب في `storage/app/private` — وهو القرصُ نفسُه الذي تعيش
 * عليه قاعدةُ البيانات. فعطبُ قرصٍ واحد يأخذ الأصلَ ونسختَه معًا، و«نسخةٌ
 * احتياطيّة» على الوسيط الذي تحمي منه ليست نسخةً احتياطيّة.
 *
 * فصار يُنسخ إلى قرصٍ ثانٍ **إن كان مضبوطًا**. والشرطُ مقصود: لا مزوّدَ
 * يُثبَّت هنا، ولا مفتاحَ يُخترع. المشغّلُ يُعرّف القرصَ في
 * `config/filesystems.php` ويكتب اسمَه في إعدادات المنصّة — وما لم يفعل،
 * يبقى النسخُ المحلّيُّ يعمل كما كان بلا حرفٍ يتغيّر.
 *
 * ═══ وخامسٌ: لكلّ متجرٍ تكرارُه ═══
 *
 * يعمل الأمرُ كلَّ ليلة، ويسأل `BackupService::due` عن كلّ متجر: اليوميُّ
 * يُنسخ كلَّ ليلةٍ كما كان، والأسبوعيُّ والشهريُّ متى مضت مدّتُه منذ آخر
 * نسخة، واليدويُّ لا يُنسخ هنا أبدًا. و`--force` يتخطّى السؤال.
 *
 * والكتابةُ والتحقّقُ والنسخُ البعيد صارت في `BackupService::store` — يقرؤها
 * هذا الأمرُ وزرُّ «إنشاء نسخة الآن» معًا، فلا تفترق نسخةُ الليل عن نسخة
 * الزرّ في شيء.
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run
        {--business= : معرّف متجر محدّد (اختياري)}
        {--keep=14 : كم يومًا تُحفظ النسخ قبل حذفها}
        {--force : انسخ كلّ متجرٍ الآن ولو لم يحن موعدُه بحسب تكراره}';

    protected $description = 'إنشاء نسخة احتياطية مضغوطة (.json.gz) لبيانات المتاجر التي حان موعدها — مع التحقّق منها';

    /** بصمة آخر تشغيل — يقرؤها abaad:preflight */
    public const STAMP = 'backups/last-run.json';

    public function handle(): int
    {
        $query = Business::query();
        if ($id = $this->option('business')) {
            $query->whereKey($id);
        }

        $businesses = $query->get();
        if ($businesses->isEmpty()) {
            $this->warn(__('لا توجد متاجر للنسخ.'));

            return self::SUCCESS;
        }

        $disk = Storage::disk('local');
        $dir = BackupService::DIR.'/'.now()->format('Y-m-d');
        $done = 0;
        $offsite = 0;
        $skipped = 0;
        $failed = [];
        $force = (bool) $this->option('force');

        foreach ($businesses as $business) {
            if (! $force && ! BackupService::due($business->id)) {
                $skipped++;
                $this->line("  · {$business->name} — ".BackupService::frequency($business->id));

                continue;
            }

            try {
                $record = BackupService::store($business->id);
                $this->line("  ✓ {$business->name} → {$record['path']}".($record['offsite'] ? ' ⇄' : ''));
                $done++;
                $offsite += $record['offsite'] ? 1 : 0;

                if (! $record['offsite'] && ArchivePolicy::backupRemoteEnabled()) {
                    $this->line('  <fg=yellow>!</> '.__('تعذّر النسخ إلى القرص البعيد — والنسخة المحلّية سليمة.'));
                }
            } catch (\Throwable $e) {
                /*
                 * الملف المعطوب حذفه `store` قبل أن يرمي — لا يُترك.
                 *
                 * تركُه يجعله يبدو نسخةً في القائمة، فيُطمأنّ إليه ولا يُفتح
                 * إلا في الأزمة. وغيابُه يُرى في العدّ.
                 */
                $failed[$business->id] = $business->name.': '.$e->getMessage();
                $this->line("  <fg=red>✗</> {$business->name} — {$e->getMessage()}");
            }
        }

        $pruned = $this->prune($disk, max(1, (int) $this->option('keep')));

        $disk->put(self::STAMP, json_encode([
            'finished_at' => now()->toIso8601String(),
            'businesses' => $businesses->count(),
            'written' => $done,
            // متاجرُ لم يحن موعدُها بحسب تكرارها — لا فشلٌ ولا إهمال
            'skipped' => $skipped,
            'failed' => $failed,
            'pruned_days' => $pruned,
            /*
             * والبصمةُ تقول أنُسخ بعيدًا أم لا — ويقرؤها `preflight`.
             *
             * وهو السؤالُ الذي لا جواب له اليوم: النسخُ يعمل منذ شهور، ولا
             * موضعَ يقول إنّه كلَّه على القرص الذي يحميه.
             */
            'offsite_disk' => ArchivePolicy::backupRemoteEnabled()
                ? ArchivePolicy::remoteDisk()
                : null,
            'offsite_copied' => $offsite,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->newLine();
        $this->info(__('تم إنشاء :count نسخة احتياطية في :path', [
            'count' => $done, 'path' => $disk->path($dir),
        ]));

        if ($pruned) {
            $this->line('  '.__('حُذفت :n مجلّدات أقدم من :keep يومًا.', [
                'n' => $pruned, 'keep' => (int) $this->option('keep'),
            ]));
        }

        if ($failed) {
            /*
             * الخروج بخطأ لا برسالةٍ في السجل.
             *
             * cron يرسل بريدًا عند الخروج غير الصفري وحده. ونسخٌ فشل وخرج
             * بنجاح هو أسوأ الحالتين: لا نسخة، ولا خبر بأن لا نسخة.
             */
            $this->error(__('فشل :n متجرًا — راجع الأسباب أعلاه.', ['n' => count($failed)]));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * يحذف مجلّدات الأيام الأقدم من المدة، ويرجع عددها.
     *
     * ═══ إلّا آخرَ نسخةٍ لكلّ متجر ═══
     *
     * كان يحذف المجلّدَ كلَّه. ومتجرٌ أسبوعيٌّ أو شهريٌّ أو يدويٌّ نسختُه
     * الأخيرة في مجلّدٍ قديم — فتُحذف ولا يبقى له شيء يعود إليه. فيُترك ذلك
     * الملفُّ وحده، ويُحذف ما سواه، ولا يُحذف المجلّدُ ما دام فيه ما يُحفظ.
     */
    private function prune($disk, int $keepDays): int
    {
        $cutoff = now()->subDays($keepDays)->startOfDay();
        $keep = BackupService::protectedPaths();
        $gone = 0;

        foreach ($disk->directories(BackupService::DIR) as $dir) {
            $day = basename($dir);

            // المجلّدات باسم التاريخ وحدها؛ ما لا يُطابق يُترك ولا يُخمَّن فيه
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }

            try {
                if (! Carbon::parse($day)->lt($cutoff)) {
                    continue;
                }
            } catch (\Throwable) {
                // تاريخ لا يُفهم يبقى: الحذف الخاطئ لا يُستدرك
                continue;
            }

            $held = false;

            foreach ($disk->allFiles($dir) as $file) {
                if (isset($keep[$file])) {
                    $held = true;

                    continue;
                }

                $disk->delete($file);
            }

            if (! $held) {
                $disk->deleteDirectory($dir);
                $gone++;
            }
        }

        return $gone;
    }
}
