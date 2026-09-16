<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\BusinessArchive;
use App\Support\Archive\Archives;
use App\Support\Archive\Period;
use App\Support\Archive\Policy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * جسمُ أمرِ الأرشيف — واحدٌ للأسبوعيّ والشهريّ.
 *
 * ═══ ولمَ أساسٌ ولم يُنسخ الأمر ═══
 *
 * الأمران يفعلان الشيء نفسَه حرفيًّا: يفحصان المقبض، ويحسبان المدى المُغلَق،
 * ويرفضان المدى الجاري، ويطلبان صفًّا لكلّ متجر، ويعدّان المطلوبَ والمتروك،
 * ثمّ ينظّفان ما مضى أجلُه ومساحاتِ العمل المتروكة.
 *
 * ونسخُ ذلك في ملفّين يعني أنّ إصلاحًا في أحدهما لا يبلغ الآخر: عطبٌ في
 * عدّ «المتروك» يُصلَح في الشهريّ ويبقى في الأسبوعيّ سنةً بلا أن يُرى.
 *
 * فالفرقُ كلُّه في سطرين: أيُّ نوعٍ هذا، وبأيّ خيارٍ تُكتب فترتُه.
 *
 * ═══ ولا يبني شيئًا بنفسه ═══
 *
 * يضع صفًّا ويدفع وظيفةً لكلّ متجر، ثمّ يخرج. والبناءُ في الطابور.
 *
 * والسببُ أنّ المجدولَ خيطٌ واحد يُنادى كلَّ دقيقة: لو بنى مئةَ أرشيفٍ
 * بنفسه لَبقي ساعاتٍ، و`withoutOverlapping` تمنع تشغيلَه التالي — فيتأخّر
 * كلُّ ما بعده في الجدول، ويكفي متجرٌ واحدٌ ضخمٌ ليُعطّل تسعةً وتسعين.
 */
abstract class ArchiveRun extends Command
{
    /** `Period::WEEKLY` أو `Period::MONTHLY` */
    abstract protected function type(): string;

    /** اسمُ الخيار الذي تُكتب فيه الفترة — `month` أو `week` */
    abstract protected function periodOption(): string;

    public function handle(): int
    {
        $type = $this->type();

        if (! Policy::enabledFor($type)) {
            $this->warn($type === Period::WEEKLY
                ? __('الأرشيف الأسبوعي مُطفأ في إعدادات المنصّة — لم يُطلب شيء.')
                : __('الأرشيف الشهري مُطفأ في إعدادات المنصّة — لم يُطلب شيء.'));

            return self::SUCCESS;
        }

        $period = $this->resolve();

        if ($period === null) {
            $this->error($type === Period::WEEKLY
                ? __('صيغةُ الأسبوع غير صحيحة — استعمل YYYY-MM-DD ليومٍ فيه.')
                : __('صيغةُ الشهر غير صحيحة — استعمل YYYY-MM.'));

            return self::FAILURE;
        }

        if (! $period->isClosed()) {
            $this->error($type === Period::WEEKLY
                ? __('لا يُؤرشَف إلّا أسبوعٌ انتهى — الأسبوعُ الجاري لم ينتهِ بعد.')
                : __('لا يُؤرشَف إلّا شهرٌ انتهى — الشهرُ الجاري لم ينتهِ بعد.'));

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
                 * تشغيلٌ ثانٍ في اليوم نفسِه — أو استدراكٌ يدويّ بعد عطب —
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
         *
         * و`prune` تحذف ما مضى أجلُه من **النوعين**: الأجلُ مكتوبٌ في الصفّ،
         * فلا يحذف الأسبوعيُّ الشهريَّ بمدّته.
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
             * بنجاحٍ يُخفي أنّ متجرًا بلا أرشيفٍ هذه الفترة.
             */
            $this->error(__('فشل :n متجرًا — راجع الأسباب أعلاه.', ['n' => count($failed)]));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** المدى المطلوب — من الخيار، وإلّا المنقضي */
    private function resolve(): ?Period
    {
        $raw = trim((string) ($this->option($this->periodOption()) ?? ''));

        if ($raw === '') {
            return Period::previous($this->type());
        }

        return $this->type() === Period::WEEKLY
            ? $this->week($raw)
            : $this->month($raw);
    }

    /**
     * أسبوعٌ يُكتب بيومٍ فيه — لا برقم أسبوع.
     *
     * «2026-W37» يبدو أدقّ، وهو أسوأ: من يكتبه في الطرفيّة لا يعرف أيَّ
     * أسبوعٍ هو، ولا يعرف أنّ ISO يعدّ أسبوعَ رأس السنة إلى السنة السابقة
     * أحيانًا. واليومُ يُقرأ من التقويم، و`Period::week` تجد أسبوعَه.
     */
    private function week(string $raw): ?Period
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        try {
            return Period::week(Carbon::parse($raw));
        } catch (\Throwable) {
            return null;
        }
    }

    private function month(string $raw): ?Period
    {
        if (! preg_match('/^(\d{4})-(\d{1,2})$/', $raw, $m)) {
            return null;
        }

        $month = (int) $m[2];

        if ($month < 1 || $month > 12) {
            return null;
        }

        return Period::month((int) $m[1], $month);
    }
}
