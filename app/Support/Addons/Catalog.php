<?php

namespace App\Support\Addons;

/**
 * ما تفتحه إضافاتُ الاشتراك — قائمةٌ مغلقة، وكلُّ مفتاحٍ فيها له حارسُه.
 *
 * ═══ ولمَ مغلقة ═══
 *
 * إضافةٌ يكتب صاحبُ المنصّة رمزَها بيده ولا يقرؤه حارسٌ تُباع ولا تفتح شيئًا،
 * وأخرى برمز قدرةٍ لم يُرد بيعَها تُغلق على متاجر السياسة الجديدة ما كان مشمولًا.
 * فالرمزُ (`code`) حرٌّ يكتبه، وما تفتحه (`capability`) يُختار من هنا:
 *
 *   - مفاتيحُ الباقة (`FEATURE_KEYS`): تُسأل في `PlanFeatures::allows`.
 *   - `gift_orders`: الإهداءُ في `GiftOrders::on`، والطلبُ المخصَّص في
 *     `CustomArrangement::enabled`.
 *   - `whatsapp_own_number`: `WhatsAppFeature::canUseOwnNumber`.
 *   - `branches`: سقفٌ لا قدرة — يرفع `PlanLimits::cap` بعدد الوحدات.
 *   - الفراغ: خدمةٌ تقدّمها أبعاد بلا بابٍ في النظام (التكاملُ عبر API) — تُفوتَر
 *     وتُسجَّل، ولا تفتح شيئًا.
 */
final class Catalog
{
    public const POS = 'pos';

    public const GIFT_ORDERS = 'gift_orders';

    public const OWN_NUMBER = 'whatsapp_own_number';

    public const BRANCHES = 'branches';

    /** ما تفتحه الإضافة — والاسمُ كما يُعرض لصاحب المنصّة */
    public const CAPABILITIES = [
        self::POS => 'نظام الكاشير ونقاط البيع',
        'website' => 'الموقع الإلكتروني',
        'marketing_tools' => 'أدوات التسويق',
        'loyalty' => 'برنامج الولاء',
        'whatsapp' => 'إشعارات واتساب',
        self::GIFT_ORDERS => 'الهدايا والطلبات المخصّصة',
        self::OWN_NUMBER => 'ربط واتساب برقم المتجر',
        self::BRANCHES => 'فروعٌ فوق سقف الباقة',
    ];

    /**
     * مفاتيحُ قدرات الباقة التي تُباع إضافةً — تقرّرها الإضافاتُ لمتاجر السياسة الجديدة.
     *
     * وما سواها من قدرات الباقة (التقاريرُ المتقدّمة، والصلاحياتُ المخصّصة،
     * والتكاملات، وقدراتُ المؤسسات) يبقى من الباقة نفسِها كما كان.
     */
    public const FEATURE_KEYS = [self::POS, 'website', 'marketing_tools', 'loyalty', 'whatsapp'];

    public const PAID = 'paid';

    public const FREE = 'free';

    /** حسب الطلب: يُسعَّر لكلّ متجرٍ ويُعتمد قبل أن يُفعَّل */
    public const QUOTE = 'quote';

    public const PRICING = [self::PAID, self::FREE, self::QUOTE];

    public const UNIT_STORE = 'store';

    public const UNIT_BRANCH = 'branch';

    public const UNIT_USAGE = 'usage';

    public const UNITS = [self::UNIT_STORE, self::UNIT_BRANCH, self::UNIT_USAGE];

    /** دورتا الإضافة — شهرٌ أو سنة، ولكلٍّ سعرُها في الكتالوج */
    public const CYCLES = ['monthly', 'yearly'];

    /** تُشترى في الباقة */
    public const ELIGIBLE = 'eligible';

    /** مشمولةٌ بالباقة — لا تُشترى فيها ولا تُفوتَر مرّتين */
    public const INCLUDED = 'included';

    public const MODES = [self::ELIGIBLE, self::INCLUDED];

    /** أقصى وحداتٍ في طلبٍ واحد — حدٌّ لخطأ إصبعٍ لا سقفٌ تجاريّ */
    public const MAX_QUANTITY = 50;

    /** هل تُباع هذه الوحدة بعددٍ؟ — الفرعُ ووحدةُ الاستخدام، لا المتجر */
    public static function counted(string $unit): bool
    {
        return $unit !== self::UNIT_STORE;
    }

    /** @return list<array{key: string, label: string}> */
    public static function capabilityOptions(): array
    {
        return array_map(fn ($key) => ['key' => $key, 'label' => __(self::CAPABILITIES[$key])], array_keys(self::CAPABILITIES));
    }
}
