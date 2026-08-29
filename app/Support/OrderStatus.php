<?php

namespace App\Support;

/**
 * حالات الطلب — مصدرٌ واحد يقرأ منه الخادمُ والشاشة والبذرة.
 *
 * كانت تُكتب نصًّا في سبعة مواضع: البذرة، والمتجر التجريبيّ، والتنبيهات،
 * وشاشة الطلبات، وأمر تعبئة التاريخ. فمن أضاف حالةً في أحدها لم يعرف أنّ
 * عليه إضافتها في الستّة الباقية — و«جديد» موجودةٌ في البذرة والتنبيهات
 * وغائبةٌ عن مُرشِّح الشاشة، فطلبٌ بها لا يظهر في أيّ تبويب.
 *
 * والقيم تبقى عربيّةً كما هي في القاعدة: ٦٠٦ طلبًا «مكتمل» و١٥٠ «قيد
 * التجهيز» على الإنتاج، وترجمتُها إلى مفاتيح لاتينية تعني ترحيلَ بياناتٍ
 * على جدولٍ حيّ لا يشتري شيئًا. المفتاح هو القيمة، والثوابت تمنع الخطأ
 * المطبعيّ لا تغيّر ما هو مكتوب.
 *
 * ولا عمود `fulfillment_status` ثانٍ: «خرج للتوصيل» و«جاهز» محفوظتان في
 * `status` منذ اليوم الأول، وعمودٌ موازٍ يعني مصدرين للحقيقة الواحدة —
 * أحدهما يُحدَّث والآخر يُنسى، فتقرأ الشاشة غير ما يقرأ التقرير.
 */
class OrderStatus
{
    /* ------------------------------ الحالات ------------------------------ */

    /** طلبٌ سُجّل ولم يُؤكَّد بعد */
    public const PENDING = 'جديد';

    /** أُكّد: العربون قُبض أو الزبون ثبّت الموعد */
    public const CONFIRMED = 'مؤكّد';

    public const PREPARING = 'قيد التجهيز';

    public const READY = 'جاهز';

    public const OUT_FOR_DELIVERY = 'خرج للتوصيل';

    public const DELIVERED = 'تم التسليم';

    public const PICKED_UP = 'تم الاستلام';

    public const DELIVERY_FAILED = 'تعذّر التوصيل';

    /** بيعةٌ تمّت وأُغلقت — وهي حال بيع الصندوق الفوريّ */
    public const COMPLETED = 'مكتمل';

    public const CANCELLED = 'ملغي';

    /** كل الحالات بالترتيب الذي يسير به الطلب — مصدر قوائم الاختيار */
    public const ALL = [
        self::PENDING,
        self::CONFIRMED,
        self::PREPARING,
        self::READY,
        self::OUT_FOR_DELIVERY,
        self::DELIVERED,
        self::PICKED_UP,
        self::DELIVERY_FAILED,
        self::COMPLETED,
        self::CANCELLED,
    ];

    /**
     * ما انتهى أمرُه: لا يُجهَّز ولا يُنتظر.
     *
     * تقرؤها لوحة التجهيز لتستبعد، ويقرؤها حارس الانتقالات ليمنع العودة.
     */
    public const CLOSED = [
        self::DELIVERED,
        self::PICKED_UP,
        self::COMPLETED,
        self::CANCELLED,
    ];

    /* ---------------------------- الانتقالات ---------------------------- */

    /**
     * إلى أين يجوز أن ينتقل الطلب من كل حالة.
     *
     * الحارس هنا لا في الشاشة: الشاشة تُخفي الزرّ، والطلب يصل من عنوانٍ
     * يُكتب. و«تم التسليم ← قيد التجهيز» ليست خطأً في الترتيب وحده — هي
     * طلبٌ خرج من المحل يُعاد إلى طاولة العمل، فيُجهَّز مرّتين ويُحسب
     * مرّتين.
     *
     * والإلغاء مفتوحٌ من كل حالةٍ حيّة: الزبون يعتذر في أيّ لحظة، ومنعُ
     * الإلغاء يجعل الموظف يخترع حيلةً بدلًا منه.
     */
    private const NEXT = [
        self::PENDING => [self::CONFIRMED, self::PREPARING, self::CANCELLED],
        self::CONFIRMED => [self::PREPARING, self::READY, self::CANCELLED],
        self::PREPARING => [self::READY, self::CONFIRMED, self::CANCELLED],
        self::READY => [
            self::OUT_FOR_DELIVERY, self::PICKED_UP, self::COMPLETED,
            self::PREPARING, self::CANCELLED,
        ],
        self::OUT_FOR_DELIVERY => [self::DELIVERED, self::DELIVERY_FAILED, self::CANCELLED],
        // تعذّر التوصيل ليس نهايةً: تُعاد المحاولة غدًا، أو يأتي الزبون بنفسه
        self::DELIVERY_FAILED => [self::OUT_FOR_DELIVERY, self::READY, self::PICKED_UP, self::CANCELLED],
        self::DELIVERED => [self::COMPLETED],
        self::PICKED_UP => [self::COMPLETED],
        // مكتمل وملغى نهايتان: ما بعدهما تصحيحُ فاتورةٍ لا انتقالُ حالة
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    /** هل يجوز الانتقال؟ والبقاء في المكان يجوز دائمًا (حفظٌ بلا تغيير) */
    public static function canMove(?string $from, string $to): bool
    {
        if (! in_array($to, self::ALL, true)) {
            return false;
        }
        if ($from === $to) {
            return true;
        }
        /*
         * حالةٌ لا نعرفها تُعامَل كبدايةٍ لا كنهاية.
         *
         * في القاعدة طلباتٌ كُتبت قبل هذا الملفّ، وقد يُكتب فيها لفظٌ غير
         * ملحوظ هنا. ولو رددنا `false` لتجمّد ذلك الطلب إلى الأبد بلا زرٍّ
         * يحرّكه — وإصلاحُ بياناتٍ قديمة لا يكون بحبسها.
         */
        if ($from === null || ! isset(self::NEXT[$from])) {
            return true;
        }

        return in_array($to, self::NEXT[$from], true);
    }

    /** الحالات التي يجوز الانتقال إليها من هنا — لبناء قائمة الاختيار */
    public static function nextFrom(?string $from): array
    {
        return self::NEXT[$from] ?? self::ALL;
    }

    public static function isClosed(?string $status): bool
    {
        return in_array((string) $status, self::CLOSED, true);
    }

    /** خيارات القائمة: القيمة نفسها اسمًا وتسمية — والترجمة في الواجهة */
    public static function options(): array
    {
        return array_map(fn ($s) => ['value' => $s, 'label' => $s], self::ALL);
    }
}
