<?php

namespace App\Support\Store;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * «وصل حديثًا» في واجهة RIBBON — تلقائيٌّ كما كان، أو يدويٌّ بيد صاحبه.
 *
 * ═══ القاعدتان ═══
 *
 * التلقائيّ: أحدثُ أربعةِ أصنافٍ معروضة بالمعرّف. وتعديلُ صنفٍ قديم لا
 * يرفعه — المعرّفُ لا يتبدّل.
 *
 * اليدويّ: ما اختاره بترتيبه، **ممّا تعرضه الواجهة الآن** (`$shown`). فصنفٌ
 * أُطفئ أو أُخفي بعد اختياره يسقط وحده ويبقى الباقي بترتيبه، ولا يُحشى
 * مكانَه صنفٌ لم يختره. فإن سقطت كلُّها عاد القسمُ تلقائيًّا: إعدادٌ قديمٌ
 * لا يمحو قسمًا قائمًا — وهي قاعدةُ «المختارات» في `RibbonController::home`.
 *
 * ═══ ولمن ═══
 *
 * لمن في `storefront.ribbon_curated_new_arrivals_businesses` وحده. ومن ليس
 * فيها يبقى على التلقائيّ ولو حُفظ له «يدويّ» بطريقٍ ما، ويُردّ حفظُه
 * للمفتاحين بـ403 قبل أن يُكتب شيء.
 *
 * والقراءةُ (`StorePage::newArrivalsMode` و`newArrivalIds`) غيرُ الإذن هنا.
 */
final class NewArrivals
{
    /** أيُختار «وصل حديثًا» باليد في هذا المتجر؟ — من القائمة وحدها */
    public static function allowed(int $businessId): bool
    {
        $list = array_map('intval', (array) config('storefront.ribbon_curated_new_arrivals_businesses', []));

        return in_array($businessId, $list, true);
    }

    /**
     * أحدثُ أربعةٍ بالمعرّف — القاعدةُ كما كانت في الواجهة حرفًا.
     *
     * @param  Collection<int, Product>  $shown
     * @return Collection<int, Product>
     */
    public static function automatic(Collection $shown): Collection
    {
        return $shown->sortByDesc('id')->take(StorePage::NEW_ARRIVALS)->values();
    }

    /**
     * ما يعرضه القسمُ فعلًا — من `$shown` وحدها، فلا يبلغه صنفُ متجرٍ آخر
     * ولا صنفٌ مطفأٌ أو مخفيّ.
     *
     * @param  Collection<int, Product>  $shown  المعروضُ من هذا المتجر
     * @return Collection<int, Product>
     */
    public static function pick(int $businessId, Collection $shown): Collection
    {
        $automatic = self::automatic($shown);

        if (! self::allowed($businessId) || StorePage::newArrivalsMode($businessId) !== StorePage::NEW_MANUAL) {
            return $automatic;
        }

        $byId = $shown->keyBy('id');
        $chosen = collect(StorePage::newArrivalIds($businessId))
            ->map(fn (int $id) => $byId->get($id))
            ->filter()
            ->values();

        return $chosen->isNotEmpty() ? $chosen : $automatic;
    }

    /**
     * المفتاحان كما يُحفظان — أو رفضٌ يُقال.
     *
     * يُسأل متى أرسل الحفظُ أحدَهما. والإذنُ أوّلًا: من ليس في القائمة يُردّ
     * بـ403 ولو أُخفيت عنه الشاشة — فالشاشةُ لا تحرس بابًا.
     *
     * والقائمةُ تُقرأ بصرامةٍ لا بتسامح القراءة: رقمٌ موجبٌ في كلّ خانة، وكلُّ
     * معرّفٍ صنفٌ **من هذا المتجر** مفعّلٌ منشورٌ الآن، وأربعةٌ لا أكثر.
     * والمكرَّرُ يُطوى إلى أوّل موضعه كما تُطوى المختارات. ويُحفظ مرتّبًا
     * بفواصل (`181,175,203`).
     *
     * وما لم يُرسَل لا يُكتب: حفظُ الطريقة وحدها لا يمسّ القائمة.
     *
     * @param  array<string, string>  $current  ما هو محفوظٌ الآن (المسوّدة إن كانت)
     * @return array<string, string>
     */
    public static function validated(int $businessId, Request $request, array $current): array
    {
        abort_unless(self::allowed($businessId), 403);

        $out = [];

        if ($request->exists('store_new_arrivals_mode')) {
            $mode = (string) $request->input('store_new_arrivals_mode');

            if (! in_array($mode, [StorePage::NEW_AUTO, StorePage::NEW_MANUAL], true)) {
                throw ValidationException::withMessages([
                    'store_new_arrivals_mode' => __('اختر طريقة العرض: تلقائي أو اختيار يدوي.'),
                ]);
            }

            $out['store_new_arrivals_mode'] = $mode;
        }

        $mode = $out['store_new_arrivals_mode'] ?? StorePage::mode($current['store_new_arrivals_mode'] ?? '');

        if ($request->exists('store_new_arrivals')) {
            $ids = self::strictIds((string) $request->input('store_new_arrivals'));
            self::assertShown($businessId, $ids);
            $out['store_new_arrivals'] = implode(',', $ids);
        } else {
            $ids = StorePage::ids($current['store_new_arrivals'] ?? '', StorePage::NEW_ARRIVALS);
        }

        if ($mode === StorePage::NEW_MANUAL && $ids === []) {
            throw ValidationException::withMessages([
                'store_new_arrivals' => __('اختر منتجًا واحدًا على الأقل أو ارجع إلى الترتيب التلقائي.'),
            ]);
        }

        return $out;
    }

    /**
     * القائمةُ المرسَلة ← معرّفاتٌ صالحةٌ لهذا المتجر — أو رفض.
     *
     * @return list<int>
     */
    private static function strictIds(string $raw): array
    {
        $tokens = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($t) => $t !== ''));

        foreach ($tokens as $token) {
            if (! preg_match('/^[1-9]\d{0,18}$/', $token)) {
                throw ValidationException::withMessages([
                    'store_new_arrivals' => __('قائمة «وصل حديثًا» غير صالحة — أعد اختيار المنتجات.'),
                ]);
            }
        }

        $ids = array_values(array_unique(array_map('intval', $tokens)));

        if (count($ids) > StorePage::NEW_ARRIVALS) {
            throw ValidationException::withMessages([
                'store_new_arrivals' => __('اختر حتى :max منتجات فقط.', ['max' => StorePage::NEW_ARRIVALS]),
            ]);
        }

        return $ids;
    }

    /**
     * أكلُّها أصنافُ هذا المتجر المعروضة الآن؟
     *
     * @param  list<int>  $ids
     */
    private static function assertShown(int $businessId, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $found = Product::where('business_id', $businessId)
            ->where('active', true)->where('published', true)
            ->whereIn('id', $ids)->count();

        if ($found !== count($ids)) {
            throw ValidationException::withMessages([
                'store_new_arrivals' => __('منتجٌ في «وصل حديثًا» غير متاح — اختر من منتجاتك المفعّلة والمنشورة.'),
            ]);
        }
    }
}
