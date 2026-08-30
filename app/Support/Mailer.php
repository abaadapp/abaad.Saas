<?php

namespace App\Support;

/**
 * هل يستطيع النظام إرسال بريدٍ فعلًا؟
 *
 * `log` و`array` مُرسلان صالحان في نظر لارافيل: لا يرميان خطأً، ويعودان
 * بنجاح. فـMail::send تنجح، والشاشة تقول «أرسلنا الرابط»، والرسالة في ملفّ
 * سجلٍّ على الخادم أو في الذاكرة. نجاحٌ كاذب لا يكشفه إلا من يفتح بريده
 * وينتظر.
 *
 * فيُسأل هنا مرّةً واحدة: الشاشة تُخفي الباب الذي لا يفتح، والمسار يرفض
 * الطلب حتى لمن يعرف الرابط، وpreflight يمنع الإطلاق. ثلاثة مواضع تقرأ
 * حقيقةً واحدة، فلا يتخلّف موضعٌ يوم يُضبط SMTP.
 */
class Mailer
{
    /** مُرسِلات لا تُوصل شيئًا إلى أحد */
    private const SINKS = ['log', 'array', 'null'];

    public static function configured(): bool
    {
        $driver = (string) config('mail.default');

        if (in_array($driver, self::SINKS, true)) {
            return false;
        }

        /*
         * وsmtp بلا مضيف ليس أفضل حالًا: يُرمى الاتصال على «» فيفشل الإرسال
         * وقت التنفيذ لا وقت الإعداد — أي عند المستخدم لا عند من ضبط الخادم.
         */
        if ($driver === 'smtp' && blank(config('mail.mailers.smtp.host'))) {
            return false;
        }

        /*
         * ومزوّدُ HTTP بلا مفتاح مثله: لارافيل تقبل الإعداد وتبني المُرسِل،
         * ثم يُرفض الطلب عند المزوّد وقت الإرسال. فيُسأل عن المفتاح هنا لا
         * هناك — والشاشة تقول الحقيقة قبل أن يعتمد عليها أحد.
         */
        $keys = ['resend' => 'services.resend.key', 'postmark' => 'services.postmark.key'];
        if (isset($keys[$driver]) && blank(config($keys[$driver]))) {
            return false;
        }

        return true;
    }
}
