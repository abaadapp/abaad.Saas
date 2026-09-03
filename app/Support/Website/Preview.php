<?php

namespace App\Support\Website;

use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Website;
use App\Support\Demo;

/**
 * المستند الجاهز للعرض: اللقطة، ومعها ما تقرؤه أقسامُها من النظام.
 *
 * القسم يحمل «اعرض ثمانية من أحدث المنتجات» لا المنتجاتِ نفسها — وهذا مقصود:
 * الكتالوج مصدرُه واحد، هو جدول المنتجات. ولو جُمّدت المنتجات في اللقطة لبقي
 * سعرُ الأمس في الموقع بعد أن غيّره التاجر اليوم، ولصار في النظام كتالوجان.
 *
 * فهنا يُوصَل الوصفُ بمصدره: يُقرأ القسم، ويُجلب ما يعرضه، ويُلحق به. وهذا
 * المستند نفسه هو ما تعرضه المعاينة وما سيقرؤه العارض الخارجيّ — صيغةٌ واحدة
 * لا تفترق نسختاها.
 *
 * والجلب مجموعٌ لا لكلّ قسم: صفحةٌ فيها أربعة أقسام منتجات كانت أربعة
 * استعلامات، فصارت واحدًا يُوزَّع.
 */
class Preview
{
    /** أكثر ما يُجلب لقسمٍ واحد — حدُّ وصف الحقل يحرسه أيضًا */
    public const MAX = 24;

    /**
     * الموقع كما يُعرض — لقطةً ومحتوى.
     *
     * @return array<string, mixed>
     */
    public static function document(Website $website): array
    {
        $snapshot = Publisher::snapshot($website);

        return self::resolve($snapshot, $website->business_id);
    }

    /**
     * ويعمل على أيّ لقطة — الحيّة أو المنشورة أو نسخةٍ قديمة.
     *
     * فمعاينةُ نسخةٍ سابقة قبل استعادتها تعرض منتجاتِ اليوم في تصميم الأمس،
     * وهو الصواب: النسخة تحفظ التصميم لا البضاعة.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function resolve(array $snapshot, int $businessId): array
    {
        $needs = self::needs($snapshot);
        $bag = [
            'products' => $needs['products'] ? self::products($businessId, $needs['products']) : [],
            'categories' => $needs['categories'] ? self::categories($businessId, $needs['categories']) : [],
            'reviews' => $needs['reviews'] ? self::reviews($businessId, $needs['reviews']) : [],
            'best' => $needs['best'] ? self::bestSellers($businessId, $needs['best'], $needs['best_days']) : [],
        ];

        foreach ($snapshot['pages'] as &$page) {
            foreach ($page['sections'] as &$section) {
                $section['items'] = self::items($section, $bag);
                $section['data'] = Media::absolute($section['type'], $section['data'] ?? []);
            }
            unset($section);
        }
        unset($page);

        foreach ($snapshot['globals'] as &$slot) {
            $slot['data'] = Media::absolute($slot['type'], $slot['data'] ?? []);
        }
        unset($slot);

        $snapshot['data'] = $bag;

        /*
         * وثلاثةٌ تُقرأ عند العرض لا عند النشر — عمدًا.
         *
         * الشعار والهاتف والعملة ليست تصميمًا يُجمَّد، بل حالُ النشاط الآن.
         * التاجر الذي يبدّل شعاره أو عملته لا يخطر له أنّ عليه أن ينشر
         * موقعه من جديد ليظهر التبديل — ولو جُمّدت لبقي شعارُ الأمس في
         * موقعٍ نُشر قبل سنة. وهي أيضًا تصل بها النسخُ المنشورة قديمًا،
         * إذ تُحقن على اللقطة أيًّا كان تاريخُها.
         */
        $locale = self::locale($businessId);

        $snapshot['brand'] = self::brand($businessId, $snapshot, $locale);
        $snapshot['currency'] = Demo::currencyFor($businessId);
        $snapshot['locale'] = $locale;
        $snapshot['dir'] = $locale === 'en' ? 'ltr' : 'rtl';

