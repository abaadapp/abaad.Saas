<?php

namespace App\Support;

/**
 * ما يتبدّل في لوحة المنصّة وهي مفتوحة — لقطةٌ واحدة للصفحة ونبضِها.
 *
 * كانت نبضةُ اللوحة (`super-admin.dashboard.stats`) تردّ البطاقات وحدها،
 * و«الإيرادات الشهرية» و«نمو الشركات» خصائصُ الصفحة لحظةَ فتحها. فشركةٌ
 * تُسجَّل أو فاتورةٌ تُسدَّد بعد الفتح ترفع البطاقة ولا تمسّ الرسم تحتها.
 *
 * فالثلاثةُ من هنا: تنادي بها الصفحةُ عند الفتح والنبضةُ في كلّ دورة.
 */
final class PlatformMetrics
{
    /** @return array{stats: array, revenueSeries: array, growthSeries: array} */
    public static function dashboard(): array
    {
        return [
            'stats' => Demo::superStats(),
            'revenueSeries' => Demo::revenueSeries(),
            'growthSeries' => Demo::businessesGrowthSeries(),
        ];
    }
}
