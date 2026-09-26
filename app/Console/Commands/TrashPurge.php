<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\TrashController;
use App\Models\DismissedNotification;
use App\Models\NotificationState;
use App\Support\Demo;
use App\Support\Notifications;
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
 *
 * ويكنس معها صفوفَ التنبيهات المُخفاة التي انقضت مدّتُها — انظر
 * `purgeDismissals`: ليست محذوفاتٍ تُستردّ، لكنّها مثلُها صفوفٌ لم تعد
 * تعني شيئًا وتنمو بلا سقف.
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

        $this->purgeDismissals($dry);

        return self::SUCCESS;
    }

    /**
     * وصفوفُ التنبيهات المُخفاة التي انقضت مدّتُها.
     *
     * ═══ ولمَ هنا ═══
     *
     * ليست «محذوفات» يستردّها أحد، لكنّها الشيءُ نفسُه: صفوفٌ لم تعد تعني
     * شيئًا وتبقى في القاعدة أبدًا. و`daily-<تاريخ>` صفٌّ كلَّ يومٍ لكلّ من
     * أخفاه، و`order-<رقم>` صفٌّ لكلّ طلب — تنمو بلا سقفٍ ولا تُقرأ.
     *
     * ═══ وهذا تنظيفٌ لا سلوك ═══
     *
     * انقضاءُ المدّة يقع عند القراءة (`Demo::dismissedNotificationKeys`)،
     * فالتنبيهُ يعود وإن لم يُشغَّل هذا الأمرُ ليلةً. وهذه الخطوةُ تمحو ما
     * صار بلا أثر — ولو سقطت لَما تغيّر ما يراه التاجر.
     */
    private function purgeDismissals(bool $dry): void
    {
        $cutoff = now()->subDays(Demo::DISMISSAL_DAYS);
        $stale = DismissedNotification::where('created_at', '<', $cutoff);
        $count = $stale->count();

        if (! $dry && $count > 0) {
            $stale->delete();
        }

        $this->line(sprintf('  %-8s %d', 'تنبيهات', $count));
        $this->info($dry
            ? "سيُمحى {$count} صفَّ إخفاءٍ انقضت مدّته"
            : "مُحي {$count} صفَّ إخفاءٍ انقضت مدّته (أقدم من ".Demo::DISMISSAL_DAYS.' يومًا)');

        $this->purgeFinished($dry);
    }

    /**
     * ودوراتٌ انتهت وخرجت من السجلّ — تُمحى كما تُمحى الإخفاءات.
     *
     * سجلُّ «المكتملة» يعرض ثلاثين يومًا (`Notifications::HISTORY_DAYS`). وما
     * قبلها صفٌّ لا تقرؤه شاشة: لا يُعرض، ولا يمنع تنبيهًا — الدورةُ مغلقةٌ
     * فلا تحجب شيئًا. فبقاؤه نموٌّ بلا سقف.
     *
     * **والمفتوحةُ لا تُمسّ مهما قدُمت**: تأجيلٌ لأسبوعٍ على صفٍّ عمرُه شهران
     * ما زال يحجب تنبيهًا قائمًا — ومحوُه يُعيده فجأةً إلى جرس صاحبه.
     */
    private function purgeFinished(bool $dry): void
    {
        $stale = NotificationState::whereNotNull('resolved_at')
            ->where('resolved_at', '<', now()->subDays(Notifications::HISTORY_DAYS));

        $count = $stale->count();

        if (! $dry && $count > 0) {
            $stale->delete();
        }

        $this->line(sprintf('  %-8s %d', 'دورات', $count));
        $this->info($dry
            ? "سيُمحى {$count} صفَّ متابعةٍ خرج من السجلّ"
            : "مُحي {$count} صفَّ متابعةٍ خرج من السجلّ (أقدم من ".Notifications::HISTORY_DAYS.' يومًا)');
    }
}
