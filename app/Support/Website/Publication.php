<?php

namespace App\Support\Website;

use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\MarketingSettings;

/**
 * عقدُ النشر: ما الذي يُجمَّد، وبأيّ شكلٍ يُقرأ بعد سنة.
 *
 * كان النشر تجميعَ مصفوفةٍ في `Publisher::snapshot` بلا عقدٍ يُسمّى: مفاتيحُها
 * ما تصادف أن كُتب يومَها، ورقمُ نسختها `1` لا يقرؤه شيءٌ ولا يُرقّى. وثمنُ
 * ذلك يُدفع مرّةً واحدةً وبعد سنة: يومَ يتبدّل شكلُ قسمٍ أو يُعاد تسميةُ
 * حقل، تصير كلُّ نشرةٍ قديمة مستندًا لا يفهمه القارئ — والمنشورُ منها يعمل
 * الآن على نطاقات تجّار.
 *
 * فهذا الملفّ ثلاثةٌ في واحد:
 *
 * ١) **المُجمِّع** (`compile`): يقرأ المسوّدة ويردّ المستند كاملًا بمفاتيح
 *    العقد كلِّها. وما لا يعرفه الكتالوج لا يدخل: قسمٌ من نسخةٍ قديمة أو من
 *    وجهةٍ لا تصلح له يسقط هنا لا في العارض — فلا يصل الزائرَ ما لا يُرسم.
 *
 * ٢) **الحارس** (`problems`): ما يمنع النشر يُقال قبله لا بعده. ونشرةٌ
 *    فارغة أسوأ من غياب النشر: النطاق يفتح على لا شيء فيبدو المتجر معطوبًا.
 *
 * ٣) **المُرقّي** (`upgrade`): كلُّ مستندٍ يُقرأ يمرّ به أوّلًا. ونسخةُ
 *    الأمس تخرج منه بشكل اليوم، فالقارئُ واحدٌ لا يعرف إلا الشكل الأخير.
 *    وهذا هو الفرقُ بين «رقمُ نسخةٍ مكتوب» و«عقدٌ له نسخة».
 *
 * ═══ وما لا يُجمَّد ═══
 *
 * السعرُ والمخزونُ والمنتجاتُ النشطة والعملةُ والشعارُ والهاتف: هذه حالُ
 * النشاط الآن لا تصميمُ موقعه. تُقرأ عند العرض (انظر `Preview`)، فتاجرٌ
 * يبدّل سعرًا لا يُطالَب بنشر موقعه من جديد. والمُجمَّد هنا يصف **ماذا
 * يُعرض**، والكتالوجُ يقول **ما هو الآن**.
 */
final class Publication
{
    /**
     * نسخةُ العقد.
     *
     * ١ — الشكل الأوّل: بلا `commerce` وبلا `schema_version`.
     * ٢ — أُضيف `commerce` (عرضُ الأسعار وقبولُ الطلب) و`schema_version`.
     */
    public const SCHEMA = 2;

    /** ما يضمنه العقد لقارئه — كلُّها موجودةٌ في كلّ مستند */
    public const CONTRACT = [
        'schema_version', 'name', 'goal', 'template', 'theme', 'tokens',
        'seo', 'commerce', 'maintenance', 'maintenance_message', 'globals', 'pages',
    ];

    /* ────────────────────────────── المُجمِّع ────────────────────────────── */

