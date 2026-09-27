<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StorePaymentIntent;
use App\Support\Store\Paymob;
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
    public static function countFor(Coupon $coupon, ?string $key, ?int $customerId, ?StorePaymentIntent $except = null): int
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
            /*
             * ═══ ولا يُحسب إلّا ما وقع بعد قرار التاجر ═══
             *
             * كودٌ يعمل منذ شهور، ثمّ يضبط عليه التاجرُ «مرّتان لكلّ زبون».
             * فلو حُسبت استعمالاتُ ما قبل قراره لَاستيقظ زبائنُه ممنوعين على
             * حدٍّ لم يكن موجودًا يوم اشتروا. والعدُّ من لحظة التفعيل
             * (`per_customer_since`) — وهي محفوظةٌ لكلّ كوبونٍ على حدة.
             *
             * وكوبونٌ بلا تاريخِ تفعيلٍ (وهو ما لا يقع: التاريخُ يُكتب مع
             * الحدّ) يُحسب كلُّه — فالغيابُ لا يُفتح به الحدُّ على مصراعيه.
             */
            ->when($coupon->per_customer_since, fn ($q) => $q->where(
                fn ($w) => $w->where('redeemed_at', '>=', $coupon->per_customer_since)
                    // والحجزُ الحيُّ يُحسب أيًّا كان تاريخُ تفعيلِه: هو الآن
                    ->orWhereNotNull('reserved_until'),
            ))
            /*
             * وما يُحسب: استعمالٌ وقع (له طلب)، أو حجزٌ لم تنقضِ مدّتُه.
             *
             * وحجزٌ انقضى لا يُحسب ولا يُحذف في حينه — لا مهمّةَ مجدولةٍ
             * لأجله، ولا صفٌّ يُمحى من تحت نيّةِ دفعٍ قد يصل إشعارُها متأخّرًا.
             */
            ->where(fn ($q) => $q->whereNotNull('order_id')
                ->orWhere('reserved_until', '>', now()))
            /*
             * وحجزُ النيّةِ التي نفحصها لا يُحسب عليها.
             *
             * الزبونُ حجز فرصتَه بنفسه عند فتح صفحة الدفع. فلو حُسبت عليه
             * لَردّ الحدُّ طلبَه هو — دفع، وحُجزت له، ثمّ مُنع بحجزه.
             */
            ->when($except, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('store_payment_intent_id')
                    ->orWhere('store_payment_intent_id', '!=', $except->id),
            ))
            ->count();
    }

    /**
     * سببُ ردِّ الكوبون لهذا الزبون — أو `null` إن بقيت له مرّة.
     *
     * وكوبونٌ بلا حدٍّ لكلّ زبون يُردّ `null` دائمًا: هو كلُّ كوبونٍ في
     * القاعدة اليوم، فلا يتبدّل عليه شيء.
     */
    public static function refusal(?Coupon $coupon, ?string $key, ?int $customerId, ?StorePaymentIntent $except = null): ?string
    {
        if ($coupon === null) {
            return null;
        }

        // والحدُّ الإجماليُّ أوّلًا: من نفد كودُه لا يُسأل عن نصيبه منه
        if ($why = self::totalRefusal($coupon, $except)) {
            return $why;
        }

        if ($coupon->per_customer_limit === null) {
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

        if (self::countFor($coupon, $key, $customerId, $except) >= $limit) {
            return __('حدُّ هذا الكوبون :n استخدام لكل زبون، وقد بلغه هذا الزبون.', ['n' => $limit]);
        }

        return null;
    }

    /**
     * كم فرصةً محجوزةً الآن على هذا الكود — لكلّ الزبائن.
     *
     * والحجوزُ لا تدخل `used_count`: ذاك عدّادُ ما وقع فعلًا، ويُقرأ في
     * الفواتير والتقارير. فالحجزُ يُحسب هنا ويُضاف إليه عند الفحص وحده.
     */
    private static function reservedFor(Coupon $coupon, ?StorePaymentIntent $except = null): int
    {
        return DB::table('coupon_redemptions')
            ->where('business_id', $coupon->business_id)
            ->where('coupon_id', $coupon->id)
            ->whereNull('order_id')
            ->where('reserved_until', '>', now())
            // وحجزُ النيّةِ التي نفحصها لا يُحسب عليها — انظر `countFor`
            ->when($except, fn ($q) => $q->where('store_payment_intent_id', '!=', $except->id))
            ->count();
    }

    /**
     * سببُ ردِّ الكوبون بحدِّه الإجماليّ — والحجوزُ محسوبةٌ فيه.
     *
     * ═══ العطبُ الذي يُغلقه ═══
     *
     * `used_count` لا يعرف من فتح صفحةَ الدفع ولم يُنشأ طلبُه بعد. فكودٌ
     * بقيت له فرصةٌ واحدة: يفتح فلانٌ صفحةَ البنك عليها، ويشتري غيرُه بالدفع
     * عند الاستلام في أثنائها، فيصل إشعارُ فلانٍ إلى كودٍ نفد — وقد دفع
     * سعرًا مخفَّضًا. فتُحسب الحجوزُ مع العدّاد، ويُردّ الثاني عند السلّة قبل
     * أن يدفع أحدٌ شيئًا.
     *
     * واللفظُ لفظُ الردِّ نفسُه الذي تقوله الأبوابُ الأربعة — لا لفظٌ خامسٌ
     * يسأل التاجرُ عن معناه.
     */
    public static function totalRefusal(?Coupon $coupon, ?StorePaymentIntent $except = null): ?string
    {
        if ($coupon === null || $coupon->max_uses === null) {
            return null;
        }

        if ((int) $coupon->used_count + self::reservedFor($coupon, $except) >= (int) $coupon->max_uses) {
            return __('انتهت مرات استخدام الكوبون');
        }

        return null;
    }

    /**
     * يحجز فرصةَ استعمالٍ لنيّةِ دفعٍ — قبل أن يُفتح للزبون بابُ البنك.
     *
     * ═══ ولمَ حجزٌ لا فحصٌ وحدَه ═══
     *
     * الفحصُ يقول «بقيت فرصة» ثمّ يمضي الزبونُ إلى صفحة الدفع. وبين
     * اللحظتين دقائق يستعمل فيها غيرُه آخرَ فرصة. فيدفع هذا سعرًا مخفَّضًا
     * ويصل إشعارُه إلى كودٍ نفد: إمّا كُتب طلبُه بسعرٍ غير الذي دفعه، أو
     * رُدّ والمالُ مقبوض. والحجزُ يمنع الاثنين.
     *
     * ونيّةٌ واحدةٌ حجزٌ واحد — القيدُ الفريد على `store_payment_intent_id`.
     * فتحديثُ الصفحة لا يحجز مرّتين.
     *
     * @return bool هل حُجزت؟ (`false` إن لم يكن للكوبون حدٌّ لكلّ زبون)
     */
    public static function reserve(?Coupon $coupon, StorePaymentIntent $intent, ?string $key, ?int $customerId): bool
    {
        /*
         * ويُحجز لأيّ حدٍّ كان — لا للذي لكلّ زبون وحده.
         *
         * الفرصةُ الأخيرةُ في الحدّ **الإجماليّ** يأخذها زبونٌ آخر بالدفع عند
         * الاستلام في الدقائق التي يمضيها هذا في صفحة البنك: فيصل إشعارُه إلى
         * كودٍ نفد. وكودٌ بلا حدٍّ من أيّ نوعٍ لا يُحجز له: لا فرصةَ تُزاحم.
         */
        if ($coupon === null || $key === null && $customerId === null) {
            return false;
        }

        if (! $coupon->isPerCustomerLimited() && $coupon->max_uses === null) {
            return false;
        }

        /*
         * وما انقضى يُحرَّر صريحًا لا يُتجاهَل وحدَه.
         *
         * الاستعلامُ يتجاهله على كلّ حال، لكنّ صفًّا يبقى إلى الأبد عن دفعةٍ
         * لم تقع يُثقل الجدولَ ويُربك من يقرؤه بعد سنة. فيُكنس حجزُ هذا الكود
         * كلَّ مرّةٍ يُحجز فيه — كنسٌ محدودٌ بصفٍّ واحدٍ وفهرسِه، لا مهمّةٌ
         * مجدولةٌ تُنسى.
         *
         * ولا يُكنس ما صار استعمالًا: شرطُ `order_id IS NULL`.
         */
        DB::table('coupon_redemptions')
            ->where('business_id', $coupon->business_id)
            ->where('coupon_id', $coupon->id)
            ->whereNull('order_id')
            ->where('reserved_until', '<', now())
            ->delete();

        return DB::table('coupon_redemptions')->insertOrIgnore([
            'business_id' => (int) $coupon->business_id,
            'coupon_id' => (int) $coupon->id,
            'order_id' => null,
            'store_payment_intent_id' => (int) $intent->id,
            'customer_id' => $customerId,
            'customer_key' => $key ?? self::BY_CARD.$customerId,
            /*
             * ومدّةُ الحجز مهلةُ جلسة الدفع نفسُها — تُقرأ من النيّة لا تُحسب
             * هنا. فلو حُسبت في موضعين لَافترقتا يومَ تتبدّل مهلةُ البوّابة:
             * حجزٌ أقصرُ يُفرِج عن الفرصة وزبونٌ ما زال يدفع، وحجزٌ أطولُ
             * يُمسكها بعد أن أُغلقت صفحتُه.
             */
            'reserved_until' => $intent->expires_at ?? now()->addSeconds(Paymob::EXPIRES),
            'redeemed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }

    /**
     * يُطرح الحجزُ — دفعةٌ فشلت، أو أُلغيت، أو استُرجعت.
     *
     * ولا يُطرح حجزٌ صار استعمالًا: من دفع وأُنشئ طلبُه لا تُعاد فرصتُه
     * بإشعارٍ متأخّر. وشرطُ `order_id IS NULL` هو الذي يقول ذلك — فإشعارُ
     * فشلٍ مكرَّرٌ لا يُعيد الفرصةَ مرّتين ولا يمحو استعمالًا قائمًا.
     */
    public static function releaseReservation(StorePaymentIntent $intent): void
    {
        DB::table('coupon_redemptions')
            ->where('store_payment_intent_id', $intent->id)
            ->whereNull('order_id')
            ->delete();
    }

    /**
     * يُكتب الاستعمال — مرّةً واحدةً للطلب مهما تكرّر النداء.
     *
     * و`insertOrIgnore` تتّكئ على القيد الفريد في القاعدة لا على فحصٍ يقرأ
     * ثمّ يكتب: إشعارُ بوّابةٍ أُعيد إرسالُه، أو طلبان متزامنان، يمرّان من
     * الفحص معًا ويُردّان عند القيد.
     *
     * وحجزٌ سابقٌ لهذه النيّة **يُحوَّل** ولا يُضاف إليه صفٌّ ثانٍ: وإلّا
     * حُسب الزبونُ مرّتين — حجزًا واستعمالًا — على شراءٍ واحد.
     */
    public static function record(?Coupon $coupon, Order $order, ?string $key, ?int $customerId, ?StorePaymentIntent $intent = null): void
    {
        if ($coupon === null || $key === null && $customerId === null) {
            return;
        }

        if ($intent !== null) {
            $turned = DB::table('coupon_redemptions')
                ->where('store_payment_intent_id', $intent->id)
                ->whereNull('order_id')
                ->update([
                    'order_id' => (int) $order->id,
                    'reserved_until' => null,
                    'redeemed_at' => now(),
                    'updated_at' => now(),
                ]);

            /*
             * والخروجُ هنا توفيرُ محاولةٍ لا حارس: القيدُ الفريد على
             * `store_payment_intent_id` هو الذي يمنع الصفَّ الثاني فعلًا —
             * قِستُه بطفرةٍ تحذف هذا الشرطَ فلا يتغيّر شيء. فلا يُحذف الفهرسُ
             * الفريدُ على أنّ هذا يكفي.
             */
            if ($turned > 0) {
                return;
            }
        }

        DB::table('coupon_redemptions')->insertOrIgnore([
            'business_id' => (int) $coupon->business_id,
            'coupon_id' => (int) $coupon->id,
            'order_id' => (int) $order->id,
            'store_payment_intent_id' => $intent?->id,
            'customer_id' => $customerId,
            // هويّةٌ لا رقمَ لها: البطاقةُ وحدَها — والعمودُ لا يقبل الفراغ
            'customer_key' => $key ?? self::BY_CARD.$customerId,
            'reserved_until' => null,
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
