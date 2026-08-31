<?php

namespace App\Support;

/**
 * ترجمةُ ما يكتبه التاجر — بمعجمٍ مقروء لا بتخمين.
 *
 * الواجهة تُقرأ بالإنجليزية، لكنّ ما في الكتالوج يكتبه صاحب المحلّ
 * بالعربية: «باقة ورد أحمر»، «تغليف فاخر»، «كبير». فكانت الشاشة
 * الإنجليزية تعرض قائمةً عربيّةً كاملة — يقرأ فيها الكاشير الأجنبيّ
 * أزرارًا لا يفكّها.
 *
 * والحلّ معجمٌ مقفَل لا نقلٌ صوتيّ ولا آلةُ ترجمة:
 *
 *   - النقل الصوتيّ يعطي «Baqat Ward» — حروفٌ لاتينية بلا معنى، أسوأ من
 *     العربيّة لأنّها تُوهم القارئ أنّه فهم.
 *   - آلةُ الترجمة تحتاج خدمةً خارجية، وتُرسل أسماء بضاعة التاجر إلى طرفٍ
 *     ثالث، وتُخطئ بثقة. ولا سبيل إلى مراجعة ما ستقوله غدًا.
 *
 * فالمعجم هنا مكتوبٌ بيدٍ ومقروءٌ في مراجعة الشيفرة، وقاعدتُه صارمة:
 *
 *   **لا تُترجَم العبارة إلّا إذا عُرف كلُّ لفظٍ فيها.**
 *
 * لفظٌ واحد مجهول — اسمُ علمٍ، كلمةٌ عاميّة، شيءٌ لم يخطر ببال — يُسقط
 * الترجمة كلَّها فيبقى النصّ كما كُتب. لأنّ نصفَ ترجمةٍ («Bouquet سالم»)
 * أسوأ من لا ترجمة، والاسمُ يجب أن يبقى اسمًا.
 *
 * وما يُشتقّ هنا لا يعلو على ما يكتبه التاجر بيده في حقل «الاسم
 * بالإنجليزية»: هو أعلمُ ببضاعته من معجم.
 */
