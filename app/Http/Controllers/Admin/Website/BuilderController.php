<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\MerchantData;
use App\Support\Website\Publisher;
use App\Support\Website\Templates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الباب: معالجٌ لمن لا موقع له، ولوحةٌ لمن له موقع.
 *
 * وهذا الفرق هو المهمّة كلّها. من يفتح «الموقع الإلكتروني» أوّل مرّة لا يُقدَّم
 * له عشرون حقلًا يملؤها — يُسأل سؤالين ويُبنى له موقع. ومن له موقعٌ لا يُقذف
 * في الإعدادات — يرى حاله ورابطه وأربعة أبواب.
 *
 * والشاشتان في متحكّمٍ واحد لأنّهما بابٌ واحد: `‎/website‎` يعرف بنفسه أيّهما
 * يعرض. وعنوانان يعني تاجرًا يحفظ عنوان المعالج ويعود إليه بعد أن صار له
 * موقع، أو يحفظ عنوان اللوحة قبل أن يكون له موقعٌ فيرى شاشةً فارغة.
 */
class BuilderController extends Controller
{
    use Concerns;

    public function index(): Response
    {
        $site = $this->site();

        return $site ? $this->dashboard() : $this->wizard();
    }

    /**
     * المعالج — سؤالان وموقع.
     *
     * وبياناتُ التاجر تُعرض فيه لا تُطلب: اسمُه وشعارُه وعددُ منتجاته أمامه،
     * فيعرف أنّ النظام سيستعملها ولا يُطلب منه إدخالها من جديد.
     */
    private function wizard(): Response
    {
        $bid = $this->bid();

        return Inertia::render('Admin/Website/Wizard', [
            'goals' => Blueprints::goalOptions(),
            'templates' => Templates::options(),
            'identity' => MerchantData::identity($bid),
            'available' => MerchantData::available($bid),
            'counts' => [
                'products' => \App\Models\Product::where('business_id', $bid)->where('active', true)->count(),
                'categories' => \App\Models\Category::where('business_id', $bid)->count(),
                'reviews' => \App\Models\Review::where('business_id', $bid)->where('status', 'منشور')->count(),
            ],
            'domain' => $this->domainState(),
        ]);
    }

    /**
     * اللوحة — حال الموقع في سطر، وأربعة أبواب.
     *
     * ولا إعداداتٍ فيها: من يفتح موقعه يريد أن يعرف أهو منشور، وأين رابطه،
     * وماذا يفعل الآن. والإعدادات خلف بابها لمن قصدها.
     */
    private function dashboard(): Response
    {
        $site = $this->siteOrFail();
        $pages = $site->pages()->withCount('sections')->get();

        return Inertia::render('Admin/Website/Dashboard', $this->shell($site) + [
            'pages' => $pages->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'status' => $p->status,
                'is_home' => $p->is_home,
                'sections' => $p->sections_count,
            ])->all(),
            'summary' => [
                'pages' => $pages->count(),
                'sections' => WebsiteSection::where('website_id', $site->id)->whereNotNull('page_id')->count(),
                'hidden' => WebsiteSection::where('website_id', $site->id)->where('visible', false)->count(),
                'versions' => $site->versions()->count(),
            ],
            'domain' => $this->domainState(),
            'template_label' => __(Templates::CATALOGUE[$site->template]['label'] ?? ''),
            'versions' => $site->versions()->with('creator:id,name')->limit(5)->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'number' => $v->number,
                    'at' => optional($v->published_at)->format('Y-m-d H:i'),
                    'by' => $v->creator?->name,
                    'note' => $v->note,
                    'current' => $v->id === $site->published_version_id,
                ])->all(),
        ]);
    }

    /** إنشاء الموقع — جوابان، ثمّ موقعٌ يصلح للنشر */
    public function store(Request $request)
    {
        if ($this->site()) {
            return redirect()->route('admin.website.index');
        }

        $data = $request->validate([
            'goal' => ['required', Rule::in(array_keys(Blueprints::GOALS))],
            'template' => ['required', Rule::in(array_keys(Templates::CATALOGUE))],
            // ما يُصحَّح في المعالج: اسمٌ وجملةٌ — والباقي من بيانات النشاط
            'name' => ['nullable', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:255'],
        ]);

        $business = Business::findOrFail($this->bid());

        $site = Builder::create($business, $data['goal'], $data['template'], auth()->id(), [
            'name' => $data['name'] ?? '',
            'tagline' => $data['tagline'] ?? '',
        ]);

        \App\Support\Activity::log('created', 'أنشأ الموقع الإلكتروني: '.$site->name);

        return redirect()->route('admin.website.editor', $site->homePage()?->id)
            ->with('toast', ['msg' => __('جاهز — هذا موقعك، عدّل ما تشاء ثمّ انشره'), 'type' => 'success']);
    }

    /**
     * النشر — ما في المسوّدة يصير ما يراه الزائر.
     *
     * ولا يُنشر موقعٌ بلا صفحةٍ منشورة: نطاقٌ يفتح على لا شيء أسوأ من نطاقٍ
     * لا يفتح، لأنّ الأوّل يبدو عطبًا في المتجر.
     */
    public function publish(Request $request)
    {
        $site = $this->siteOrFail();

        if (! $site->pages()->where('status', WebsitePage::PUBLISHED)->exists()) {
            return back()->with('toast', [
                'msg' => __('لا صفحة منشورة في موقعك — انشر صفحةً واحدة على الأقل'),
                'type' => 'warning',
            ]);
        }

        $note = $request->input('note');
        $version = Publisher::publish($site, auth()->id(), is_string($note) ? $note : null);

        \App\Support\Activity::log('updated', 'نشر الموقع — نشرة رقم '.$version->number);

        return back()->with('toast', ['msg' => __('نُشر موقعك'), 'type' => 'success']);
    }

    /** استعادة نشرةٍ سابقة إلى المسوّدة — يعاينها التاجر ثمّ ينشر إن رضي */
    public function restore($id)
    {
        $site = $this->siteOrFail();
        $version = $site->versions()->where('business_id', $site->business_id)->findOrFail($id);

        Publisher::restore($site, $version);

        \App\Support\Activity::log('updated', 'استعاد نشرة الموقع رقم '.$version->number);

        return back()->with('toast', [
            'msg' => __('استُعيدت النسخة في المسوّدة — عاينها ثمّ انشرها'),
            'type' => 'success',
        ]);
    }

    /**
     * وضع الصيانة — الزائر يرى صفحةً محترمة، واللوحة تعمل كما هي.
     *
     * وهو حالٌ من أحوال الموقع لا إعدادٌ في «أخرى»: يُقرأ في اللوحة مع
     * «منشور» و«مسوّدة»، فيعرف التاجر أنّ زوّاره لا يصلون قبل أن يسأل لماذا.
     */
    public function maintenance(Request $request)
    {
        $site = $this->siteOrFail();

        $data = $request->validate([
            'maintenance' => ['required', 'boolean'],
            'maintenance_message' => ['nullable', 'string', 'max:255'],
        ]);

        $site->update([
            'maintenance' => $data['maintenance'],
            'maintenance_message' => $data['maintenance_message'] ?? $site->maintenance_message,
        ]);
        $site->touchDraft();

        return back()->with('toast', [
            'msg' => $data['maintenance']
                ? __('الموقع في وضع الصيانة — لن يصل إليه الزوّار')
                : __('عاد الموقع للزوّار'),
            'type' => $data['maintenance'] ? 'warning' : 'success',
        ]);
    }
}
