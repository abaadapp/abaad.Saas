<?php

namespace App\Support;

use App\Models\PlatformDocumentTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * نشرُ قوالب المستندات — تأكيدٌ واحدٌ من مدير المنصّة على ما رآه.
 *
 * يُنشر الإصدارُ الحاليّ من نافذة الإصدار وهو يُصدر منه (`PlatformDocuments::issue`)
 * أو من شاشة القوالب، على بصمة محتواه ساعةَ رآه المدير: فإن تغيّر القالبُ بين
 * فتحه وتأكيده رُدّ النشر. ويُكتب في القالب من نشره ومتى، وفي سجلّ إصداراته
 * (`PlatformDocumentTemplateVersion`) الإصدارُ ببصمته — ولا ينشر قالبًا تحديثٌ برمجيّ.
 */
class TemplatePublishing
{
    /**
     * ما يمنع نشرَ القالب بمحتواه الحاليّ — بعباراتٍ يقرؤها المدير.
     *
     * @return list<string>
     */
    public static function publishProblems(PlatformDocumentTemplate $template): array
    {
        $out = $template->form ? DocumentForms::problems(DocumentForms::normalize($template->form)) : [];
        if (! $template->form && array_filter($template->terms ?? [], fn ($t) => trim((string) ($t['b'] ?? '')) !== '') === []) {
            $out[] = __('القالب بلا بنود.');
        }
        // نصٌّ يذكر رسومه، أو قاعدةُ مبلغٍ تحتاجها، بلا رقم: وعدٌ بمبلغٍ لا يُعرف
        $needsFee = ($template->includes_amount && $template->amount_rule !== null)
            || array_intersect(['fee', 'fee_short'], DocumentForms::tokens($template->form)) !== [];
        if ($needsFee && $template->default_amount === null) {
            $out[] = __('اكتب رسوم القالب قبل نشره — نصّه يذكرها.');
        }

        return $out;
    }

    /** ينشر الإصدارَ الحاليّ في معاملته — انظر `publishLocked` */
    public static function publish(int $id, User $by, string $hash): PlatformDocumentTemplate
    {
        return DB::transaction(fn () => self::publishLocked($id, $by, $hash));
    }

    /**
     * ينشر الإصدارَ الحاليّ — داخل معاملةٍ قائمة، تحت قفل صفّه.
     *
     * والمنشورُ بالبصمة نفسها يُترك كما هو: نقرتان متتاليتان نشرٌ واحد.
     */
    public static function publishLocked(int $id, User $by, string $hash): PlatformDocumentTemplate
    {
        $template = PlatformDocumentTemplate::whereKey($id)->lockForUpdate()->firstOrFail();
        if (! hash_equals($template->contentHash(), $hash)) {
            throw new RuntimeException(__('تغيّر القالب منذ فتحته — راجعه من جديد ثمّ انشره.'));
        }
        if ($template->issuable()) {
            return $template;
        }
        if ($problems = self::publishProblems($template)) {
            throw new RuntimeException($problems[0]);
        }

        $template->forceFill([
            'approved_hash' => $hash,
            'approval_basis' => PlatformDocumentTemplate::BY_OWNER,
            'approved_at' => now(),
            'approved_by' => $by->id,
            'approved_by_name' => $by->name,
            'active' => true,
        ])->save();
        $template->recordVersion($by)->forceFill([
            'published_at' => now(), 'published_by' => $by->id, 'published_by_name' => $by->name,
        ])->save();

        Activity::log('updated', 'نشر قالب مستند (الإصدار '.$template->version.'، البصمة '.substr($hash, 0, 12).'): '.$template->name, [
            'business_id' => null, 'subject_id' => $template->id, 'subject_type' => PlatformDocumentTemplate::class,
        ]);

        return $template;
    }
}
