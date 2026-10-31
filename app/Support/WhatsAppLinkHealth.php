<?php

namespace App\Support;

use App\Models\WhatsAppConnection;

/**
 * أيستقبل هذا الرقمُ رسائلَ زبائنه فعلًا؟ — لا «متّصل» وحدها.
 *
 * ستّةُ أشياءَ مختلفة كانت تُقرأ كلمةً واحدة:
 *
 *   ١ · الرقمُ متّصلٌ ويُرسل (`WhatsAppLink::CONNECTED`).
 *   ٢ · تطبيقُ أبعاد مشتركٌ في إشعارات حسابه (`webhook_state = subscribed`).
 *   ٣ · وصندوقُ المحادثات مفتوحٌ للمتجر — فما يصل يُحفظ ويُعرض.
 *   ٤ · وصلتنا منه حالةُ تسليم (`last_status_at`).
 *   ٥ · ووصلتنا منه رسالةٌ كتبها زبون (`last_inbound_at`) — وصولٌ لا حفظ.
 *   ٦ · وحُفظت رسالةُ زبونٍ في صندوقه (`last_inbound_saved_at`).
 *
 * والرابعُ ليس الخامس، والخامسُ ليس السادس. حالةُ التسليم خبرٌ عمّا أرسله
 * المتجر. ورسالةٌ وصلت ثمّ رُدّت (صندوقٌ مغلق، صفٌّ قديم) تكتب سببها في
 * `inbound_problem` وتُقرأ «وصلت ولم تُحفظ». والسادسُ وحده دليلُ استقبال
 * المحادثات: الويب هوك يُرفض ما لم يُوقَّع بسرّ `whatsapp.app_secret`
 * (`WebhookController::handle`)، ثمّ لا يُختم الحفظُ إلّا بعد أن تُكتب الرسالةُ
 * في صندوق المتجر (`WhatsAppInbox::receiveOwn`).
 *
 * وتُحسب هنا في الخادم وحده، من أعمدةٍ مخزّنة بلا نداءٍ لميتا، وتُعرض كما
 * هي في لوحتَي التاجر والمنصّة.
 */
final class WhatsAppLinkHealth
{
    public const AWAITING = 'awaiting_authorization';

    /** رمزُ الربط من تطبيقٍ غير أبعاد — يحتاج إعادةَ تفويضٍ عبر تطبيق أبعاد */
    public const WRONG_APP = 'wrong_app';

    public const INCOMPLETE = 'incomplete_authorization';

    /** متّصلٌ للإرسال فقط — لم يُتحقَّق من الاشتراك */
    public const CONNECTED = 'number_connected';

    public const WEBHOOK_INCOMPLETE = 'webhook_incomplete';

    /** مشتركٌ، لكنّ توجيه المحادثات عند ميتا يُسلّم الواردَ لمستجيبٍ غير أبعاد */
    public const ROUTED_AWAY = 'routing_elsewhere';

    /** تطبيقُ أبعاد مشترك، وصندوقُ المحادثات مغلقٌ للمتجر — ما يصل لا يُحفظ */
    public const SUBSCRIBED = 'app_subscribed';

    /** مشتركٌ والصندوقُ مفتوح، ولم يصل شيءٌ بعد */
    public const READY = 'ready';

    /** وصلت حالاتُ تسليم، ولم تصل رسالةُ زبونٍ بعد */
    public const STATUSES = 'status_received';

    /** حُفظت رسالةٌ كتبها زبونٌ في صندوق المتجر — الدليلُ الوحيد على الاستقبال */
    public const INBOUND = 'inbound_received';

    /** وصلت رسالةُ زبونٍ ولم تُحفظ — والسببُ في `inbound_problem` */
    public const INBOUND_REFUSED = 'inbound_refused';

    public const REPAIR = 'needs_repair';

    /** ما يُعدّ ربطًا سليمًا في رسالة الفحص */
    public const HEALTHY = [self::READY, self::STATUSES, self::INBOUND];

