<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Review;
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

    /** أكتب صاحبُ هذا الطلب رأيَه؟ — يُقرأ من التقييم لا من ختمٍ في الطلب */
    public static function written(?Order $order): bool
    {
        return $order !== null
            && $order->exists
            && Review::where('order_id', $order->getKey())->exists();
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
