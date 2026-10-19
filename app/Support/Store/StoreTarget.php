<?php

namespace App\Support\Store;

use App\Models\Category;
use App\Models\Product;

/**
 * وجهةٌ داخل متجر RIBBON — ما تفتحه بطاقةُ الشريط ورابطُ القائمة وزرُّ الصفحة.
 *
 * ═══ ولمَ مصدرٌ واحد ═══
 *
 * ثلاثةُ مواضع تسأل السؤالَ نفسَه: «إلى أين يقود هذا؟». بطاقاتُ الشريط
 * المصوّر (`StoreVisualBar`)، وروابطُ القائمة الرئيسيّة (`StoreNav`)، وزرُّ
 * الصفحة المخصّصة (`StoreCustomPages`). ولو أجاب كلٌّ منها بنفسه لَقبل
 * أحدُها وجهةً يردّها الآخر، أو رسم رابطًا إلى مجموعةٍ أُخفيت.
 *
 * ═══ والوجهةُ نوعٌ مغلق وقيمةٌ من هذا المتجر ═══
 *
 * لا رابطَ حرٌّ يُكتب: صفحةٌ من صفحات المتجر، أو مجموعةٌ أو صفحةٌ مخصّصةٌ
 * باسمها المختصر، أو فئةٌ أو صنفٌ بمعرّفه. فلا رابطَ خارجيٌّ يُوزَّع من صفحةِ
 * تاجر، ولا `javascript:`، ولا معرّفَ لمتجرٍ آخر — الحفظُ يسأل القاعدةَ
 * عن ملكيّته، والقراءةُ تسأل ثانيةً: وجهةٌ زالت بعد الحفظ (مجموعةٌ أُخفيت،
 * صنفٌ أُوقف، صفحةٌ حُذفت) تُسقط ما يقود إليها — لا رابطَ إلى «غير موجود».
 */
final class StoreTarget
{
    /** ما يُفتح — والقيمةُ فارغةٌ للصفحات، واسمٌ مختصرٌ أو معرّفٌ لما سواها */
    public const TYPES = ['home', 'shop', 'best', 'about', 'contact', 'collection', 'category', 'product', 'page'];

    /** ما قيمتُه اسمٌ مختصر — `StoreCollections::SLUG` */
    private const BY_SLUG = ['collection', 'page'];

    /** وما قيمتُه معرّفٌ من القاعدة */
    private const BY_ID = ['category', 'product'];

    /* ═══════════ القراءة ═══════════ */

    /**
     * وجهةٌ مخزّنة ← نوعٌ وقيمة — أو `null` لما لا يُقرأ.
     *
     * والقراءةُ متسامحة كأخواتها: ما كُتب قبل قاعدةٍ لا يُسقط الصفحة.
     *
     * @return array{type: string, value: string|int}|null
     */
    public static function parse(mixed $target): ?array
    {
        if (! is_array($target)) {
            return null;
        }

        $type = is_string($target['type'] ?? null) ? $target['type'] : '';

        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        $value = $target['value'] ?? '';

        return ['type' => $type, 'value' => match (true) {
            in_array($type, self::BY_SLUG, true) => is_scalar($value) ? (string) $value : '',
            in_array($type, self::BY_ID, true) => is_numeric($value) ? (int) $value : 0,
            default => '',
        }];
    }

    /** أهما الوجهةُ نفسُها؟ — لتُضاء في القائمة الصفحةُ التي يقف عليها الزائر */
    public static function same(?array $a, ?array $b): bool
    {
        return $a !== null && $b !== null && $a['type'] === $b['type'] && (string) $a['value'] === (string) $b['value'];
    }

