<?php

namespace App\Support\Website;

use App\Models\Business;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use Illuminate\Support\Facades\DB;

/**
 * يبني الموقع كاملًا من جوابين — «ماذا تريد؟» و«أيّ شكلٍ يعجبك؟».
 *
 * لا صفحةَ بيضاء ولا زرَّ «أضف قسمًا» في الوجه. الوجهةُ تحدّد الصفحات
 * وأقسامها (`Blueprints`)، والقالبُ يحدّد الألوان (`Templates`)، وبياناتُ
 * التاجر تملأ ما في الأقسام (`MerchantData`). فيخرج من المعالج موقعٌ يصلح
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
                'data' => MerchantData::seed($slot, $identity, $website->goal),
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
            if (($source = Sections::source($type)) && ! ($available[$source] ?? true)) {
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
                'data' => MerchantData::seed($type, $identity, $website->goal),
            ]);
        }
    }

    /**
     * قائمةُ الترويسة والتذييل تتبع الصفحات — وتبقي ما زاده التاجر.
     *
     * وثلاث قواعد تحكمها، وكلٌّ منها تمنع عطبًا رآه من يستعمل هذه الشاشات:
     *
     * ١) صفحةٌ منشورة لها رابطٌ في القائمة. من ينشئ صفحةً ولا يجدها في قائمة
     *    موقعه يظنّ أنّها لم تُحفظ.
     * ٢) رابطٌ داخليّ إلى صفحةٍ لم تعد موجودة يسقط. حذفُ الصفحة وإبقاءُ
     *    رابطها يعني قائمةً تقود الزائر إلى «غير موجود».
     * ٣) الرابط الخارجيّ يبقى كما هو. التاجر يضع رابط حسابه أو رقمه، وليس
     *    للنظام أن يمحوه لأنّه لا يعرفه.
     */
    public static function syncMenu(Website $website): void
    {
        $pages = $website->pages()->where('status', WebsitePage::PUBLISHED)
            ->orderBy('position')->get();

        $links = $pages->map(fn ($p) => ['label' => $p->title, 'href' => $p->slug]);

        foreach (Sections::SLOTS as $slot) {
            $section = $website->slot($slot);

            if (! $section) {
                continue;
            }

            $custom = collect($section->data['links'] ?? [])
                ->reject(fn ($l) => str_starts_with((string) ($l['href'] ?? ''), '/'))
                ->values();

            $data = $section->data;
            $data['links'] = $links->concat($custom)->take(Content::MAX_ITEMS)->values()->all();

            $section->update(['data' => Content::clean($section->type, $data, $website->goal)]);
        }
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