    /**
     * المسوّدةُ تصير مستندًا — هذا ما يُكتب في `website_versions.payload`.
     *
     * @return array<string, mixed>
     */
    public static function compile(Website $website): array
    {
        $website->loadMissing(['pages.sections', 'sections']);

        $goal = $website->goal();

        return [
            'schema_version' => self::SCHEMA,
            /*
             * و`version` تبقى بجانبها نسخةً مهجورة.
             *
             * كانت اسمَ نسخةِ الشكل قبل أن يُسمّى، ويقرؤها عارضٌ منشورٌ
             * الآن. وحذفُها اليوم يكسر ما يعمل؛ فتُكتب بالقيمة نفسها حتى
             * تُرفع في نسخةٍ تالية.
             */
            'version' => self::SCHEMA,
            'name' => $website->name,
            'goal' => $goal,
            'template' => Templates::key($website->template),
            'theme' => $website->theme,
            'tokens' => $website->tokens(),
            'seo' => self::seo($website),
            'commerce' => self::commerce($website),
            'maintenance' => (bool) $website->maintenance,
            'maintenance_message' => $website->maintenance_message,
            'globals' => $website->sections->whereNotNull('slot')->sortBy('slot')
                ->map(fn ($s) => [
                    'slot' => $s->slot,
                    'type' => $s->type,
                    'visible' => (bool) $s->visible,
                    'data' => $s->data,
                ])->values()->all(),
            'pages' => $website->pages->map(fn ($page) => [
                'key' => $page->key,
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status,
                'is_home' => (bool) $page->is_home,
                'removable' => (bool) $page->removable,
                'seo' => $page->seo,
                'sections' => $page->sections
                    /*
                     * وما لا يعرفه الكتالوج لا يدخل النشرة.
                     *
                     * قسمٌ بقي في القاعدة من نسخةٍ رُفع فيها نوعُه، أو قسمٌ
                     * لا يصلح للوجهة بعد أن بدّلها التاجر: يبقى في مسوّدته
                     * ولا يخرج إلى زائر. والعارض لا يعرف كيف يرسمه أصلًا،
                     * فإسقاطُه هنا يجعل ما يصله كلُّه مرسومًا.
                     */
                    ->filter(fn ($s) => self::carries($s->type, $goal))
                    ->map(fn ($s) => [
                        'type' => $s->type,
                        'visible' => (bool) $s->visible,
                        // مصدرُ محتواه إن كان يقرأ من النظام — يقرؤه العارض ليصله
                        'source' => Sections::source($s->type),
                        'data' => $s->data,
                    ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** أيدخل هذا النوعُ نشرةَ موقعٍ وجهتُه كذا؟ */
    public static function carries(string $type, string $goal): bool
    {
        return Sections::exists($type)
            && ! Sections::isSlot($type)
            && Blueprints::sectionFits($type, $goal);
    }

    /**
     * السيو كما يُجمَّد — والفهرسةُ منه.
     *
     * و`index` تُحفظ في الشاشة منذ بُنيت ولا يقرؤها شيء: التاجر يُطفئ
     * «السماح لمحرّكات البحث» فيرى «حُفظ» ويبقى موقعُه مفهرسًا. فتدخل
     * العقدَ هنا، ويكتبها الرأسُ `noindex` هناك.
     *
     * @return array<string, mixed>
     */
    private static function seo(Website $website): array
    {
        $seo = is_array($website->seo) ? $website->seo : [];

        return [
            'title' => (string) ($seo['title'] ?? ''),
            'description' => (string) ($seo['description'] ?? ''),
            'image' => (string) ($seo['image'] ?? ''),
            // الغياب إذنٌ بالفهرسة: موقعٌ نُشر قبل وجود المفتاح لا يُخفى فجأةً
            'index' => (bool) ($seo['index'] ?? true),
        ];
    }

    /**
     * ما يستطيع الزائر أن يراه وأن يفعله — مُجمَّدًا مع التصميم.
     *
     * وهو تصميمٌ لا حالُ نشاط: «أخفِ الأسعار» قرارُ عرضٍ كتبديل لونٍ أو
     * إخفاء قسم، فيتبع النشر كما يتبعه سائرُ التصميم. ولذلك تختم شاشتُه
     * بـ`touchDraft` فتقول اللوحة «فيه تغييرات لم تُنشر».
     *
     * ═══ و«قبول الطلب» يعني ما يقع فعلًا ═══
     *
     * لا سلّةَ في هذا العارض ولا دفع: الطلبُ محادثةُ واتساب تُفتح باسم
     * المنتج وسعره. فالمفتاح يقول أيظهر ذلك الزرّ أم لا — لا أكثر.
     *
     * وثلاثُ وجهاتٍ ثلاثةُ أجوبة:
     *
     * · «تعريفيّ» لا كتالوج له، فلا سعرَ ولا طلب.
     * · «كتالوج» تعريفُه أن يُطلب منه على واتساب — فالطلب فيه من الوجهة لا
     *   من مفتاح، ولذلك لا يُسأل عنه صاحبُه أصلًا في الشاشة.
     * · «متجر» يُسأل صاحبُه، فيُقرأ جوابُه.
     *
     * وسعرٌ مخفيٌّ لا يُطلب معه في الوجهات كلّها: زبونٌ يطلب ما لا يعرف
     * ثمنه يصل إلى المحادثة ثمّ يفاجأ.
     *
     * @return array{show_prices: bool, allow_orders: bool}
     */
    public static function commerce(Website $website): array
    {
        return self::commerceFor($website->goal(), (int) $website->business_id);
    }

    /**
     * والجوابُ نفسُه لموقعٍ لم يُكتب بعد.
     *
     * شاشةُ الإنشاء ترسم للتاجر متجرَه قبل أن يُنشأ (`Builder::proposal`)،
     * وفيها سؤالُ «أيظهر السعر؟ وأيظهر زرُّ الطلب؟» قائمٌ كما هو. ولو نُسخ
     * الجوابُ هناك لافترق عن هذا عند أوّل تعديلٍ في القاعدة — فيرى التاجر
     * أسعارًا في المعاينة ولا يراها في موقعه.
     *
     * @return array{show_prices: bool, allow_orders: bool}
     */
    public static function commerceFor(string $goal, int $businessId): array
    {
        $goal = Blueprints::goal($goal);
        $marketing = MarketingSettings::group($businessId, 'website');

        $showPrices = Blueprints::hasCatalogue($goal)
            && ($marketing['store_show_prices'] ?? '1') === '1';

        return [
            'show_prices' => $showPrices,
            'allow_orders' => match ($goal) {
                Blueprints::STORE => $showPrices && ($marketing['store_allow_orders'] ?? '1') === '1',
                Blueprints::CATALOG => $showPrices,
                default => false,
            },
        ];
    }

    /* ────────────────────────────── الحارس ────────────────────────────── */

    /**
     * ما يمنع النشر — يُقال قبله لا بعده.
     *
     * @return list<string>
     */
    public static function problems(Website $website): array
    {
        $out = [];

        if (! $website->pages()->where('status', WebsitePage::PUBLISHED)->exists()) {
            $out[] = __('لا صفحة منشورة في موقعك — انشر صفحةً واحدة على الأقل');
        }

        /*
         * ولا نشرةَ بلا رئيسية.
         *
         * الرئيسية ما يُفتح حين يُكتب العنوان وحده. وموقعٌ بلا صفحةٍ أولى
         * نطاقٌ يردّ بلا شيء — والزائر يقرؤها عطبًا في المتجر لا نقصًا في
         * الإعداد.
         */
        if (! $website->pages()->where('is_home', true)->exists()) {
            $out[] = __('موقعك بلا صفحةٍ رئيسية — اجعل إحدى صفحاتك رئيسيةً قبل النشر');
        }

        return $out;
    }

    /* ────────────────────────────── المُرقّي ────────────────────────────── */

    /**
     * مستندٌ بأيّ نسخةٍ كُتب يخرج بشكل اليوم.
     *
     * ويُنادى في كلّ قراءة — في العرض وفي المعاينة وفي الاستعادة — لا في
     * هجرةٍ تكتب على الصفوف. والفرق أنّ الهجرة تُنفَّذ مرّةً وتُخطئ مرّةً في
     * صفٍّ لا يُنتبه له، وهذا يُنفَّذ على كلّ قراءةٍ فيصحّ كلَّ مرّة. ونشراتُ
     * التاجر تبقى كما نُشرت — تاريخُه لا يُعاد كتابتُه من تحته.
     *
     * ولا يرمي أبدًا: هذا المسار يخدم زائرًا على نطاق تاجر. مستندٌ ناقصٌ
     * يخرج ناقصًا ويُرسم ما فيه، ولا يردّ صفحةَ خطأ على زبونٍ يريد أن يشتري.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public static function upgrade(array $doc): array
    {
        $from = (int) ($doc['schema_version'] ?? 1);

        $goal = Blueprints::goal(is_string($doc['goal'] ?? null) ? $doc['goal'] : null);

        if ($from < 2) {
            /*
             * ١ ← ٢: لم يكن للمستند بابُ تجارة.
             *
             * والقيمُ هنا ليست الافتراضيّ الجديد — هي **ما كان يراه زائرُ
             * تلك النشرة**: سعرٌ ظاهر، وزرُّ طلبٍ في المتجر والكتالوج. فلو
             * كُتب الافتراضيّ لَتبدّلت مواقعُ منشورةٌ بلا أن ينشرها أحد.
             */
            $doc['commerce'] = [
                'show_prices' => true,
                'allow_orders' => Blueprints::hasCatalogue($goal),
            ];
        }

        return self::sound($doc, $goal);
    }

    /**
     * مستندٌ لا ينقصه مفتاحٌ يقرؤه قارئ.
     *
     * والغائبُ يُملأ بما لا يكذب: قائمةٌ فارغة لا `null` يكسر حلقةً، ونصٌّ
     * فارغ لا مفتاحٌ غائب يصير خطأً في العرض.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private static function sound(array $doc, string $goal): array
    {
        $doc['schema_version'] = self::SCHEMA;
        $doc['version'] = self::SCHEMA;
        $doc['goal'] = $goal;
        $doc['name'] = (string) ($doc['name'] ?? '');
        $doc['template'] = Templates::key(is_string($doc['template'] ?? null) ? $doc['template'] : null);
        $doc['theme'] = is_array($doc['theme'] ?? null) ? $doc['theme'] : [];
        $doc['tokens'] = is_array($doc['tokens'] ?? null) ? $doc['tokens'] : Theme::tokens($doc['theme']);
        $doc['seo'] = is_array($doc['seo'] ?? null) ? $doc['seo'] : [];
        $doc['seo'] += ['title' => '', 'description' => '', 'image' => '', 'index' => true];
        $doc['commerce'] = is_array($doc['commerce'] ?? null) ? $doc['commerce'] : [];
        $doc['commerce'] += ['show_prices' => Blueprints::hasCatalogue($goal), 'allow_orders' => false];
        $doc['maintenance'] = (bool) ($doc['maintenance'] ?? false);
        $doc['maintenance_message'] = $doc['maintenance_message'] ?? null;
        $doc['globals'] = self::rows($doc['globals'] ?? null);
        $doc['pages'] = array_map(static function ($page) {
            $page = is_array($page) ? $page : [];
            $page['sections'] = self::rows($page['sections'] ?? null);
            $page['status'] = (string) ($page['status'] ?? WebsitePage::PUBLISHED);
            $page['slug'] = (string) ($page['slug'] ?? '/');
            $page['title'] = (string) ($page['title'] ?? '');
            $page['is_home'] = (bool) ($page['is_home'] ?? false);

            return $page;
        }, self::rows($doc['pages'] ?? null));

        return $doc;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
