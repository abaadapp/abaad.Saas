<?php

namespace App\Support;

use App\Models\Business;
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

    /**
     * من يفتح «المنتجات» وحدها — كصاحب بوتيكٍ أُعطي حسابًا ليرتّب قطعه.
     *
     * لا مبيعاتٍ ولا مخزونًا ولا مصروفاتٍ ولا ماليّةً ولا تقارير: فليس له بابٌ يقرأ
     * منه كم بيع. ومن يملك واحدًا منها يقرأه من بابه أصلًا، فلا يتغيّر عليه شيء.
     */
    public static function productsOnly(?User $user): bool
    {
        return $user !== null && ! collect(['orders', 'inventory', 'expenses', 'finance', 'reports'])
            ->contains(fn (string $section) => $user->allows($section));
    }

    /** أيُحجب ما بيع من صنفٍ عن هذا الجالس؟ — في متجرٍ يؤوي بوتيكاتٍ وحده */
    public static function hidesSales(?User $user, ?Business $business): bool
    {
        return self::productsOnly($user) && Boutiques::holds($business);
    }

    /**
     * قطعُ البوتيك في صفحة الطلب كما تُرسَل: يُسأل عن حالها من يلغي، وثمنُها
     * (وهو مستحقُّها) لمن يقرأ المال وحده — والشاشةُ لا ترسمه أصلًا.
     *
     * @param  list<array<string, mixed>>  $pieces
     * @return list<array<string, mixed>>
     */
    public static function pieces(array $pieces, ?User $user): array
    {
        return self::read($user) ? $pieces : array_map(fn (array $p) => array_diff_key($p, ['unit_price' => 1]), $pieces);
    }
}