    /**
     * ما سمعه الرقمُ السابق — يُمحى حين يحلّ رقمٌ آخر محلَّه على الوصلة نفسها.
     *
     * الأختامُ تُقرأ عن الرقم الحاليّ: رسالةٌ حُفظت على الرقم القديم تُقرأ
     * «تم استقبال رسالة عميل» عن رقمٍ لم يصله شيء. ورقمٌ أُعيد ربطُه كما هو
     * يبقى تاريخُه — هو الرقمُ نفسُه.
     */
    public const UNHEARD = [
        'last_webhook_at' => null,
        'last_status_at' => null,
        'last_inbound_at' => null,
        'last_inbound_saved_at' => null,
        'inbound_problem' => null,
        'inbound_problem_at' => null,
    ];

    public static function state(?WhatsAppConnection $c): string
    {
        $link = WhatsAppLink::state($c);

        if ($link === WhatsAppLink::DISCONNECTED || $link === WhatsAppLink::CONNECTING) {
            return self::AWAITING;
        }

        if (in_array($link, [WhatsAppLink::EXPIRED, WhatsAppLink::ERROR], true)
            || $c->status === WhatsAppConnection::REVOKED
            || $c->link_problem === WhatsAppLinkCheck::INVALID_TOKEN) {
            return self::REPAIR;
        }

        if ($c->link_problem === WhatsAppLinkCheck::WRONG_APP
            || (filled($c->token_app_id) && $c->token_app_id !== WhatsAppLinkCheck::appId())) {
            return self::WRONG_APP;
        }

        if (in_array($c->link_problem, WhatsAppLinkCheck::INCOMPLETE, true)) {
            return self::INCOMPLETE;
        }

        /*
         * واشتراكٌ وُجد ناقصًا بعد آخر إشعار يغلب الإشعار: الفحصُ أحدثُ
         * خبرًا — الاشتراكُ سُحب بعد أن كان يصلنا.
         */
        $lost = in_array($c->webhook_state, [WhatsAppLinkCheck::NOT_SUBSCRIBED, WhatsAppLinkCheck::OVERRIDE], true)
            && ($c->last_webhook_at === null || ($c->webhook_checked_at !== null && $c->webhook_checked_at->gt($c->last_webhook_at)));

        /*
         * وتوجيهٌ قُرئ بعد آخر رسالة زبونٍ حُفظت وأبعاد ليست فيه أساسيّةً للخدمة:
         * الاشتراكُ قائمٌ والواردُ يذهب إلى غيرنا (`WhatsAppLinkCheck::routing`).
         */
        $routedAway = $c->routing_state === WhatsAppLinkCheck::ROUTING_ELSEWHERE
            && ($c->last_inbound_saved_at === null || ($c->webhook_checked_at !== null && $c->webhook_checked_at->gt($c->last_inbound_saved_at)));

        return match (true) {
            $lost => self::WEBHOOK_INCOMPLETE,
            $routedAway => self::ROUTED_AWAY,
            /*
             * وعلّةٌ قائمةٌ أحدثُ من كلّ حفظ: تُمحى بأوّل رسالةٍ تُحفظ بعدها، فبقاؤها
             * يعني أنّ آخرَ ما وصل رُدّ. ورسالةٌ وصلت ولم يُختم حفظُها قطّ لا تُعدّ
             * استقبالًا ولو غاب السبب.
             */
            filled($c->inbound_problem) => self::INBOUND_REFUSED,
            $c->last_inbound_saved_at !== null => self::INBOUND,
            $c->last_inbound_at !== null => self::INBOUND_REFUSED,
            /*
             * و`last_webhook_at` وحده — وصلةٌ خُتمت قبل أن يُفرَّق بين النوعين —
             * يُقرأ حالةَ تسليم: لا يُدّعى عنه أنّه رسالةُ زبون.
             */
            $c->last_status_at !== null || $c->last_webhook_at !== null => self::STATUSES,
            $c->webhook_state === WhatsAppLinkCheck::SUBSCRIBED => WhatsAppInbox::available($c->business) ? self::READY : self::SUBSCRIBED,
            $c->token_verified_at !== null => self::WEBHOOK_INCOMPLETE,
            default => self::CONNECTED,
        };
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::AWAITING => __('بانتظار التفويض'),
            self::WRONG_APP => __('يحتاج إعادة تفويض عبر تطبيق أبعاد'),
            self::INCOMPLETE => __('تفويض أو صلاحيات غير مكتملة'),
            self::CONNECTED => __('الرقم متصل للإرسال فقط'),
            self::WEBHOOK_INCOMPLETE => __('اشتراك Webhook غير مكتمل'),
            self::ROUTED_AWAY => __('توجيه المحادثات عند ميتا لا يمرّ بأبعاد'),
            self::SUBSCRIBED => __('تطبيق أبعاد مشترك في Webhook'),
            self::READY => __('جاهز نظريًا لاستقبال الرسائل'),
            self::STATUSES => __('تم استقبال Webhook لحالة تسليم'),
            self::INBOUND => __('تم استقبال رسالة عميل فعلية'),
            self::INBOUND_REFUSED => __('وصلت رسالة عميل ولم تُحفظ'),
            default => __('يحتاج إصلاحًا'),
        };
    }

    public static function hint(string $state, ?WhatsAppConnection $c): string
    {
        $hint = match ($state) {
            self::AWAITING => __('لم يكتمل ربطُ رقمٍ بعد.'),
            self::WRONG_APP => __('رمزُ هذا الربط صادرٌ من تطبيقٍ غير Abaad Messaging، فرسائلُ الزبائن لا تصل أبعاد. الرقمُ ما زال يُرسل، ولن يُستبدل ربطُه تلقائيًّا. الإصلاح: يضغط صاحبُ المتجر «إعادة تفويض واتساب» ويختار حسابه ورقمه نفسيهما — ويبقى الربطُ الحاليّ كما هو حتى يثبت الجديد.'),
            self::INCOMPLETE => WhatsAppLinkCheck::say((string) $c?->link_problem),
            self::CONNECTED => __('الرقمُ يُرسل، ولم يُتحقَّق بعدُ من تطبيق أبعاد ولا من اشتراك الإشعارات — اضغط «فحص الربط».'),
            self::WEBHOOK_INCOMPLETE => match ($c?->webhook_state) {
                WhatsAppLinkCheck::OVERRIDE => __('تطبيقُ أبعاد مشترك، لكنّ إشعاراتِ هذا الحساب موجَّهةٌ إلى عنوانٍ بديلٍ لا إلى أبعاد.'),
                default => __('تطبيقُ أبعاد ليس مشتركًا في إشعارات هذا الحساب — الرسائلُ الواردة لا تصل. أعد الربط.'),
            },
            self::ROUTED_AWAY => __('تطبيقُ أبعاد مشترك، لكنّ إعداد «توجيه المحادثات» (Conversation Routing) عند ميتا يُسلّم رسائلَ الزبائن لمستجيبٍ آخر على هذا الرقم، فلا تصل أبعاد. يضبطه صاحبُ حساب واتساب من Meta Business Suite بجعل أبعاد المستجيبَ الأساسيَّ لنقطة «الخدمة» — ولا يُضبط من أبعاد.'),
            self::SUBSCRIBED => __('تطبيقُ أبعاد مشتركٌ في الإشعارات، لكنّ صندوق محادثات واتساب غير متاحٍ لهذا المتجر — باقتُه لا تشمل واتساب أو واتساب مطفأ لحسابه — فرسائلُ الزبائن تصل ولا تُحفظ.'),
            self::READY => __('تطبيقُ أبعاد مشتركٌ في الإشعارات وصندوقُ المحادثات مفتوح، ولم يصل شيءٌ بعد. يثبت الاستقبال بأوّل رسالةٍ يكتبها زبون.'),
            self::STATUSES => __('وصلت حالاتُ تسليمٍ لما أرسله المتجر، ولم تصل رسالةُ زبونٍ بعد — حالةُ التسليم وحدها لا تُثبت أنّ رسائل الزبائن تصل.'),
            self::INBOUND => __('آخرُ رسالة عميل حُفظت في المحادثات: :at', ['at' => optional($c?->last_inbound_saved_at)->format('Y-m-d H:i')]),
            self::INBOUND_REFUSED => filled($c?->inbound_problem)
                ? __('وصلت رسالةُ زبونٍ إلى رقم المتجر ولم تُحفظ في المحادثات: :why', ['why' => WhatsAppInbox::refusal((string) $c->inbound_problem)])
                : __('وصلت رسالةُ زبونٍ إلى رقم المتجر ولم يثبت حفظُها في المحادثات.'),
            default => (string) ($c?->last_error_message ?: __('أعد الربط أو اختبر الاتصال.')),
        };

        // ورسالةٌ وصلت ولم تُحفظ تقول لماذا تحت أيّ حالٍ أعلى — بلا نصّها
        if (filled($c?->inbound_problem) && $state !== self::INBOUND_REFUSED) {
            $hint .= ' '.__('آخرُ رسالةٍ وصلت لم تُحفظ: :why', ['why' => WhatsAppInbox::refusal((string) $c->inbound_problem)]);
        }

        return $hint;
    }

    public static function tone(string $state): string
    {
        return match ($state) {
            self::INBOUND => 'success',
            self::READY, self::STATUSES, self::SUBSCRIBED, self::CONNECTED => 'info',
            self::AWAITING => 'neutral',
            default => 'danger',
        };
    }

    /**
     * ما تقرؤه الشاشتان — بلا رمزٍ ولا معرّفِ تطبيقٍ آخر ولا نصِّ رسالة.
     *
     * @return array{key:string, label:string, hint:string, tone:string, checked_at:?string, last_status_at:?string, last_inbound_at:?string, last_inbound_saved_at:?string, inbound_problem:?string}
     */
    public static function view(?WhatsAppConnection $c): array
    {
        $state = self::state($c);

        return [
            'key' => $state,
            'label' => self::label($state),
            'hint' => self::hint($state, $c),
            'tone' => self::tone($state),
            'checked_at' => optional($c?->webhook_checked_at ?? $c?->token_verified_at)->format('Y-m-d H:i'),
            'last_status_at' => optional($c?->last_status_at ?? $c?->last_webhook_at)->format('Y-m-d H:i'),
            'last_inbound_at' => optional($c?->last_inbound_at)->format('Y-m-d H:i'),
            'last_inbound_saved_at' => optional($c?->last_inbound_saved_at)->format('Y-m-d H:i'),
            'inbound_problem' => filled($c?->inbound_problem) ? WhatsAppInbox::refusal((string) $c->inbound_problem) : null,
        ];
    }

    /**
     * أرقامُ المتاجر المستقلّة كلُّها كما يقرؤها مديرُ المنصّة.
     *
     * من الأعمدة المخزّنة وحدها: لا نداءَ لميتا عند فتح الشاشة، ولا رمزٌ ولا
     * معرّفُ رقمٍ ولا معرّفُ تطبيقٍ آخر. والفحصُ الحيُّ زرٌّ لكلّ متجر
     * (`super-admin.businesses.whatsapp.check`) أو أمرُ `whatsapp:own-numbers`.
     *
     * @return list<array{business_id:int, business:string, phone:?string, inbox:bool, health:array<string, mixed>}>
     */
    public static function fleet(): array
    {
        return WhatsAppConnections::ownNumbers()->map(fn (WhatsAppConnection $c) => [
            'business_id' => (int) $c->business_id,
            'business' => (string) $c->business?->name,
            'phone' => $c->display_phone_number,
            'inbox' => WhatsAppInbox::available($c->business),
            'health' => self::view($c),
        ])->all();
    }
}
