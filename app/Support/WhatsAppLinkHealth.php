<?php

namespace App\Support;

use App\Models\WhatsAppConnection;

/**
 * أيستقبل هذا الرقمُ رسائلَ زبائنه فعلًا؟ — لا «متّصل» وحدها.
 *
 * ثلاثةُ أشياءَ مختلفة كانت تُقرأ كلمةً واحدة:
 *
 *   ١ · الرقمُ متّصلٌ ويُرسل (`WhatsAppLink::CONNECTED`).
 *   ٢ · تطبيقُ أبعاد مشتركٌ في إشعارات حسابه (`webhook_state = subscribed`).
 *   ٣ · وصلنا منه إشعارٌ حقيقيٌّ موقَّعٌ بسرّ تطبيقنا (`last_webhook_at`).
 *
 * والثالثُ وحده دليلُ الاستقبال: الويب هوك يُرفض ما لم يُوقَّع بسرّ
 * `whatsapp.app_secret` (`WebhookController::handle`) — فإشعارٌ مُرّ به ختمٌ
 * على أنّ تطبيقنا هو المشترك ساعتَها.
 *
 * وتُحسب هنا في الخادم وحده، وتُعرض كما هي في لوحتَي التاجر والمنصّة.
 */
final class WhatsAppLinkHealth
{
    public const AWAITING = 'awaiting_authorization';

    public const WRONG_APP = 'wrong_app';

    public const INCOMPLETE = 'incomplete_authorization';

    public const CONNECTED = 'number_connected';

    public const WEBHOOK_INCOMPLETE = 'webhook_incomplete';

    public const READY = 'ready';

    public const RECEIVING = 'receiving';

    public const REPAIR = 'needs_repair';

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

        return match (true) {
            $lost => self::WEBHOOK_INCOMPLETE,
            $c->last_webhook_at !== null => self::RECEIVING,
            $c->webhook_state === WhatsAppLinkCheck::SUBSCRIBED => self::READY,
            $c->token_verified_at !== null => self::WEBHOOK_INCOMPLETE,
            default => self::CONNECTED,
        };
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::AWAITING => __('بانتظار التفويض'),
            self::WRONG_APP => __('تطبيق Meta غير مطابق'),
            self::INCOMPLETE => __('تفويض أو صلاحيات غير مكتملة'),
            self::CONNECTED => __('رقم API متصل'),
            self::WEBHOOK_INCOMPLETE => __('اشتراك Webhook غير مكتمل'),
            self::READY => __('جاهز نظريًا لاستقبال الرسائل'),
            self::RECEIVING => __('تم إثبات استقبال Webhook فعلي'),
            default => __('اتصال منتهٍ أو يحتاج إصلاحًا'),
        };
    }

    public static function hint(string $state, ?WhatsAppConnection $c): string
    {
        return match ($state) {
            self::AWAITING => __('لم يكتمل ربطُ رقمٍ بعد.'),
            self::WRONG_APP => __('رمزُ هذا الربط صادرٌ من تطبيقٍ غير Abaad Messaging، فإشعاراتُ الرقم لا تصل أبعاد. الرقمُ ما زال يُرسل. الإصلاح: إعادةُ الربط من زرّ «ربط واتساب API».'),
            self::INCOMPLETE => WhatsAppLinkCheck::say((string) $c?->link_problem),
            self::CONNECTED => __('الرقمُ يُرسل، ولم يُتحقَّق بعدُ من تطبيق أبعاد ولا من اشتراك الإشعارات — اضغط «فحص الربط».'),
            self::WEBHOOK_INCOMPLETE => match ($c?->webhook_state) {
                WhatsAppLinkCheck::OVERRIDE => __('تطبيقُ أبعاد مشترك، لكنّ إشعاراتِ هذا الحساب موجَّهةٌ إلى عنوانٍ بديلٍ لا إلى أبعاد.'),
                default => __('تطبيقُ أبعاد ليس مشتركًا في إشعارات هذا الحساب — الرسائلُ الواردة لا تصل. أعد الربط.'),
            },
            self::READY => __('تطبيقُ أبعاد مشتركٌ في الإشعارات، ولم يصل إشعارٌ بعد. يثبت الاستقبال بأوّل رسالةٍ أو حالةِ تسليم.'),
            self::RECEIVING => __('آخرُ إشعارٍ وصل: :at', ['at' => optional($c?->last_webhook_at)->format('Y-m-d H:i')]),
            default => (string) ($c?->last_error_message ?: __('أعد الربط أو اختبر الاتصال.')),
        };
    }

    public static function tone(string $state): string
    {
        return match ($state) {
            self::RECEIVING => 'success',
            self::READY, self::CONNECTED => 'info',
            self::AWAITING => 'neutral',
            default => 'danger',
        };
    }

    /**
     * ما تقرؤه الشاشتان — بلا رمزٍ ولا معرّفِ تطبيقٍ آخر.
     *
     * @return array{key:string, label:string, hint:string, tone:string, checked_at:?string}
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
        ];
    }
}
