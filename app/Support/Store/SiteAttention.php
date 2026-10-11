<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Models\Category;
use App\Models\CustomerPaymentLink;
use App\Models\Product;
use App\Models\User;

/**
 * «ما يحتاج انتباهك في موقعك» — ما ينقص الآن، وزرُّ إصلاحه في مكانه.
 *
 * ═══ ولمَ قائمةٌ لا قسمُ تحكّمٍ ثانٍ ═══
 *
 * كلُّ ما يُصلح هنا له بابٌ قائم: صورةُ المنتج في صفحته، وصورةُ بطاقة الفئة
 * في المحرّر، والاسمُ الإنجليزيّ في صفّه، ومفتاحُ Paymob في إعداداته. وقسمٌ
 * يُكرّرها يصنع لكلّ حقلٍ موضعين يكتب أحدُهما فوق الآخر. فهذه قراءةٌ لا
 * كتابة: تقول ما ينقص وأين يُصلح، ثمّ يغيب البندُ وحده متى أُصلح.
 *
 * ═══ وجاهزيّةُ الإطلاق شيءٌ آخر ═══
 *
 * `StoreReadiness` تسأل «أيفتح المتجر؟» — عنوانٌ وصنفٌ ودفعٌ ونشر — ويتجاوزها
 * المتجرُ يومَ يفتح. وهذه تسأل كلَّ صباح: منتجٌ منشورٌ بلا صورة يراه الزبونُ
 * بصورةٍ بديلة، وفئةٌ ببطاقةٍ مخطّطة، وصفحةٌ إنجليزيّةٌ تقول بالعربيّة.
 *
 * ═══ والبندُ لمن يملك إصلاحَه ═══
 *
 * بندٌ يقود إلى بابٍ مغلقٍ عليه يُقرأ عطبًا. فبنودُ المنتجات والفئات لمن
 * يملك «المنتجات»، وروابطُ الدفع لمن يملك «المالية»، وإعدادُ Paymob لمن يملك
 * «التكاملات».
 */
final class SiteAttention
{
    /**
     * كم اسمًا يُذكر في البند — وما زاد يُعدّ.
     *
     * والعددُ بعد نقطتين لا قبل الاسم: «منتجات منشورة بلا صورة: ٣» تُقرأ
     * صحيحةً بأيّ عدد، و`trans_choice` بصيغتين تقع في العربيّة على الأولى
     * لكلّ عددٍ فوق اثنين — فللعربيّة ستُّ صيغ.
     */
    public const NAMES = 5;

    /**
     * @return list<array{key: string, title: string, why: string, items: list<array{name: string, href: ?string}>, more: int, action: ?array{label: string, href: string}}>
     */
    public static function for(Business $business, ?User $user): array
    {
        $bid = (int) $business->id;
        $ribbon = $business->storefrontTheme() === 'ribbon';
        $products = (bool) $user?->allows('products');
        $out = [];

        if ($products) {
            $out[] = self::photos($bid);

            if ($ribbon) {
                $out[] = self::cardImages($bid);
                $out[] = self::english($bid);
            }
        }

        $out[] = self::paymentLinks($bid, $user);

        return array_values(array_filter($out));
    }

    /** منتجٌ منشورٌ بلا صورته — والزبونُ يرى صورةً بديلة (`ProductImages::hasRealMain`) */
    private static function photos(int $bid): ?array
    {
        $bare = self::shown($bid)
            ->where(fn ($q) => $q->whereNull('image')->orWhere('image', ''))
            ->orderBy('name');

        $total = (clone $bare)->count();

        if ($total === 0) {
            return null;
        }

        return self::item(
            'photos',
            __('منتجات منشورة بلا صورة: :n', ['n' => $total]),
            __('الزبون يرى صورة بديلة بدل منتجك.'),
            $bare->take(self::NAMES)->get(['id', 'name'])
                ->map(fn ($p) => ['name' => (string) $p->name, 'href' => route('admin.products.edit', $p->id)])->all(),
            $total,
            null,
        );
    }

    /**
     * فئةٌ ببطاقةٍ ظاهرةٍ بلا صورة — والبطاقةُ خطوطٌ ملوّنة.
     *
     * وبقاعدة الرئيسيّة نفسِها (`RibbonController::home`): فيها صنفٌ معروض،
     * وبطاقتُها غيرُ مخفيّة، والقسمُ نفسُه مرفوعٌ في ترتيب الصفحة.
     */
    private static function cardImages(int $bid): ?array
    {
        if (! in_array('cats', StorePage::order($bid), true)) {
            return null;
        }

        $bare = Category::where('business_id', $bid)
            ->where('card_hidden', false)
            ->where(fn ($q) => $q->whereNull('card_image')->orWhere('card_image', ''))
            ->whereIn('id', self::shown($bid)->whereNotNull('category_id')->select('category_id'))
            ->inStoreOrder()
            ->pluck('name');

        if ($bare->isEmpty()) {
            return null;
        }

        return self::item(
            'card_images',
            __('فئات تظهر بلا صورة بطاقة: :n', ['n' => $bare->count()]),
            __('بطاقتها في «تسوّق حسب الفئة» خطوطٌ ملوّنة بدل صورة.'),
            $bare->take(self::NAMES)->map(fn ($n) => ['name' => (string) $n, 'href' => null])->values()->all(),
            $bare->count(),
            ['label' => __('تسوّق حسب الفئة'), 'href' => route('admin.website.editor', ['row' => 'cats'])],
        );
    }

