<?php

namespace App\Support;

/**
 * قناةُ البيع — البابُ الذي دخل منه الطلب.
 *
 * تُكتب في `orders.channel` حين يُنشأ الطلب ولا تُخمَّن بعده. واليومَ بابٌ
 * واحدٌ يُنشئ طلبًا: الصندوق. والموقعُ يفتح حديثَ واتساب ولا يُنشئ طلبًا
 * (انظر `Website\Commerce::checkout`) — فلا تُكتب له قناةٌ حتى يُنشئ.
 * والطلباتُ التي سبقت العمودَ فارغةُ القناة، وتُعرض «غير محدّدة» لا
 * «صندوق»: تاريخٌ لم يُكتب لا يُملأ بالظنّ.
 */
final class SalesChannel
{
    public const POS = 'pos';

    /** محجوزةٌ ليوم يصير في الموقع إتمامُ طلب — لا يكتبها شيءٌ اليوم */
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
}