class Lexicon
{
    /**
     * الأسماء — عربيّةٌ إلى [الاسم، وصورتُه حين يكون وصفًا لغيره].
     *
     * لأنّ الإنجليزية تقدّم الوصف على الموصوف وتُفرد الاسمَ حين يصف:
     * «باقة ورد» ليست «Bouquet roses» بل «Rose bouquet». فلكلّ اسمٍ صورتان:
     * ما يُقال وحده، وما يُقال قبل غيره. والمتساويتان تُكتبان مرّةً.
     *
     * ويُزاد فيه ما شاع في كتالوج محلّ ورد أو متجر تجزئة — لا اسمَ علمٍ
     * ولا عبارةً تخصّ محلًّا واحدًا.
     *
     * @var array<string, string|array{0: string, 1: string}>
     */
    private const NOUNS = [
        // الورد وما حوله
        'ورد' => ['Roses', 'Rose'], 'وردة' => 'Rose', 'ورود' => ['Roses', 'Rose'],
        'زهرة' => 'Flower', 'زهور' => ['Flowers', 'Flower'], 'أزهار' => ['Flowers', 'Flower'],
        'باقة' => 'Bouquet', 'باقات' => 'Bouquets', 'بوكيه' => 'Bouquet', 'بوكيهات' => 'Bouquets',
        'شتلة' => 'Seedling', 'شتلات' => 'Seedlings', 'نبتة' => 'Plant',
        'نباتات' => ['Plants', 'Plant'], 'زرع' => ['Plants', 'Plant'], 'ساق' => 'Stem',
        'جوري' => 'Damask rose', 'توليب' => 'Tulip', 'أوركيد' => 'Orchid', 'أوركيدا' => 'Orchid',
        'قرنفل' => 'Carnation', 'ليلي' => 'Lily', 'ياسمين' => 'Jasmine', 'نرجس' => 'Narcissus',
        'لافندر' => 'Lavender', 'صبار' => 'Cactus', 'أوراق' => 'Foliage', 'ورق' => 'Paper',
        'أغصان' => 'Branches', 'زجاج' => 'Glass',

        // التغليف والإضافات
        'تغليف' => 'Wrapping', 'غلاف' => 'Wrapper', 'شريط' => 'Ribbon', 'أشرطة' => 'Ribbons',
        'بطاقة' => 'Card', 'بطاقات' => 'Cards', 'مزهرية' => 'Vase', 'مزهريات' => 'Vases',
        'سلة' => 'Basket', 'سلال' => 'Baskets', 'صندوق' => 'Box', 'صناديق' => 'Boxes',
        'علبة' => 'Box', 'علب' => 'Boxes', 'كيس' => 'Bag', 'أكياس' => 'Bags',
        'دبّ' => ['Teddy bear', 'Teddy'], 'دب' => ['Teddy bear', 'Teddy'], 'دباديب' => 'Teddy bears',
        'بالون' => 'Balloon', 'بالونات' => 'Balloons', 'شمعة' => 'Candle', 'شموع' => 'Candles',
        'شوكولاتة' => 'Chocolate', 'شوكولا' => 'Chocolate', 'حلوى' => 'Sweets', 'تمر' => 'Dates',
        'عطر' => 'Perfume', 'عطور' => ['Perfumes', 'Perfume'], 'بخور' => 'Incense', 'عود' => 'Oud',
        'هدية' => 'Gift', 'هدايا' => ['Gifts', 'Gift'], 'إهداء' => 'Gift', 'توصيل' => 'Delivery',

        // العناية والمستلزمات
        'سماد' => 'Fertiliser', 'تربة' => 'Soil', 'أصيص' => 'Pot', 'أصص' => 'Pots',
        'ماء' => 'Water', 'رذاذ' => 'Spray', 'مقص' => 'Shears', 'أدوات' => 'Tools',

        // المناسبات
        'ميلاد' => 'Birthday', 'زواج' => 'Wedding', 'خطوبة' => 'Engagement',
        'تخرّج' => 'Graduation', 'تخرج' => 'Graduation', 'مولود' => 'Newborn',
        'تهنئة' => 'Congratulations', 'شكر' => 'Thanks', 'اعتذار' => 'Apology',
        'عزاء' => 'Condolence', 'ذكرى' => 'Anniversary', 'عيد' => 'Eid', 'رمضان' => 'Ramadan',
        'حب' => 'Love', 'حبّ' => 'Love', 'معايدة' => 'Greeting', 'ترحيب' => 'Welcome',
        'شفاء' => 'Get well', 'نجاح' => 'Success', 'افتتاح' => 'Opening', 'وداع' => 'Farewell',

        // التجزئة عمومًا
        'منتج' => 'Product', 'منتجات' => ['Products', 'Product'], 'صنف' => 'Item',
        'أصناف' => ['Items', 'Item'], 'قسم' => 'Category', 'أقسام' => 'Categories',
        'عرض' => 'Offer', 'عروض' => 'Offers', 'خصم' => 'Discount', 'مجموعة' => 'Set',
        'طقم' => 'Set', 'قطعة' => 'Piece', 'حبة' => 'Piece', 'خدمة' => 'Service',
        'خدمات' => ['Services', 'Service'], 'إضافة' => 'Add-on', 'إضافات' => ['Add-ons', 'Add-on'],
        'مستلزمات' => 'Supplies',
    ];

