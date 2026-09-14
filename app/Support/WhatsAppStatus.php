<?php

namespace App\Support;

/**
 * حال الرسالة — وهي غير حال الطلب.
 *
 * «تم التسليم» في الطلب تعني أنّ الورد وصل إلى يدٍ. و`delivered` هنا تعني
 * أنّ الرسالة وصلت إلى جهازٍ. والخلط بينهما يعني أنّ إشعارًا من ميتا يُغيّر
 * حالة طلبٍ في المحلّ — فيُقفل طلبٌ لم يخرج أحدٌ لتسليمه.
 *
 * ولذلك لا يكتب مسار الإشعارات في `orders` حرفًا واحدًا.
 */
class WhatsAppStatus
{
    /** أُنشئ السجلّ وحُجزت الحصّة ولم يُرسل بعد */
    public const QUEUED = 'queued';

    /** قبِلها المزوّد وأعاد معرّفًا لها */
    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    /** لم تُرسَل لسببٍ معروفٍ مسبقًا — لا هاتف، أو الحدث مُطفأ، أو الوصلة معطّلة */
    public const SKIPPED = 'skipped';

    /** نفدت حصّة الشهر — تُفصَل عن `skipped` لأنّها السبب الذي يُسأل عنه */
    public const QUOTA_EXCEEDED = 'quota_exceeded';

    public const ALL = [
        self::QUEUED, self::SENT, self::DELIVERED, self::READ,
        self::FAILED, self::SKIPPED, self::QUOTA_EXCEEDED,
    ];

    /** ما لا يُنتظر منه شيءٌ بعد — لا يُعاد إرساله ولا يُحدَّث بإشعار */
    public const TERMINAL = [self::FAILED, self::SKIPPED, self::QUOTA_EXCEEDED];

    /** أسباب الامتناع — تُكتب في `error_code` لتُقرأ لا لتُخمَّن */
    public const SKIP_AUTOMATION_OFF = 'automation_disabled';

    public const SKIP_EVENT_OFF = 'event_disabled';

    public const SKIP_NO_RECIPIENT = 'no_recipient';

    public const SKIP_INVALID_PHONE = 'invalid_phone';

    public const SKIP_NO_CONNECTION = 'no_connection';

    public const SKIP_NO_TEMPLATE = 'no_template';

    /** لا طلبَ ولا فاتورةَ تتحدّث عنها الرسالة — فلا قيمةَ لمتغيّراتها */
    public const SKIP_NO_SUBJECT = 'no_subject';

    public const SKIP_DUPLICATE = 'duplicate';

    public const SKIP_QUOTA = 'quota_exceeded';

    public const SKIP_OWN_NOT_ALLOWED = 'own_mode_not_entitled';

    /** باقةُ المتجر لا تفتح إشعارات واتساب — سببٌ يُقرأ، لا صمت */
    public const SKIP_PLAN = 'plan_excluded';

    /*
     * ═══════════ ما يُقرأ من هذا كلِّه في شاشةِ تاجر ═══════════
     *
     * الحالاتُ والأسبابُ أعلاه أسماءُ أعمدةٍ لا لغةُ بشر. وحتّى اليوم لم
     * تكن تُعرض لأحد: «تُكتب `failed` في جدولٍ لا تفتحه شاشة». فلمّا فُتحت
     * الشاشة احتاجت نصًّا — والنصُّ يُكتب هنا لا هناك.
     *
     * ولمَ هنا: الحالةُ تُضاف في هذا الملفّ، فإن كُتب نصُّها في ملفّ الشاشة
     * أُضيفت يومًا بلا نصّ، فتقرأ التاجرةُ `quota_exceeded` في عمودٍ عربيّ
     * ولا تعرف ما جرى. وحارسُ `EveryOutcomeOfAMessageHasAWordTest` يمنع
     * ذلك: لا حالةَ بلا كلمة، ولا سببَ بلا كلمة.
     */

    /** ما تعنيه الحالةُ لصاحب المحلّ — لا ما تعنيه لميتا */
    public const LABELS = [
        self::QUEUED => 'في الانتظار',
        /*
         * و«خرجت» لا «وصلت».
         *
         * `sent` تعني أنّ ميتا قبِلتها، لا أنّ الهاتف استلمها. وكتابةُ
         * «وصلت» هنا تجعل التاجر يقسم لزبونه أنّ الرسالة وصلته وهي راقدةٌ
         * عند ميتا — وتقريرُ حالٍ كاذب أسوأ من غياب التقرير.
         */
        self::SENT => 'خرجت إلى واتساب',
        self::DELIVERED => 'وصلت جهاز الزبون',
        self::READ => 'قرأها الزبون',
        self::FAILED => 'لم تصل',
        self::SKIPPED => 'لم تُرسَل',
        self::QUOTA_EXCEEDED => 'نفدت الحصّة',
    ];

