<?php

namespace App\Support\Store;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * قائمةُ أصنافٍ يختارها صاحبُ المتجر بيده ويرتّبها — قراءتُها وحفظُها.
 *
 * ═══ لمن ═══
 *
 * «اختيارات RIBBON» (`RibbonPicks`) و«أضف مع طلبك» (`RibbonUpsells`).
 * وهي قاعدةُ «وصل حديثًا» اليدويّ نفسُها (`NewArrivals`) — مكتوبةٌ هنا مرّةً
 * لقائمتين، ولم تُنقل إليها «وصل حديثًا»: تلك تعمل ولا تُمسّ.
 *
 * ═══ والقاعدتان ═══
 *
 * الحفظُ صارم: رقمٌ موجبٌ في كلّ خانة، وكلُّ معرّفٍ صنفٌ **من هذا المتجر**
 * مفعّلٌ منشورٌ الآن، وإلى الحدّ لا أكثر. والمكرَّرُ يُطوى إلى أوّل موضعه.
 *
 * والقراءةُ متسامحة: ما اختاره بترتيبه **ممّا تعرضه الواجهة الآن**. فصنفٌ
 * أُخفي بعد اختياره يسقط وحده، ولا يُحشى مكانَه صنفٌ لم يختره.
 *
 * ولا يُسأل قسمٌ من الأصناف: الاختيارُ عرضٌ لا تصنيف، والصنفُ يبقى في قسمه.
 */
final class ProductList
{
    /**
     * ما اختاره بترتيبه — من `$shown` وحدها.
     *
     * @param  Collection<int, Product>  $shown  المعروضُ من هذا المتجر
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    public static function pick(Collection $shown, array $ids): Collection
    {
        $byId = $shown->keyBy('id');

        return collect($ids)->map(fn (int $id) => $byId->get($id))->filter()->values();
    }

    /**
     * القائمةُ المرسَلة ← معرّفاتٌ صالحةٌ لهذا المتجر بفواصل — أو رفض.
     *
     * والفراغُ صالح: يُحفظ فارغًا ويعني «لا اختيار».
     *
     * @param  string  $key  اسمُ الحقل الذي يُردّ عليه الخطأ
     * @param  string  $label  اسمُ القائمة كما يقرؤه صاحبُها
     */
    public static function validated(int $businessId, string $key, mixed $raw, int $max, string $label): string
    {
        if (! is_scalar($raw) && $raw !== null) {
            throw ValidationException::withMessages([$key => __('قائمة «:label» غير صالحة — أعد اختيار المنتجات.', ['label' => __($label)])]);
        }

        $tokens = array_values(array_filter(array_map('trim', explode(',', (string) $raw)), fn ($t) => $t !== ''));

        foreach ($tokens as $token) {
            if (! preg_match('/^[1-9]\d{0,18}$/', $token)) {
                throw ValidationException::withMessages([$key => __('قائمة «:label» غير صالحة — أعد اختيار المنتجات.', ['label' => __($label)])]);
            }
        }

        $ids = array_values(array_unique(array_map('intval', $tokens)));

        if (count($ids) > $max) {
            throw ValidationException::withMessages([$key => __('اختر حتى :max منتجات فقط.', ['max' => $max])]);
        }

        if ($ids !== []) {
            $found = Product::where('business_id', $businessId)
                ->where('active', true)->where('published', true)
                ->whereIn('id', $ids)->count();

            if ($found !== count($ids)) {
                throw ValidationException::withMessages([
                    $key => __('منتجٌ في «:label» غير متاح — اختر من منتجاتك المفعّلة والمنشورة.', ['label' => __($label)]),
                ]);
            }
        }

        return implode(',', $ids);
    }
}
