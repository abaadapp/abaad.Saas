<?php

namespace App\Support\Store;

use App\Models\Product;
use App\Support\FlowerOrder;
use Illuminate\Validation\ValidationException;

/**
 * كرتُ الهدية صنفًا من رفّ المتجر — لمتاجر القائمة وحدها.
 *
 * ═══ وما الصنف ═══
 *
 * صنفُ المتجر نفسِه، نشطٌ ومنشور، اسمُه «كرت هدية» حرفًا بحرف
 * (`GiftCard::PRODUCT_NAME`). يُديره صاحبُه من «المنتجات»: الاسمُ والثمنُ
 * والصورةُ والنشر. ولا يُنشأ هنا صنفٌ ولا يُكتب ثمن — والثمنُ يُقرأ من
 * الصنف بالتسعير القائم (`SaleLines::priceItems`) كأيّ صنف.
 *
 * والسؤالُ دائمًا عن صنفٍ **في هذا المتجر**: صنفٌ بالاسم نفسِه في متجرٍ
 * آخر لا يُعدّ كرتًا هنا، ولا يُقرأ معرّفٌ من خارجه.
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

    /** أهذا الصنفُ كرتُ الهدية في هذا المتجر؟ */
    public static function is(int $businessId, ?Product $product): bool
    {
        return $product !== null
            && self::on($businessId)
            && (int) $product->business_id === $businessId
            && $product->name === GiftCard::PRODUCT_NAME
            && (bool) $product->active
            && (bool) $product->published;
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