        return $snapshot;
    }

    /**
     * لغة الموقع — لغةُ النشاط، لا لغةُ من يقرأ.
     *
     * زائرُ متجرٍ في نطاقه ليس مستخدمًا في أبعاد ولا لغةَ له تُقرأ منها، وما
     * يُقرأ بلا هذا هو لغةُ التطبيق الافتراضية (`APP_LOCALE=en`) — فيخرج
     * متجرٌ عربيٌّ كتب التاجر كلّ محتواه بالعربية، وطرقُ دفعه فيه «Bank
     * Transfer» وشبكاتُه «Instagram» في صفحةٍ من اليمين إلى اليسار.
     *
     * وإعدادُ لغة النشاط موجودٌ منذ `SetLocale` — فيُقرأ من مكانه.
     */
    private static function locale(int $businessId): string
    {
        $locale = \App\Models\Setting::where('business_id', $businessId)
            ->where('key', 'locale')->value('value');

        return in_array($locale, \App\Http\Middleware\SetLocale::SUPPORTED, true) ? $locale : 'ar';
    }

    /**
     * هويّة النشاط كما يعرضها الموقع — شعارًا وتواصلًا وحساباتٍ ودفعًا.
     *
     * الترويسة والتذييل فيهما «إظهار الشعار» و«معلومات التواصل» و«حسابات
     * التواصل» و«طرق الدفع» مفاتيحَ بلا بيانات: البيانات ليست فيهما، وهي
     * في مواضعها من النظام. فتُقرأ من هناك وتُوضع في المستند مرّةً — لا
     * أن يُسأل عنها التاجر ثانيةً في كلّ قسم.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function brand(int $businessId, array $snapshot, ?string $locale = null): array
    {
        $locale ??= self::locale($businessId);
        $identity = MerchantData::identity($businessId);

        return [
            'name' => $snapshot['name'] ?? $identity['name'],
            'logo' => Media::url($identity['logo']),
            'tagline' => $identity['tagline'],
            'phone' => $identity['phone'],
            'email' => $identity['email'],
            'address' => $identity['address'],
            'whatsapp' => $identity['whatsapp'],
            'social' => self::social($snapshot, $identity, $locale),
            'payments' => self::payments($businessId, $locale),
        ];
    }

    /**
     * حسابات التواصل — ما أضافه التاجر في أقسامه، وإنستغرامُ إعداداته.
     *
     * ولا حقلَ جديد يُسأل عنه: من كتب حساباته في قسم «تواصل اجتماعي» كتبها
     * مرّةً، فيقرؤها التذييلُ منها.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $identity
     * @return array<int, array<string, string>>
     */
    private static function social(array $snapshot, array $identity, string $locale): array
    {
        $out = [];

        foreach ($snapshot['pages'] ?? [] as $page) {
            foreach ($page['sections'] ?? [] as $section) {
                if (($section['type'] ?? '') !== 'social' || ($section['visible'] ?? true) === false) {
                    continue;
                }

                foreach ($section['data']['accounts'] ?? [] as $account) {
                    $out[(string) ($account['network'] ?? '')] = (string) ($account['value'] ?? '');
                }
            }
        }

        if ($identity['instagram'] !== '' && ! isset($out['instagram'])) {
            $out['instagram'] = $identity['instagram'];
        }

        return collect($out)
            ->filter(fn ($value, $network) => $value !== '' && isset(Sections::NETWORKS[$network]))
            ->map(fn ($value, $network) => [
                'network' => $network,
                'value' => $value,
                // الرابط يُبنى هنا لا في العارض: قاعدةُ كلّ شبكةٍ في موضعٍ واحد
                'url' => Sections::NETWORKS[$network]['base'].$value,
                // بلغة الموقع لا بلغة الطلب — انظر `locale` أعلاه
                'label' => trans(Sections::NETWORKS[$network]['label'], [], $locale),
            ])->values()->all();
    }

    /**
     * طرق الدفع المفعّلة — من إعدادات نقطة البيع نفسها.
     *
     * ولا مصدر ثانٍ لها: تاجرٌ يُطفئ «تحويل بنكي» في نقطة البيع ويبقى شعارُه
     * في تذييل موقعه هو بالضبط ما يجعل زبونًا يحوّل ثم لا يُقبَل تحويلُه.
     *
     * @return array<int, string>
     */
    private static function payments(int $businessId, string $locale): array
    {
        $settings = \App\Models\Setting::where('business_id', $businessId)->pluck('value', 'key')->all();

        return array_values(array_map(
            fn ($label) => trans($label, [], $locale),
            \App\Http\Controllers\Pos\PosController::enabledPaymentMethods($settings),
        ));
    }

    /**
     * ماذا تحتاج هذه اللقطة، وكم؟ — مرّةً واحدة لكلّ مصدر.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, int>
     */
    private static function needs(array $snapshot): array
    {
        $needs = ['products' => 0, 'categories' => 0, 'reviews' => 0, 'best' => 0, 'best_days' => 90];

        foreach ($snapshot['pages'] ?? [] as $page) {
            foreach ($page['sections'] ?? [] as $section) {
                if (($section['visible'] ?? true) === false) {
                    continue;
                }

                $limit = min(self::MAX, max(1, (int) ($section['data']['limit'] ?? 8)));

                match ($section['type']) {
                    'featured_products', 'latest_products' => $needs['products'] = max($needs['products'], $limit + count($section['data']['product_ids'] ?? [])),
                    'best_sellers' => [
                        $needs['best'] = max($needs['best'], $limit),
                        $needs['best_days'] = max($needs['best_days'], (int) ($section['data']['days'] ?? 90)),
                    ],
                    'categories' => $needs['categories'] = max($needs['categories'], $limit),
                    'testimonials' => $needs['reviews'] = max($needs['reviews'], $limit),
                    default => null,
                };
            }
        }

        return $needs;
    }

    /**
     * ما يعرضه قسمٌ بعينه من الحقيبة.
     *
     * @param  array<string, mixed>  $section
     * @param  array<string, array<int, array<string, mixed>>>  $bag
     * @return array<int, array<string, mixed>>|null
     */
    private static function items(array $section, array $bag): ?array
    {
        $limit = min(self::MAX, max(1, (int) ($section['data']['limit'] ?? 8)));

        return match ($section['type']) {
            /*
             * «منتجات مختارة» بلا اختيار تعرض الأحدث.
             *
             * التاجر يضيف القسم ولا يفتح مُنتقي المنتجات، فيبقى فارغًا في
             * موقعه ولا يعرف لماذا. والافتراضُ المعقول أفضل من فراغٍ يُلام
             * عليه المستخدم.
             */
            'featured_products' => self::pick($bag['products'], $section['data']['product_ids'] ?? [], $limit),
            'latest_products' => array_slice($bag['products'], 0, $limit),
            'best_sellers' => array_slice($bag['best'], 0, $limit),
            'categories' => array_slice($bag['categories'], 0, $limit),
            // والحدّ يُطبَّق بعد الترشيح لا قبله: «اعرض ستّة» تعني ستّةً ممّا نجا
            'testimonials' => array_slice(array_values(array_filter(
                $bag['reviews'],
                fn ($r) => $r['rating'] >= (int) ($section['data']['min_rating'] ?? 4),
            )), 0, $limit),
            default => null,
        };
    }

    /** @param array<int, array<string, mixed>> $products */
    private static function pick(array $products, array $ids, int $limit): array
    {
        if ($ids === []) {
            return array_slice($products, 0, $limit);
        }

        $byId = collect($products)->keyBy('id');

        return collect($ids)->map(fn ($id) => $byId[$id] ?? null)
            ->filter()->take($limit)->values()->all();
    }

    private static function products(int $businessId, int $limit): array
    {
        return Product::where('business_id', $businessId)->where('active', true)
            ->orderByDesc('id')->limit(max($limit, self::MAX))
            ->get(['id', 'name', 'description', 'price', 'discount', 'image', 'category_id'])
            ->map(fn ($p) => self::product($p))->all();
    }

    private static function bestSellers(int $businessId, int $limit, int $days): array
    {
        $ids = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.business_id', $businessId)
            ->where('orders.ordered_at', '>=', now()->subDays(max(7, $days)))
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->orderByRaw('SUM(order_items.quantity) DESC')
            ->limit($limit)->pluck('order_items.product_id');

        if ($ids->isEmpty()) {
            return [];
        }

        $products = Product::where('business_id', $businessId)->where('active', true)
            ->whereIn('id', $ids)->get(['id', 'name', 'description', 'price', 'discount', 'image', 'category_id'])
            ->keyBy('id');

        // ترتيبُ المبيعات لا ترتيبُ المعرّفات: `whereIn` لا تحفظ ترتيب القائمة
        return $ids->map(fn ($id) => isset($products[$id]) ? self::product($products[$id]) : null)
            ->filter()->values()->all();
    }

    private static function product(Product $p): array
    {
        $price = round((float) $p->price, 3);
        $discount = round((float) $p->discount, 2);

        return [
            'id' => $p->id,
            'name' => $p->name,
            'excerpt' => mb_substr(trim((string) $p->description), 0, 120),
            'price' => $price,
            // السعر بعد الخصم يُحسب هنا لا في العارض: حسابُ مالٍ في موضعٍ واحد
            'was' => $discount > 0 ? $price : null,
            'final' => $discount > 0 ? round($price * (1 - $discount / 100), 3) : $price,
            'image' => self::image($p),
            'category_id' => $p->category_id,
        ];
    }

    /**
     * صورة المنتج — أو لا صورة.
     *
     * `Product::getImageAttribute` تردّ صورةً من picsum.photos حين لا صورة،
     * وهي في اللوحة علامةُ فراغٍ يفهمها التاجر. لكنّها في موقعه المنشور صورةُ
     * غريبٍ تُعرض على أنّها منتجُه — وزبونٌ يطلب ما في الصورة لا يجد شبيهًا
     * له في المحلّ. فالفراغ هنا يُقال فراغًا، والعارض يرسم مكانًا محايدًا.
     *
     * وهذا علاجٌ في مخرج الموقع لا في مصدره: الأصل ألّا يُكتب في العمود رابطٌ
     * وهميّ يوم يُضاف منتجٌ بلا صورة.
     */
    private static function image(Product $p): ?string
    {
        $raw = (string) $p->getRawOriginal('image');

        return str_contains($raw, 'picsum.photos') ? null : Media::url($raw);
    }

    private static function categories(int $businessId, int $limit): array
    {
        return Category::where('business_id', $businessId)->orderBy('name')
            ->limit(max($limit, self::MAX))->get(['id', 'name', 'icon', 'color'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'icon' => $c->icon,
                'color' => $c->color,
            ])->all();
    }

    private static function reviews(int $businessId, int $limit): array
    {
        return Review::where('business_id', $businessId)->where('status', 'منشور')
            ->whereNotNull('comment')->orderByDesc('id')
            ->limit(max($limit, self::MAX))->get(['author_name', 'rating', 'comment'])
            ->map(fn ($r) => [
                'author' => $r->author_name ?: 'عميل',
                'rating' => (int) $r->rating,
                'comment' => mb_substr((string) $r->comment, 0, 300),
            ])->all();
    }
}