    /**
     * روابطُ الوجهات بترتيبها — `null` لما لا يُفتح الآن.
     *
     * والأصنافُ والفئاتُ تُسأل دفعةً واحدة لا سؤالًا لكلّ رابط. والفئةُ تُفتح
     * إن كان فيها صنفٌ معروض، والصنفُ إن كان معروضًا، والمجموعةُ والصفحةُ إن
     * كانتا ظاهرتين — وكلُّها من إعداد هذا المتجر وحده.
     *
     * @param  list<array{type: string, value: string|int}|null>  $targets
     * @return list<string|null>
     */
    public static function hrefs(int $businessId, string $base, string $lang, array $targets): array
    {
        $ids = fn (string $type) => array_values(array_unique(array_map(
            fn (array $t) => (int) $t['value'],
            array_filter($targets, fn ($t) => $t !== null && $t['type'] === $type && (int) $t['value'] > 0),
        )));

        $shown = Product::where('business_id', $businessId)->where('active', true)->where('published', true);
        $products = $ids('product') === [] ? [] : (clone $shown)->whereIn('id', $ids('product'))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
        $categories = $ids('category') === [] ? [] : Category::where('business_id', $businessId)
            ->whereIn('id', $ids('category'))
            ->whereIn('id', (clone $shown)->select('category_id'))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        $asks = fn (string $type) => array_filter($targets, fn ($t) => $t !== null && $t['type'] === $type) !== [];
        $collections = $asks('collection') ? array_column(StoreCollections::visible($businessId), 'slug') : [];
        $pages = $asks('page') ? array_column(StoreCustomPages::visible($businessId), 'slug') : [];

        return array_map(function (?array $t) use ($businessId, $base, $lang, $products, $categories, $collections, $pages) {
            if ($t === null) {
                return null;
            }

            $value = $t['value'];

            return match ($t['type']) {
                'home' => $base.'/',
                'shop' => $base.'/shop',
                'best' => $base.'/shop?view='.StoreHeader::VIEW_BEST,
                'about', 'contact' => StoreNav::has($businessId, $t['type'], $lang) ? $base.'/'.$t['type'] : null,
                'collection' => in_array($value, $collections, true) ? $base.'/shop?'.StoreCollections::QUERY.'='.$value : null,
                'page' => in_array($value, $pages, true) ? $base.'/'.StoreCustomPages::PATH.'/'.$value : null,
                'category' => in_array((int) $value, $categories, true) ? $base.'/shop?cat='.(int) $value : null,
                'product' => in_array((int) $value, $products, true) ? $base.'/p/'.(int) $value : null,
                default => null,
            };
        }, array_values($targets));
    }

    /* ═══════════ الحفظ ═══════════ */

    /**
     * وجهةٌ أرسلتها الشاشة ← وجهةٌ تُحفظ — أو سببُ الرفض.
     *
     * والسببُ كلمةٌ لا جملة: `type` لنوعٍ لا يُعرف، و`missing` لقيمةٍ ليست
     * في هذا المتجر. فلكلّ موضعٍ رسالتُه بلسانه («البطاقة :n»، «الرابط :n»)،
     * والقاعدةُ واحدة.
     *
     * @param  list<string>  $collections  أسماءُ المجموعات المختصرة التي يُقاس عليها — ما يُحفظ معها، وإلّا المسوّدة
     * @param  list<string>  $pages  وأسماءُ الصفحات المخصّصة كذلك
     * @param  list<string>  $types  الأنواعُ المقبولة في هذا الموضع
     * @return array{0: array{type: string, value: string|int}|null, 1: 'type'|'missing'|null}
     */
    public static function checked(int $businessId, mixed $target, array $collections, array $pages, array $types = self::TYPES): array
    {
        $type = is_array($target) && is_string($target['type'] ?? null) ? $target['type'] : '';
        $value = is_array($target) ? ($target['value'] ?? '') : '';

        if (! in_array($type, $types, true) || ! in_array($type, self::TYPES, true)) {
            return [null, 'type'];
        }

        $id = is_int($value) || (is_string($value) && preg_match('/^[1-9]\d{0,18}$/', $value)) ? (int) $value : 0;

        $ok = match ($type) {
            'collection' => is_string($value) && in_array($value, $collections, true),
            'page' => is_string($value) && in_array($value, $pages, true),
            'category' => $id > 0 && Category::where('business_id', $businessId)->whereKey($id)->exists(),
            'product' => $id > 0 && Product::where('business_id', $businessId)->whereKey($id)
                ->where('active', true)->where('published', true)->exists(),
            default => true,
        };

        if (! $ok) {
            return [null, 'missing'];
        }

        return [['type' => $type, 'value' => match (true) {
            in_array($type, self::BY_SLUG, true) => (string) $value,
            in_array($type, self::BY_ID, true) => $id,
            default => '',
        }], null];
    }
}