    /**
     * منتجٌ منشورٌ أو فئةٌ ظاهرةٌ بلا اسمٍ إنجليزيّ — والصفحةُ الإنجليزيّة
     * تعرضه بالعربيّة وسطَ واجهةٍ إنجليزيّة.
     */
    private static function english(int $bid): ?array
    {
        $products = self::shown($bid)
            ->where(fn ($q) => $q->whereNull('name_en')->orWhere('name_en', ''))
            ->orderBy('name');
        $categories = Category::where('business_id', $bid)
            ->where(fn ($q) => $q->whereNull('name_en')->orWhere('name_en', ''))
            ->whereIn('id', self::shown($bid)->whereNotNull('category_id')->select('category_id'))
            ->inStoreOrder();

        $total = (clone $products)->count() + (clone $categories)->count();

        if ($total === 0) {
            return null;
        }

        $items = $categories->take(self::NAMES)->get(['id', 'name'])
            ->map(fn ($c) => ['name' => (string) $c->name, 'href' => route('admin.website.editor', ['row' => 'cats'])])
            ->concat($products->take(self::NAMES)->get(['id', 'name'])
                ->map(fn ($p) => ['name' => (string) $p->name, 'href' => route('admin.products.edit', $p->id)]))
            ->take(self::NAMES)->values()->all();

        return self::item(
            'english',
            __('أسماء بلا ترجمة إنجليزية: :n', ['n' => $total]),
            __('تظهر بالعربي في صفحتك الإنجليزية.'),
            $items,
            $total,
            null,
        );
    }

    /**
     * روابطُ الدفع — مفعّلةٌ أم ينقصها مفتاح، وما رُدّ منها أو يحتاج مراجعة.
     *
     * ومن لا Paymob عنده لا يُسأل عنها: دفعُ الموقع نفسُه غيرُ مفعّل، وروابطُ
     * الدفع لا تُطلب من متجرٍ لا بوّابةَ له.
     */
    private static function paymentLinks(int $bid, ?User $user): ?array
    {
        $gateway = PaymobSettings::row($bid);

        if ($gateway === null || ! $gateway->ready()) {
            return null;
        }

        if (! $gateway->linksReady()) {
            if (! $user?->allows('integrations')) {
                return null;
            }

            return self::item(
                'links_off',
                __('روابط الدفع غير مفعّلة — ينقص مفتاح API أو البيئة'),
                __('لا تستطيع أن ترسل لعميلك رابطًا يدفع به.'),
                [],
                0,
                ['label' => __('إعدادات Paymob'), 'href' => route('admin.integrations.paymob')],
            );
        }

        if (! $user?->allows('finance')) {
            return null;
        }

        // المعلَّقُ الذي وصل عنه ما رُدّ، والمدفوعُ الذي جاءت عليه دفعةٌ ثانية أو استرجاع
        $stuck = CustomerPaymentLink::where('business_id', $bid)
            ->where('status', CustomerPaymentLink::PENDING)->whereNotNull('error')->count();
        $review = CustomerPaymentLink::where('business_id', $bid)
            ->where('status', CustomerPaymentLink::PAID)->whereNotNull('meta')->get(['meta'])
            ->filter(fn ($l) => ! empty(($l->meta ?? [])['extra_transactions']) || isset(($l->meta ?? [])['provider_refund']))
            ->count();

        if ($stuck + $review === 0) {
            return null;
        }

        return self::item(
            'links_trouble',
            __('روابط دفع تحتاج نظرة: :n', ['n' => $stuck + $review]),
            __('دفعةٌ رُدّت أو استُرجعت أو وصلت مرّتين — والسببُ مكتوبٌ تحت كلّ رابط.'),
            [],
            0,
            ['label' => __('روابط الدفع'), 'href' => route('admin.finance.paymentLinks')],
        );
    }

    /** ما يراه الزبون — قاعدةُ `RibbonController::shown` نفسُها */
    private static function shown(int $bid)
    {
        return Product::where('business_id', $bid)->where('active', true)->where('published', true);
    }

    private static function item(string $key, string $title, string $why, array $items, int $total, ?array $action): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'why' => $why,
            'items' => $items,
            'more' => max(0, $total - count($items)),
            'action' => $action,
        ];
    }
}
