<?php

namespace App\Support\Store;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\FlowerOrder;
use Illuminate\Validation\ValidationException;

/**
 * كرتُ الهدية صنفًا من رفّ المتجر — لمتاجر القائمة وحدها.
 *
 * ═══ وما الصنف ═══
 *
 * صنفُ المتجر نفسِه، نشطٌ ومنشورٌ وخارجَ دفتر المخزون، اسمُه «كرت هدية»
 * حرفًا بحرف (`GiftCard::PRODUCT_NAME`) — وهو وحده بهذا الاسم في متجره.
 * يُديره صاحبُه من «المنتجات»: الثمنُ والصورةُ والوصفُ والنشرُ والاسمُ
 * الإنجليزيّ. أمّا الاسمُ العربيّ فثابت: هو ما يُعرَف به الكرت، فلا يُغيَّر
 * (`forSave`). ولا يُنشأ هنا صنفٌ ولا يُكتب ثمن — والثمنُ يُقرأ من الصنف
 * بالتسعير القائم (`SaleLines::priceItems`) كأيّ صنف.
 *
 * والسؤالُ دائمًا عن صنفٍ **في هذا المتجر**: صنفٌ بالاسم نفسِه في متجرٍ
 * آخر لا يُعدّ كرتًا هنا، ولا يُقرأ معرّفٌ من خارجه. ولا معرّفَ ثابتٌ ولا
 * عمودٌ جديد: الاسمُ الثابت هو التعريف.
 *
 * ═══ ونصُّه مع بنده ═══
 *
 * يُكتب على صفحة الصنف ويُحفظ مع البند في السلّة، ويصل الخادمَ في `note`
 * البند. وبندُ كرتٍ بلا نصٍّ يُردّ — الحمولةُ يكتبها من شاء. ويُكتب في
 * بند الطلب (`order_items.note`) وفي `orders.card_message` لشاشات التجهيز
 * والطباعة، من المصدر نفسِه.
 *
 * ولا تُعرض في إتمام الطلب خانةُ كرتٍ عند هؤلاء: لا رسالةٌ مجّانيّة ولا
 * كرتٌ مدفوع (`GiftCard::enabled` تُغلقه لهم).
 *
 * انظر `storefront.ribbon_gift_card_product_businesses`.
 */
final class GiftCardProduct
{
    /** أطولُ نصٍّ على الكرت — حدُّ خانة الكرت نفسُه */
    public const MAX = FlowerOrder::CARD_MAX;

    public static function on(int $businessId): bool
    {
        $list = array_map('intval', (array) config('storefront.ribbon_gift_card_product_businesses', []));

        return in_array($businessId, $list, true);
    }

    /** أيحمل هذا الصنفُ اسمَ الكرت في متجرٍ من القائمة؟ — بلا سؤالٍ عن حاله */
    public static function named(int $businessId, ?Product $product): bool
    {
        return $product !== null
            && self::on($businessId)
            && (int) $product->business_id === $businessId
            && $product->name === GiftCard::PRODUCT_NAME;
    }

    /**
     * أهذا الصنفُ كرتُ الهدية في هذا المتجر؟
     *
     * باسمه، نشطًا ومنشورًا، خارجَ دفتر المخزون — ووحيدًا بهذا الاسم بين
     * المعروض. فصنفان معروضان بالاسم نفسِه لا يُختار أحدُهما: لا كرتَ
     * حتّى يُصلَح (`settle` تردّ بندَه بسببه).
     */
    public static function is(int $businessId, ?Product $product): bool
    {
        return self::named($businessId, $product)
            && (bool) $product->active
            && (bool) $product->published
            && ! $product->tracksStock()
            && Product::where('business_id', $businessId)->where('name', GiftCard::PRODUCT_NAME)
                ->where('active', true)->where('published', true)->count() === 1;
    }

    /**
     * ما يُحفظ من «المنتجات» لصنفٍ هو الكرتُ أو يصير إليه — في متاجر القائمة.
     *
     * - الاسمُ العربيّ ثابت: صنفٌ اسمُه «كرت هدية» لا يُسمّى غيرَه.
     * - ولا صنفان بهذا الاسم في المتجر الواحد: الكرتُ يُعدَّل لا يُكرَّر.
     * - وخارجَ دفتر المخزون دائمًا: في الإضافة يُفرض (`tracks_stock=false`
     *   وكميّةٌ صفر) ولو أُرسل غيرُه، وفي التعديل يُردّ ربطُه بالمخزون.
     *
     * وما سوى ذلك يُحفظ كما أُرسل: الثمنُ والصورةُ والوصفُ والنشرُ والاسمُ
     * الإنجليزيّ. ومتجرٌ خارج القائمة لا يُسأل عن شيء.
     *
     * @param  array<string, mixed>  $data  ما تحقّق منه المتحكّم
     * @param  Product|null  $existing  الصنفُ قبل التعديل — `null` في الإضافة
     * @return array<string, mixed>
     */
    public static function forSave(int $businessId, array $data, ?Product $existing): array
    {
        if (! self::on($businessId)) {
            return $data;
        }

        $name = (string) ($data['name'] ?? $existing?->name ?? '');

        if (self::named($businessId, $existing) && $name !== GiftCard::PRODUCT_NAME) {
            throw ValidationException::withMessages(['name' => __('اسم منتج كرت الهدية ثابت ولا يمكن تغييره.')]);
        }

        if ($name !== GiftCard::PRODUCT_NAME) {
            return $data;
        }

        $twin = Product::where('business_id', $businessId)->where('name', GiftCard::PRODUCT_NAME)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();

        if ($twin) {
            throw ValidationException::withMessages(['name' => __('كرت الهدية موجود في منتجاتك — عدّله بدل إضافة كرت آخر.')]);
        }

        if ($existing === null) {
            return ['tracks_stock' => false, 'quantity' => 0] + $data;
        }

        $tracks = array_key_exists('tracks_stock', $data) ? (bool) $data['tracks_stock'] : $existing->tracksStock();

        if ($tracks) {
            throw ValidationException::withMessages(['tracks_stock' => __('كرت الهدية غير مرتبط بالمخزون.')]);
        }

        return $data;
    }

