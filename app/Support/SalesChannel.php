<?php

namespace App\Support;

/**
 * قناةُ البيع — البابُ الذي دخل منه الطلب.
 *
 * تُكتب في `orders.channel` حين يُنشأ الطلب ولا تُخمَّن بعده. وبابان يُنشئان
 * طلبًا: الصندوق (`PosController`) والموقعُ ذو السلّة (`WebCheckout::place`).
 * ومواقعُ واتساب لا تُنشئ طلبًا — تفتح حديثًا — فلا تُكتب لها قناة.
 *
 * والطلباتُ التي سبقت العمودَ فارغةُ القناة، وتُعرض «غير محدّدة» لا
 * «صندوق»: تاريخٌ لم يُكتب لا يُملأ بالظنّ.
 */
final class SalesChannel
{
    public const POS = 'pos';

    /** طلبٌ أتمّه الزبون بنفسه من الموقع — يكتبها `WebCheckout::place` وحدها */
    public const WEBSITE = 'website';

    public const UNKNOWN = 'unknown';

    public static function label(?string $channel): string
    {
        return match ($channel) {
            self::POS => __('نقطة البيع'),
            self::WEBSITE => __('الموقع الإلكتروني'),
            default => __('غير محدّدة'),
        };
    }

    /**
     * القنواتُ التي تُرشَّح بها القوائم — مصدرٌ واحد تقرأ منه الشاشةُ والخادم.
     *
     * و«غير محدّدة» فيها: طلباتُ ما قبل العمود موجودةٌ فعلًا في قواعد
     * المتاجر، ومُرشِّحٌ لا يبلغها يجعل مجموعَ القنوات أقلَّ من مجموع
     * القائمة — فيُقرأ الفرقُ عطبًا وهو تاريخ.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $c) => ['value' => $c, 'label' => self::label($c === self::UNKNOWN ? null : $c)],
            [self::WEBSITE, self::POS, self::UNKNOWN],
        );
    }

    /**
     * حصرُ استعلامِ طلباتٍ على قناةٍ واحدة.
     *
     * وموضعٌ واحد يعرف أنّ «غير محدّدة» فراغٌ في العمود لا قيمةٌ فيه: كانت
     * القاعدةُ مكتوبةً في `SeasonSales` وحدها، فكلُّ مُرشِّحٍ جديد يعيد
     * اكتشافَها — أو ينساها فيردّ صفرًا على قناةٍ فيها مئات.
     */
    public static function scope(\Illuminate\Database\Eloquent\Builder $q, ?string $channel): \Illuminate\Database\Eloquent\Builder
    {
        if ($channel === null || $channel === '') {
            return $q;
        }

        return $channel === self::UNKNOWN
            ? $q->whereNull('channel')
            : $q->where('channel', $channel);
    }
}
