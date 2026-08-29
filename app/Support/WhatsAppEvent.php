<?php

namespace App\Support;

/**
 * الأحداث التي تُرسل عندها رسالة — أربعةٌ لا أكثر.
 *
 * وكلٌّ منها مربوطٌ بحالةٍ قائمة في `OrderStatus` لا بحالةٍ اخترعناها له:
 * حالتان لمعنًى واحد تفترقان عند أوّل تعديل، فيرسل النظام على «جاهز» بينما
 * تعرض الشاشة «قيد التجهيز».
 *
 * و«قيد التجهيز» ليست منها عمدًا: الزبون لا يحتاج أن يعرف أنّ باقته على
 * الطاولة الآن، ورسالةٌ لا تُنتظر تُقرأ إزعاجًا — والرسائل محدودةٌ بحصّة،
 * فكلُّ رسالةٍ لا تُفيد تأكل واحدةً تُفيد.
 */
class WhatsAppEvent
{
    public const ORDER_CONFIRMED = 'order_confirmed';

    public const ORDER_READY = 'order_ready';

    public const ORDER_OUT_FOR_DELIVERY = 'order_out_for_delivery';

    public const ORDER_DELIVERED = 'order_delivered';

    public const ALL = [
        self::ORDER_CONFIRMED,
        self::ORDER_READY,
        self::ORDER_OUT_FOR_DELIVERY,
        self::ORDER_DELIVERED,
    ];

    /** الحالة التي تُطلق كلّ حدث — من `OrderStatus` لا من نصٍّ مكتوبٍ هنا */
    public static function statusMap(): array
    {
        return [
            OrderStatus::CONFIRMED => self::ORDER_CONFIRMED,
            OrderStatus::READY => self::ORDER_READY,
            OrderStatus::OUT_FOR_DELIVERY => self::ORDER_OUT_FOR_DELIVERY,
            OrderStatus::DELIVERED => self::ORDER_DELIVERED,
        ];
    }

    /** حدث هذه الحالة، أو لا شيء إن كانت حالةً لا تُرسَل عندها رسالة */
    public static function forStatus(?string $status): ?string
    {
        return self::statusMap()[$status] ?? null;
    }

    /**
     * مفتاح الإطفاء في إعدادات المتجر.
     *
     * وهي مفاتيح `wa_on_*` القائمة منذ شاشة «إشعارات واتساب» — تُعاد قراءتها
     * لا تُستنسخ: مفتاحٌ ثانٍ لنفس المقبض يعني تاجرًا يُطفئ أحدهما ويبقى
     * الآخر يعمل.
     */
    public const SETTING_KEYS = [
        self::ORDER_CONFIRMED => 'wa_on_order',
        self::ORDER_READY => 'wa_on_ready',
        self::ORDER_OUT_FOR_DELIVERY => 'wa_on_out_for_delivery',
        self::ORDER_DELIVERED => 'wa_on_delivered',
    ];

    public const LABELS = [
        self::ORDER_CONFIRMED => 'تأكيد الطلب',
        self::ORDER_READY => 'جاهزية الطلب',
        self::ORDER_OUT_FOR_DELIVERY => 'خروج الطلب للتوصيل',
        self::ORDER_DELIVERED => 'تسليم الطلب',
    ];

    /** اسم قالب أبعاد الافتراضي لكلّ حدث — يُهيَّأ عند ربط الرقم المشترك */
    public const DEFAULT_TEMPLATES = [
        self::ORDER_CONFIRMED => 'abaad_order_confirmed',
        self::ORDER_READY => 'abaad_order_ready',
        self::ORDER_OUT_FOR_DELIVERY => 'abaad_order_out_for_delivery',
        self::ORDER_DELIVERED => 'abaad_order_delivered',
    ];

    public static function label(string $event): string
    {
        return __(self::LABELS[$event] ?? $event);
    }
}
