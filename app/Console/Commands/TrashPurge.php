<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\TrashController;
use Illuminate\Console\Command;

/**
 * يمحو ما انقضت مهلته في سلّة المحذوفات — محوًا لا رجعة فيه.
 *
 * الشاشة كانت تقول «يمكن استعادة ما حُذف خلال ٩٠ يومًا» ولا شيء ينفّذ ذلك:
 * الرقم مرشِّح عرضٍ فقط، والصفوف تبقى في القاعدة أبدًا. فيقرأ التاجر الجملة
 * ويظنّ ما حذفه ذهب وهو باقٍ — وهذا وحده يكفي؛ لكنّ الأسوأ أنه بعد اليوم
 * ٩١ لا يستطيع استعادته ولا محوه: غير مرئيّ وغير قابل للتصرّف معًا.
 *
 * هذا الأمر هو الطرف الآخر من الجملة. وبه يصير الرقم وعدًا، وتتوقّف القاعدة
 * عن حمل كل ما حُذف منذ أوّل يوم.
 */
class TrashPurge extends Command
{
    protected $signature = 'trash:purge {--days= : المهلة بالأيام — تُقرأ من TrashController افتراضًا}
                                        {--dry-run : يعدّ ولا يمحو}';

    protected $description = 'محو المحذوفات التي انقضت مهلة استردادها محوًا نهائيًّا';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: TrashController::WINDOW_DAYS);
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);
        $total = 0;

        /*
         * الفرع ليس في PURGEABLE فلا يمرّ هنا: محوُه يمحو تسجيل صناديقه
         * وأذون موظفيه بالتسلسل، ويترك مبيعاته تشير إلى رقمٍ لا وجود له.
         */
        foreach (TrashController::PURGEABLE as $type) {
            $model = TrashController::MODELS[$type];
            $rows = $model::onlyTrashed()->where('deleted_at', '<', $cutoff)->get();

            foreach ($rows as $row) {
                /*
                 * صفًّا صفًّا لا بجملةٍ واحدة: المحو يجرّ معه ملفًّا على القرص
                 * ورصيدَ المنتج في الفروع، وحذفٌ جماعيّ يترك ذلك كلّه يتيمًا.
                 * والعدد هنا صغير بطبعه — ما حُذف قبل ثلاثة أشهر ولم يُستردّ.
                 */
                if (! $dry) {
                    TrashController::purgeRow($type, $row);
                }
                $total++;
            }

            $this->line(sprintf('  %-8s %d', $type, $rows->count()));
        }

        $this->info($dry
            ? "سيُمحى {$total} صفًّا مضى على حذفها أكثر من {$days} يومًا"
            : "مُحي {$total} صفًّا نهائيًّا (أقدم من {$days} يومًا)");

        return self::SUCCESS;
    }
}
