<?php

namespace App\Jobs;

use App\Models\PurgeRun;
use App\Models\User;
use App\Support\BusinessPurge;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * محوُ شركةٍ — بعيدًا عن الطلب الذي طلبه.
 *
 * ═══ ولمَ لا يُنفَّذ في الطلب ═══
 *
 * المحوُ يكتب ثمانيةً وثلاثين دفترًا في ZIP، وينسخ مرفقاتِ الشركة، ويشفّر
 * الملفَّ كلَّه، ويرفعه إلى تخزينٍ بعيد، ثمّ **يُنزّله ثانيةً** ويفكُّه
 * ويفتحه للتحقّق، ثمّ يمحو صفوفًا وملفّات. ومتجرٌ بألف فاتورةٍ يقضي في
 * ذلك دقائق — وطلبُ HTTP ينتظرها يموت عند حدّ المهلة.
 *
 * وموتُ الطلب هنا أسوأ منه في كلّ بابٍ آخر: مديرُ المنصّة يرى ٥٠٤ بينما
 * المحوُ ماضٍ في الخلفيّة على بيانات تاجر — فيضغط ثانيةً، أو يظنّ أنّ
 * شيئًا لم يقع.
 *
 * ═══ ولا إعادةَ تلقائيّة ═══
 *
 * `$tries = 1` عن قصد. الإعادةُ التلقائيّة تُفيد في عطبٍ عابرٍ لفعلٍ
 * يُقرأ؛ وهذا فعلٌ يكتب ويمحو. ومهمّةٌ سقطت وهي تمحو الصفوفَ ثمّ أُعيدت
 * وحدَها تمحو على ما مُحي بلا أن ينظر أحدٌ في السبب.
 *
 * فالإعادةُ بيدِ مديرِ المنصّة: يقرأ أين وقفت في صفّها، ثمّ يضغط. وهي
 * آمنةٌ لأنّ الصفَّ يقول ما تمّ — انظر `PurgeRun::archiveProven`.
 *
 * ═══ والوحدانيّةُ في طبقتين ═══
 *
 * `ShouldBeUnique` تمنع مهمّتين للصفّ نفسِه في الطابور، وهي **ليست
 * الحارس**: قفلُها في الذاكرة المؤقّتة يسقط بمسحها. والحارسُ الحقيقيُّ
 * فهرسٌ فريدٌ على `business_id` في القاعدة، وقفلٌ ثالثٌ داخل التنفيذ.
 */
class PurgeBusiness implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** مرّةٌ واحدة — انظر ترويسة الملفّ */
    public $tries = 1;

    /**
     * ساعةٌ سقفًا.
     *
     * وهي أوسعُ ممّا يحتاجه أكبرُ متجرٍ عندنا بمراحل — والرفعَ والتنزيلَ
     * للتحقّق داخلَها. وغايتُها ألّا تبقى مهمّةٌ عالقةٌ تُمسك العاملَ عن
     * كلّ ما بعدها.
     */
    public $timeout = 3600;

    public $uniqueFor = 3900;

    public function __construct(public int $runId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'purge:'.$this->runId;
    }

    public function handle(): void
    {
        $run = PurgeRun::find($this->runId);

        if ($run === null) {
            return;
        }

        /*
         * ويُقيَّد الفعلُ باسم من طلبه — لا «زائرًا».
         *
         * ═══ ولمَ يُضبط المستخدمُ هنا ═══
         *
         * `Activity::log` تقرأ `auth()->user()`، وعاملُ الطابور لا جلسةَ له:
         * فكان سطرُ السجلّ يقول «زائر» عن فعلٍ لا تراجعَ فيه. وأصلُ الشرط
         * أن يُثبت السجلُّ **هويّةَ المدير الذي أجراه** — فبلا هذا يسقط
         * الشرطُ نفسُه يومَ يُسأل: من محا هذه الشركة؟
         *
         * والصفُّ يحمل الاسمَ والمعرّفَ على كلّ حال (`requested_by`)، وهذا
         * يجعل السجلَّ العامَّ يقول ما يقوله الصفّ.
         *
         * وكشفه تشغيلٌ في متصفّحٍ حقيقيّ: الاختبارُ يسحب من الطابور في
         * العمليّة نفسِها التي دخلت، فيرى مستخدمًا حيث لا يراه الخادم.
         */
        $actor = $run->requested_by === null ? null : User::find($run->requested_by);

        if ($actor !== null) {
            Auth::setUser($actor);
        }

        BusinessPurge::execute($run);
    }

    /**
     * ومهمّةٌ سقطت قبل أن تبلغ `execute` تُكتب هنا.
     *
     * `execute` تكتب سقوطَها في صفّها ثمّ ترفع الاستثناء. وهذا للسقوط
     * الذي يقع خارجها: انتهاءُ المهلة، أو عاملٌ قُتل، أو تعذّرُ فكّ
     * التسلسل. ولولاه بقي الصفُّ يقول «تعمل» إلى الأبد وليس ثمّ من يعمل.
     */
    public function failed(?Throwable $e): void
    {
        $run = PurgeRun::find($this->runId);

        if ($run === null || ! $run->isActive()) {
            return;
        }

        $run->update([
            'status' => PurgeRun::FAILED,
            'error' => $e === null
                ? __('توقّفت المهمة في الخلفية بلا سبب مذكور.')
                : mb_substr($e->getMessage(), 0, 480),
            'finished_at' => now(),
        ]);
    }
}
