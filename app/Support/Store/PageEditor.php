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
    /**
     * رأسُ المتجر: فوق كلّ شيءٍ وفي كلّ صفحة — شريطُ الإعلان واختصاراتُ
     * صفّ «المتجر». وليس قسمًا يُطفأ: فارغُه لا يُرسم. انظر `StoreHeader`.
     */
    public const HEAD = 'head';

    /** الواجهة: فوق الأقسام دائمًا، وليست قسمًا يُطفأ */
    public const HERO = 'hero';

    /** والتذييل: تحتها دائمًا، وفي كلّ صفحةٍ لا في الرئيسية وحدها */
    public const FOOT = 'foot';

    /**
     * و«أضف مع طلبك» — في صفحة المنتج لا في الرئيسية، فليس قسمًا يُرتَّب.
     * ولمن له القسم وحده (`RibbonUpsells::settings`).
     */
    public const UPSELLS = 'upsells';

    /**
     * ومجموعاتُ المتجر — رفوفٌ يختار أصنافَها بيده، غيرُ الفئات. لكلّ من يلبس
     * الواجهة، وفارغةٌ حتّى يكتبها. ليست قسمًا في الرئيسية: تُفتح من صفّ
     * خيارات المتجر ومن الشريط المصوّر. انظر `StoreCollections`.
     */
    public const COLLECTIONS = 'collections';

    /**
     * وشريطُها المصوّر — بطاقاتٌ بصورها فوق قائمة الصفحات، أوّلُ ما يُرى في
     * الصفحة. مطفأٌ حتّى يُشغَّل وينشر. انظر `StoreVisualBar`.
     */
    public const VISUAL_BAR = 'vbar';

    /**
     * والقائمةُ الرئيسيّة وصفحاتُها — الصفحاتُ الأربعُ بترتيب صاحبها، وبينها
     * أقسامُه: عنوانٌ لا يُضغط، أو صفحةٌ يكتبها هنا (كتابة، صور، منتجات…)،
     * أو رابط. والصفحةُ تُنشأ مع اسمها في القائمة ويُكتب محتواها في مكانه —
     * لا صفٌّ ثانٍ يُبحث فيه عنها. انظر `StoreNav::menu` و`StoreCustomPages`.
     */
    public const MENU = 'menu';

    /**
     * وعرضُ الصور — صورُ المنتجات والفئات كاملةً أو تملأ إطارها. وسائرُ الصور
     * يُختار عرضُها إلى جانبها في صفّها. انظر `StoreMedia::fit`.
     */
    public const IMAGES = 'images';

    /** بابٌ من أبواب المحرّر الأولى — يُرى أوّلَ ما يُفتح */
    public const MAIN = 'main';

    /** وما يُضبط مرّةً ثمّ يُترك — مطويٌّ تحت «إعدادات متقدّمة» */
    public const ADVANCED = 'advanced';

    /**
     * مقابضُ الصفحة البسيطة — لا تُحرّك واجهةً خاصّة فلا تُعرض لصاحبها.
     *
     * `store_theme` لونُ `store.show.blade`، و`store_show_prices` مفتاحُها
     * ومفتاحُ الموقع المبنيّ. وواجهةُ RIBBON ألوانُها في قالبها وأسعارُها
     * مكتوبةٌ في كلّ بطاقة (انظر `_card.blade.php`) — لا تسأل عن أيٍّ منهما.
     *
     * ومقبضٌ يُعرض ولا يُدير شيئًا أسوأُ من غياب المقبض: يُقلَّب ويُحفظ
     * ويُنتظر أثرُه، ثمّ يُظنّ العطبُ في الصفحة.
     *
     * و`store_headline` («العنوان الكبير») حلّ محلَّه عنوانُ الواجهة لكلّ لغة
     * (`store_hero_title` و`_en`). ويبقى مقروءًا سابقًا للعربيّ وحده لمن
     * كتبه قبلُ (`StorePage::heroTitle`) — فلا يتبدّل عنوانُ متجرٍ قائم —
     * ولا يُحرَّر من بابين.
     */
    public const DEAD = ['store_theme', 'store_show_prices', 'store_headline'];

    /**
     * حقولُ كلّ صفٍّ بترتيب ظهورها فيه.
     *
     * و`kind` شكلُ المقبض لا نوعُ البيانات: `image` ترفع وتردّ رابطًا
     * (انظر `StoreImageField`)، و`featured` تنتقي أصنافًا، و`toggle` تُحفظ
     * `'1'`/`'0'`. و`gate` مفتاحٌ لا يُعرض الحقلُ إلّا إن رُفع.
     *
     * و`align` ثلاثةُ أزرارٍ مغلقة (`StoreHeader::ALIGNS`)، و`shortcuts`
     * تختار فئاتٍ من متجره وترتّبها — معرّفاتٍ لا روابط. و`products` تختار
     * أصنافًا من المعروض وترتّبها إلى `max`. و`collections` قائمةُ مجموعاتٍ
     * نصًّا بصيغة JSON — حتّى `max` مجموعة، في كلٍّ حتّى `max_products` صنفًا.
     * و`visualbar` بطاقاتُ الشريط المصوّر نصًّا بصيغة JSON — حتّى `max` بطاقة.
     * و`menu` القائمةُ الرئيسيّة نصَّ JSON — حتّى `max` عنصرًا بين صفحاتها الأربع،
     * ويكتب معها الصفحاتِ المخصّصة (`owns`): حتّى `max_pages` صفحة، في كلٍّ
     * `max_blocks` وحدة. و`pages` تلك الصفحاتُ نصَّ JSON — لا مقبضَ لها وحدها.
     * و`footer` أعمدةُ التذييل وسطرُه نصَّ JSON، ويكتب معها ما في `owns`.
     * و`owns` مفاتيحُ يرسمها الحقلُ نفسُه في مواضعها منه — فلا تُرسم ثانيةً.
     * و`shoprow` ترتيبُ أزرار صفّ المتجر كلِّها رموزًا. و`catalogfit` طريقةُ عرض
     * صور المنتجات والفئات. و`fit` على حقل `image` مفتاحُ طريقة عرض صورته.
     *
     * والنصُّ الذي يكتبه صاحبُ المحلّ لزبونه حقلان: عربيٌّ وإنجليزيّ (`_en`)،
     * لا يقع أحدُهما على الآخر. ونصوصُ القالب نفسِه (الأزرارُ وأسماءُ الأقسام)
     * ليست هنا — هي في `RibbonTexts`.
     *
     * @var array<string, list<array{key: string, kind: string, label: string, hint?: string, dir?: string, gate?: string, max?: int, max_products?: int, max_pages?: int, max_blocks?: int, owns?: list<string>, fit?: string, ratio?: string}>>
     */
    public const FIELDS = [
        self::VISUAL_BAR => [
            ['key' => StoreVisualBar::KEY, 'kind' => 'visualbar', 'label' => 'بطاقات الشريط', 'hint' => 'كلُّ بطاقةٍ صورةٌ واسمٌ لكلّ لغة وتفتح مجموعةً أو فئةً أو منتجًا أو صفحة — شغّله وانشر ليظهر', 'max' => StoreVisualBar::MAX_CARDS],
        ],
        self::COLLECTIONS => [
            ['key' => StoreCollections::KEY, 'kind' => 'collections', 'label' => 'المجموعات', 'hint' => 'رفوفٌ تختار منتجاتها من أيّ فئة — ويبقى كلُّ منتجٍ في فئته. انشر لتظهر لزبائنك', 'max' => StoreCollections::MAX, 'max_products' => StoreCollections::MAX_PRODUCTS],
            ['key' => StoreHeader::ROW, 'kind' => 'shoprow', 'label' => 'ترتيب شريط المتجر', 'hint' => 'رتّب «كل المنتجات» و«الأكثر مبيعًا» ومجموعاتك وفئاتك كما تريدها في الشريط — و«كل المنتجات» يبقى دائمًا'],
        ],
        self::MENU => [
            ['key' => StoreNav::MENU, 'kind' => 'menu', 'label' => 'عناصر القائمة', 'hint' => 'أضف قسمًا أو صفحة ورتّبها بين الرئيسية والمتجر ومن نحن وتواصل معنا — بلا رابطٍ تكتبه', 'max' => StoreNav::MAX_LINKS, 'max_pages' => StoreCustomPages::MAX, 'max_blocks' => StoreCustomPages::MAX_BLOCKS, 'owns' => [StoreCustomPages::KEY]],
            ['key' => StoreCustomPages::KEY, 'kind' => 'pages', 'label' => 'الصفحات', 'hint' => 'تُكتب في القائمة نفسِها — كلُّ صفحةٍ مع اسمها'],
        ],
        self::IMAGES => [
            ['key' => 'store_catalog_fit', 'kind' => 'catalogfit', 'label' => 'صور المنتجات والفئات', 'hint' => 'في بطاقات المتجر وصفحة المنتج والسلّة — «كاملة» تُري الصورةَ كلَّها بلا قصّ، و«ملء الإطار» تقصّ أطرافها لتملأ البطاقة'],
        ],
        self::HEAD => [
            ['key' => 'store_announcement_ar', 'kind' => 'text', 'label' => 'نص الشريط بالعربية', 'hint' => 'أعلى كلّ صفحةٍ عربيّة — واتركه فارغًا فلا شريط'],
            ['key' => 'store_announcement_en', 'kind' => 'text', 'label' => 'Announcement text in English', 'hint' => 'أعلى كلّ صفحةٍ إنجليزيّة — ولا يُترجَم النصُّ العربيّ إليها', 'dir' => 'ltr'],
            ['key' => 'store_announcement_align', 'kind' => 'align', 'label' => 'محاذاة النص'],
            ['key' => 'store_shop_nav_categories', 'kind' => 'shortcuts', 'label' => 'اختصارات صفحة المتجر', 'hint' => 'بعد «كل المنتجات» و«الأكثر مبيعًا» — حتى ٦ فئات بترتيبك، والفئةُ بلا منتجٍ معروض لا تظهر'],
        ],
        self::HERO => [
            ['key' => 'store_hero_title', 'kind' => 'text', 'label' => 'عنوان الواجهة — العربية', 'hint' => 'أوّلُ سطرٍ يقرؤه زبونك — واتركه فارغًا فيبقى العنوانُ الأصليّ'],
            ['key' => 'store_hero_title_en', 'kind' => 'text', 'label' => 'Hero title — English', 'hint' => 'للصفحة الإنجليزيّة — ولا يُترجَم العنوانُ العربيّ إليها', 'dir' => 'ltr'],
            ['key' => 'store_hero_sub', 'kind' => 'textarea', 'label' => 'وصف الواجهة — العربية', 'hint' => 'تحت العنوان — واتركه فارغًا فيبقى الوصفُ الأصليّ'],
            ['key' => 'store_hero_sub_en', 'kind' => 'textarea', 'label' => 'Hero description — English', 'hint' => 'للصفحة الإنجليزيّة — واتركه فارغًا فيبقى الوصفُ الإنجليزيّ الأصليّ', 'dir' => 'ltr'],
            ['key' => 'store_hero_image', 'kind' => 'image', 'label' => 'صورة الواجهة', 'hint' => 'إلى جانب العنوان — وبلا اختيارك تُؤخذ من أوّل صنفٍ مبيعًا', 'fit' => 'store_hero_image_fit', 'ratio' => '5/4'],
        ],
        'cats' => [],
        'best' => [
            ['key' => 'store_featured', 'kind' => 'featured', 'label' => 'مختاراتنا', 'hint' => 'اختر حتى ٤ أصناف تتصدّر صفحتك — وبلا اختيارٍ يبقى «الأكثر مبيعًا» محسوبًا من بيعك'],
        ],
        'new' => [],
        'banner' => [
            ['key' => 'store_banner_image', 'kind' => 'image', 'label' => 'صورة الشريط', 'hint' => 'الشريطُ يَعِد بباقةٍ وكرتِ هدية — وبلا صورةٍ يبقى إلى جانب وعده مستطيلٌ مخطَّط', 'fit' => 'store_banner_image_fit', 'ratio' => '4/3'],
        ],
        'block' => [
            ['key' => 'store_block_on', 'kind' => 'toggle', 'label' => 'اكتب قسمك', 'hint' => 'لِما لا تقوله بضاعتك: اشتراكٌ شهريّ، تنسيقُ أعراس، توصيلٌ إلى ولايتك'],
            ['key' => 'store_block_title', 'kind' => 'text', 'label' => 'العنوان — العربية', 'gate' => 'store_block_on'],
            ['key' => 'store_block_title_en', 'kind' => 'text', 'label' => 'Title — English', 'hint' => 'للصفحة الإنجليزيّة — وبلا عنوانٍ ونصٍّ إنجليزيَّين لا يظهر القسمُ فيها', 'dir' => 'ltr', 'gate' => 'store_block_on'],
            ['key' => 'store_block_text', 'kind' => 'textarea', 'label' => 'النصّ — العربية', 'hint' => 'أسطرُك تبقى أسطرًا كما تكتبها', 'gate' => 'store_block_on'],
            ['key' => 'store_block_text_en', 'kind' => 'textarea', 'label' => 'Text — English', 'dir' => 'ltr', 'gate' => 'store_block_on'],
            ['key' => 'store_block_image', 'kind' => 'image', 'label' => 'صورة القسم', 'hint' => 'اختيارية — واحدةٌ للّغتين', 'gate' => 'store_block_on', 'fit' => 'store_block_image_fit', 'ratio' => '4/3'],
            ['key' => 'store_block_cta', 'kind' => 'text', 'label' => 'نصّ الزرّ — العربية', 'hint' => 'اتركه فارغًا فلا زرّ', 'gate' => 'store_block_on'],
            ['key' => 'store_block_cta_en', 'kind' => 'text', 'label' => 'Button text — English', 'hint' => 'اتركه فارغًا فلا زرّ في الصفحة الإنجليزيّة', 'dir' => 'ltr', 'gate' => 'store_block_on'],
            ['key' => 'store_block_href', 'kind' => 'text', 'label' => 'وجهة الزرّ', 'hint' => 'رابطٌ كامل أو /shop — واحدةٌ للّغتين', 'dir' => 'ltr', 'gate' => 'store_block_on'],
        ],
        RibbonPicks::SECTION => [
            ['key' => 'store_picks_title', 'kind' => 'text', 'label' => 'عنوان القسم — العربية', 'hint' => 'واتركه فارغًا فيبقى «اختيارات RIBBON»'],
            ['key' => 'store_picks_title_en', 'kind' => 'text', 'label' => 'Section title — English', 'hint' => 'واتركه فارغًا فيبقى «RIBBON picks»', 'dir' => 'ltr'],
            ['key' => 'store_picks', 'kind' => 'products', 'label' => 'الأصناف', 'hint' => 'اخترها ورتّبها كما تريد أن تظهر — والصنفُ يبقى في قسمه', 'max' => RibbonPicks::MAX],
        ],
        'about' => [
            ['key' => 'store_about', 'kind' => 'textarea', 'label' => 'نبذة النشاط — العربية', 'hint' => 'تُقرأ في هذا القسم، وفي صفحة «من نحن»، وفي تذييل كلّ صفحةٍ عربيّة'],
            ['key' => 'store_about_en', 'kind' => 'textarea', 'label' => 'About description — English', 'hint' => 'للصفحة الإنجليزيّة — ولا تُعرض فيها النبذةُ العربيّة، وبلا نبذةٍ إنجليزيّة لا يظهر القسمُ فيها', 'dir' => 'ltr'],
        ],
        'reviews' => [],
        self::UPSELLS => [
            ['key' => RibbonUpsells::KEY, 'kind' => 'products', 'label' => 'أضف مع طلبك', 'hint' => 'أصنافٌ تُقترح في صفحة كلّ منتج قبل زرّ السلّة — وبلا اختيارٍ تبقى من قسم الإضافات كما كانت', 'max' => 6],
        ],
        self::FOOT => [
            ['key' => StoreFooter::KEY, 'kind' => 'footer', 'label' => 'أعمدة التذييل', 'hint' => 'رتّب الأعمدة وسمّها واختر ما يظهر فيها — والشعارُ يبقى أوّلَها', 'max' => StoreFooter::MAX_TEXT, 'owns' => ['store_tagline', 'store_tagline_en', 'store_whatsapp', 'store_instagram', 'store_hours', 'store_hours_en']],
            ['key' => 'store_tagline', 'kind' => 'text', 'label' => 'سطر التذييل', 'hint' => 'أسفل كلّ صفحة — واتركه فارغًا فلا يُكتب سطر'],
            ['key' => 'store_tagline_en', 'kind' => 'text', 'label' => 'سطر التذييل (English)', 'hint' => 'للصفحة الإنجليزيّة — واتركه فارغًا فلا يُكتب فيها سطر', 'dir' => 'ltr'],
            ['key' => 'store_whatsapp', 'kind' => 'text', 'label' => 'واتساب', 'hint' => 'زرٌّ في التذييل يفتح محادثةً — ورقمُ متجرك إن تركته فارغًا', 'dir' => 'ltr'],
            ['key' => 'store_instagram', 'kind' => 'text', 'label' => 'إنستغرام', 'hint' => 'اسمُ الحساب بلا «@» ولا رابط', 'dir' => 'ltr'],
            ['key' => 'store_hours', 'kind' => 'text', 'label' => 'ساعات العمل', 'hint' => 'تُكتب في التذييل، ويقرؤها من يستلم من المحلّ'],
            ['key' => 'store_hours_en', 'kind' => 'text', 'label' => 'ساعات العمل (English)', 'hint' => 'للصفحة الإنجليزيّة — مثل: Daily 10 AM – 10 PM', 'dir' => 'ltr'],
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
        self::HEAD => ['label' => 'رأس المتجر', 'hint' => 'شريطُ إعلانٍ أعلى كلّ صفحة، واختصاراتُ الفئات في صفحة المتجر', 'fixed' => true, 'source' => null],
        self::VISUAL_BAR => ['label' => 'الشريط المصوّر', 'hint' => 'بطاقاتٌ بصورها فوق قائمة الصفحات — مطفأٌ حتى تشغّله', 'fixed' => true, 'source' => null],
        self::COLLECTIONS => ['label' => 'مجموعات المتجر', 'hint' => 'هدايا التخرّج، عروضٌ خاصّة… رفوفٌ تختار منتجاتها بيدك وتظهر بجانب «كل المنتجات»', 'fixed' => true, 'source' => null],
        self::MENU => ['label' => 'القائمة الرئيسية والصفحات', 'hint' => 'الرئيسية | المتجر | المناسبات | من نحن — أضف أقسامك وصفحاتك ورتّبها', 'fixed' => true, 'source' => null],
        self::IMAGES => ['label' => 'عرض الصور', 'hint' => 'صور المنتجات والفئات كاملةً بلا قصّ، أو تملأ إطارها', 'fixed' => true, 'source' => null],
        self::HERO => ['label' => 'الواجهة', 'hint' => 'أعلى الصفحة — عنوانٌ وصورةٌ وزرُّ تسوّق', 'fixed' => true, 'source' => null],
        'cats' => ['label' => 'تسوّق حسب الفئة', 'hint' => 'فئاتُك التي فيها بضاعةٌ معروضة، كلٌّ ببطاقتها', 'fixed' => false, 'source' => ['الأصناف والفئات', 'admin.products.index']],
        'best' => ['label' => 'الأكثر مبيعًا', 'hint' => 'أربعةُ أصنافٍ تتصدّر — محسوبةً من بيعك أو مختارةً بيدك', 'fixed' => false, 'source' => null],
        'new' => ['label' => 'وصل حديثًا', 'hint' => 'أحدثُ ما أضفتَه إلى متجرك — يُرتَّب وحده', 'fixed' => false, 'source' => ['الأصناف', 'admin.products.index']],
        'banner' => ['label' => 'شريط المناسبات والهدايا', 'hint' => 'شريطٌ عريضٌ يَعِد بباقاتِ المناسبات وكرتِ الهدية', 'fixed' => false, 'source' => null],
        'block' => ['label' => 'قسمك الخاصّ', 'hint' => 'عنوانٌ ونصٌّ وصورةٌ وزرّ — تكتبه بنفسك', 'fixed' => false, 'source' => null],
        RibbonPicks::SECTION => ['label' => 'اختيارات RIBBON', 'hint' => 'أصنافٌ تختارها بيدك وترتّبها — لا يظهر حتى ترفعه', 'fixed' => false, 'source' => null],
        'about' => ['label' => 'عنّا', 'hint' => 'نبذتُك إلى جانب شعارك', 'fixed' => false, 'source' => null],
        'reviews' => ['label' => 'آراء الزبائن', 'hint' => 'ما نشرتَه من آراءٍ وصلتك', 'fixed' => false, 'source' => ['آراء الزبائن', 'admin.marketing.reviews']],
        self::UPSELLS => ['label' => 'أضف مع طلبك', 'hint' => 'في صفحة المنتج — أصنافٌ تختارها بيدك بدل قسم الإضافات', 'fixed' => true, 'source' => null],
        self::FOOT => ['label' => 'التذييل', 'hint' => 'أسفل كلّ صفحة — أعمدتُه وعناوينُها وسطرُه الأخير', 'fixed' => true, 'source' => ['هاتفك وبريدك وعنوانك', 'admin.settings.index']],
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
        'cats' => 'لا فئةَ ظاهرةً فيها صنفٌ معروض — والفئةُ الفارغة أو المخفيّةُ بطاقتُها لا تُرسم.',
        'best' => 'لا صنفَ معروضًا في متجرك بعد.',
        'new' => 'لا صنفَ معروضًا في متجرك بعد.',
        'banner' => 'الشريطُ يَعِد ببضاعةٍ — ولا صنفَ معروضًا بعد، فلا يُرسم.',
        'block' => 'لا يظهر حتى تُشغّله وتكتب عنوانه ونصّه معًا.',
        RibbonPicks::SECTION => 'لا صنفَ مختارًا معروضًا — اختر أصنافًا ليظهر القسم.',
        'about' => 'اكتب نبذتك ليظهر القسم — وتُفتح بها صفحة «من نحن».',
        'reviews' => 'لا رأيَ معروضًا بعد.',
    ];

    /**
     * صفٌّ واحدٌ كما تقرؤه الشاشة.
     *
     * @param  list<array<string, mixed>>|null  $fields  حقولُه إن خالفت `FIELDS` (حدٌّ يُقرأ من القائمة)
     */
    private static function row(string $key, bool $on, ?string $silent, ?array $fields = null, string $group = self::ADVANCED): array
    {
        $spec = self::ROWS[$key];

        return [
            'key' => $key,
            'label' => $spec['label'],
            'hint' => $spec['hint'],
            'fixed' => $spec['fixed'],
            'group' => $group,
            'on' => $on,
            'silent' => $silent,
            'source' => $spec['source'] === null
                ? null
                : ['label' => $spec['source'][0], 'route' => $spec['source'][1]],
            'fields' => $fields ?? self::FIELDS[$key],
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

                // وطريقةُ عرض الصورة تُكتب إلى جانبها في المحرّر — لا في بطاقة الإعدادات
                if (isset($field['fit'])) {
                    $keys[] = $field['fit'];
                }
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
        // والصفوفُ كما يحرّرها — المسوّدةُ إن كانت، كالقيم (`values`) والترتيب
        $site = array_merge(MarketingSettings::group($businessId, 'website'), StoreContent::draft($businessId));

        /*
         * و«ما يُعرض» هنا هو تعريفُ الواجهة نفسِه: فعّالٌ ومعروض (انظر
         * `RibbonController::shown`). فقسمٌ يُقال عنه هنا إنّه يظهر يظهر.
         */
        $shown = Product::where('business_id', $businessId)
            ->where('active', true)->where('published', true)
            ->pluck('category_id');

        $hasProducts = $shown->isNotEmpty();
        // والفئةُ التي أخفى بطاقتَها لا تُرسم في القسم — فلا تُسكت تنبيهَه
        $hasCats = Category::where('business_id', $businessId)->where('card_hidden', false)
            ->whereIn('id', $shown->filter()->unique()->all())->exists();
        $hasReviews = Review::where('business_id', $businessId)->testimonial()->exists();

        // والقسمُ الحرُّ يظهر بلغةٍ كُتب لها عنوانُه ونصُّه — بأيّهما
        $written = fn (string $suffix) => trim((string) ($site['store_block_title'.$suffix] ?? '')) !== ''
            && trim((string) ($site['store_block_text'.$suffix] ?? '')) !== '';
        $blockReady = ($site['store_block_on'] ?? '0') === '1' && ($written('') || $written('_en'));

        /*
         * و«اختيارات RIBBON» لمن فُتحت له وحده — ومن ليس فيها لا يُرسَل له صفُّها.
         * و«يظهر» إن بقي ممّا اختاره صنفٌ معروض — قاعدةُ الواجهة نفسُها.
         */
        $picksOn = RibbonPicks::allowed($businessId);
        $picksShown = $picksOn && RibbonPicks::ids($businessId) !== []
            && Product::where('business_id', $businessId)->where('active', true)->where('published', true)
                ->whereIn('id', RibbonPicks::ids($businessId))->exists();

        $silent = [
            'cats' => $hasCats ? null : self::SILENT['cats'],
            'best' => $hasProducts ? null : self::SILENT['best'],
            'new' => $hasProducts ? null : self::SILENT['new'],
            'banner' => $hasProducts ? null : self::SILENT['banner'],
            'block' => $blockReady ? null : self::SILENT['block'],
            'about' => trim((string) ($site['store_about'] ?? '')) !== '' || trim((string) ($site['store_about_en'] ?? '')) !== '' ? null : self::SILENT['about'],
            'reviews' => $hasReviews ? null : self::SILENT['reviews'],
            RibbonPicks::SECTION => $picksShown ? null : self::SILENT[RibbonPicks::SECTION],
        ];

        /*
         * ═══ أربعةُ أبوابٍ أوّلًا، وما سواها تحت «إعدادات متقدّمة» ═══
         *
         * الواجهةُ، ومجموعاتُ المتجر، والقائمةُ وصفحاتُها، والتذييل — ما يبني
         * به صاحبُ المتجر متجرَه. وما يُضبط مرّةً ثمّ يُترك (رأسُ المتجر،
         * وأقسامُ الرئيسية بترتيبها، وعرضُ الصور، و«أضف مع طلبك») تحت
         * «إعدادات متقدّمة» — لا يُحذف ولا يتبدّل، يُطوى.
         *
         * والشريطُ المصوّر بين الأبواب الأولى ما دام مشغَّلًا في مسوّدته — فوقها
         * كما هو فوق الترويسة. فإن أطفأه وحفظ نزل تحت «إعدادات متقدّمة»
         * ببطاقاته وصوره كما حُفظت: لا يُحذف منها شيء، ولا يُطفأ لتاجرٍ غيره.
         */
        $barOn = StoreVisualBar::switchedOn((string) (StoreContent::draft($businessId)[StoreVisualBar::KEY] ?? ''));

        $rows = [
            ...($barOn ? [self::row(self::VISUAL_BAR, true, null, null, self::MAIN)] : []),
            self::row(self::HERO, true, null, null, self::MAIN),
            self::row(self::COLLECTIONS, true, null, null, self::MAIN),
            self::row(self::MENU, true, null, null, self::MAIN),
            self::row(self::FOOT, true, null, null, self::MAIN),
            self::row(self::HEAD, true, null),
        ];

        /*
         * الأقسامُ بترتيب المسوّدة كلِّه: المطفأُ في موضعه إن حُفظ له موضع
         * (`StorePage::draftLayout`)، وإلّا بعد المشغَّل بترتيبه الأصليّ كما كان.
         */
        foreach (StorePage::draftLayout($businessId) as ['key' => $key, 'on' => $on]) {
            // ولا يُقال «لا يظهر» لقسمٍ أطفأه صاحبُه: هو يعرف لمَ أطفأه
            $rows[] = self::row($key, $on, $on ? $silent[$key] : null);
        }

        // وعرضُ الصور ليس قسمًا في الرئيسية
        $rows[] = self::row(self::IMAGES, true, null);

        /*
         * و«أضف مع طلبك» لمن له القسم وحده — وحدُّه حدُّ القائمة نفسُها
         * (`storefront.ribbon_product_upsells`)، لا رقمٌ ثانٍ يفترق عنه.
         */
        if (($upsells = RibbonUpsells::settings($businessId)) !== null) {
            $rows[] = self::row(self::UPSELLS, true, null, array_map(
                fn (array $f) => ['max' => $upsells['limit']] + $f,
                self::FIELDS[self::UPSELLS],
            ));
        }

        if (! $barOn) {
            $rows[] = self::row(self::VISUAL_BAR, true, null);
        }

        return $rows;
    }

    /**
     * اسمُ المفتاح كما يقرؤه صاحبُه — أو فراغٌ لمن لا اسمَ له في المحرّر.
     *
     * ويُقرأ من `FIELDS` لا من قائمةٍ ثانية: قائمتان تفترقان عند أوّل
     * إعادةِ تسمية، فيقول سجلُّ النشر «العنوان الكبير» والشاشةُ «العنوان».
     */
    public static function labelOf(string $key): string
    {
        foreach (self::FIELDS as $fields) {
            foreach ($fields as $field) {
                if ($field['key'] === $key) {
                    return (string) $field['label'];
                }
            }
        }

        return match ($key) {
            'store_pages' => 'الصفحات',
            'store_sections' => 'ترتيب الأقسام',
            'store_shelf_order' => 'ترتيب المنتجات',
            'store_about_image' => 'صورة «من نحن»',
            'store_seo_title' => 'عنوان البحث',
            'store_seo_desc' => 'وصف البحث',
            'store_seo_title_en' => 'عنوان البحث بالإنجليزية',
            'store_seo_desc_en' => 'وصف البحث بالإنجليزية',
            'store_headline' => 'العنوان الكبير',
            'store_hero_image_fit' => 'عرض صورة الواجهة',
            'store_banner_image_fit' => 'عرض صورة الشريط',
            'store_block_image_fit' => 'عرض صورة القسم',
            default => '',
        };
    }

    /**
     * قيمُ المفاتيح كما هي الآن — نصًّا، والمفاتيحُ الثنائية `'1'`/`'0'`.
     *
     * @return array<string, string>
     */
    public static function values(int $businessId): array
    {
        /*
         * والمحرّرُ يفتح على **المسوّدة** لا على المنشور.
         *
         * ولولا ذلك لَفتح صاحبُ المتجر شاشتَه بعد حفظٍ لم يُنشر فوجد ما
         * كتبه قد ذهب — وهو لم يذهب، بل ينتظر النشر. ومن لا مسوّدةَ له
         * تردّ `draft` المنشورَ نفسَه.
         */
        $site = array_merge(
            MarketingSettings::group($businessId, 'website'),
            StoreContent::draft($businessId),
        );
        $out = [];

        foreach (self::FIELDS as $fields) {
            foreach ($fields as $field) {
                $out[$field['key']] = (string) ($site[$field['key']] ?? '');

                if (isset($field['fit'])) {
                    $out[$field['fit']] = (string) ($site[$field['fit']] ?? '');
                }
            }
        }

        return $out;
    }
}
