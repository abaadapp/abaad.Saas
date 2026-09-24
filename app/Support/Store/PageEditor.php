<?php

namespace App\Support\Store;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Support\MarketingSettings;

/**
 * محرّرُ صفحة الواجهة الخاصّة — كلُّ حقلٍ في القسم الذي يظهر فيه.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * صاحبُ الواجهة الخاصّة كان يضبط صفحتَه من بطاقةٍ واحدة في الإعدادات فيها
 * نحوُ ثلاثين مقبضًا، مرتّبةً بترتيب ما أُضيف لا بترتيب ما يُرى:
 *
 *   - «العنوان الرئيسي» و«نبذة قصيرة» تحت عنوان «شكل الصفحة» ومعهما
 *     منتقي لونٍ **لا تقرؤه واجهتُه أصلًا**،
 *   - «صورة شريط المناسبات» في مجموعةٍ على بعد أربع شاشاتٍ من المقبض الذي
 *     يُشغّل الشريط نفسَه،
 *   - و«سطر التذييل» فوق قائمة الأقسام، وهو ليس قسمًا ولا يُرتَّب معها.
 *
 * فمن أراد أن يبدّل شيئًا في صفحته بحث عن مقبضه — ولم يكن له موضعٌ يُبحث
 * فيه. وسائرُ متاجر أبعاد ليست كذلك: لها محرّرٌ يعرض أقسامَ الصفحة بترتيبها،
 * ومن ضغط قسمًا رأى **حقولَه وحدها** (انظر `Website\EditorController`).
 *
 * ═══ ومصدرٌ واحد لا اثنان ═══
 *
 * هذا الملفّ يقول: ما صفوفُ الصفحة، وأيُّ مفتاحٍ لأيّ صفّ، وبأيّ شكلٍ
 * يُحرَّر. تقرؤه الشاشةُ فترسم، ويقرؤه `PageController` فيعرف ما **لم تعد**
 * بطاقةُ الإعدادات تملكه، ويقرؤه الحارس. وقائمةٌ ثانية في الواجهة كانت
 * ستفترق عن هذه عند أوّل حقلٍ يُضاف — فيُحرَّر الحقل في موضعين، أو في لا
 * موضع.
 */
final class PageEditor
{
    /** الواجهة: فوق الأقسام دائمًا، وليست قسمًا يُطفأ */
    public const HERO = 'hero';

    /** والتذييل: تحتها دائمًا، وفي كلّ صفحةٍ لا في الرئيسية وحدها */
    public const FOOT = 'foot';

    /**
     * مقابضُ الصفحة البسيطة — لا تُحرّك واجهةً خاصّة فلا تُعرض لصاحبها.
     *
     * `store_theme` لونُ `store.show.blade`، و`store_show_prices` مفتاحُها
     * ومفتاحُ الموقع المبنيّ. وواجهةُ RIBBON ألوانُها في قالبها وأسعارُها
     * مكتوبةٌ في كلّ بطاقة (انظر `_card.blade.php`) — لا تسأل عن أيٍّ منهما.
     *
     * ومقبضٌ يُعرض ولا يُدير شيئًا أسوأُ من غياب المقبض: يُقلَّب ويُحفظ
     * ويُنتظر أثرُه، ثمّ يُظنّ العطبُ في الصفحة.
     */
    public const DEAD = ['store_theme', 'store_show_prices'];

