<?php

namespace App\Support\Store;

use Illuminate\Validation\ValidationException;

/**
 * الزبونُ وافق على شروطٍ حُدّثت بعد أن فتح صفحتَه.
 *
 * ردُّ تحقّقٍ كسائر ردوده (فلا طلبَ ولا دفعة)، ومعه الشروطُ الجديدة بلغة
 * صفحته: تعرضها الصفحةُ مكانَ القديمة وتُفرغ خانةَ الموافقة، فيقرأ ويوافق
 * على ما سيُكتب في طلبه فعلًا. انظر `StoreTerms::check`.
 */
final class TermsChanged extends ValidationException
{
    /** @var array{id: int, version: int, text: string}|null */
    public ?array $terms = null;

    /**
     * @param  array{id: int, version: int, text: string}  $terms
     */
    public static function with(array $terms): self
    {
        $e = self::withMessages([
            StoreTerms::FIELD => __('تحدّثت الشروط والأحكام — اقرأها ووافق عليها من جديد.'),
        ]);
        $e->terms = $terms;

        return $e;
    }
}
