<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Support\Website\Blueprints;
use App\Support\Website\Content;
use App\Support\Website\MerchantData;
use App\Support\Website\Preview;
use App\Support\Website\Sections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * المحرّر: لوحةٌ إلى جانب الموقع نفسه.
 *
 * التاجر لا يتخيّل النتيجة — يراها. يضغط قسمًا فتظهر حقولُه وحدها، ويكتب
 * فيرى، ويرتّب فيرى. ولا يُعرض له في المرّة الواحدة إلا ما يخصّ القسم الذي
 * فتحه: عشرون قسمًا بحقولها معًا شاشةٌ لا تُقرأ.
 *
 * وكلّ فعلٍ هنا يُختم بـ`touchDraft`: منه تُعرف «تم الحفظ ✓» ومنه يُعرف أنّ
 * في المسوّدة ما لم يُنشر. ونسيانُه في فعلٍ واحد يعني تاجرًا ينشر ولا يخرج
 * تعديلُه — وهو أسوأ من عطبٍ ظاهر، لأنّه يبدو نجاحًا.
 */
class EditorController extends Controller
{
    use Concerns;

    public function show(Request $request, $pageId = null): Response
    {
        $site = $this->siteOrFail();
        $page = $pageId ? $this->page($site, $pageId) : $site->homePage();

        abort_if(! $page, 404);

        $site->load(['pages.sections', 'sections']);

        return Inertia::render('Admin/Website/Editor', $this->shell($site) + [
            'pages' => $site->pages->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'status' => $p->status,
                'is_home' => $p->is_home,
                'current' => $p->id === $page->id,
            ])->all(),
            'page' => [
                'id' => $page->id,
                'key' => $page->key,
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status,
                'is_home' => $page->is_home,
                'removable' => $page->removable,
                'seo' => $page->seo ?? ['title' => '', 'description' => '', 'image' => ''],
            ],
            'sections' => $page->sections->map(fn ($s) => $this->sectionPayload($s, $site))->values()->all(),
            // الترويسة والتذييل يُحرَّران من كلّ صفحة: هما في كلّ صفحة
            'globals' => $site->sections->whereNotNull('slot')->sortBy('slot')
                ->map(fn ($s) => $this->sectionPayload($s, $site))->values()->all(),
            'library' => $this->library($site),
            'groups' => array_map(fn ($g) => __($g), Sections::GROUPS),
            'networks' => collect(Sections::NETWORKS)->map(fn ($n, $k) => [
                'value' => $k, 'label' => __($n['label']),
            ])->values()->all(),
            'products' => $this->pickerProducts($site),
            // المستند نفسه الذي سيقرؤه العارض — فالمعاينة هي الموقع لا صورته
            'document' => Preview::document($site),
            'statuses' => collect(WebsitePage::STATUSES)->map(fn ($l, $v) => [
                'value' => $v, 'label' => __($l),
            ])->values()->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function sectionPayload(WebsiteSection $section, Website $site): array
    {
        return [
            'id' => $section->id,
            'type' => $section->type,
            'slot' => $section->slot,
            'label' => $section->label(),
            'hint' => __(Sections::CATALOGUE[$section->type]['hint'] ?? ''),
            'visible' => $section->visible,
            'source' => $section->source(),
            'data' => $section->data,
            'schema' => Sections::schema($section->type, $site->goal()),
        ];
    }

    /** المنتجات كما يعرضها مُنتقي «منتجات مختارة» — اسمٌ وصورةٌ وسعر */
    private function pickerProducts(Website $site): array
    {
        if (! Blueprints::hasCatalogue($site->goal())) {
            return [];
        }

        return \App\Models\Product::where('business_id', $site->business_id)
            ->where('active', true)->orderBy('name')->limit(300)
            ->get(['id', 'name', 'price', 'image'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'image' => $p->image,
            ])->all();
    }

    /* ============================ الأقسام ============================ */

    /**
     * إضافة قسم — مملوءًا لا فارغًا.
     *
     * القسم الذي يُضاف فارغًا يجعل الموقع أسوأ بضغطة زر، فلا يُضاف مرّةً
     * ثانية. فيُبنى بما يعرفه النظام عن التاجر كما تُبنى أقسام المعالج.
     */
    public function addSection(Request $request, $pageId)
    {
        $site = $this->siteOrFail();
        $page = $this->page($site, $pageId);

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys(Sections::CATALOGUE))],
        ]);

        $type = $data['type'];

        if (Sections::isSlot($type)) {
            return back()->withErrors(['type' => __('الترويسة والتذييل يُعدَّلان ولا يُضافان')]);
        }

        if (! Blueprints::sectionFits($type, $site->goal())) {
            return back()->withErrors(['type' => __('هذا القسم لا يصلح لهذا النوع من المواقع')]);
        }

        // القسم الذي لا يتكرّر لا يتكرّر: ترويسة ثانية أو خريطتان في صفحة
        if ((Sections::CATALOGUE[$type]['unique'] ?? false)
            && $page->sections()->where('type', $type)->exists()) {
            return back()->withErrors(['type' => __('هذا القسم موجودٌ في الصفحة — عدّله بدل إضافة ثانٍ')]);
        }

        WebsiteSection::create([
            'website_id' => $site->id,
            'business_id' => $site->business_id,
            'page_id' => $page->id,
            'slot' => null,
            'type' => $type,
            'position' => ((int) $page->sections()->max('position')) + 1,
            'visible' => true,
            'data' => MerchantData::seed($type, MerchantData::identity($site->business_id), $site->goal()),
        ]);

        $site->touchDraft();

        return back()->with('toast', ['msg' => __('أُضيف :name', ['name' => Sections::label($type)]), 'type' => 'success']);
    }

    /** حفظ محتوى قسم — وما يُحفظ يمرّ بـ`Content::clean` وحدها */
    public function updateSection(Request $request, $sectionId)
    {
        $site = $this->siteOrFail();
        $section = $this->section($site, $sectionId);

        $section->update([
            'data' => Content::clean($section->type, (array) $request->input('data', []), $site->goal()),
        ]);

        // قائمةُ الموقع تتبع الصفحات إلا ما زاده التاجر — والترويسة تُحرَّر هنا
        $site->touchDraft();

        return back(303);
    }

    /** إظهار القسم وإخفاؤه — الإخفاء لا الحذف، فلا يضيع ما كُتب فيه */
    public function toggleSection($sectionId)
    {
        $site = $this->siteOrFail();
        $section = $this->section($site, $sectionId);

        $section->update(['visible' => ! $section->visible]);
        $site->touchDraft();

        return back()->with('toast', [
            'msg' => $section->visible ? __('ظهر القسم') : __('أُخفي القسم — ولم يُحذف ما فيه'),
            'type' => 'success',
        ]);
    }

    /** نسخُ قسمٍ بمحتواه — لمن يريد شريطين متشابهين بمنتجاتٍ مختلفة */
    public function duplicateSection($sectionId)
    {
        $site = $this->siteOrFail();
        $section = $this->section($site, $sectionId);

        if ($section->slot !== null) {
            return back()->withErrors(['section' => __('القسم العامّ لا يُنسخ')]);
        }

        if (Sections::CATALOGUE[$section->type]['unique'] ?? false) {
            return back()->with('toast', ['msg' => __('هذا القسم لا يتكرّر في الصفحة'), 'type' => 'warning']);
        }

        DB::transaction(function () use ($section, $site) {
            // النسخة تلي أصلها لا تقع في آخر الصفحة: من نسخ يريدها بجانبه
            WebsiteSection::where('page_id', $section->page_id)
                ->where('position', '>', $section->position)->increment('position');

            WebsiteSection::create($section->only([
                'website_id', 'business_id', 'page_id', 'type', 'visible', 'data',
            ]) + ['position' => $section->position + 1, 'slot' => null]);

            $site->touchDraft();
        });

        return back()->with('toast', ['msg' => __('نُسخ القسم'), 'type' => 'success']);
    }

    public function destroySection($sectionId)
    {
        $site = $this->siteOrFail();
        $section = $this->section($site, $sectionId);

        if ($section->slot !== null) {
            return back()->withErrors(['section' => __('الترويسة والتذييل يُخفيان ولا يُحذفان')]);
        }

        $label = $section->label();
        $section->delete();
        $site->touchDraft();

        return back()->with('toast', ['msg' => __('حُذف :name', ['name' => $label]), 'type' => 'warning']);
    }

    /**
     * ترتيب الأقسام — بالسحب أو بزرَّي أعلى وأسفل.
     *
     * والترتيب يصل قائمةَ معرّفات لا «حرّك هذا خطوةً»: السحب يعيد ترتيبًا
     * كاملًا، والزرّان يعيدان الترتيب نفسه بعد تبديل عنصرين. فمسارٌ واحد
     * يخدم الطريقتين، ولا تفترق نتيجتاهما.
     */
    public function reorderSections(Request $request, $pageId)
    {
        $site = $this->siteOrFail();
        $page = $this->page($site, $pageId);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'integer'],
        ]);

        $owned = $page->sections()->pluck('id')->all();

        DB::transaction(function () use ($data, $owned, $page, $site) {
            $position = 0;

            foreach ($data['order'] as $id) {
                // معرّفٌ من خارج الصفحة يُتخطّى: الترتيب لا ينقل أقسام غيرها
                if (! in_array((int) $id, $owned, true)) {
                    continue;
                }

                WebsiteSection::where('id', $id)->where('page_id', $page->id)
                    ->update(['position' => ++$position]);
            }

            $site->touchDraft();
        });

        return back(303);
    }
}