    /**
     * أسبابُ الامتناع بلغةٍ تُقرأ — ولكلِّ سببٍ ما يُفعل به.
     *
     * والسببُ يُقال كاملًا: «الحدث مُطفأ» وحدها تترك التاجر يبحث أين. فكلُّ
     * نصٍّ هنا يقول ما جرى **وأين يُصلَح**.
     */
    public const SKIP_REASONS = [
        self::SKIP_AUTOMATION_OFF => 'إشعارات واتساب مُطفأةٌ لمتجرك — تُشعَل من «التطبيقات التكاملية ‹ واتساب».',
        self::SKIP_EVENT_OFF => 'هذا الحدث مُطفأٌ في «إشعارات واتساب» — أشعِله ليُرسَل مستقبلًا.',
        self::SKIP_NO_RECIPIENT => 'لا رقم هاتفٍ لهذا الزبون — أضِفه في بطاقته.',
        self::SKIP_INVALID_PHONE => 'رقم الزبون غير صالحٍ للواتساب — راجِعه في بطاقته.',
        self::SKIP_NO_CONNECTION => 'لا وصلةَ واتساب صالحةٍ وقتَ الإرسال — راجِع شاشة الربط.',
        self::SKIP_NO_TEMPLATE => 'لا قالبَ معتمَدٌ لهذا الحدث عند واتساب — العطب عند أبعاد، ونحن نعالجه.',
        self::SKIP_NO_SUBJECT => 'لا طلبَ ولا فاتورةَ تتحدّث عنها الرسالة — العطب عند أبعاد، ونحن نعالجه.',
        self::SKIP_DUPLICATE => 'أُرسلت رسالةٌ لهذا الحدث من قبل — ولا تُرسَل مرّتين.',
        self::SKIP_QUOTA => 'نفدت حصّةُ رسائل الشهر — تتجدّد أوّل الشهر القادم.',
        self::SKIP_OWN_NOT_ALLOWED => 'متجرك في وضع «رقم المتجر» بلا صلاحيةٍ لذلك — راجِع أبعاد.',
        self::SKIP_PLAN => 'باقتُك لا تشمل إشعارات واتساب — راجِع أبعاد للترقية.',
    ];

    public static function label(string $status): string
    {
        return __(self::LABELS[$status] ?? $status);
    }

    /**
     * سببُ الامتناع بلغةٍ تُقرأ — أو لا شيء.
     *
     * ويُردّ `null` لأرقام ميتا (`131047`, `190`, …): تلك ليست أسبابَ امتناعٍ
     * عندنا بل ردُّ المزوّد، ونصُّها يصل في `error_message`. وردُّ نصٍّ
     * مخترَعٍ لها يعني أن نُسمّي عطبًا لا نعرفه.
     */
    public static function reason(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $text = self::SKIP_REASONS[trim($code)] ?? null;

        return $text === null ? null : __($text);
    }

    /*
     * ═══ حِزَمُ الحالات — تصنيفٌ واحدٌ يقرؤه المرشِّح والملخّص معًا ═══
     *
     * مرشِّحُ الشاشة يسأل «أرِني ما لم يصل»، والملخّصُ يعدّ «كم لم يصل».
     * وسؤالان بقائمتين يفترقان يوم تُضاف حالة: يعدّها الملخّص ولا يعرضها
     * المرشِّح. فالقائمةُ واحدة.
     *
     * وحارسُها يقول إنّ اتّحاد الحِزَم هو `ALL` تمامًا: لا حالةَ خارج حزمة
     * فتسقط من كلّ مرشِّح، ولا حالةَ في حزمتين فتُعدّ مرّتين.
     */

    /** @var array<string, list<string>> */
    public const BUCKETS = [
        'pending' => [self::QUEUED, self::SENT],
        'arrived' => [self::DELIVERED, self::READ],
        'failed' => [self::FAILED],
        'not_sent' => [self::SKIPPED, self::QUOTA_EXCEEDED],
    ];

    public const BUCKET_LABELS = [
        'pending' => 'في الطريق',
        'arrived' => 'وصلت',
        'failed' => 'لم تصل',
        'not_sent' => 'لم تُرسَل',
    ];

    /** الحالاتُ التي تدخل هذه الحزمة — وقائمةٌ فارغةٌ لاسمٍ لا نعرفه */
    public static function bucket(string $name): array
    {
        return self::BUCKETS[$name] ?? [];
    }
}
