<?php

namespace App\Support\Website;

use App\Models\Business;
use App\Support\PaymentMethods;
use App\Support\Store\Paymob;

/**
 * ما يستطيع الموقعُ أن يفعله بالمال فعلًا — لا ما يُظنّ أنّه يستطيعه.
 *
 * ═══ العطب الذي وُضعت له ═══
 *
 * شاشةُ «المتجر» كانت تعرض وسائل الدفع من `PaymentMethods::state` وتقول عن
 * كلٍّ منها «مفعّل» أو «مطفأ». وذلك جوابٌ عن سؤالٍ آخر: `PaymentMethods` تقول
 * **ما تقبله نقطة البيع** — أي ما يأخذه الكاشير من يد الزبون على المنضدة.
 *
 * وبين «البطاقة مفعّلة» على المنضدة و«البطاقة مقبولة على الإنترنت» هُوّة:
 * الثانية تلزمها بوّابةُ دفعٍ مربوطة ومتحقَّق منها، وسلّةٌ في الموقع تُستعمل
 * فيها. فالشاشةُ كانت تقول لصاحب المتجر إنّ موقعه يقبل البطاقة، وهو لا يقبل
 * شيئًا: لا سلّةَ فيه ولا دفع.
 *
 * وهذا ليس نقصَ ميزة، بل **ادّعاءٌ كاذب** — وصاحبُ متجرٍ ينشر موقعه على هذا
 * الظنّ يوزّع رابطه ثمّ يكتشف من زبونه أنّ لا شيء يُدفع.
 *
 * ═══ خمس حالاتٍ لا حالتان ═══
 *
 * وسيلةُ الدفع تمرّ بخمس بواباتٍ قبل أن يصحّ أن يُقال إنّها تعمل على الموقع:
 *
 *   أ) مأذونةٌ في أبعاد/نقطة البيع        → `PaymentMethods::enabledFor`
 *   ب) يقبلها الموقع أصلًا                → `self::ONLINE_CAPABLE`
 *   ج) لها بوّابةٌ مربوطة                 → `self::gateways`
 *   د) البوّابة متحقَّق منها وجاهزة        → `self::gateways` (لا تُدرِج غيرَ الجاهز)
 *   هـ) وفي الموقع سلّةٌ تُستعمل فيها       → `self::checkout`
 *
 * وواحدةٌ ساقطةٌ تُسقط الادّعاء كلَّه. فالجواب يُحسب من القدرات لا يُكتب.
 *
 * ═══ والبوّابةُ غيرُ السلّة ═══
 *
 * `gateways` تقول ما **يقبضه حسابٌ مربوط** — Paymob جاهزةٌ تعني البطاقة —
 * ولا تسأل عن السلّة: التاجرُ يربط حسابَه من «التطبيقات التكاملية» قبل أن
 * يكون في موقعه سلّة، وذاك ربطٌ صحيح. و`payments` هي التي تجمع البوّابةَ
 * والسلّةَ معًا قبل أن تقول «تُقبض على الموقع» — فبوّابةٌ بلا سلّة تُقال
 * «لا سلّة في الموقع بعد» لا «تُقبض».
 */
final class Commerce
{
    /** طلبُ الموقع محادثةُ واتساب — لا سلّة ولا دفع */
    public const CHANNEL_WHATSAPP = 'whatsapp';

    /** ولا قناةَ أصلًا: لا رقمَ واتساب ولا سلّة */
    public const CHANNEL_NONE = 'none';

    /**
     * الوسائل التي **يمكن** أن تُقبض على الإنترنت لو وُجدت بوّابة.
     *
     * والنقدُ ليس منها: «نقدي» على الإنترنت معناه الدفعُ عند الاستلام — وهو
     * حالٌ أخرى لها شرطُها (توصيلٌ أو استلامٌ من المحلّ)، لا وسيلةُ قبضٍ
     * إلكترونيّة. وخلطُهما يجعل متجرًا بلا توصيل يَعِد بالدفع عند الاستلام.
     */
    public const ONLINE_CAPABLE = [PaymentMethods::CARD];

