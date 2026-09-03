<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تصحيحٌ لمرّةٍ واحدة: أوقاتٌ كُتبت بساعةٍ وتُقرأ بأخرى.
 *
 * أعمدة الوقت في القاعدة بلا منطقةٍ زمنية (`timestamp without time zone`):
 * تُكتب وتُقرأ بمنطقة التطبيق. وكانت المنطقة UTC بينما المحلّ يعمل بتوقيت
 * عُمان — فكلّ ما كُتب قبل تصحيحها متأخّرٌ أربع ساعاتٍ عن ساعة الحائط:
 * فاتورةٌ طُبعت الثامنة مساءً مكتوبٌ عليها الرابعة، ومبيعاتُ ما بعد منتصف
 * الليل تقع في يوم أمس.
 *
 * وبعد التصحيح تصير القاعدة على ساعتين: ما قبله متأخّرٌ أربعًا، وما بعده
 * صحيح. فهذا الأمر يرفع القديم إلى الساعة نفسها ليبقى الجدول على ساعةٍ
 * واحدة.
 *
 * ولا يُشغَّل من تلقاء نفسه ولا في نشرٍ: يكتب على تواريخ فواتيرَ وقيودٍ
 * وأوقاتِ ورديات. يُشغَّل مرّةً بيد صاحبه، وبعد نسخةٍ احتياطية.
 *
 *   php artisan clock:shift --before=2026-09-01 --dry-run
 *   php artisan clock:shift --before=2026-09-01 --force
 *
 * والحدّ يقبل ساعةً مع اليوم — ويلزمه أحيانًا: إن وقع التصحيح في منتصف يوم
 * عملٍ فصفوفُ صباحه كُتبت بالساعة القديمة وصفوفُ مسائه بالجديدة، ويومٌ كامل
 * حدًّا يُزيح المساء مرّةً ثانية أو يترك الصباح متأخّرًا. فيُكتب الحدّ عند
 * لحظة النشر لا عند منتصف الليل:
 *
 *   php artisan clock:shift --before="2026-09-01 09:00:00" --force
 */
class ShiftStoredClock extends Command
{
    protected $signature = 'clock:shift
        {--hours=4 : كم ساعةً تُضاف إلى ما كُتب قبل التصحيح}
        {--before= : اللحظة التي بدأت عندها الساعة الصحيحة (YYYY-MM-DD أو YYYY-MM-DD HH:MM:SS) — مطلوب}
        {--dry-run : عُدّ ولا تكتب}
        {--force : اكتب فعلًا}';

    protected $description = 'يرفع الأوقات المكتوبة بالمنطقة القديمة إلى المنطقة الحالية — مرّةً واحدة';

    /** الأعمدة التي يقرؤها تاجرٌ أو تقرير — الجدول ← أعمدته */
    private const COLUMNS = [
        'orders' => ['ordered_at', 'scheduled_for', 'created_at', 'updated_at'],
        'order_items' => ['created_at', 'updated_at'],
        'transactions' => ['occurred_at', 'created_at', 'updated_at'],
        'expenses' => ['spent_at', 'created_at', 'updated_at'],
        'journal_entries' => ['posted_at', 'created_at', 'updated_at'],
        'inventory_movements' => ['created_at', 'updated_at'],
        'shifts' => ['opened_at', 'closed_at', 'created_at', 'updated_at'],
        'purchase_orders' => ['received_at', 'created_at', 'updated_at'],
        'supplier_invoices' => ['paid_at', 'created_at', 'updated_at'],
        'activities' => ['created_at', 'updated_at'],
        'customers' => ['created_at', 'updated_at'],
        'products' => ['created_at', 'updated_at'],
    ];

    public function handle(): int
    {
        /*
         * الحدّ يومٌ أو لحظة — والمقارنة على القيمة المخزَّنة كما هي.
         *
         * اليوم وحده يكفي إن وقع التصحيح بين يومين. وإن وقع في منتصف يومٍ
         * فيه بيع، لزمت الساعة: بدونها يُزاح مساءُ ذلك اليوم مرّةً ثانية —
         * وهو أسوأ من ترك القديم كما هو، لأنّ الجدول يصير على ثلاث ساعات
         * بدل اثنتين ولا يُعرف أيُّ صفٍّ في أيّها.
         */
        $before = trim((string) $this->option('before'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $before)) {
            $this->error('يلزم ‎--before=YYYY-MM-DD أو ‎--before="YYYY-MM-DD HH:MM:SS" — اللحظة التي صارت عندها الساعة صحيحة.');

            return self::FAILURE;
        }

        $hours = (int) $this->option('hours');
        $write = (bool) $this->option('force') && ! $this->option('dry-run');

        if (! $write) {
            $this->warn('عَدٌّ بلا كتابة. أضف ‎--force للتنفيذ.');
        }

        $driver = DB::connection()->getDriverName();
        $total = 0;

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                // الحدّ على العمود نفسه: صفٌّ كُتب بعد التصحيح لا يُزاح مرّتين
                $rows = DB::table($table)->whereNotNull($column)->where($column, '<', $before)->count();

                if ($rows === 0) {
                    continue;
                }

                $total += $rows;
                $this->line(sprintf('%-22s %-16s %d', $table, $column, $rows));

                if ($write) {
                    $expr = match ($driver) {
                        'pgsql' => "{$column} + interval '{$hours} hours'",
                        'mysql', 'mariadb' => "DATE_ADD({$column}, INTERVAL {$hours} HOUR)",
                        default => "datetime({$column}, '+{$hours} hours')",
                    };
                    DB::table($table)->whereNotNull($column)->where($column, '<', $before)
                        ->update([$column => DB::raw($expr)]);
                }
            }
        }

        $this->info(($write ? 'أُزيح ' : 'سيُزاح ').$total." قيمةً بمقدار {$hours} ساعات.");

        return self::SUCCESS;
    }
}
