<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Models\WebsitePage;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\MerchantData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الصفحات — قائمتها وحالاتها وروابطها.
 *
 * والصفحة الجديدة تُبنى من قالبٍ لا من فراغ: «من نحن» تأتي بفقرتها وأرقامها،
 * و«تواصل معنا» تأتي بهاتف التاجر وعنوانه وخريطته. والفارغة خيارٌ من الخيارات
 * لمن يعرف ما يريد، لا الحالُ الوحيدة.
 */
class PageController extends Controller
{
    use Concerns;

    public function index(): Response
    {
        $site = $this->siteOrFail();
        $pages = $site->pages()->withCount('sections')->get();

        return Inertia::render('Admin/Website/Pages', $this->shell($site) + [
            'pages' => $pages->map(fn ($p) => [
                'id' => $p->id,
                'key' => $p->key,
                'title' => $p->title,
                'slug' => $p->slug,
                'status' => $p->status,
                'is_home' => $p->is_home,
                'removable' => $p->removable,
                'sections' => $p->sections_count,
                'seo' => $p->seo ?? ['title' => '', 'description' => '', 'image' => ''],
            ])->all(),
            'templates' => Blueprints::pageTemplateOptions($site->goal()),
            'statuses' => collect(WebsitePage::STATUSES)->map(fn ($l, $v) => [
                'value' => $v, 'label' => __($l),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request)
    {
        $site = $this->siteOrFail();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120'],
            'template' => ['required', Rule::in(array_keys(Blueprints::PAGE_TEMPLATES))],
        ]);

        // والحقل الفارغ يصل غائبًا لا فارغًا (ConvertEmptyStringsToNull) — فيُقرأ بحذر
        $slug = $this->freeSlug($site->id, ($data['slug'] ?? '') ?: $data['title']);

        $page = DB::transaction(function () use ($site, $data, $slug) {
            $page = WebsitePage::create([
                'website_id' => $site->id,
                'business_id' => $site->business_id,
                'key' => 'custom',
                'title' => $data['title'],
                'slug' => $slug,
                // مسوّدةً أوّلًا: الصفحة الجديدة لا تظهر للزوّار قبل أن تُملأ
                'status' => WebsitePage::DRAFT,
                'is_home' => false,
                'removable' => true,
                'position' => ((int) $site->pages()->max('position')) + 1,
                'seo' => ['title' => '', 'description' => '', 'image' => ''],
            ]);

            Builder::addSections(
                $page,
                Blueprints::PAGE_TEMPLATES[$data['template']]['sections'],
                MerchantData::identity($site->business_id),
            );

            /*
             * وعنوان أوّل قسمٍ نصّيّ يتبع اسم الصفحة.
             *
             * قوالب الصفحات تُبنى بالبذور نفسها، فقسمُ «صورة ونصّ» يأتي
             * معنونًا «من نحن» أيًّا كانت الصفحة — فتُفتح «سياسة الخصوصية»
             * وعنوانها «من نحن». والاسم الذي كتبه التاجر أصدقُ من بذرة.
             */
            $first = $page->sections()->where('type', 'image_text')->first();

            if ($first) {
                $first->update(['data' => ['title' => $page->title] + $first->data]);
            }

            $site->touchDraft();

            return $page;
        });

        return redirect()->route('admin.website.editor', $page->id)
            ->with('toast', ['msg' => __('أُنشئت الصفحة — انشرها حين تجهز'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $site = $this->siteOrFail();
        $page = $this->page($site, $id);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(array_keys(WebsitePage::STATUSES))],
            'seo' => ['nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:70'],
            'seo.description' => ['nullable', 'string', 'max:170'],
            'seo.image' => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * والرئيسية لا يُبدَّل رابطُها ولا تُخفى.
         *
         * هي ما يُفتح حين يُكتب النطاق وحده، فرابطٌ آخر لها يعني نطاقًا يفتح
         * على «غير موجود»، وإخفاؤها يعني موقعًا بابُه مغلق.
         */
        $slug = $page->is_home
            ? '/'
            : $this->freeSlug($site->id, ($data['slug'] ?? '') ?: $data['title'], $page->id);

        $status = $page->is_home ? WebsitePage::PUBLISHED : $data['status'];

        $page->update([
            'title' => $data['title'],
            'slug' => $slug,
            'status' => $status,
            'seo' => array_map(fn ($v) => (string) $v, $data['seo'] ?? []) + ['title' => '', 'description' => '', 'image' => ''],
        ]);

        Builder::syncMenu($site->fresh());
        $site->touchDraft();

        return back()->with('toast', ['msg' => __('حُفظت الصفحة'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $site = $this->siteOrFail();
        $page = $this->page($site, $id);

        if (! $page->removable || $page->is_home) {
            return back()->with('toast', [
                'msg' => __('هذه الصفحة أساسية في موقعك — أخفِها من القائمة بدل حذفها'),
                'type' => 'warning',
            ]);
        }

        $title = $page->title;
        $page->delete();

        Builder::syncMenu($site->fresh());
        $site->touchDraft();

        return redirect()->route('admin.website.pages')
            ->with('toast', ['msg' => __('حُذفت صفحة :name', ['name' => $title]), 'type' => 'warning']);
    }

    /** ترتيب الصفحات — وهو ترتيب القائمة في الموقع */
    public function reorder(Request $request)
    {
        $site = $this->siteOrFail();

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'integer'],
        ]);

        $owned = $site->pages()->pluck('id')->all();

        DB::transaction(function () use ($data, $owned, $site) {
            $position = 0;

            foreach ($data['order'] as $id) {
                if (! in_array((int) $id, $owned, true)) {
                    continue;
                }

                WebsitePage::where('id', $id)->where('website_id', $site->id)
                    ->update(['position' => ++$position]);
            }

            Builder::syncMenu($site->fresh());
            $site->touchDraft();
        });

        return back(303);
    }

    /**
     * رابطٌ لا يصطدم برابطٍ آخر.
     *
     * الرابط فريدٌ في القاعدة، فصفحتان بـ«/about» تُسقطان الحفظ بخطأ قاعدة
     * بيانات لا يفهمه أحد. والإزاحة بلاحقةٍ رقمية تُبقي الحفظ يعمل وتُري
     * التاجر ما صار إليه رابطُه.
     */
    private function freeSlug(int $websiteId, string $wanted, ?int $exceptId = null): string
    {
        $base = WebsitePage::normalizeSlug($wanted);
        $slug = $base;
        $n = 1;

        while (WebsitePage::where('website_id', $websiteId)->where('slug', $slug)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
