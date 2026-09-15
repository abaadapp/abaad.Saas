<?php

namespace App\Jobs;

use App\Models\BusinessArchive;
use App\Support\Archive\Builder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * بناءُ أرشيفِ شهرٍ — بعيدًا عن الطلب الذي طلبه.
 *
 * ═══ ولمَ لا يُبنى في الطلب ═══
 *
 * البناءُ يكتب اثنتي عشرةَ ورقةَ إكسل، ويرسم كلَّ فاتورةٍ صدرت في الشهر
 * PDF، وينسخ مرفقاتِها، ثمّ يضغط الكلَّ. متجرٌ بألف فاتورةٍ في الشهر يقضي
 * في ذلك دقائق. وطلبُ HTTP ينتظرها يموت عند حدّ المهلة — فيرى التاجر خطأً
 * ٥٠٤ بينما الوظيفةُ ماضيةٌ في الخلفيّة، فيضغط ثانيةً.
 *
 * ═══ والوحدانيّة في طبقتين ═══
 *
 * `ShouldBeUnique` تمنع وظيفتين للصفّ نفسِه في الطابور. وهي **ليست
 * الحارس**: تعتمد على قفلٍ في الذاكرة المؤقّتة، ويسقط القفلُ بانتهاء مهلته
 * أو بمسح الذاكرة. والحارسُ الحقيقيّ فهرسٌ فريد في القاعدة
 * (`business_id, year, month`) — فصفٌّ واحدٌ للشهر مهما تكرّرت الوظائف.
 *
 * وهذه تُوفّر عملًا مكرَّرًا، وذاك يمنع خطأً. ولكلٍّ موضعُه.
 */
class BuildBusinessArchive implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * مرّتان لا ثلاث.
     *
     * الإعادةُ تُفيد في عطبٍ عابر: قفلُ ملفٍّ، أو ذروةُ حملٍ على القاعدة.
     * ولا تُفيد في قرصٍ ممتلئ ولا في شهرٍ أكبرُ من الحدّ — وإعادةُ بناءٍ
     * يستغرق دقائقَ ثلاثَ مرّاتٍ على عطبٍ دائم تُعطّل الطابور لغيره.
     */
    public $tries = 2;

    public array $backoff = [120];

    /**
     * ساعةٌ سقفًا.
     *
     * وهي أوسعُ ممّا يحتاجه أكبرُ متجرٍ عندنا بمراحل. وغايتُها ألّا تبقى
     * وظيفةٌ عالقةٌ تُمسك عاملَ الطابور عن كلّ ما بعدها.
     */
    public $timeout = 3600;

    /** ما دام قفلُ الوحدانيّة قائمًا — ولا يتجاوز مهلةَ التنفيذ كثيرًا */
    public $uniqueFor = 3900;

    public function __construct(public int $archiveId)
    {
        $this->afterCommit();
    }

    /** الصفُّ هو الهويّة: وظيفتان لأرشيفٍ واحد لا تجتمعان */
    public function uniqueId(): string
    {
        return 'archive:'.$this->archiveId;
    }

    public function handle(): void
    {
        $archive = BusinessArchive::find($this->archiveId);

        /*
         * والحالةُ تُعاد قراءتها لا تُفترض.
         *
         * صفٌّ صار «جاهزًا» بين الطلب والتنفيذ — إعادةُ محاولةٍ التقطت بعد
         * نجاحٍ — لا يُبنى ثانيةً: بناؤه يكتب فوق ملفٍّ سليمٍ يُنزَّل الآن.
         */
        if (! $archive || ! in_array($archive->status, [
            BusinessArchive::PENDING,
            BusinessArchive::PROCESSING,
            BusinessArchive::FAILED,
        ], true)) {
            return;
        }

        (new Builder($archive))->run();
    }

    /**
     * ما يقع حين تُستنفد المحاولات.
     *
     * و`Builder` يلتقط استثناءاته كلَّها ويكتب «فشل» بنفسه — فلا يصل إلى
     * هنا إلّا ما خرج عنه: مهلةٌ انتهت، أو ذاكرةٌ نفدت. وصفٌّ يبقى على «قيد
     * الإنشاء» بعد موت وظيفته يجعل التاجر ينتظر ما لن يأتي، ويمنعه من
     * المحاولة ثانيةً.
     */
    public function failed(?\Throwable $e): void
    {
        $archive = BusinessArchive::find($this->archiveId);

        if (! $archive || $archive->status === BusinessArchive::READY) {
            return;
        }

        $archive->update([
            'status' => BusinessArchive::FAILED,
            'failure_reason' => __('توقّف إنشاء الأرشيف قبل أن يكتمل — حاول مرّةً أخرى.'),
            'completed_at' => now(),
        ]);
    }
}
