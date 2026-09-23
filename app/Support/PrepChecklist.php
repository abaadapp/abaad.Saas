<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderPrepCheck;

/**
 * قائمةُ تحقّق الطاولة — ما يُجمع قبل أن يُقال «جاهز».
 *
 * ═══ ما هي، وما ليست ═══
 *
 * علامةٌ تشغيليّة يضعها من يجهّز ليتذكّر ما جمعه: هذا البند، وهذه الإضافة،
 * والتغليف، ومراجعة التعليمات. لا تخصم من رفّ، ولا تكتب قيدًا، ولا تنقل
 * الطلب. من يؤشّر كلَّ المربّعات يضغط «جاهز» بنفسه — واللوحة لا تضغط عنه.
 *
 * ═══ والمفاتيحُ من لقطة البيع لا من الكتالوج ═══
 *
 * `item:{order_item_id}` و`addon:{order_item_addon_id}`: صفوفُ الطلب نفسُه،
 * وهي لقطةٌ لا تتغيّر بعد البيع. فمنتجٌ يُعاد تسميتُه أو قالبٌ يُبدَّل بعد
 * شهر لا يُزحزح علامةً وُضعت يوم التجهيز، ولا يترك مربّعًا مؤشَّرًا بلا سطر.
 *
 * والمهمّتان الثابتتان مفتاحُهما `task:` — لا صفَّ لهما في الطلب أصلًا.
 *
 * ═══ والمفتاحُ يُتحقَّق منه عند الكتابة لا عند العرض ═══
 *
 * `allows()` تسأل: أهذا المفتاح من هذا الطلب؟ فلا يضع موظّفٌ علامةً على
 * بندِ طلبٍ آخر بتبديل رقمٍ في الطلب — والطلبُ نفسُه محصورٌ بمتجره وفرعه
 * قبل أن تصل الدالّة أصلًا.
 */
final class PrepChecklist
{
    /** التغليف — آخرُ ما يُفعل وأوّلُ ما يُنسى */
    public const TASK_PACKAGING = 'task:packaging';

    /** مراجعةُ التعليمات: نصُّ البطاقة والملاحظات وخيارات الطلب المخصَّص */
    public const TASK_INSTRUCTIONS = 'task:instructions';

    /** المهمّتان الثابتتان — تُعرضان لكلّ طلبٍ مهما كانت بنودُه */
    public const TASKS = [self::TASK_PACKAGING, self::TASK_INSTRUCTIONS];

    /**
     * المفاتيح المشروعة لهذا الطلب — بنودُه وإضافاتُه والمهمّتان.
     *
     * تُقرأ من العلاقات المحمَّلة سلفًا حين تكون محمَّلة: اللوحة تحمّل
     * `items.addons` لكلّ بطاقاتها في استعلامين، فلا تُفتح هنا مئتا استعلام.
     *
     * @return list<string>
     */
    public static function keys(Order $order): array
    {
        $keys = self::TASKS;

        foreach ($order->items as $item) {
            $keys[] = 'item:'.$item->id;

            foreach ($item->addons as $addon) {
                $keys[] = 'addon:'.$addon->id;
            }
        }

        return $keys;
    }

    /** أينتمي هذا المفتاح إلى هذا الطلب؟ — حارسُ الكتابة */
    public static function allows(Order $order, string $key): bool
    {
        return in_array($key, self::keys($order), true);
    }

    /**
     * يضع العلامة أو يرفعها — ويردّ حالَها بعد الفعل.
     *
     * والإدراجُ `firstOrCreate` لا `create`: موظّفان يضغطان المربّعَ نفسه معًا،
     * والقيدُ الفريد يردّ الثاني باستثناء. ولا `Contention` هنا لأنّ الأمر لا
     * يقع داخل معاملةٍ محيطة — انظر تعليق `Contention` نفسه.
     */
    public static function set(Order $order, string $key, bool $on): bool
    {
        if ($on) {
            OrderPrepCheck::firstOrCreate(
                ['order_id' => $order->id, 'key' => $key],
                [
                    'business_id' => $order->business_id,
                    'user_id' => auth()->id(),
                    'user_name' => auth()->user()?->name,
                ],
            );

            return true;
        }

        OrderPrepCheck::where('order_id', $order->id)->where('key', $key)->delete();

        return false;
    }

    /**
     * علاماتُ طلبٍ واحد — مفتاحٌ إلى من وضعها ومتى.
     *
     * @return array<string, array{by: string|null, at: string}>
     */
    public static function of(Order $order): array
    {
        return self::forOrders([$order->id])[$order->id] ?? [];
    }

    /**
     * علاماتُ دفعةٍ من الطلبات — استعلامٌ واحد لا واحدٌ لكلّ بطاقة.
     *
     * اللوحةُ تعرض مئتين، فقراءةُ العلامات بطاقةً بطاقة تعني مئتي استعلامٍ
     * في كلّ استطلاع — وهو يقع كلّ عشرين ثانية.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array<string, array{by: string|null, at: string}>>
     */
    public static function forOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return OrderPrepCheck::whereIn('order_id', $orderIds)
            ->get(['order_id', 'key', 'user_name', 'created_at'])
            ->groupBy('order_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [
                $r->key => [
                    'by' => $r->user_name,
                    'at' => optional($r->created_at)->format('Y-m-d H:i'),
                ],
            ])->all())
            ->all();
    }
}