    /**
     * بوّاباتُ الدفع المربوطة والجاهزة لهذا المتجر — بحسابه هو.
     *
     * وPaymob وحدها اليوم: صفُّ هذا المحلّ في `payment_gateways` مكتملٌ
     * ومشغَّل (`PaymentGateway::ready`) فهي تقبض البطاقة. و Apple Pay لا
     * يُعدّ هنا وسيلةً مستقلّة: صفحةُ Paymob تعرضه ضمن الدفع الإلكترونيّ
     * لمن فعّلته له، ولا نعرف ذلك من رقمٍ محفوظ.
     *
     * @return array<int, string> أسماء الوسائل التي تقبضها بوّابةٌ جاهزة
     */
    public static function gateways(int $businessId): array
    {
        return Paymob::gateway($businessId) !== null ? [PaymentMethods::CARD] : [];
    }

    /** هل في الموقع سلّةٌ ومسارُ إتمامِ طلب؟ — انظر `renderer/commerce.ts` */
    public static function checkout(int $businessId): bool
    {
        /*
         * إلّا لمن لبس واجهةً خاصّة: واجهةُ RIBBON فيها سلّةٌ وإتمامُ طلبٍ
         * يُنشئ طلبًا حقيقيًّا في أبعاد — انظر `Store\WebCheckout`. وسائرُ
         * المتاجر على ما يقوله السطرُ التالي.
         */
        if (Business::find($businessId)?->storefrontTheme() !== null) {
            return true;
        }

        /*
         * لا. والعارضُ يقولها في تعليقه: «لا سلّة في هذا العارض ولا دفع».
         *
         * ولا مسارَ عامًّا يقبل طلبًا: `routes/web.php` ليس فيها `POST` يفتحه
         * زبونٌ بلا حساب. فطلبُ الموقع اليوم رسالةُ واتساب يكتبها الزبون
         * ويقرؤها التاجر بيده.
         */
        return false;
    }

    /**
     * ما يفعله زرُّ الطلب في الموقع فعلًا.
     *
     * و«واتساب» ليست إتمامَ طلب: هي قناةٌ يُفتح فيها حديث. تُسمّى باسمها في
     * الشاشة (انظر «السماح بالطلب عبر واتساب») حتّى لا يُقرأ المفتاحُ وعدًا
     * بسلّةٍ ودفع.
     */
    public static function channel(int $businessId): string
    {
        if (self::checkout($businessId)) {
            return 'checkout';
        }

        return trim((string) (MerchantData::identity($businessId)['whatsapp'] ?? '')) !== ''
            ? self::CHANNEL_WHATSAPP
            : self::CHANNEL_NONE;
    }

    /**
     * وسائلُ الدفع كما هي على الموقع — لا كما هي على المنضدة.
     *
     * @return array<int, array{label: string, pos: bool, online: bool, note: string}>
     */
    public static function payments(int $businessId): array
    {
        $enabled = PaymentMethods::enabledFor($businessId);
        $ready = self::gateways($businessId);
        $hasCheckout = self::checkout($businessId);

        return array_map(function (string $method) use ($enabled, $ready, $hasCheckout) {
            $pos = in_array($method, $enabled, true);
            $capable = in_array($method, self::ONLINE_CAPABLE, true);
            $online = $pos && $capable && $hasCheckout && in_array($method, $ready, true);

            return [
                'label' => __($method),
                'pos' => $pos,
                'online' => $online,
                /*
                 * والسببُ يُقال لا يُترك للتخمين.
                 *
                 * «غير متاحة» بلا سببٍ تجعل التاجر يفتح الإعدادات ويُطفئ
                 * ويُشغّل بحثًا عن مقبضٍ لا وجود له. والسببُ الأوّلُ الذي
                 * يقف عنده هو ما يُقال — لا كلُّ الأسباب معًا.
                 */
                'note' => match (true) {
                    $online => __('تُقبض على الموقع'),
                    ! $pos => __('مطفأة في إعدادات الدفع'),
                    ! $capable => __('تُقبض عند الاستلام لا على الموقع'),
                    ! $hasCheckout => __('متاحة في نقطة البيع فقط — لا سلّة في الموقع بعد'),
                    default => __('تحتاج بوّابة دفعٍ مربوطة'),
                },
            ];
        }, PaymentMethods::ALL);
    }
}
