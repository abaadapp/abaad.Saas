<?php

namespace App\Support\Addons;

use App\Models\SubscriptionAddon;
use Illuminate\Support\Str;

/**
 * ما تفتحه إضافاتُ الاشتراك — قائمةٌ مغلقة، وكلُّ مفتاحٍ فيها له حارسُه.
 *
 * ═══ ولمَ مغلقة ═══
 *
 * إضافةٌ يكتب صاحبُ المنصّة رمزَها بيده ولا يقرؤه حارسٌ تُباع ولا تفتح شيئًا،
 * وأخرى برمز قدرةٍ لم يُرد بيعَها تُغلق على متاجر السياسة الجديدة ما كان مشمولًا.
 * فالرمزُ (`code`) يولّده الخادم (`newCode`)، وما تفتحه (`capability`) يُختار من هنا:
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

    /**
     * وصفٌ لما يفتحه البابُ فعلًا — لإضافةٍ لم يكتب لها صاحبُ المنصّة وصفًا.
     *
     * كلُّ سطرٍ يقول ما يفتحه حارسُ قدرته، لا ما يُتمنّى. والخدمةُ بلا باب
     * (`capability = null`) لا وصفَ لها هنا: قد تكون أيَّ شيءٍ يقدّمه فريقُ أبعاد،
     * فلا يُنسب إليها ما لا يُعرف.
     */
    public const DESCRIPTIONS = [
        self::POS => 'البيع من صندوق المحلّ وإصدار الإيصالات ومتابعة جلسات الكاشير.',
        'website' => 'متجرٌ إلكترونيٌّ باسمك يستقبل طلبات زبائنك وتديرها من لوحتك.',
        'marketing_tools' => 'الكوبونات والعروض وتقييمات العملاء.',
        'loyalty' => 'نقاطٌ يجمعها عملاؤك من مشترياتهم ويستبدلونها عند الصندوق.',
        'whatsapp' => 'إرسال حالة الطلب إلى العميل عبر واتساب.',
        self::GIFT_ORDERS => 'طلبات الإهداء ببيانات المستلم ومناسبته، والطلبات المخصّصة بحقولها.',
        self::OWN_NUMBER => 'إرسال رسائل متجرك من رقم واتساب الخاص به بدل الرقم المشترك.',
        self::BRANCHES => 'فروعٌ فوق حدّ الفروع في باقتك.',
    ];

    /** طولُ عمود الرمز في القاعدة — `subscription_addons.code` */
    public const CODE_MAX = 40;

    /**
     * رمزٌ داخليٌّ لإضافةٍ جديدة — يولّده الخادم ولا يكتبه أحد.
     *
     * ═══ ولمَ لا من الاسم ═══
     *
     * الاسمُ يُعدَّل، ويتكرّر («دعم بأولوية» مرّتين لباقتين)، وعربيٌّ لا يصلح
     * رمزًا. والرمزُ مفتاحٌ تقرؤه الطلباتُ والفواتيرُ والاستحقاقات، فيُكتب مرّةً
     * يومَ تُنشأ ولا يتبدّل بعدها.
     *
     * `addon_` وULID بحروفٍ صغيرة: ٣٢ حرفًا في عمودٍ من ٤٠، فريدٌ بلا تنسيق،
     * ويُفحص قبل أن يُكتب. والفهرسُ الفريد في القاعدة يبقى الحارسَ الأخير.
     */
    public static function newCode(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $code = 'addon_'.Str::lower((string) Str::ulid());

            if (! SubscriptionAddon::where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('subscription add-on code: no free code after 5 tries');
    }

    /** وصفُ الإضافة كما يُعرض للتاجر — ما كتبه صاحبُ المنصّة، وإلّا ما يفتحه بابُها */
    public static function describe(SubscriptionAddon $addon, bool $en): ?string
    {
        $own = $en && filled($addon->description_en) ? $addon->description_en : $addon->description;

        if (filled($own)) {
            return $own;
        }

        $fallback = self::DESCRIPTIONS[$addon->capability ?? ''] ?? null;

        return $fallback === null ? null : __($fallback);
    }

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
