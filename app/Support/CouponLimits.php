<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * حدُّ الكوبون لكلّ زبون — الهويّةُ والعدُّ والردُّ في موضعٍ واحد.
 *
 * ═══ ولمَ موضعٌ واحد ═══
 *
 * الكوبون يُفحص في أربعة أماكن: معاينةُ الصندوق، ودفعُ الصندوق، وتسعيرةُ
 * الموقع، وإتمامُ الموقع. وأربعُ نسخٍ من قاعدةٍ واحدة تفترق يومًا — فيقبل
 * بابٌ ما يردّه الآخر، والتاجر لا يعرف أيَّهما الصحيح. فالقاعدةُ هنا،
 * ويقرؤها الأربعةُ جميعًا.
 *
 * ═══ هويّةُ الزبون ═══
 *
 * الرقمُ مطبَّعًا أوّلًا (`WhatsAppPhone::normalize`): «91234567» و«+968 9123
 * 4567» و«00968-91234567» و«٩١٢٣٤٥٦٧» زبونٌ واحد، فلا يُتجاوز الحدُّ بإعادة
 * كتابة الرقم بشكلٍ آخر. ومن لا رقمَ له وله بطاقةٌ في المتجر يُعرف
 * بمعرّفها.
 *
 * ولا يُعتمد عنوانُ الشبكة: بيتٌ واحدٌ يجمع خمسةَ زبائن، وزبونٌ واحدٌ يحمل
 * عنوانين في الطريق بين بيته وعمله. حدٌّ يُبنى عليه يمنع بريئًا ويمرّر
 * متجاوزًا في اليوم نفسِه.
 *
 * ═══ ورقمُ الهاتف ليس فريدًا في الدنيا ═══
 *
 * هو فريدٌ في متجرٍ واحد. فالعدُّ مُقيَّدٌ بـ`business_id` الكوبون نفسِه —
 * وزبونُ متجرٍ لا يُحسب على كوبون متجرٍ آخر أبدًا.
 */
final class CouponLimits
{
    /** بادئةُ الهويّة بالرقم — والبادئةُ تمنع تصادمَ «5» رقمًا و«5» معرّفًا */
    private const BY_PHONE = 'phone:';

    /** وهويّةُ من لا رقمَ له: بطاقتُه في هذا المتجر */
    private const BY_CARD = 'customer:';

    /**
     * هويّةُ الزبون لحساب الحدّ — أو `null` إن لم تكن موثوقة.
     *
     * والأولويّةُ للرقم لا للبطاقة: البطاقتان بالرقم نفسِه زبونٌ واحد.
     *
     * @param  string|null  $rawPhone  ما كتبه الكاشير أو الزبون، بأيّ شكل
     */
    public static function identity(?Customer $customer, ?string $rawPhone = null): ?string
    {
        $phone = WhatsAppPhone::normalize($customer?->phone ?: $rawPhone);

        if ($phone !== null) {
            return self::BY_PHONE.$phone;
        }

        return $customer ? self::BY_CARD.$customer->id : null;
    }

    /**
     * كم مرّةً استعمل هذا الزبونُ هذا الكود.
     *
     * ═══ وطريقان لا طريق ═══
     *
     * الهويّةُ لقطةٌ يوم الاستعمال، ولا تُعاد كتابتُها — فطلبٌ قديم يبقى على
     * هويّته. ولهذا يُسأل الطريقان معًا:
     *
     *   من غيَّر رقمه على بطاقته نفسِها تنقطع هويّتُه الأولى — فيُعرف
     *   بمعرّف بطاقته، ولا يُفتح له الحدُّ من جديد بتعديل حقل.
     *
     *   ومن أنشأ بطاقةً ثانيةً بالرقم نفسِه معرّفُه جديد — فيُعرف بالرقم.
     *
     * فلا يُفلت أحدُهما.
     */
    public static function countFor(Coupon $coupon, ?string $key, ?int $customerId): int
    {
        if ($key === null && $customerId === null) {
            return 0;
        }

        /*
         * و`business_id` ليس عزلًا هنا — `coupon_id` وحده يعزل، فالكوبونُ
         * لمتجرٍ واحد. وإنّما هو أوّلُ عمودٍ في الفهرس المركَّب، وبلا هذا
         * الشرط لا يُقرأ الفهرسُ أصلًا فيُمسح الجدولُ في كلّ بيعة. فلا
         * يُحذف على أنّه مكرَّر — والعزلُ الحقيقيّ في عمود الصفّ يوم يُكتب.
         */
        return DB::table('coupon_redemptions')
            ->where('business_id', $coupon->business_id)
            ->where('coupon_id', $coupon->id)
            ->where(function ($q) use ($key, $customerId) {
                if ($key !== null) {
                    $q->orWhere('customer_key', $key);
                }
                if ($customerId !== null) {
                    $q->orWhere('customer_id', $customerId);
                }
            })
            ->count();
    }