    /**
     * حقولُ كلّ صفٍّ بترتيب ظهورها فيه.
     *
     * و`kind` شكلُ المقبض لا نوعُ البيانات: `image` ترفع وتردّ رابطًا
     * (انظر `StoreImageField`)، و`featured` تنتقي أصنافًا، و`toggle` تُحفظ
     * `'1'`/`'0'`. و`gate` مفتاحٌ لا يُعرض الحقلُ إلّا إن رُفع.
     *
     * @var array<string, list<array{key: string, kind: string, label: string, hint?: string, dir?: string, gate?: string}>>
     */
    public const FIELDS = [
        self::HERO => [
            ['key' => 'store_headline', 'kind' => 'text', 'label' => 'العنوان الكبير', 'hint' => 'أوّلُ سطرٍ يقرؤه زبونك — واسمُ متجرك إن تركته فارغًا'],
            ['key' => 'store_hero_image', 'kind' => 'image', 'label' => 'صورة الواجهة', 'hint' => 'إلى جانب العنوان — وبلا اختيارك تُؤخذ من أوّل صنفٍ مبيعًا'],
        ],
        'cats' => [],
        'best' => [
            ['key' => 'store_featured', 'kind' => 'featured', 'label' => 'مختاراتنا', 'hint' => 'اختر حتى ٤ أصناف تتصدّر صفحتك — وبلا اختيارٍ يبقى «الأكثر مبيعًا» محسوبًا من بيعك'],
        ],
        'new' => [],
        'banner' => [
            ['key' => 'store_banner_image', 'kind' => 'image', 'label' => 'صورة الشريط', 'hint' => 'الشريطُ يَعِد بباقةٍ وكرتِ هدية — وبلا صورةٍ يبقى إلى جانب وعده مستطيلٌ مخطَّط'],
        ],
        'block' => [
            ['key' => 'store_block_on', 'kind' => 'toggle', 'label' => 'اكتب قسمك', 'hint' => 'لِما لا تقوله بضاعتك: اشتراكٌ شهريّ، تنسيقُ أعراس، توصيلٌ إلى ولايتك'],
            ['key' => 'store_block_title', 'kind' => 'text', 'label' => 'العنوان', 'gate' => 'store_block_on'],
            ['key' => 'store_block_text', 'kind' => 'textarea', 'label' => 'النصّ', 'hint' => 'أسطرُك تبقى أسطرًا كما تكتبها', 'gate' => 'store_block_on'],
            ['key' => 'store_block_image', 'kind' => 'image', 'label' => 'صورة القسم', 'hint' => 'اختيارية', 'gate' => 'store_block_on'],
            ['key' => 'store_block_cta', 'kind' => 'text', 'label' => 'نصّ الزرّ', 'hint' => 'اتركه فارغًا فلا زرّ', 'gate' => 'store_block_on'],
            ['key' => 'store_block_href', 'kind' => 'text', 'label' => 'وجهة الزرّ', 'hint' => 'رابطٌ كامل أو /shop', 'dir' => 'ltr', 'gate' => 'store_block_on'],
        ],
        'about' => [
            ['key' => 'store_about', 'kind' => 'textarea', 'label' => 'نبذتك', 'hint' => 'تُقرأ في هذا القسم وفي تذييل كلّ صفحة — وفي نتائج البحث'],
        ],
        'reviews' => [],
        self::FOOT => [
            ['key' => 'store_tagline', 'kind' => 'text', 'label' => 'سطر التذييل', 'hint' => 'أسفل كلّ صفحة — واتركه فارغًا فيبقى «FLOWERS · LOUNGE · AND MORE»'],
            ['key' => 'store_whatsapp', 'kind' => 'text', 'label' => 'واتساب', 'hint' => 'زرٌّ في التذييل يفتح محادثةً — ورقمُ متجرك إن تركته فارغًا', 'dir' => 'ltr'],
            ['key' => 'store_instagram', 'kind' => 'text', 'label' => 'إنستغرام', 'hint' => 'اسمُ الحساب بلا «@» ولا رابط', 'dir' => 'ltr'],
            ['key' => 'store_hours', 'kind' => 'text', 'label' => 'ساعات العمل', 'hint' => 'تُكتب في التذييل، ويقرؤها من يستلم من المحلّ'],
        ],
    ];

