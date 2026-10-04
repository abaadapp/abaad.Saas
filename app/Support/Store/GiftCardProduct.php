<?php

namespace App\Support\Store;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\FlowerOrder;
use App\Support\SalesChannel;
use Illuminate\Validation\ValidationException;

/**
 * كرتُ الهدية صنفًا من رفّ المتجر — لمتاجر القائمة وحدها.
 *
 * ═══ وما الصنف ═══
 *
 * صنفُ المتجر نفسِه **المعلَّمُ** كرتًا (`products.is_gift_card`)، نشطٌ ومنشورٌ
 * وخارجَ دفتر المخزون — وهو وحده المعلَّمُ المعروضُ في متجره. يُديره صاحبُه
 * من «المنتجات»: الثمنُ والصورةُ والوصفُ والنشرُ والاسمان. والاسمُ اسمُ عرضٍ
 * لا هويّة: يُغيَّر ولا يسقط الكرت. ولا يُنشأ هنا صنفٌ ولا يُكتب ثمن —
 * والثمنُ يُقرأ من الصنف بالتسعير القائم (`SaleLines::priceItems`) كأيّ صنف.
 *
 * ولمَ علامةٌ لا اسم: كان الكرتُ يُعرف بالاسم «كرت هدية» حرفًا بحرف، فاسمٌ
 * بفارقِ حرفٍ يُسقطه صامتًا — يُعرض صنفًا عاديًّا ولا تُفتح خانةُ رسالته.
 * والاسمُ الحرفيّ (`GiftCard::PRODUCT_NAME`) صار بابَ الولادة وحده: صنفٌ
 * يُحفظ به ولا كرتَ معلَّمٌ في متجره يُعلَّم (`forSave`). والكرتُ القائم
 * عُلِّم بهجرة (`..._230100_...`).
 *
 * والسؤالُ دائمًا عن صنفٍ **في هذا المتجر**: صنفٌ معلَّمٌ في متجرٍ آخر لا
 * يُعدّ كرتًا هنا، ولا يُقرأ معرّفٌ من خارجه.
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

    /** أهذا الصنفُ معلَّمٌ كرتًا في متجرٍ من القائمة؟ — بلا سؤالٍ عن حاله */
    public static function marked(int $businessId, ?Product $product): bool
    {
        return $product !== null
            && self::on($businessId)
            && (int) $product->business_id === $businessId
            && (bool) $product->is_gift_card;
    }

    /**
     * أهذا الصنفُ كرتُ الهدية في هذا المتجر؟
     *
     * بعلامته لا باسمه، نشطًا ومنشورًا، خارجَ دفتر المخزون — ووحيدًا بين
     * المعلَّم المعروض. فكرتان معروضان لا يُختار أحدُهما: لا كرتَ حتّى
     * يُصلَح (`settle` تردّ بندَه بسببه).
     */
    public static function is(int $businessId, ?Product $product): bool
    {
        return self::marked($businessId, $product)
            && (bool) $product->active
            && (bool) $product->published
            && ! $product->tracksStock()
            && Product::where('business_id', $businessId)->where('is_gift_card', true)
                ->where('active', true)->where('published', true)->count() === 1;
    }

    /**
     * ما يُحفظ من «المنتجات» لصنفٍ هو الكرتُ أو يصير إليه — في متاجر القائمة.
     *
     * - الكرتُ المعلَّمُ يبقى معلَّمًا، واسماه يُغيَّران بحرّيّة.
     * - وصنفٌ غيرُ معلَّمٍ يُحفظ بالاسم «كرت هدية» حرفًا بحرف يصير الكرت —
     *   إن لم يكن في المتجر كرتٌ معلَّم. وإن كان: يُردّ، فالكرتُ يُعدَّل لا
     *   يُكرَّر. ولا علامةَ تُرسل من الشاشة: لا مفتاحَ يجعل صنفًا ما كرتًا.
     * - والكرتُ خارجَ دفتر المخزون دائمًا: في الإضافة يُفرض (`tracks_stock=false`
     *   وكميّةٌ صفر) ولو أُرسل غيرُه، وفي التعديل يُردّ ربطُه بالمخزون.
     *
     * وما سوى ذلك يُحفظ كما أُرسل. ومتجرٌ خارج القائمة لا يُسأل عن شيء،
     * ولا يُعلَّم له صنف.
     *
     * @param  array<string, mixed>  $data  ما تحقّق منه المتحكّم
     * @param  Product|null  $existing  الصنفُ قبل التعديل — `null` في الإضافة
     * @return array<string, mixed>
     */
    public static function forSave(int $businessId, array $data, ?Product $existing): array
    {
        unset($data['is_gift_card']);

        if (! self::on($businessId)) {
            return $data;
        }

        if (! self::marked($businessId, $existing)) {
            if ((string) ($data['name'] ?? $existing?->name ?? '') !== GiftCard::PRODUCT_NAME) {
                return $data;
            }

            $twin = Product::where('business_id', $businessId)->where('is_gift_card', true)
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();

            if ($twin) {
                throw ValidationException::withMessages(['name' => __('كرت الهدية موجود في منتجاتك — عدّله بدل إضافة كرت آخر.')]);
            }

            $data['is_gift_card'] = true;
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
     * فبندُ الكرت بلا ملاحظةٍ على الورقة، ويُعرف بأحد اثنين:
     *
     * - **علامةُ صنفه** (`products.is_gift_card`) — الهويّةُ نفسُها التي يُباع
     *   بها (`marked`)، لا الاسم: كرتٌ أُعيدت تسميتُه يبقى كرتًا. وتُقرأ ولو
     *   حُذف الصنفُ بعد البيع، ومن صنفٍ في متجر الطلب وحده.
     * - **أو لقطةُ يوم البيع** — احتياطٌ لبندٍ سبق العلامةَ (هجرةُ التعليم
     *   تترك الملتبسَ والمحذوفَ بلا علامة) أو فقد صنفَه. ولا تحجب إلّا ما
     *   لا يُثبَت أنّه عاديّ (`legacyCardLine`).
     *
     * ولا اسمَ في السؤال. وسائرُ الملاحظات تُطبع كما كانت — ولو طابق نصُّها
     * رسالةَ الكرت. والبندُ وثمنُه باقيان: الكرتُ مبيعٌ يُحاسَب.
     */
    public static function paperNote(OrderItem $item, Order $order): ?string
    {
        $note = trim((string) $item->note);

        if ($note === '') {
            return null;
        }

        $product = $item->product_id
            ? Product::withTrashed()->whereKey($item->product_id)->where('business_id', $order->business_id)->first()
            : null;

        if ($product?->is_gift_card || self::legacyCardLine($note, $product, $order)) {
            return null;
        }

        return $item->note;
    }

    /**
     * بندُ كرتٍ بلا علامة — من وقائع يوم البيع، لا من نصّ الملاحظة وحده.
     *
     * كان الاحتياطُ «الملاحظةُ جزءٌ من `card_message`» — فحجب ملاحظةَ بندِ
     * وردٍ عاديّ طابقت الرسالةَ أو كانت بعضَها («كل عام»). والشروطُ الآن معًا:
     *
     * - **طلبُ الموقع:** إتمامُ الموقع وحده كتب نصَّ الكرت في بنده، ويمحو
     *   ملاحظةَ كلّ بندٍ سواه (`settle`). وملاحظاتُ الصندوق لا تُمسّ.
     * - **رسالةٌ كاملة لا بعضُها:** `orderMessage` تكتب نصوصَ الكروت بسطرٍ
     *   فارغٍ بينها، فالملاحظةُ إحداها بتمامها.
     * - **وصنفُه لا يتتبّع المخزون أو ذهب:** الكرتُ خارجَ الدفتر دائمًا
     *   (`is`, `forSave`)، فصنفٌ يُعدّ في المخزون ليس كرتًا.
     */
    private static function legacyCardLine(string $note, ?Product $product, Order $order): bool
    {
        $message = trim((string) $order->card_message);

        if ($order->channel !== SalesChannel::WEBSITE || $message === '') {
            return false;
        }

        $whole = $message === $note
            || str_starts_with($message, $note."\n\n")
            || str_ends_with($message, "\n\n".$note)
            || str_contains($message, "\n\n".$note."\n\n");

        return $whole && ($product === null || ! $product->tracksStock());
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
                 * وصنفٌ معلَّمٌ ليس كرتًا (مرتبطٌ بالمخزون، أو له توأمٌ
                 * معروض) لا يُباع صنفًا عاديًّا بلا نصّ — يُردّ حتّى يُصلَح.
                 */
                if (self::marked($businessId, $l['product'] ?? null)) {
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
