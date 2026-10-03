<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * كرتُ هديةٍ أُنشئ قبل أن يصير الكرتُ صنفًا يخرج من دفتر المخزون.
 *
 * ═══ ما وقع ═══
 *
 * كرتُ متجر سعود صار صنفًا من رفّه في 2026-10-02
 * (`storefront.ribbon_gift_card_product_businesses`)، ومن يومها لا يُحفظ صنفٌ
 * بهذا الاسم مرتبطًا بالمخزون (`GiftCardProduct::forSave`). أمّا ما أُنشئ قبل
 * ذلك من «المنتجات» فصنفٌ عاديٌّ بافتراض الشاشة: `tracks_stock = true`. فيبقى
 * باسم الكرت ونشطًا ومنشورًا وعلى الرفّ (برصيده) — ويُعرض في «أضف مع طلبك» —
 * لكنّ `GiftCardProduct::is` تقول «ليس كرتًا» لأنّه مرتبط. فلا `data-gift-card`
 * على بطاقته، ولا تُفتح خانةُ رسالته، ويُردّ في الإتمام «كرت الهدية غير متاح».
 *
 * ═══ وما تفعله هذه ═══
 *
 * تفكّ ربطَه وحده — ولا تُرخي شرطًا في `is`:
 *
 *   - لمتاجر القائمة وحدها، لا لكلّ متجر.
 *   - للصنف باسم الكرت حرفًا بحرف (`GiftCard::PRODUCT_NAME`) — لا مطابقةَ
 *     فضفاضة، ولا اسمَ إنجليزيّ.
 *   - وحين يكون وحيدًا بهذا الاسم بين المعروض في متجره (نشطًا ومنشورًا) —
 *     القاعدةُ نفسُها التي تعدّ بها `is` التوأم. فتوأمان معروضان لا يُختار
 *     أحدُهما: يبقيان كما هما حتّى يُصلحهما صاحبُهما.
 *
 * والكميّةُ تُترك كما هي — كما يتركها نموذجُ المنتج حين يُفكّ الربط
 * (`ProductController::update`): لا حركةَ مخزونٍ تُكتب لرفٍّ لم يُمسّ. ولا
 * ثمنَ ولا اسمَ ولا نشرَ يُغيَّر.
 *
 * و`down` فارغةٌ عمدًا: إعادةُ ربطه إعادةٌ للعطب.
 */
return new class extends Migration
{
    public function up(): void
    {
        // الاسمُ مكتوبٌ هنا لا مقروءٌ من `GiftCard`: ما يجري على القاعدة مرّةً لا يتبدّل بعدها
        $name = 'كرت هدية';

        foreach (array_map('intval', (array) config('storefront.ribbon_gift_card_product_businesses', [])) as $bid) {
            $ids = DB::table('products')->where('business_id', $bid)->where('name', $name)
                ->where('active', true)->where('published', true)->whereNull('deleted_at')->pluck('id');

            if ($ids->count() !== 1) {
                continue;
            }

            DB::table('products')->where('id', $ids->first())->where('tracks_stock', true)
                ->update(['tracks_stock' => false, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // ‏لا رجوع: كرتٌ مرتبطٌ بالمخزون لا يُعرف كرتًا
    }
};