    /**
     * الصفوفُ ونصوصُها — ما هو الصفّ، وماذا يعرض، وأين يُكتب محتواه.
     *
     * و`fixed` ما ليس قسمًا يُطفأ: الواجهةُ والتذييلُ هويّةُ الصفحة. و`source`
     * بابُ المحتوى حين لا يكون حقلًا هنا — فقسمٌ يُبنى من فئاته لا تُنسَخ
     * حقولُه إلى المحرّر: عمودٌ يُكتب من بابين يفترق عند أوّل تعديل.
     *
     * @var array<string, array{label: string, hint: string, fixed: bool, source: array{0: string, 1: string}|null}>
     */
    public const ROWS = [
        self::HERO => ['label' => 'الواجهة', 'hint' => 'أعلى الصفحة — عنوانٌ وصورةٌ وزرُّ تسوّق', 'fixed' => true, 'source' => null],
        'cats' => ['label' => 'تسوّق حسب الفئة', 'hint' => 'فئاتُك التي فيها بضاعةٌ معروضة، كلٌّ ببطاقتها', 'fixed' => false, 'source' => ['الأصناف والفئات', 'admin.products.index']],
        'best' => ['label' => 'الأكثر مبيعًا', 'hint' => 'أربعةُ أصنافٍ تتصدّر — محسوبةً من بيعك أو مختارةً بيدك', 'fixed' => false, 'source' => null],
        'new' => ['label' => 'وصل حديثًا', 'hint' => 'أحدثُ ما أضفتَه إلى متجرك — يُرتَّب وحده', 'fixed' => false, 'source' => ['الأصناف', 'admin.products.index']],
        'banner' => ['label' => 'شريط المناسبات والهدايا', 'hint' => 'شريطٌ عريضٌ يَعِد بباقاتِ المناسبات وكرتِ الهدية', 'fixed' => false, 'source' => null],
        'block' => ['label' => 'قسمك الخاصّ', 'hint' => 'عنوانٌ ونصٌّ وصورةٌ وزرّ — تكتبه بنفسك', 'fixed' => false, 'source' => null],
        'about' => ['label' => 'عنّا', 'hint' => 'نبذتُك إلى جانب شعارك', 'fixed' => false, 'source' => null],
        'reviews' => ['label' => 'آراء الزبائن', 'hint' => 'ما نشرتَه من آراءٍ وصلتك', 'fixed' => false, 'source' => ['آراء الزبائن', 'admin.marketing.reviews']],
        self::FOOT => ['label' => 'التذييل', 'hint' => 'أسفل كلّ صفحة — لا الرئيسية وحدها', 'fixed' => true, 'source' => ['هاتفك وبريدك وعنوانك', 'admin.settings.index']],
    ];

    /**
     * ═══ و«مُشغَّلٌ ولا يظهر» يُقال في صفّه ═══
     *
     * قسمٌ مرفوعٌ مفتاحُه ولا يُرسم على الصفحة حالةٌ يراها صاحبُ المحلّ فيظنّ
     * العطبَ في النظام: يفتح متجره فلا يجد «آراء الزبائن» وقد شغّلها بيده.
     * والسببُ عندنا مكتوب — فلا يُترك يُخمَّن.
     *
     * @var array<string, string>
     */
    public const SILENT = [
        'cats' => 'لا فئةَ فيها صنفٌ معروض — والفئةُ الفارغة لا تُرسم.',
        'best' => 'لا صنفَ معروضًا في متجرك بعد.',
        'new' => 'لا صنفَ معروضًا في متجرك بعد.',
        'banner' => 'الشريطُ يَعِد ببضاعةٍ — ولا صنفَ معروضًا بعد، فلا يُرسم.',
        'block' => 'لا يظهر حتى تُشغّله وتكتب عنوانه ونصّه معًا.',
        'about' => 'اكتب نبذتك ليظهر القسم.',
        'reviews' => 'لا رأيَ معروضًا بعد.',
    ];

    /** صفٌّ واحدٌ كما تقرؤه الشاشة */
    private static function row(string $key, bool $on, ?string $silent): array
    {
        $spec = self::ROWS[$key];

        return [
            'key' => $key,
            'label' => $spec['label'],
            'hint' => $spec['hint'],
            'fixed' => $spec['fixed'],
            'on' => $on,
            'silent' => $silent,
            'source' => $spec['source'] === null
                ? null
                : ['label' => $spec['source'][0], 'route' => $spec['source'][1]],
            'fields' => self::FIELDS[$key],
        ];
    }

