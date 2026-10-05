<?php

namespace App\Support\Store;

use App\Models\Review;

/**
 * آراءُ صنفٍ على صفحته — المعدّلُ والعددُ وتوزيعُ النجوم والقائمة.
 *
 * ═══ ما يدخلها ═══
 *
 * رأيُ **صنف** (`type = product`) كُتب عن بندٍ اشتُري، **منشور**، لهذا الصنف
 * في هذا المتجر (`Review::forProductPage`). فالمعلّقُ والمرفوضُ لا يمسّان
 * رقمًا ولا يُعرضان، ورأيُ الطلب لا يُنسب لصنفٍ لا يُعرف أنّه عنه.
 *
 * ═══ والقائمةُ غيرُ العدد ═══
 *
 * النجومُ بلا كلامٍ تُحتسب في المعدّل والعدد والتوزيع، ولا تُعرض في القائمة
 * — وهي قاعدةُ `Review::scopeShowable` نفسُها في قسم الآراء: رقمٌ لا شهادة.
 *
 * ═══ وبلا تخزين ═══
 *
 * استعلامان لصفحة صنفٍ واحدة: تجميعٌ واحدٌ للأرقام، وقائمةٌ محدودةٌ بأصحابها.
 * ولا عمودَ معدّلٍ يُحفظ في المنتج: رقمٌ محفوظٌ يحتاج من يحدّثه عند كلّ نشرٍ
 * وحجبٍ وحذف — ويفترق يوم يُنسى أحدُها.
 */
final class ProductReviews
{
    /** كم رأيًا يُعرض على الصفحة — الأحدثُ أوّلًا */
    public const SHOWN = 10;

    /**
     * @return array{average: float, count: int, distribution: array<int, int>, list: list<array<string, mixed>>}
     */
    public static function for(int $businessId, int $productId): array
    {
        $case = fn (int $n) => "sum(case when rating = {$n} then 1 else 0 end) as r{$n}";

        $row = Review::forProductPage($businessId, $productId)
            ->selectRaw('count(*) as total, avg(rating) as average, '.implode(', ', array_map($case, [5, 4, 3, 2, 1])))
            ->toBase()
            ->first();

        $count = (int) ($row->total ?? 0);
        $distribution = [];
        foreach ([5, 4, 3, 2, 1] as $n) {
            $distribution[$n] = (int) ($row->{'r'.$n} ?? 0);
        }

        $list = $count === 0 ? [] : Review::forProductPage($businessId, $productId)
            ->whereNotNull('comment')->where('comment', '!=', '')
            ->with('customer:id,name')
            ->latest()->latest('id')
            ->take(self::SHOWN)
            ->get()
            ->map(fn (Review $r) => [
                'id' => (int) $r->id,
                'name' => $r->displayName(),
                'rating' => max(1, min(5, (int) $r->rating)),
                'comment' => (string) $r->comment,
                'date' => optional($r->created_at)->format('Y-m-d'),
                'verified' => $r->verifiedPurchase(),
                // ردُّ المتجر — باسمه لا باسم موظّفٍ كتبه. وحذفُه يُفرغه فيغيب
                'reply' => filled($r->reply) ? [
                    'text' => (string) $r->reply,
                    'date' => optional($r->replied_at)->format('Y-m-d'),
                ] : null,
            ])
            ->all();

        return [
            'average' => $count ? round((float) $row->average, 1) : 0.0,
            'count' => $count,
            'distribution' => $distribution,
            'list' => $list,
        ];
    }
}
