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

    /** كم منتجًا يُرسَل مع المحرّر أو مع بحثٍ واحد */
    private const PICKER = 40;

    /**
     * ما يبدأ به مُنتقي المنتجات — أوّلُ صفحةٍ لا الكتالوج كلُّه.
     *
     * كان يُرسَل ثلاثمئة منتجٍ في حمولة المحرّر عند كلّ فتحة: صورةٌ واسمٌ
     * وسعرٌ لكلٍّ منها، في شاشةٍ يفتحها التاجر ليكتب عنوانًا. ومتجرٌ بألفَي
     * صنف يدفع الثمن مرّتين — في الشبكة، وفي أنّ المنتقي لا يعرض إلّا
     * ثلاثمئةً منها فيبحث التاجر عن صنفه فلا يجده ويظنّه محذوفًا.
     *
     * فصار المُنتقي يسأل حين يُفتح (`products` أدناه)، ويبدأ بأربعين تكفي
     * للاختيار السريع. والمنتجاتُ المختارةُ سلفًا تُرسَل معها مهما كان
     * ترتيبُها — وإلّا رأى التاجر بطاقاتٍ بلا أسماء لِما اختاره أمس.
     */
    private function pickerProducts(Website $site): array
    {
        if (! Blueprints::hasCatalogue($site->goal())) {
            return [];
        }

        $chosen = collect($site->pages->flatMap(fn ($p) => $p->sections)->concat($site->sections))
            ->flatMap(fn ($s) => (array) ($s->data['product_ids'] ?? []))
            ->map(fn ($id) => (int) $id)->filter()->unique()->take(Content::MAX_ITEMS)->all();

        return $this->productRows($site, null, $chosen);
    }

    /**
     * بحثُ المنتقي — يُنادى من الشاشة عند الفتح وعند الكتابة.
     *
     * وهو `GET` يردّ JSON لا صفحةَ Inertia: المنتقي جزءٌ من شاشةٍ مفتوحة،
     * وردُّ صفحةٍ كاملة له يعيد بناء المحرّر كلَّه ويُضيّع ما لم يُحفظ فيه.
     */
    public function products(Request $request)
    {
        $site = $this->siteOrFail();

        abort_if(! Blueprints::hasCatalogue($site->goal()), 404);

        $term = \App\Support\Search::term($request);

        return response()->json(['products' => $this->productRows($site, $term !== '' ? $term : null)]);
    }

    /**
     * صفوفُ المنتجات — اسمٌ وصورةٌ وسعر، لا أكثر.
     *
     * @param  array<int, int>  $ids  معرّفاتٌ تُضمّ مهما كان ترتيبُها
     */
    private function productRows(Website $site, ?string $term, array $ids = []): array
    {
        $query = \App\Models\Product::where('business_id', $site->business_id)
            ->where('active', true);

        if ($term !== null) {
            // والبحثُ في الاسم وحده، بمعامل المحرّك لا بيدٍ — انظر `Search::like`
            $query->where('name', \App\Support\Search::like(), '%'.$term.'%');
        }

        $rows = $query->orderBy('name')->limit(self::PICKER)->get(['id', 'name', 'price', 'image']);

        if ($ids !== []) {
            $missing = array_diff($ids, $rows->pluck('id')->all());

            if ($missing !== []) {
                $rows = $rows->concat(
                    \App\Models\Product::where('business_id', $site->business_id)
                        ->whereIn('id', $missing)->get(['id', 'name', 'price', 'image']),
                );
            }
        }

        return $rows->unique('id')->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'price' => (float) $p->price,
            'image' => $p->image,
        ])->values()->all();
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
            /*
             * ═══ وما لم يُذكر في الطلب يلحق آخرَه ═══
             *
             * كان يُمشى على المُرسَل وحده فيُرقَّم من واحد، ويبقى ما لم
             * يُرسَل على موضعه القديم. فقائمةٌ ناقصةٌ — تبويبٌ قديم، أو
             * قسمٌ أُضيف في نافذةٍ أخرى بعد أن حُمّلت هذه — تُخرج موضعين
             * متساويين، ويصير ترتيبُ القسمين ما يقرّره محرّكُ القاعدة:
             * يتبدّل بين طلبٍ وطلب، والتاجر يرى موقعه يعيد ترتيب نفسه.
             *
             * فالترتيبُ يُكتب على الصفحة كلّها لا على ما وصل: المذكورُ
             * بترتيبه، وما بقي بعده على ترتيبه السابق.
             */
            $wanted = array_values(array_filter(
                array_map('intval', $data['order']),
                fn ($id) => in_array($id, $owned, true),
            ));

            $rest = array_values(array_diff($owned, $wanted));
            $position = 0;

            foreach (array_merge($wanted, $rest) as $id) {
                WebsiteSection::where('id', $id)->where('page_id', $page->id)
                    ->update(['position' => ++$position]);
            }

            $site->touchDraft();
        });

        return back(303);
    }
}
