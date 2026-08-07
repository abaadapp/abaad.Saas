<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Order;
use App\Support\BackupService;
use Illuminate\Console\Command;
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
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run
        {--business= : معرّف متجر محدّد (اختياري)}
        {--keep=14 : كم يومًا تُحفظ النسخ قبل حذفها}';

    protected $description = 'إنشاء نسخة احتياطية JSON لبيانات كل المتاجر — مع التحقّق منها';

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
        $dir = 'backups/'.now()->format('Y-m-d');
        $done = 0;
        $failed = [];

        foreach ($businesses as $business) {
            $path = $dir.'/'.BackupService::filename($business->id);

            try {
                $disk->put($path, BackupService::json($business->id));
                $this->verify($disk, $path, $business->id);
                $this->line("  ✓ {$business->name} → {$path}");
                $done++;
            } catch (\Throwable $e) {
                /*
                 * الملف المعطوب يُحذف لا يُترك.
                 *
                 * تركُه يجعله يبدو نسخةً في القائمة، فيُطمأنّ إليه ولا يُفتح
                 * إلا في الأزمة. وغيابُه يُرى في العدّ.
                 */
                $disk->delete($path);
                $failed[$business->id] = $business->name.': '.$e->getMessage();
                $this->line("  <fg=red>✗</> {$business->name} — {$e->getMessage()}");
            }
        }

        $pruned = $this->prune($disk, max(1, (int) $this->option('keep')));

        $disk->put(self::STAMP, json_encode([
            'finished_at' => now()->toIso8601String(),
            'businesses' => $businesses->count(),
            'written' => $done,
            'failed' => $failed,
            'pruned_days' => $pruned,
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
     * يُعيد قراءة الملف من القرص ويطابق ما فيه.
     *
     * لا يكفي أن تنجح الكتابة: القرص الممتلئ يكتب نصف ملف بلا خطأ في كثيرٍ
     * من أنظمة الملفات. والعدّ يُطابق طلبات المتجر لأنها أكثر ما يُستعاد وأثقله.
     */
    private function verify($disk, string $path, int $bid): void
    {
        if (! $disk->exists($path)) {
            throw new \RuntimeException(__('الملف لم يُكتب على القرص.'));
        }

        $raw = $disk->get($path);
        $data = json_decode((string) $raw, true);

        if (! is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(__('الملف مكتوب لكنه لا يُقرأ (JSON معطوب).'));
        }

        if (($data['meta']['business_id'] ?? null) !== $bid) {
            throw new \RuntimeException(__('الملف يحمل معرّف متجر مختلفًا.'));
        }

        $expected = Order::where('business_id', $bid)->count();
        $actual = count($data['orders'] ?? []);

        if ($actual !== $expected) {
            throw new \RuntimeException(__('عدد الطلبات ناقص: :actual من :expected.', [
                'actual' => $actual, 'expected' => $expected,
            ]));
        }
    }

    /** يحذف مجلّدات الأيام الأقدم من المدة، ويرجع عددها */
    private function prune($disk, int $keepDays): int
    {
        $cutoff = now()->subDays($keepDays)->startOfDay();
        $gone = 0;

        foreach ($disk->directories('backups') as $dir) {
            $day = basename($dir);

            // المجلّدات باسم التاريخ وحدها؛ ما لا يُطابق يُترك ولا يُخمَّن فيه
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }

            try {
                if (\Illuminate\Support\Carbon::parse($day)->lt($cutoff)) {
                    $disk->deleteDirectory($dir);
                    $gone++;
                }
            } catch (\Throwable) {
                // تاريخ لا يُفهم يبقى: الحذف الخاطئ لا يُستدرك
                continue;
            }
        }

        return $gone;
    }
}