    /**
     * الأوصاف — تتقدّم في الإنجليزية على ما تصفه.
     *
     * @var array<string, string>
     */
    private const ADJECTIVES = [
        // المقاسات والأوصاف
        'صغير' => 'Small', 'صغيرة' => 'Small', 'وسط' => 'Medium', 'متوسط' => 'Medium',
        'متوسطة' => 'Medium', 'كبير' => 'Large', 'كبيرة' => 'Large', 'ضخم' => 'Extra large',
        'فاخر' => 'Premium', 'فاخرة' => 'Premium', 'مميز' => 'Special', 'مميّز' => 'Special',
        'عادي' => 'Standard', 'عادية' => 'Standard', 'خاص' => 'Special', 'خاصة' => 'Special',
        'خاصّة' => 'Special', 'مفرد' => 'Single', 'مفردة' => 'Single', 'مزدوج' => 'Double',
        'طويل' => 'Tall', 'قصير' => 'Short', 'جديد' => 'New', 'جديدة' => 'New',
        'وطني' => 'National', 'متنوعة' => 'Assorted', 'مشكّل' => 'Assorted', 'مشكل' => 'Assorted',

        // الألوان
        'أحمر' => 'Red', 'حمراء' => 'Red', 'أبيض' => 'White', 'بيضاء' => 'White',
        'أصفر' => 'Yellow', 'صفراء' => 'Yellow', 'وردي' => 'Pink', 'ورديّة' => 'Pink',
        'أزرق' => 'Blue', 'زرقاء' => 'Blue', 'أسود' => 'Black', 'سوداء' => 'Black',
        'أخضر' => 'Green', 'خضراء' => 'Green', 'بنفسجي' => 'Purple', 'ذهبي' => 'Gold',
        'ذهبية' => 'Gold', 'فضي' => 'Silver', 'فضية' => 'Silver', 'برتقالي' => 'Orange',
        'ملون' => 'Multicoloured', 'ملوّن' => 'Multicoloured', 'ملونة' => 'Multicoloured',
        'مشكّلة' => 'Assorted', 'مشكلة' => 'Assorted', 'متنوّع' => 'Assorted', 'متنوع' => 'Assorted',
        'فخم' => 'Luxury', 'فخمة' => 'Luxury', 'طبيعي' => 'Natural', 'طبيعية' => 'Natural',
        'صناعي' => 'Artificial', 'صناعية' => 'Artificial', 'يدوي' => 'Handmade', 'يدوية' => 'Handmade',
    ];

    /** حروف الوصل — تقسم العبارة ولا تُعدّ لفظًا مجهولًا */
    private const JOINERS = ['و' => 'and', 'مع' => 'with', 'أو' => 'or'];

    /** أدوات تُلغى في الإنجليزية: «باقة من ورد» = «Rose bouquet» */
    private const DROPPED = ['من', 'في', 'ال'];

    /**
     * ترجمةُ عبارةٍ عربية — أو null إن كان فيها لفظٌ لا يعرفه المعجم.
     *
     * و`null` ليست فشلًا: هي القرار الصحيح لاسمِ محلٍّ أو كلمةٍ عاميّة —
     * يبقى النصّ كما كُتب، ويُقرأ بلغته.
     */
    public static function translate(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '' || ! NameTransliterator::isArabic($text)) {
            return null;
        }

        $words = preg_split('/\s+/u', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return null;
        }

        /*
         * العبارة تُقسَم عند حرف الوصل: «ورد وشوكولاتة» جملتان لا جملة،
         * وترتيبُ كلٍّ منهما يُحسب وحده ثم تُوصلان بـ«and».
         */
        $segments = [[]];
        $known = 0;

        foreach ($words as $word) {
            $bare = self::strip($word);

            if (in_array($bare, self::DROPPED, true)) {
                continue;
            }

            if (isset(self::JOINERS[$bare])) {
                $segments[] = [];

                continue;
            }

            // «وشوكولاتة»: الواو تُلصَق بما بعدها في الكتابة العربية —
            // وتُجرَّب بعد اللفظ كاملًا لا قبله، وإلّا صار «ورد» واوًا وردًّا
            $piece = self::classify($bare);
            if ($piece === null && mb_strlen($bare, 'UTF-8') > 2 && str_starts_with($bare, 'و')) {
                $piece = self::classify(self::strip(mb_substr($bare, 1, null, 'UTF-8')));
                if ($piece !== null) {
                    $segments[] = [];
                }
            }

            if ($piece === null) {
                return null;   // لفظٌ مجهول — والعبارة كلّها تسقط
            }

            $segments[count($segments) - 1][] = $piece;
            $known++;
        }

        if ($known === 0) {
            return null;
        }

        $out = [];
        foreach ($segments as $segment) {
            $phrase = self::order($segment);
            if ($phrase !== '') {
                $out[] = $phrase;
            }
        }