    /**
     * سببُ ردِّ الكوبون لهذا الزبون — أو `null` إن بقيت له مرّة.
     *
     * وكوبونٌ بلا حدٍّ لكلّ زبون يُردّ `null` دائمًا: هو كلُّ كوبونٍ في
     * القاعدة اليوم، فلا يتبدّل عليه شيء.
     */
    public static function refusal(?Coupon $coupon, ?string $key, ?int $customerId): ?string
    {
        if ($coupon === null || $coupon->per_customer_limit === null) {
            return null;
        }

        $limit = (int) $coupon->per_customer_limit;

        /*
         * ولا حدَّ لمجهولٍ.
         *
         * «مرّتان لكلّ زبون» تفترض زبونًا يُعرف. وبيعةُ المارّ بلا اسمٍ ولا
         * رقم لا زبونَ فيها — فلو مرّت لَكان الكودُ بلا حدٍّ أصلًا: يُستعمل
         * ألفَ مرّةٍ على «عميل نقدي» واحد.
         *
         * فيُطلب الرقمُ ويُقال لماذا. والكاشيرُ يملك الحقلَ في الشاشة نفسِها.
         */
        if ($key === null && $customerId === null) {
            return __('هذا الكوبون محدودٌ لكلّ زبون — أضف رقم هاتف الزبون ليُحسب له.');
        }

        if (self::countFor($coupon, $key, $customerId) >= $limit) {
            return __('حدُّ هذا الكوبون :n استخدام لكل زبون، وقد بلغه هذا الزبون.', ['n' => $limit]);
        }

        return null;
    }

    /**
     * يُكتب الاستعمال — مرّةً واحدةً للطلب مهما تكرّر النداء.
     *
     * و`insertOrIgnore` تتّكئ على القيد الفريد في القاعدة لا على فحصٍ يقرأ
     * ثمّ يكتب: إشعارُ بوّابةٍ أُعيد إرسالُه، أو طلبان متزامنان، يمرّان من
     * الفحص معًا ويُردّان عند القيد.
     */
    public static function record(?Coupon $coupon, Order $order, ?string $key, ?int $customerId): void
    {
        if ($coupon === null || $key === null && $customerId === null) {
            return;
        }

        DB::table('coupon_redemptions')->insertOrIgnore([
            'business_id' => (int) $coupon->business_id,
            'coupon_id' => (int) $coupon->id,
            'order_id' => (int) $order->id,
            'customer_id' => $customerId,
            // هويّةٌ لا رقمَ لها: البطاقةُ وحدَها — والعمودُ لا يقبل الفراغ
            'customer_key' => $key ?? self::BY_CARD.$customerId,
            'redeemed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * يُمحى استعمالُ طلبٍ ألغي — كما يُردّ العدّادُ الإجماليّ.
     *
     * وإلّا بقي الزبونُ ممنوعًا بكوبونٍ لم ينتفع به: طلبٌ ألغاه المحلُّ نفسُه
     * يأكل مرّةً من مرّاته.
     */
    public static function release(Order $order): void
    {
        DB::table('coupon_redemptions')->where('order_id', $order->id)->delete();
    }
}
