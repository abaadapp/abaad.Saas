<?php

namespace App\Support;

/**
 * أحداث واتساب التي يعرفها النظام.
 *
 * الدورة الافتراضيّة تبقى كما كانت لبقيّة المتاجر. أمّا RIBBON فله ملفّ
 * صريحٌ من أربع رسائل فقط؛ فلا يغيّر طلبُ متجرٍ واحد سلوكَ كلّ المنصّة.
 */
class WhatsAppEvent
{
    public const ORDER_RECEIVED = 'order_received';

    public const ORDER_CONFIRMED = 'order_confirmed';

    public const ORDER_READY = 'order_ready';

    public const ORDER_OUT_FOR_DELIVERY = 'order_out_for_delivery';

    public const ORDER_DELIVERED = 'order_delivered';

    public const INVOICE_DUE_SOON = 'invoice_due_soon';

    public const INVOICE_OVERDUE = 'invoice_overdue';

    public const INVOICE_PAID = 'invoice_paid';

    /** كلّ الأحداث التي يعرفها المرسل — لا يعني أنّ كلّ متجر يستعملها كلّها. */
    public const ALL = [
        self::ORDER_RECEIVED,
        self::ORDER_CONFIRMED,
        self::ORDER_READY,
        self::ORDER_OUT_FOR_DELIVERY,
        self::ORDER_DELIVERED,
        self::INVOICE_DUE_SOON,
        self::INVOICE_OVERDUE,
        self::INVOICE_PAID,
    ];

    /** دورة أبعاد الافتراضيّة لبقيّة المتاجر — كما كانت قبل تخصيص RIBBON. */
    public const DEFAULT_EVENTS = [
        self::ORDER_CONFIRMED,
        self::ORDER_READY,
        self::ORDER_OUT_FOR_DELIVERY,
        self::ORDER_DELIVERED,
        self::INVOICE_DUE_SOON,
        self::INVOICE_OVERDUE,
        self::INVOICE_PAID,
    ];

    /** ما طلبه RIBBON حرفيًّا — لا تسليم ولا رسائل فواتير. */
    public const RIBBON_EVENTS = [
        self::ORDER_RECEIVED,
        self::ORDER_CONFIRMED,
        self::ORDER_READY,
        self::ORDER_OUT_FOR_DELIVERY,
    ];

    /** ما يُربط قالبُه فقط يومَ يظهر عند ميتا معتمَدًا. */
    public const ON_APPROVAL = [self::INVOICE_PAID];

    /** الدورة الافتراضيّة للحالات — RIBBON يمرّ من forBusinessStatus أدناه. */
    public static function statusMap(): array
    {
        return [
            OrderStatus::CONFIRMED => self::ORDER_CONFIRMED,
            OrderStatus::READY => self::ORDER_READY,
            OrderStatus::OUT_FOR_DELIVERY => self::ORDER_OUT_FOR_DELIVERY,
            OrderStatus::DELIVERED => self::ORDER_DELIVERED,
        ];
    }

    public static function forStatus(?string $status): ?string
    {
        return self::statusMap()[$status] ?? null;
    }

    /** أهذا أحد متاجر RIBBON التي خُصّصت لها دورة سعود؟ */
    public static function isRibbonBusiness(int $businessId): bool
    {
        return in_array($businessId, array_map('intval', (array) config('whatsapp.ribbon_businesses', [])), true);
    }

    /** الأحداث التي يجوز لهذا المتجر أن يرسلها أصلًا. */
    public static function eventsForBusiness(int $businessId): array
    {
        return self::isRibbonBusiness($businessId) ? self::RIBBON_EVENTS : self::DEFAULT_EVENTS;
    }

    public static function allowedForBusiness(int $businessId, string $event): bool
    {
        return in_array($event, self::eventsForBusiness($businessId), true);
    }

    /**
     * حالة الطلب → حدث هذا المتجر.
     *
     * RIBBON وحده يعتبر «جديد» استلامًا للطلب، ولا يرسل عند «تم التسليم».
     */
    public static function forBusinessStatus(int $businessId, ?string $status): ?string
    {
        if (! self::isRibbonBusiness($businessId)) {
            return self::forStatus($status);
        }

        return match ($status) {
            OrderStatus::PENDING => self::ORDER_RECEIVED,
            OrderStatus::CONFIRMED => self::ORDER_CONFIRMED,
            OrderStatus::READY => self::ORDER_READY,
            OrderStatus::OUT_FOR_DELIVERY => self::ORDER_OUT_FOR_DELIVERY,
            default => null,
        };
    }

    /** مفاتيح مقابض الإرسال في إعدادات المتجر. */
    public const SETTING_KEYS = [
        self::ORDER_RECEIVED => 'wa_on_received',
        self::ORDER_CONFIRMED => 'wa_on_order',
        self::ORDER_READY => 'wa_on_ready',
        self::ORDER_OUT_FOR_DELIVERY => 'wa_on_out_for_delivery',
        self::ORDER_DELIVERED => 'wa_on_delivered',
        self::INVOICE_DUE_SOON => 'wa_on_invoice_due_soon',
        self::INVOICE_OVERDUE => 'wa_on_invoice_overdue',
        self::INVOICE_PAID => 'wa_on_invoice_paid',
    ];

    public const LABELS = [
        self::ORDER_RECEIVED => 'استلام الطلب',
        self::ORDER_CONFIRMED => 'تأكيد الطلب',
        self::ORDER_READY => 'جاهزية الطلب',
        self::ORDER_OUT_FOR_DELIVERY => 'خروج الطلب للتوصيل',
        self::ORDER_DELIVERED => 'تسليم الطلب',
        self::INVOICE_DUE_SOON => 'اقتراب استحقاق فاتورة',
        self::INVOICE_OVERDUE => 'تأخّر سداد فاتورة',
        self::INVOICE_PAID => 'إرسال الفاتورة بعد دفعها برابط الدفع',
    ];

    public const LANGUAGES = ['ar', 'en'];

    /** أسماء أبعاد الافتراضيّة لبقيّة الحسابات. */
    public const DEFAULT_TEMPLATES = [
        self::ORDER_RECEIVED => 'abaad_order_received',
        self::ORDER_CONFIRMED => 'abaad_order_confirmed',
        self::ORDER_READY => 'abaad_order_ready',
        self::ORDER_OUT_FOR_DELIVERY => 'abaad_order_out_for_delivery',
        self::ORDER_DELIVERED => 'abaad_order_delivered',
        self::INVOICE_DUE_SOON => 'abaad_invoice_due_soon',
        self::INVOICE_OVERDUE => 'abaad_invoice_overdue',
        self::INVOICE_PAID => 'abaad_invoice_paid',
    ];

    /** أسماء القوالب التي سينشئها سعود في WABA الخاص بـRIBBON. */
    public const RIBBON_TEMPLATES = [
        self::ORDER_RECEIVED => 'ribbon_order_received',
        self::ORDER_CONFIRMED => 'ribbon_order_confirmed',
        self::ORDER_READY => 'ribbon_order_ready',
        self::ORDER_OUT_FOR_DELIVERY => 'ribbon_order_out_for_delivery',
    ];

    public static function templateNameForBusiness(int $businessId, string $event): string
    {
        if (self::isRibbonBusiness($businessId) && isset(self::RIBBON_TEMPLATES[$event])) {
            return self::RIBBON_TEMPLATES[$event];
        }

        return self::DEFAULT_TEMPLATES[$event] ?? $event;
    }

    public static function label(string $event): string
    {
        return __(self::LABELS[$event] ?? $event);
    }
}
