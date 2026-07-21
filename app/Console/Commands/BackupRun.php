<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Support\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * نسخ احتياطي تلقائي لكل المتاجر (أو متجر محدّد) إلى storage/app/private/backups.
 * يُجدول يوميًا في routes/console.php — ويتطلب cron على الخادم (انظر README: النشر).
 * تشغيل يدوي: php artisan backup:run
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run {--business= : معرّف متجر محدّد (اختياري)}';

    protected $description = 'إنشاء نسخة احتياطية JSON لبيانات كل المتاجر';

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

        $dir = 'backups/' . now()->format('Y-m-d');
        $count = 0;

        foreach ($businesses as $business) {
            $path = $dir . '/' . BackupService::filename($business->id);
            Storage::disk('local')->put($path, BackupService::json($business->id));
            $this->line("✓ {$business->name} → {$path}");
            $count++;
        }

        $this->info(__('تم إنشاء :count نسخة احتياطية في :path', ['count' => $count, 'path' => Storage::disk('local')->path($dir)]));

        return self::SUCCESS;
    }
}
