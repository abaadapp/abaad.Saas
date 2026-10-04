<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * دعوةُ التقييم — الرمزُ الذي يكتب به الزبونُ رأيَه بيده.
 *
 * وهي الموضعُ الوحيد الذي يعرف ثلاثةَ أشياء: متى يُدعى الزبون، وما رمزُ
 * دعوته، وهل كتب. ومن سألها لا يعيد بناءَ الجواب.
 *
 * ═══ ولمَ الطلبُ هو الرمز ═══
 *
 * لا جدولَ «دعوات»: الدعوةُ ليست شيئًا قائمًا بذاته — هي طلبٌ وُلد له رمز.
 * وجدولٌ ثانٍ يعني عمودَين يقولان «كُتب الرأي»: صفًّا في الدعوة و`order_id`
 * في التقييم. وحقلان يقولان الشيء نفسه يفترقان يومًا.
 *
 * فالرمزُ عمودٌ في الطلب، و«هل كُتب؟» يُقرأ من التقييم نفسِه.
 */
class ReviewInvite
{
    /** اثنان وعشرون حرفًا من ستّةٍ وستّين احتمالًا — كرمز الورقة */
    private const LENGTH = 22;

    /**
     * أيُدعى صاحبُ هذا الطلب؟
     *
     * وشرطُه واحد: أن تكون التجربةُ قد وقعت — `OrderStatus::fulfilled`.
     * وطلبٌ مُعلَّق (`is_held`) ليس طلبًا بعد: هو سلّةٌ في الصندوق.
     */
    public static function eligible(?Order $order): bool
    {
        return $order !== null
            && $order->exists
            && ! $order->is_held
            && OrderStatus::fulfilled($order->status);
    }

    /**
     * رمزُ دعوة هذا الطلب — يُنشأ عند أوّل طلبٍ له ويبقى.
     *
     * ولا يُبنى لطلبٍ لم يبلغ صاحبَه: رابطٌ مبنيٌّ رابطٌ يُسرَّب. وما لا
     * يُبنى لا يُفتح قبل أوانه.
     */
    public static function token(?Order $order): ?string
    {
        if (! self::eligible($order)) {
            return null;
        }

        if (filled($order->review_token)) {
            return $order->review_token;
        }

        /*
         * والكتابةُ مشروطةٌ بالفراغ ثمّ يُقرأ ما استقرّ.
         *
         * صندوقان يفتحان الطلبَ نفسَه في اللحظة نفسها يمرّان معًا على «هل
         * له رمز؟» فيجيب كلاهما «لا». والشرطُ في `where` يجعل الثانيةَ
         * تكتب صفرَ صفوف، والقراءةُ بعدها تردّ رمزَ الأوّل — فالرابطان
         * واحد.
         */
        Order::whereKey($order->getKey())
            ->whereNull('review_token')
            ->update(['review_token' => Str::random(self::LENGTH)]);

        $order->refresh();

        return $order->review_token;
    }

    /** رابطُ الدعوة — أو null لطلبٍ لا يُدعى صاحبُه */
    public static function url(?Order $order): ?string
    {
        $token = self::token($order);

        return $token === null ? null : route('review.write', $token);
    }

    /** الطلبُ الذي يشير إليه رمزٌ — أو null فلا شيء يُفتح */
    public static function find(string $token): ?Order
    {
        return Order::with(['business', 'customer'])
            ->where('review_token', $token)
            ->where('is_held', false)
            ->first();
    }

    /**
     * أأتمّ صاحبُ هذا الطلب ما يُكتب عنه؟ — يُقرأ من التقييمات لا من ختمٍ في الطلب.
     *
     * ═══ ولم يعد «رأيٌ واحد يكفي» ═══
     *
     * طلبٌ فيه أصنافٌ يُكتب لكلّ بندٍ رأيُه: فلا يُغلق حتّى يُكتب لكلّها.
     * ورأيُ صنفٍ واحد — أو رأيٌ في الطلب كُتب قبل آراء الأصناف — لا يُغلق
     * الباقي. وطلبٌ بلا صنفٍ يُقيَّم يبقى على حاله: رأيٌ واحدٌ فيه.
     */
    public static function written(?Order $order): bool
    {
        if ($order === null || ! $order->exists) {
            return false;
        }

        $items = self::items($order);

        if ($items->isEmpty()) {
            return Review::where('order_id', $order->getKey())
                ->where('type', Review::TYPE_ORDER)->exists();
        }

        return $items->every(fn (OrderItem $i) => $i->review_id !== null);
    }

    /**
     * بنودُ الطلب التي يُكتب فيها رأي — ومع كلٍّ رأيُه إن كُتب (`review_id`).
     *
     * بندٌ لصنفٍ من **متجر الطلب** ما زال قائمًا. وما لا صنفَ له — طلبٌ
     * مخصَّصٌ بلا صنف، أو صنفٌ حُذف — لا صفحةَ له يُعرض عليها رأي.
     *
     * @return Collection<int, OrderItem>
     */
    public static function items(Order $order): Collection
    {
        return OrderItem::where('order_items.order_id', $order->getKey())
            ->whereNotNull('order_items.product_id')
            ->whereHas('product', fn ($q) => $q->where('business_id', $order->business_id))
            ->with('product:id,name,name_en,image')
            ->addSelect(['review_id' => Review::select('id')
                ->whereColumn('reviews.order_item_id', 'order_items.id')
                ->where('reviews.type', Review::TYPE_PRODUCT)
                ->limit(1)])
            ->orderBy('order_items.id')
            ->get();
    }

    /**
     * بندٌ من هذا الطلب يُكتب فيه رأي — أو null.
     *
     * والمعرّفُ يأتي من المتصفّح فلا يُصدَّق: يُطلب بين بنود **هذا الطلب**
     * وحدها. بندُ طلبٍ آخر — في المتجر نفسِه أو في غيره — لا يوجد هنا.
     */
    public static function item(Order $order, int $itemId): ?OrderItem
    {
        return self::items($order)->firstWhere('id', $itemId);
    }

    /**
     * الاسمُ الذي يُنسب إليه الرأي حين لا عميلَ مسجَّلًا.
     *
     * و`null` حين يكون: `Review::displayName()` تقرأ اسمَ العميل من صفّه،
     * فنسخُه هنا يجعل تقييمًا باسمٍ قديم يبقى عليه بعد أن يُصحَّح الاسم.
     */
    public static function author(Order $order): ?string
    {
        if ($order->customer_id !== null) {
            return null;
        }

        $name = trim((string) ($order->customer_name ?? ''));

        return $name === '' ? null : mb_substr($name, 0, 255);
    }
}
