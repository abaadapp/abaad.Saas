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

    public const SKIP_DUPLICATE = 'duplicate';

    public const SKIP_QUOTA = 'quota_exceeded';

    public const SKIP_OWN_NOT_ALLOWED = 'own_mode_not_entitled';

    /** باقةُ المتجر لا تفتح إشعارات واتساب — سببٌ يُقرأ، لا صمت */
    public const SKIP_PLAN = 'plan_excluded';
}
