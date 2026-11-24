<?php

namespace App\Support;

use App\Models\User;

/**
 * من يقرأ مالَ البوتيكات، ومن يُصدر ورقتَه، ومن يسدّدها.
 *
 * ═══ وثلاثةٌ لا واحد ═══
 *
 * شاشةُ البوتيك تُفتح بصلاحيّة «المنتجات» — لمن يُدخل أصنافه. وكان بها يُقرأ
 * الكشفُ والعمولةُ والصافي وتُصدر ورقةُ التسوية، فموظفُ مبيعاتٍ أو صاحبُ
 * بوتيكٍ أُعطي حسابًا لأصنافه يُصدرها. فصارت ثلاثةَ أسئلة، كلٌّ بصلاحيّةٍ قائمة:
 *
 *   القراءة — قسمُ «المصروفات»: وهو ما تُقرَّر به مرتجعاتُ البوتيك أصلًا
 *     (`reviewReturn`)، ومستحقُّ البوتيك مصروفٌ على المتجر.
 *   الإصدار — «اعتماد سند المورّد»: ورقةٌ تُقيّد مستحقًّا على المتجر.
 *   السداد والاسترداد والاستلام والمقاصّة — «سداد سندات الموردين»: مالٌ يخرج أو يدخل.
 *
 * ولا يُفهم من القراءة إصدارٌ ولا من الإصدار سداد. ولا مفتاحَ جديدٌ هنا: الثلاثةُ
 * قائمةٌ في `Permissions`، والمالكُ والمديرُ يملكانها، ومن خُصّصت صلاحيّاتُه يدويًّا
 * يُعطاها بالاسم.
 */
final class BoutiqueAccess
{
    public static function read(?User $user): bool
    {
        return $user !== null && $user->allows('expenses');
    }

    public static function issue(?User $user): bool
    {
        return self::read($user) && $user->may(Permissions::INVOICE_APPROVE);
    }

    public static function pay(?User $user): bool
    {
        return self::read($user) && $user->may(Permissions::INVOICE_PAY);
    }
}