        // أوّلُ حرفٍ كبير وحده: العبارة عنوانُ صنفٍ لا جملة
        return ucfirst(mb_strtolower(implode(' and ', $out), 'UTF-8'));
    }

    /**
     * ترتيب الإنجليزية: الأوصافُ أوّلًا، ثمّ الأسماء والرأسُ في آخرها.
     *
     * «باقة ورد أحمر» → أوصاف [Red] وأسماء [Bouquet, Rose] → «Red rose
     * bouquet». والرأس في العربية أوّلُ الأسماء وفي الإنجليزية آخرُها،
     * فتُقلَب — وما قبله يُقال بصورته الوصفيّة المفردة.
     *
     * @param  list<array{kind: string, head: string, attr: string}>  $pieces
     */
    private static function order(array $pieces): string
    {
        $adjectives = [];
        $nouns = [];

        foreach ($pieces as $piece) {
            if ($piece['kind'] === 'adj') {
                $adjectives[] = $piece['head'];
            } else {
                $nouns[] = $piece;
            }
        }

        $nouns = array_reverse($nouns);
        $words = $adjectives;

        foreach ($nouns as $i => $noun) {
            // الأخيرُ رأسُ العبارة فيبقى بصورته، وما قبله يصف فيُفرَد
            $words[] = $i === count($nouns) - 1 ? $noun['head'] : $noun['attr'];
        }

        return implode(' ', $words);
    }

    /**
     * ما هذا اللفظ؟ اسمٌ بصورتيه، أو وصف، أو لا شيء.
     *
     * @return array{kind: string, head: string, attr: string}|null
     */
    private static function classify(string $bare): ?array
    {
        if (isset(self::ADJECTIVES[$bare])) {
            return ['kind' => 'adj', 'head' => self::ADJECTIVES[$bare], 'attr' => self::ADJECTIVES[$bare]];
        }

        if (isset(self::NOUNS[$bare])) {
            $entry = self::NOUNS[$bare];
            $head = is_array($entry) ? $entry[0] : $entry;
            $attr = is_array($entry) ? $entry[1] : $entry;

            return ['kind' => 'noun', 'head' => $head, 'attr' => $attr];
        }

        return null;
    }

    /** يُسقط التشكيل والتطويل ويوحّد الهمزات كي لا يُفوَّت لفظٌ لشكلة */
    private static function normalize(string $text): string
    {
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{0652}\x{0640}]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * «الورد» و«ورد» لفظٌ واحد — والمعجم يحفظه مرّةً.
     *
     * والتشذيب بتعبيرٍ يعرف الحروف لا بـ`trim` تعرف البايتات: قائمةُ
     * `trim` تُقرأ بايتًا بايتًا، وفيها «،» و«»» متعدّدةُ البايتات — فكان
     * أوّلُ بايتٍ من «باقة» (وهو نفسه أوّلُ بايتٍ من «،») يُقصّ، فيصير
     * اللفظ مشوّهًا ولا يُعرف. ولا يظهر ذلك إلا في الألفاظ التي تبدأ به.
     */
    private static function strip(string $word): string
    {
        $word = preg_replace('/^[\p{P}\p{Z}]+|[\p{P}\p{Z}]+$/u', '', $word) ?? $word;

        return mb_strlen($word, 'UTF-8') > 3 && str_starts_with($word, 'ال')
            ? mb_substr($word, 2, null, 'UTF-8')
            : $word;
    }

    /**
     * يملأ `name_en` من المعجم إن تُرك فارغًا.
     *
     * وما يكتبه التاجر بيده يبقى كما كتبه: هو أعلمُ ببضاعته من معجم، ومن
     * سمّى باقتَه «Signature» لا يريدها «Bouquet».
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fill(array $data, string $source = 'name', string $target = 'name_en'): array
    {
        if (trim((string) ($data[$target] ?? '')) !== '') {
            return $data;
        }

        $english = self::translate($data[$source] ?? null);
        if ($english !== null) {
            $data[$target] = $english;
        }

        return $data;
    }
}
