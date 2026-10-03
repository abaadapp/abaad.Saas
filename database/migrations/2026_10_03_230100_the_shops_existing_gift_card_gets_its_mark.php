<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * كرتُ الهدية القائم في متاجر القائمة يُعلَّم — إن عُرف بلا تخمين.
 *
 * ═══ لمَ لا يكفي الاسمُ الحرفيّ ═══
 *
 * هجرةُ 2026-10-03 (`..._200000_...`) فكّت ربطَ الصنف باسم «كرت هدية»
 * حرفًا بحرف — ولم تُصلح الخانةَ على الإنتاج. فالصنفُ هناك لا يحمل الاسمَ
 * الحرفيّ (أو له توأم). ولا قراءةَ للإنتاج من هنا، فلا يُدّعى أيُّهما.
 *
 * ═══ فما يُعلَّم ═══
 *
 * لكلّ متجرٍ في `storefront.ribbon_gift_card_product_businesses` وحده:
 *
 *   - المرشّحون: أصنافُه غيرُ المحذوفة التي اسمُها العربيّ — بعد التطبيع —
 *     «كرت/كارت/بطاقة» + «هدية/الهدية»، أو اسمُها (أيُّ الخانتين) بالإنجليزيّة
 *     «gift card». والتطبيعُ: الأطرافُ والمسافاتُ المكرّرة والتشكيلُ والتطويل،
 *     و«أإآ» ألفًا و«ة» هاءً و«ى» ياءً، والإنجليزيّةُ بحروفٍ صغيرة.
 *     أسماءٌ معدودةٌ بعينها — لا «يحتوي على».
 *   - مرشّحٌ واحد يُعلَّم. وإن كانوا أكثر: الواحدُ المعروضُ منهم (نشطٌ
 *     ومنشور) إن كان وحده. وإلّا لا يُعلَّم أحد — لا يُختار واحدٌ عشوائيًّا.
 *   - ومتجرٌ فيه كرتٌ معلَّمٌ لا يُمسّ.
 *
 * والمعلَّمُ يخرج من دفتر المخزون (`tracks_stock = false`) — شرطُ الكرت.
 * ولا ثمنَ ولا كميّةَ ولا نشرَ ولا اسمَ يُغيَّر.
 */
return new class extends Migration
{
    private const AR = ['كرت هديه', 'كرت الهديه', 'كارت هديه', 'كارت الهديه', 'بطاقه هديه', 'بطاقه الهديه'];

    private const EN = ['gift card', 'giftcard', 'gift-card'];

    public function up(): void
    {
        foreach (array_map('intval', (array) config('storefront.ribbon_gift_card_product_businesses', [])) as $bid) {
            $alive = DB::table('products')->where('business_id', $bid)->whereNull('deleted_at');

            if ((clone $alive)->where('is_gift_card', true)->exists()) {
                continue;
            }

            $candidates = (clone $alive)->get(['id', 'name', 'name_en', 'active', 'published'])
                ->filter(fn ($p) => in_array($this->ar((string) $p->name), self::AR, true)
                    || in_array($this->en((string) $p->name), self::EN, true)
                    || in_array($this->en((string) $p->name_en), self::EN, true))
                ->values();

            if ($candidates->count() > 1) {
                $candidates = $candidates->filter(fn ($p) => (bool) $p->active && (bool) $p->published)->values();
            }

            if ($candidates->count() !== 1) {
                continue;
            }

            DB::table('products')->where('id', $candidates->first()->id)
                ->update(['is_gift_card' => true, 'tracks_stock' => false, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // ‏لا رجوع: العلامةُ تبقى، وعمودُها تُسقطه الهجرةُ السابقة إن رجعت
    }

    private function ar(string $name): string
    {
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $name);
        $name = strtr($name, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);

        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    private function en(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }
};
