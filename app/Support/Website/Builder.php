<?php

namespace App\Support\Website;

use App\Models\Business;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use Illuminate\Support\Facades\DB;

/**
 * يبني الموقع كاملًا من جوابٍ واحد — «أيّ شكلٍ يعجبك؟».
 *
 * لا صفحةَ بيضاء ولا زرَّ «أضف قسمًا» في الوجه. الوجهةُ تحدّد الصفحات
 * وأقسامها (`Blueprints`)، والقالبُ يحدّد الألوان (`Templates`)، وبياناتُ
 * التاجر تملأ ما في الأقسام (`MerchantData`). فيخرج من الاختيار موقعٌ يصلح
 * للنشر كما هو، ويعدّل التاجر ما يريد على شيءٍ قائم.
 *
 * والبناء كلّه في معاملةٍ واحدة: موقعٌ بصفحتين من أربع، أو صفحةٌ بلا أقسام،
 * أسوأ من لا شيء — لأنّ التاجر يظنّه ما بناه النظام له فيحكم عليه.
 */
class Builder
{
    /**
     * موقعٌ جديد لنشاطٍ لا موقع له.
     *
     * ولا يُبنى ثانٍ: العمود فريد، والفحص هنا يجعل الرسالة مفهومة بدل خطأ
     * قاعدة بيانات.
     */
    public static function create(
        Business $business,
        string $goal,
        string $template,
        ?int $userId = null,
        array $overrides = [],
    ): Website {
        if ($existing = Website::where('business_id', $business->id)->first()) {
            return $existing;
        }

        $goal = Blueprints::goal($goal);
        $template = Templates::key($template);

        return DB::transaction(function () use ($business, $goal, $template, $userId, $overrides) {
            $identity = array_merge(MerchantData::identity($business->id), array_filter(
                $overrides,
                fn ($v) => is_string($v) && trim($v) !== '',
            ));

            $website = Website::create([
                'business_id' => $business->id,
                'name' => $identity['name'] !== '' ? $identity['name'] : $business->name,
                'goal' => $goal,
                'template' => $template,
                'theme' => Templates::theme($template),
                'seo' => self::seo($identity),
                'created_by' => $userId,
                'draft_saved_at' => now(),
            ]);

            self::buildGlobals($website, $identity);
            self::buildPages($website, $identity);
            self::syncMenu($website);

            /*
             * وعناوينُ النشاط تعرف موقعَها.
             *
             * الاسمُ يُحجز قبل أن يُبنى الموقع غالبًا، فصفُّ عنوانه بلا
             * `website_id`. وهنا يُوصَل — فمن يسأل «أيُّ موقعٍ على هذا
             * العنوان» يجد الجواب في الصفّ لا في استعلامٍ ثانٍ.
             */
            Domains::sync($business);

            return $website->fresh(['pages.sections']);
        });
    }

    /** الترويسة والتذييل — قسمان عامّان يُبنيان مرّةً مع الموقع */
    private static function buildGlobals(Website $website, array $identity): void
    {
        foreach (Sections::SLOTS as $slot) {
            WebsiteSection::create([
                'website_id' => $website->id,
                'business_id' => $website->business_id,
                'page_id' => null,
                'slot' => $slot,
                'type' => $slot,
                'position' => 0,
                'visible' => true,
                'data' => Templates::apply(
                    $website->template,
                    $slot,
                    MerchantData::seed($slot, $identity, $website->goal),
                ),
            ]);
        }
    }

    private static function buildPages(Website $website, array $identity): void
    {
        foreach (Blueprints::pages($website->goal) as $i => $spec) {
            $page = WebsitePage::create([
                'website_id' => $website->id,
                'business_id' => $website->business_id,
                'key' => $spec['key'],
                'title' => __($spec['title']),
                'slug' => WebsitePage::normalizeSlug($spec['slug']),
                // كلّ ما يُبنى منشور: موقعٌ صفحاتُه مسوّدات موقعٌ لا يُفتح منه شيء
                'status' => WebsitePage::PUBLISHED,
                'is_home' => (bool) ($spec['home'] ?? false),
                'removable' => (bool) ($spec['removable'] ?? true),
                'position' => $i,
                'seo' => ['title' => '', 'description' => '', 'image' => ''],
            ]);

            self::addSections($page, $spec['sections'], $identity);
        }
    }

    /**
     * أقسام صفحةٍ بترتيبها.
     *
     * والقسم الذي لا يجد ما يعرضه يسقط: «الأكثر مبيعًا» في متجرٍ لم يبع بعد
     * شريطٌ فارغ، و«آراء العملاء» بلا تقييمات كذلك. ولا يُبنى ما يُقبّح الموقع
     * في يومه الأول.
     *
     * @param  array<int, string>  $types
     */
    public static function addSections(WebsitePage $page, array $types, array $identity): void
    {
        $website = $page->website;
        $available = MerchantData::available($page->business_id);
        $position = (int) $page->sections()->max('position');

        foreach ($types as $type) {
            if (! Sections::exists($type) || Sections::isSlot($type)) {
                continue;
            }
            if (! Blueprints::sectionFits($type, $website->goal)) {
                continue;
            }
            if (($needs = Sections::requires($type)) && ! ($available[$needs] ?? true)) {
                continue;
            }

            WebsiteSection::create([
                'website_id' => $website->id,
                'business_id' => $page->business_id,
                'page_id' => $page->id,
                'slot' => null,
                'type' => $type,
                'position' => ++$position,
                'visible' => true,
                // والقالبُ يختار هيئةَ القسم كما يختار لونَه — انظر `Templates::apply`
                'data' => Templates::apply(
                    $website->template,
                    $type,
                    MerchantData::seed($type, $identity, $website->goal),
                ),
            ]);
        }
    }

