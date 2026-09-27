<?php

namespace App\Support\Purge;

use App\Models\PurgeRun;

/**
 * أين وصل الحذفُ — بكلمةٍ تُقرأ لا برمزٍ يُفسَّر.
 *
 * والحالُ تُقرأ قبل المرحلة: «فشلت» تُقال ولو كانت المرحلةُ «حذف»، فمن
 * يقرأ يريد أن يعرف أوّلًا أنّ شيئًا وقع. ثمّ تقول المرحلةُ **أين** وقع
 * — وهي الفرقُ بين سقوطٍ لم يُمسّ فيه شيءٌ وسقوطٍ بعد المحو.
 */
final class Stages
{
    public static function label(PurgeRun $run): string
    {
        if ($run->status === PurgeRun::DONE) {
            return __('مكتملة');
        }

        if ($run->status === PurgeRun::FAILED) {
            return __('فشلت عند: :stage', ['stage' => self::stage($run->stage)]);
        }

        if ($run->status === PurgeRun::PENDING) {
            return __('في الطابور — بانتظار عامل');
        }

        return self::stage($run->stage);
    }

    /**
     * أيمكن للسقوط أن يكون قد مسّ البيانات؟
     *
     * ═══ ولمَ يُقال هذا صريحًا ═══
     *
     * سقوطٌ في الأرشفة أو الرفع أو التحقّق لم يمسّ صفًّا واحدًا — والشركةُ
     * كما كانت. وسقوطٌ في «حذف الصفوف» أو «حذف الملفّات» يعني أنّ المحوَ
     * بدأ. ومن يقرأ «فشلت» وحدَها لا يعرف أيَّ السقوطين، فيظنّ الشركةَ
     * سليمةً وقد مُحيت، أو يُعيد المحاولةَ على ما لم يُمسّ ظانًّا أنّه نصفُ
     * ممحوّ.
     */
    public static function touched(PurgeRun $run): bool
    {
        return in_array($run->stage, [PurgeRun::DELETING, PurgeRun::FILES, PurgeRun::FINISHED], true);
    }

    private static function stage(?string $stage): string
    {
        return match ($stage) {
            PurgeRun::ARCHIVING => __('أرشفة الدفاتر'),
            PurgeRun::UPLOADING => __('رفع النسخة المشفَّرة'),
            PurgeRun::VERIFYING => __('التحقّق من استعادة النسخة'),
            PurgeRun::DELETING => __('حذف البيانات'),
            PurgeRun::FILES => __('حذف الملفات'),
            PurgeRun::FINISHED => __('مكتملة'),
            default => __('في الطابور'),
        };
    }
}
