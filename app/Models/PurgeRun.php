<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * حذفٌ نهائيٌّ واحد — صفٌّ يقول أين وصل، ويبقى بعد أن تزول الشركة.
 *
 * ═══ والحالُ حالان: أين هي، وكيف انتهت ═══
 *
 * `status` تقول: بانتظار، أو تعمل، أو تمّت، أو سقطت. و`stage` تقول أين
 * وصلت بالضبط: أرشفةٌ، أم رفعٌ، أم استعادةٌ للتحقّق، أم حذفُ صفوفٍ، أم
 * حذفُ ملفّات. وعمودٌ واحدٌ يحمل الاثنين يفقد الفرقَ بين «سقطت وهي
 * تؤرشف» — ولم يُمسّ شيء — و«سقطت وهي تحذف الملفّات» — وقد مُحيت
 * الصفوف. والفرقُ بينهما هو كلُّ ما يحتاجه من يقرأ.
 *
 * ═══ وإعادةُ المحاولة تقرأ هذا الصفّ ═══
 *
 * صفٌّ سقط وقد `verified_at` له ونسختُه البعيدة مبصومة: أرشيفُه تمّ
 * وثبتت استعادتُه، فالإعادةُ تُكمل من الحذف ولا تُعيد أرشفةً تمّت. وصفٌّ
 * سقط قبل ذلك يبدأ من أوّله. فالإعادةُ آمنةٌ لأنّها تقرأ أين وقفت، لا
 * لأنّها تُعيد كلَّ شيءٍ وتأمل.
 */
class PurgeRun extends Model
{
    protected $table = 'business_purges';

    protected $guarded = [];

    /* ═══ الحال ═══ */

    /** صُفَّ في الطابور ولم يلتقطه عاملٌ بعد */
    public const PENDING = 'pending';

    /** التقطه عاملٌ ويعمل الآن */
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /* ═══ المرحلة ═══ */

    public const QUEUED = 'queued';

    public const ARCHIVING = 'archiving';

    public const UPLOADING = 'uploading';

    /** تُنزَّل النسخةُ وتُفكّ وتُفتح — استعادةٌ حقيقيّة قبل أيّ محو */
    public const VERIFYING = 'verifying';

    public const DELETING = 'deleting';

    public const FILES = 'files';

    public const FINISHED = 'done';

    protected $casts = [
        'business_id' => 'integer',
        'requested_by' => 'integer',
        'rows_total' => 'integer',
        'users_deleted' => 'integer',
        'files_deleted' => 'integer',
        'archive_bytes' => 'integer',
        'offsite_bytes' => 'integer',
        'failures' => 'array',
        'verified_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** أما زالت تعمل أو تنتظر عاملًا؟ */
    public function isActive(): bool
    {
        return in_array($this->status, [self::PENDING, self::RUNNING], true);
    }

    /**
     * أأرشيفٌ تمّ وثبتت استعادتُه؟ — فالإعادةُ تُكمل ولا تُعيد.
     *
     * والشروطُ ثلاثةٌ معًا: نسخةٌ بعيدةٌ بمسارها، وبصمتُها، ولحظةُ استعادةٍ
     * نجحت. واثنان من ثلاثةٍ ليس دليلًا: مسارٌ وبصمةٌ بلا استعادةٍ يعني
     * جسمًا مرفوعًا لم يُقرأ بعد.
     */
    public function archiveProven(): bool
    {
        return $this->verified_at !== null
            && (string) $this->offsite_path !== ''
            && (string) $this->offsite_sha256 !== ''
            && (string) $this->archive_sha256 !== '';
    }

    /** أبقيت الشركةُ بعد أن سقطت المحاولة؟ — فثمّ ما يُكمل */
    public function businessStillThere(): bool
    {
        return Business::whereKey($this->business_id)->exists();
    }
}
