<?php

namespace App\Support;

use App\Models\Setting;

/**
 * كيف تُعرض إضافاتُ المتجر في نقطة البيع — شريطًا أم قسمًا كاملًا.
 *
 * تفضيلُ عرضٍ للنشاط كلِّه لا صفةُ إضافة: لا يغيّر ما يُباع ولا سعرَه ولا
 * ما يُخصم من الرفّ — الإضافاتُ هي هي، والسلّةُ تستقبلها كما كانت.
 *
 * ═══ والغيابُ «شريط» ═══
 *
 * لا صفَّ يُكتب لكلّ متجرٍ عند النشر: من لم يختر شيئًا يبقى على ما كان
 * يراه. وقيمةٌ قديمةٌ أو فاسدة تُقرأ «شريطًا» كذلك — فلا تنكسر شاشةُ
 * الصندوق بسبب صفٍّ لا يُفهم.
 */
final class PosAddonsLayout
{
    public const KEY = 'pos_addons_layout';

    public const BAR = 'bar';

    public const SECTION = 'section';

    public const VALUES = [self::BAR, self::SECTION];

    /** قيمةٌ مفهومة من أيّ مدخل — وما سواها «شريط» */
    public static function normalize(mixed $raw): string
    {
        return in_array($raw, self::VALUES, true) ? $raw : self::BAR;
    }

    /** طريقةُ العرض من خريطة الإعدادات — `Demo::businessSettings` */
    public static function fromSettings(array $settings): string
    {
        return self::normalize($settings[self::KEY] ?? null);
    }

    /** طريقةُ العرض لنشاطٍ بعينه — صفٌّ واحد */
    public static function for(int $businessId): string
    {
        return self::normalize(
            Setting::where('business_id', $businessId)->where('key', self::KEY)->value('value'),
        );
    }
}
