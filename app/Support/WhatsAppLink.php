<?php

namespace App\Support;

use App\Models\WhatsAppConnection;

/**
 * حالُ الربط كما تُقرأ على البطاقة — ستُّ حالاتٍ لا سابعة.
 *
 * ═══ ولمَ تُحسب هنا لا في الشاشة ═══
 *
 * الحالُ تُقرأ في ثلاثة مواضع: البطاقة، والتنبيه قبل الانتهاء، والمُرسِل
 * حين يُقرّر أيرسل أم يقف. ولو حُسبت في كلٍّ منها لَافترقت: تقول البطاقةُ
 * «متّصل» ويمتنع المُرسِل — وهو أسوأ عطبٍ في تكاملٍ كهذا، لأنّه لا يُكتشف
 * إلّا حين يسأل زبونٌ عن رسالةٍ لم تصله.
 *
 * ═══ ومتى يُنبَّه ═══
 *
 * أربعةَ عشر يومًا وسبعةً وثلاثة. ولمَ ثلاثةُ عتباتٍ لا واحدة: التنبيهُ
 * الواحدُ قبل أسبوعين يُنسى، والواحدُ قبل ثلاثةِ أيّامٍ قد يقع في إجازة.
 * وكلٌّ منها يرفع اللهجة، ومن تجاوز الثالثة قرأ «يحتاج إعادة تفويض» على
 * البطاقة نفسِها لا في سطرٍ صغير.
 */
class WhatsAppLink
{
    /** لا وصلةَ أصلًا — أو وصلةٌ فُصلت */
    public const DISCONNECTED = 'disconnected';

    /** التفويض تمّ والرقمُ لم يصل بعد */
    public const CONNECTING = 'connecting';

    public const CONNECTED = 'connected';

    /** يعمل اليوم ويوشك أن يقف — أو نقصت صلاحية */
    public const REAUTH = 'reauthorization_required';

    /** وقف فعلًا */
    public const EXPIRED = 'expired';

    public const ERROR = 'error';

    /** عتباتُ التنبيه بالأيّام — من الأبعد إلى الأقرب */
    public const ALERT_DAYS = [14, 7, 3];

    /**
     * الحالُ الواحدة التي تُعرض ويُقرَّر بها.
     *
     * والترتيبُ مقصود: ما انتهى يُقال أوّلًا، ثمّ ما فُصل، ثمّ ما ينتظر،
     * ثمّ ما أخطأ، ثمّ ما يوشك. ولو قُدّم «يوشك» على «انتهى» لَقرأ صاحبُ
     * رمزٍ ميّتٍ أنّ أمامه أيّامًا.
     */
    public static function state(?WhatsAppConnection $c): string
    {
        if (! $c || $c->status === WhatsAppConnection::INACTIVE) {
            return self::DISCONNECTED;
        }

        if ($c->status === WhatsAppConnection::EXPIRED
            || ($c->token_expires_at !== null && $c->token_expires_at->isPast())) {
            return self::EXPIRED;
        }

        if ($c->status === WhatsAppConnection::REVOKED) {
            return self::REAUTH;
        }

        if ($c->status === WhatsAppConnection::PENDING) {
            return self::CONNECTING;
        }

        if ($c->status === WhatsAppConnection::ERROR) {
            return self::ERROR;
        }

        if ($c->status === WhatsAppConnection::REAUTH || self::daysLeft($c) !== null && self::daysLeft($c) <= self::ALERT_DAYS[0]) {
            return self::REAUTH;
        }

        /*
         * ووصلةٌ نشطةٌ بلا رقم ليست «متّصلة».
         *
         * تقع حين يُفصل الرقمُ عند ميتا ويبقى الصفُّ عندنا. والبطاقةُ التي
         * تقول «متّصل» بلا رقمٍ تجعل التاجر ينتظر رسائل لا مخرجَ لها.
         */
        if (blank($c->phone_number_id)) {
            return self::CONNECTING;
        }

        return $c->status === WhatsAppConnection::ACTIVE ? self::CONNECTED : self::ERROR;
    }

    /** كم يومًا بقي للرمز — و`null` لرمزٍ لا ينتهي */
    public static function daysLeft(?WhatsAppConnection $c): ?int
    {
        if (! $c || $c->token_expires_at === null) {
            return null;
        }

        return (int) floor(now()->diffInDays($c->token_expires_at, false));
    }

    /**
     * عتبةُ التنبيه التي دخلها الرمز — و`null` إن لم يدخل واحدة.
     *
     * تُردّ العتبةُ نفسُها (١٤ أو ٧ أو ٣) لا عددُ الأيّام: الشاشةُ تكتب
     * «أقلّ من :d يومًا» فيقرأ التاجر رقمًا ثابتًا لا رقمًا يتناقص أمامه.
     */
    public static function alert(?WhatsAppConnection $c): ?int
    {
        $left = self::daysLeft($c);

        if ($left === null || $left < 0) {
            return null;
        }

        foreach (array_reverse(self::ALERT_DAYS) as $threshold) {
            if ($left <= $threshold) {
                return $threshold;
            }
        }

        return null;
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::CONNECTED => __('متصل'),
            self::CONNECTING => __('جاري الربط'),
            self::REAUTH => __('يحتاج إعادة تفويض'),
            self::EXPIRED => __('انتهت صلاحية التفويض'),
            self::ERROR => __('حدث خطأ'),
            default => __('غير متصل'),
        };
    }

    /**
     * ما تقرؤه البطاقة — بلا رمزٍ ولا سرّ.
     *
     * ولا يُقرأ `access_token` هنا ولا يُشتقّ منه شيء: ما يخرج هو الحالُ
     * والمعرّفاتُ التي يملكها التاجر أصلًا (رقمه وحساب أعماله).
     *
     * @return array<string, mixed>
     */
    public static function view(?WhatsAppConnection $c): array
    {
        $state = self::state($c);

        return [
            'state' => $state,
            'label' => self::label($state),
            'waba_id' => $c?->waba_id,
            'meta_business_id' => $c?->meta_business_id,
            'phone_number_id' => $c?->phone_number_id,
            'display_phone_number' => $c?->display_phone_number,
            'coexistence' => (bool) ($c?->coexistence),
            'connected_at' => optional($c?->connected_at)->format('Y-m-d H:i'),
            'connected_by' => $c?->connectedBy?->name,
            'last_webhook_at' => optional($c?->last_webhook_at)->format('Y-m-d H:i'),
            'expires_at' => optional($c?->token_expires_at)->format('Y-m-d'),
            'days_left' => self::daysLeft($c),
            'alert_days' => self::alert($c),
            /*
             * ورسالةُ الخطأ تُعرض كما قالتها ميتا — وهي ليست سرًّا.
             *
             * «الرقم غير مؤهّل» و«الحساب موقوف» جملٌ تقولها ميتا للتاجر
             * نفسِه في لوحتها. وإخفاؤها يترك من يقرأ «حدث خطأ» بلا طريق.
             */
            'error_code' => $c?->last_error_code,
            'error_message' => $c?->last_error_message,
        ];
    }
}
