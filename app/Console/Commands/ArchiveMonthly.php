<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\BusinessArchive;
use App\Support\Archive\Archives;
use App\Support\Archive\Period;
use App\Support\Archive\Policy;
use Illuminate\Console\Command;

/**
 * أرشيفُ الشهر المنقضي لكلّ متجر — يُجدول أوّلَ كلّ شهر.
 *
 * ═══ ولا يبني شيئًا بنفسه ═══
 *
 * الأمرُ يضع صفًّا ويدفع وظيفةً لكلّ متجر، ثمّ يخرج. والبناءُ في الطابور.
 *
 * والسببُ أنّ المجدولَ خيطٌ واحد يُنادى كلَّ دقيقة: لو بنى مئةَ أرشيفٍ
 * بنفسه لَبقي ساعاتٍ، و`withoutOverlapping` تمنع تشغيلَه التالي — فيتأخّر
 * كلُّ ما بعده في الجدول، ويكفي متجرٌ واحدٌ ضخمٌ ليُعطّل تسعةً وتسعين.
 *
 * وعاملُ الطابور يعالجها واحدةً واحدةً بلا أن يُمسك أحدًا.
 */
class ArchiveMonthly extends Command
{
    protected $signature = 'archive:monthly
        {--business= : معرّف متجر محدّد (اختياري)}
        {--month= : شهرٌ بعينه بصيغة YYYY-MM (افتراضيًّا: الشهر المنقضي)}';

    protected $description = 'طلبُ أرشيف الشهر المنقضي لكل المتاجر — يُبنى في الطابور';

    public function handle(): int
    {
        if (! Policy::enabled()) {
            $this->warn(__('الأرشيف الشهري مُطفأ في إعدادات المنصّة — لم يُطلب شيء.'));

            return self::SUCCESS;
        }

        $period = $this->period();

        if ($period === null) {
            $this->error(__('صيغةُ الشهر غير صحيحة — استعمل YYYY-MM.'));

            return self::FAILURE;
        }

        if (! $period->isClosed()) {
            $this->error(__('لا يُؤرشَف إلّا شهرٌ انتهى — الشهرُ الجاري لم ينتهِ بعد.'));

            return self::FAILURE;
        }

        $query = Business::query();

        if ($id = $this->option('business')) {
            $query->whereKey($id);
        }

        $businesses = $query->get();

        if ($businesses->isEmpty()) {
            $this->warn(__('لا توجد متاجر.'));

            return self::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;
        $failed = [];

        foreach ($businesses as $business) {
            try {
                $archive = Archives::request((int) $business->id, $period, null);

                /*
                 * والقائمُ الجاهزُ يُعدّ «متروكًا» لا «مطلوبًا».
                 *
                 * تشغيلٌ ثانٍ في اليوم نفسه — أو استدراكٌ يدويّ بعد عطب —
                 * لا يُعيد بناءَ ما بُني. وعدٌّ يقول «طُلب مئة» وقد طُلب
                 * ثلاثةٌ تقريرُ حالٍ كاذب.
                 */
                if ($archive->wasRecentlyCreated || $archive->status === BusinessArchive::PENDING) {
                    $this->line("  ✓ {$business->name} → {$period->key()}");
                    $queued++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed[] = $business->name.': '.$e->getMessage();
                $this->line("  <fg=red>✗</> {$business->name} — {$e->getMessage()}");
            }
        }

        /*
         * وتنظيفُ ما مضت مدّتُه مع الطلب لا في أمرٍ ثانٍ.
         *
         * الاحتفاظُ والإنشاءُ وجهان لسؤالٍ واحد: كم أرشيفًا يعيش على القرص.
         * وأمرٌ يُنشئ ولا يحذف يملأ القرصَ في سنة، وأمرُ حذفٍ منفصلٌ يُنسى
         * من الجدول فلا يُنادى أبدًا.
         */
        $pruned = Archives::prune();
        $swept = Archives::sweepWorkspaces();

        $this->newLine();
        $this->info(__('طُلب :queued أرشيفًا، وتُرك :skipped قائمًا.', [
            'queued' => $queued, 'skipped' => $skipped,
        ]));

        if ($pruned) {
            $this->line('  '.__('حُذف :n أرشيفًا انتهت مدّته.', ['n' => $pruned]));
        }

        if ($swept) {
            $this->line('  '.__('نُظّفت :n مساحة عملٍ متروكة.', ['n' => $swept]));
        }

        if ($failed) {
            /*
             * الخروجُ بخطأ لا برسالةٍ في السجلّ — كما في `backup:run`.
             *
             * cron يرسل بريدًا عند الخروج غير الصفريّ وحده. وأمرٌ سقط وخرج
             * بنجاحٍ يُخفي أنّ متجرًا بلا أرشيفٍ هذا الشهر.
             */
            $this->error(__('فشل :n متجرًا — راجع الأسباب أعلاه.', ['n' => count($failed)]));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** الشهرُ المطلوب — من الخيار، وإلّا المنقضي */
    private function period(): ?Period
    {
        $option = trim((string) ($this->option('month') ?? ''));

        if ($option === '') {
            return Period::previous();
        }

        if (! preg_match('/^(\d{4})-(\d{1,2})$/', $option, $m)) {
            return null;
        }

        $month = (int) $m[2];

        if ($month < 1 || $month > 12) {
            return null;
        }

        return Period::of((int) $m[1], $month);
    }
}