    /**
     * ملاحظةُ البند كما تُطبع على الفاتورة والإيصال — ورسالةُ الكرت لا تُطبع.
     *
     * نصُّ الكرت يُحفظ في `order_items.note` لأنّه نصُّ بنده (`settle`)،
     * والورقةُ الماليّة تطبع ملاحظةَ كلّ بندٍ تحت اسمه. فكانت الرسالةُ
     * الخاصّة تخرج على الفاتورة وشريطِ الصندوق ورابطِ الورقة العامّ — وهي
     * للمستلِم على كرته، لا لورقة حساب.
     *
     * فبندُ الكرت بلا ملاحظةٍ على الورقة: صنفُه «كرت هدية»، أو نصُّه من
     * رسالة الطلب (`orders.card_message`) إن تغيّر صنفُه بعد البيع. وسائرُ
     * الملاحظات تُطبع كما كانت. والبندُ وثمنُه باقيان: الكرتُ مبيعٌ يُحاسَب.
     */
    public static function paperNote(OrderItem $item, Order $order): ?string
    {
        $note = trim((string) $item->note);

        if ($note === '') {
            return null;
        }

        if ($item->name === GiftCard::PRODUCT_NAME || $item->product?->name === GiftCard::PRODUCT_NAME) {
            return null;
        }

        if (filled($order->card_message) && str_contains((string) $order->card_message, $note)) {
            return null;
        }

        return $item->note;
    }

    /** نصُّ البند كما وصل — وما ليس نصًّا فراغ */
    public static function message(mixed $raw): string
    {
        return is_scalar($raw) ? trim(str_replace("\r\n", "\n", (string) $raw)) : '';
    }

    /**
     * يُفحص نصُّ كلّ بندٍ مسعَّر — ويُمحى عمّا ليس كرتًا.
     *
     * بندُ الكرت بلا نصٍّ يُردّ، وأطولُ من الحدّ يُردّ ولا يُقصّ (نصٌّ مقصوصٌ
     * يُطبع على كرتٍ ولم يكتبه أحدٌ هكذا). وبندٌ غيرُ الكرت لا يحمل نصًّا:
     * يبقى `note` فيه فارغًا كما كان قبل هذا.
     *
     * @param  list<array<string, mixed>>  $lines  أسطرُ `SaleLines::priceItems`
     * @return list<array<string, mixed>>
     */
    public static function settle(int $businessId, array $lines): array
    {
        foreach ($lines as $i => $l) {
            if (! self::is($businessId, $l['product'] ?? null)) {
                /*
                 * وصنفٌ باسم الكرت ليس كرتًا (مرتبطٌ بالمخزون، أو له توأمٌ
                 * معروض) لا يُباع صنفًا عاديًّا بلا نصّ — يُردّ حتّى يُصلَح.
                 */
                if (self::named($businessId, $l['product'] ?? null)) {
                    throw ValidationException::withMessages(['items' => __('كرت الهدية غير متاح الآن.')]);
                }

                $lines[$i]['note'] = null;

                continue;
            }

            $text = self::message($l['note'] ?? null);

            if ($text === '') {
                throw ValidationException::withMessages(['items' => __('اكتب رسالة كرت الهدية قبل إضافته إلى الطلب.')]);
            }

            if (mb_strlen($text) > self::MAX) {
                throw ValidationException::withMessages(['items' => __('رسالة كرت الهدية أطول من :max حرفًا.', ['max' => self::MAX])]);
            }

            $lines[$i]['note'] = $text;
            $lines[$i]['gift_card'] = true;
        }

        return $lines;
    }

    /**
     * نصوصُ الكروت في الطلب — لعمود `orders.card_message`.
     *
     * من بنود الكرت نفسِها لا من حقلٍ آخر: مصدرٌ واحد. وكرتان بنصّين
     * يُكتبان معًا بسطرٍ فارغٍ بينهما، فلا يضيع أحدُهما.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public static function orderMessage(array $lines): ?string
    {
        $texts = array_values(array_unique(array_filter(array_map(
            fn ($l) => ($l['gift_card'] ?? false) ? (string) $l['note'] : '',
            $lines,
        ))));

        return $texts === [] ? null : implode("\n\n", $texts);
    }
}