    /**
     * ما لا تكتبه بطاقةُ الإعدادات لمن لبس واجهةً خاصّة.
     *
     * ═══ ولمَ تُحذف من الحمولة لا تُخفى من الشاشة ═══
     *
     * نموذجُ Inertia يلتقط قيمَه حين تُفتح الشاشة. فلو بقيت هذه المفاتيح في
     * حمولة «حفظ ونشر» — وإن لم يُرسم لها مقبض — لَحملت ما كان قبل أن يفتح
     * المحرّر، وكتب حفظٌ للتوصيل فوق ما رتّبه في صفحته قبل دقيقة.
     *
     * وهو عطبٌ صامت: لا خطأ ولا رسالة، فقط تعديلٌ يختفي.
     *
     * @return list<string>
     */
    public static function omitted(): array
    {
        $keys = self::DEAD;

        foreach (self::FIELDS as $fields) {
            foreach ($fields as $field) {
                $keys[] = $field['key'];
            }
        }

        // وترتيبُ الأقسام معها: القائمةُ تُكتب بالسحب في المحرّر
        $keys[] = 'store_sections';

        return array_values(array_unique($keys));
    }

    /**
     * صفوفُ المحرّر بترتيب ما يراه الزائر.
     *
     * والواجهةُ أوّلًا والتذييلُ آخرًا وبينهما الأقسامُ بترتيب صاحبها، ثمّ
     * ما أطفأه — فالمطفأُ يبقى في الشاشة ليُرفع ثانيةً، ولا يخرج من الصفحة
     * إلى العدم.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(int $businessId): array
    {
        $site = MarketingSettings::group($businessId, 'website');
        $order = StorePage::order($businessId);

        /*
         * و«ما يُعرض» هنا هو تعريفُ الواجهة نفسِه: فعّالٌ ومعروض (انظر
         * `RibbonController::shown`). فقسمٌ يُقال عنه هنا إنّه يظهر يظهر.
         */
        $shown = Product::where('business_id', $businessId)
            ->where('active', true)->where('published', true)
            ->pluck('category_id');

        $hasProducts = $shown->isNotEmpty();
        $hasCats = Category::where('business_id', $businessId)
            ->whereIn('id', $shown->filter()->unique()->all())->exists();
        $hasReviews = Review::where('business_id', $businessId)->showable()->exists();

        $blockReady = ($site['store_block_on'] ?? '0') === '1'
            && trim((string) ($site['store_block_title'] ?? '')) !== ''
            && trim((string) ($site['store_block_text'] ?? '')) !== '';

        $silent = [
            'cats' => $hasCats ? null : self::SILENT['cats'],
            'best' => $hasProducts ? null : self::SILENT['best'],
            'new' => $hasProducts ? null : self::SILENT['new'],
            'banner' => $hasProducts ? null : self::SILENT['banner'],
            'block' => $blockReady ? null : self::SILENT['block'],
            'about' => trim((string) ($site['store_about'] ?? '')) !== '' ? null : self::SILENT['about'],
            'reviews' => $hasReviews ? null : self::SILENT['reviews'],
        ];

        $rows = [self::row(self::HERO, true, null)];

        // المختارُ بترتيبه، ثمّ المطفأُ بترتيبه الأصليّ — كما في `StorePage::SECTIONS`
        foreach (array_merge($order, array_values(array_diff(StorePage::SECTIONS, $order))) as $key) {
            $on = in_array($key, $order, true);

            // ولا يُقال «لا يظهر» لقسمٍ أطفأه صاحبُه: هو يعرف لمَ أطفأه
            $rows[] = self::row($key, $on, $on ? $silent[$key] : null);
        }

        $rows[] = self::row(self::FOOT, true, null);

        return $rows;
    }

    /**
     * قيمُ المفاتيح كما هي الآن — نصًّا، والمفاتيحُ الثنائية `'1'`/`'0'`.
     *
     * @return array<string, string>
     */
    public static function values(int $businessId): array
    {
        $site = MarketingSettings::group($businessId, 'website');
        $out = [];

        foreach (self::FIELDS as $fields) {
            foreach ($fields as $field) {
                $out[$field['key']] = (string) ($site[$field['key']] ?? '');
            }
        }

        return $out;
    }
}