    /**
     * قائمةُ الترويسة والتذييل — والمنطقُ في `Nav`.
     *
     * ويبقى هذا الاسم لأنّ شاشة الصفحات تناديه من ثلاثة مواضع، وهو سطرٌ
     * واحد لا منطقَ فيه.
     */
    public static function syncMenu(Website $website): void
    {
        Nav::sync($website);
    }

    /**
     * موقعٌ مقترَح لم يُكتب منه صفٌّ واحد — ليراه التاجر قبل أن يختار.
     *
     * شاشةُ الإنشاء تعرض ثلاثة قوالب، ولا تعرضها مربّعاتِ ألوان: تعرض متجرَ
     * التاجر نفسَه مرسومًا بكلٍّ منها — باسمه وشعاره ومنتجاته وأسعارها. وهو
     * الفرق بين «اختر لونًا» و«اختر متجرك»: الأوّل قرارٌ لا يملك التاجر
     * أساسًا للمفاضلة فيه، والثاني يُتّخذ في ثانيةٍ بلمحة عين.
     *
     * وما يُبنى هنا نسخةٌ من الحقيقة لا تقريبٌ لها: الشكلُ شكلُ العقد
     * (`Publication::CONTRACT`)، والصفحاتُ صفحاتُ `Blueprints`، ومحتوى
     * أقسامها من `MerchantData` وهيئتُها من `Templates` — أي ما سيُكتب في
     * القاعدة بالحرف لو ضغط «استخدم هذا القالب». فلا يرى شيئًا ثمّ يُنشأ له
     * غيرُه.
     *
     * ولا يُكتب منه شيء: من يقلّب في ثلاثة قوالب لا يترك خلفه ثلاثة مواقع.
     *
     * @return array<string, mixed>
     */
    public static function proposal(Business $business, string $goal, string $template): array
    {
        $goal = Blueprints::goal($goal);
        $template = Templates::key($template);
        $bid = (int) $business->id;

        $identity = MerchantData::identity($bid);
        $available = MerchantData::available($bid);
        $name = $identity['name'] !== '' ? $identity['name'] : (string) $business->name;

        $pages = collect(Blueprints::pages($goal));

        /*
         * وقائمةُ الترويسة تُبنى من الصفحات كما تُبنى بعد الإنشاء.
         *
         * `Nav::sync` تقرأ صفوفًا في القاعدة ولا صفَّ هنا، فتُبنى الروابطُ
         * من الوصف نفسه الذي ستُبنى منه الصفحات. وترويسةٌ بلا قائمة في
         * المعاينة تجعل القوالب الثلاثة تبدو أقربَ ممّا هي.
         */
        $links = $pages->map(fn ($spec) => [
            'label' => __($spec['title']),
            'href' => WebsitePage::normalizeSlug($spec['slug']),
            'type' => Nav::PAGE,
            'page_id' => 0,
        ])->all();

        $seed = fn (string $type) => Templates::apply(
            $template,
            $type,
            MerchantData::seed($type, $identity, $goal),
        );

        return [
            'schema_version' => Publication::SCHEMA,
            'version' => Publication::SCHEMA,
            'name' => $name,
            'goal' => $goal,
            'template' => $template,
            'theme' => Templates::theme($template),
            'tokens' => Theme::tokens(Templates::theme($template)),
            'seo' => self::seo($identity),
            'commerce' => Publication::commerceFor($goal, $bid),
            'maintenance' => false,
            'maintenance_message' => null,
            'globals' => collect(Sections::SLOTS)->map(fn ($slot) => [
                'slot' => $slot,
                'type' => $slot,
                'visible' => true,
                'data' => array_merge($seed($slot), ['links' => $links]),
            ])->all(),
            'pages' => $pages->map(fn ($spec) => [
                'key' => $spec['key'],
                'title' => __($spec['title']),
                'slug' => WebsitePage::normalizeSlug($spec['slug']),
                'status' => WebsitePage::PUBLISHED,
                'is_home' => (bool) ($spec['home'] ?? false),
                'removable' => (bool) ($spec['removable'] ?? true),
                'seo' => null,
                'sections' => collect($spec['sections'])
                    ->filter(fn ($type) => Publication::carries($type, $goal))
                    // والقسمُ الذي لا يجد ما يعرضه يسقط هنا كما يسقط في البناء
                    ->filter(function ($type) use ($available) {
                        $needs = Sections::requires($type);

                        return ! $needs || ($available[$needs] ?? true);
                    })
                    ->map(fn ($type) => [
                        'type' => $type,
                        'visible' => true,
                        'source' => Sections::source($type),
                        'data' => $seed($type),
                    ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** سيو الموقع الأوّل — عنوانٌ ووصفٌ من بيانات النشاط لا من فراغ */
    private static function seo(array $identity): array
    {
        $name = $identity['name'] !== '' ? $identity['name'] : __('متجري');

        return [
            'title' => $name,
            'description' => mb_substr($identity['tagline'] ?: $identity['about'] ?: __('تسوّق من :name.', ['name' => $name]), 0, 160),
            'image' => $identity['logo'],
            'index' => true,
        ];
    }
}
