<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Support\MarketingSettings;
use App\Support\Website\Blueprints;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * المتجر والسيو والصيانة — الإعدادات التي بقيت بعد أن صار الباقي بنيةً.
 *
 * وأكثرها ليس جديدًا: `store_show_prices` و`store_allow_orders` يُحفظان
 * منذ نسخٍ ولا يقرؤهما شيء. وقد صار لهما الآن قارئ — العارض يقرأ اللقطة،
 * واللقطة تحملهما. فما نُقل إلى هنا لم يُخترع، وإنّما وُصل بما يعنيه.
 *
 * ولا يُعرض إعدادٌ لا يعني شيئًا لهذا الموقع: من اختار «تعريفيّ» لا يرى
 * إعداداتِ السلّة ولا عرضَ الأسعار — ليست مطفأةً عنده، هي غيرُ موجودة.
 *
 * والدفع ليس هنا: طرقُه في «الضرائب والعملة والدفع» ومصدرُه واحد. وما يملكه
 * الموقع أن يعرض ما فُعّل هناك، لا أن يفتح مصدرًا ثانيًا يعارضه.
 */
class SettingsController extends Controller
{
    use Concerns;

    /** المتجر: ما يراه الزائر وما يستطيع فعله */
    public function store(): Response
    {
        $site = $this->siteOrFail();
        $bid = $this->bid();
        $marketing = MarketingSettings::group($bid, 'website');

        return Inertia::render('Admin/Website/Store', $this->shell($site) + [
            'settings' => [
                'show_prices' => ($marketing['store_show_prices'] ?? '1') === '1',
                'allow_orders' => ($marketing['store_allow_orders'] ?? '0') === '1',
            ],
            'sells' => $site->sells(),
            'hasCatalogue' => Blueprints::hasCatalogue($site->goal()),
            /*
             * طرق الدفع تُقرأ ولا تُضبط هنا.
             *
             * مصدرها «الضرائب والعملة والدفع»، وضبطُها في موضعين يعني تاجرًا
             * يُطفئ البطاقة في أحدهما وتبقى تعمل في الآخر — ولا يعرف أيّهما
             * يقرأ الموقع.
             */
            'payments' => $this->payments($bid),
            'counts' => [
                'products' => \App\Models\Product::where('business_id', $bid)->where('active', true)->count(),
                'categories' => \App\Models\Category::where('business_id', $bid)->count(),
            ],
        ]);
    }

    public function saveStore(Request $request)
    {
        $site = $this->siteOrFail();

        $data = $request->validate([
            'show_prices' => ['required', 'boolean'],
            'allow_orders' => ['required', 'boolean'],
        ]);

        // ولا طلبَ في موقعٍ لا يبيع: الوجهة تحكم لا المفتاح
        if (! $site->sells()) {
            $data['allow_orders'] = false;
        }

        /*
         * وسعرٌ مخفيٌّ لا يُطلب معه.
         *
         * «اطلب» على منتجٍ بلا سعر يعني زبونًا يضع في سلّته ما لا يعرف ثمنه،
         * ثمّ يصل إلى الدفع فيفاجأ. وهذا كان تحذيرًا في الشاشة القديمة —
         * وصار قاعدةً في الخادم: الشاشة قد تُتخطّى.
         */
        if (! $data['show_prices']) {
            $data['allow_orders'] = false;
        }

        MarketingSettings::save($this->bid(), 'website', [
            'store_show_prices' => $data['show_prices'] ? '1' : '0',
            'store_allow_orders' => $data['allow_orders'] ? '1' : '0',
        ]);

        $site->touchDraft();

        return back()->with('toast', ['msg' => __('حُفظت إعدادات المتجر'), 'type' => 'success']);
    }

    /**
     * السيو — بلغةِ من يبيع لا بلغةِ من يبرمج.
     *
     * «عنوان موقعك في غوغل» لا «meta title»، و«صورة المشاركة» لا «og:image».
     * والكلمات المفتاحية ليست محور الشاشة: `seo_keywords` تبقى محفوظةً لمن
     * ضبطها ولا تُعرض أوّلًا — لا يقرؤها محرّك بحثٍ منذ سنين.
     */
    public function seo(): Response
    {
        $site = $this->siteOrFail();
        $seo = $site->seo ?? [];

        return Inertia::render('Admin/Website/Seo', $this->shell($site) + [
            'seo' => [
                'title' => (string) ($seo['title'] ?? ''),
                'description' => (string) ($seo['description'] ?? ''),
                'image' => (string) ($seo['image'] ?? ''),
                'index' => (bool) ($seo['index'] ?? true),
            ],
            'pages' => $site->pages()->get()->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'status' => $p->status,
                'seo' => $p->seo ?? ['title' => '', 'description' => '', 'image' => ''],
            ])->all(),
            'domain' => $this->domainState(),
        ]);
    }

    public function saveSeo(Request $request)
    {
        $site = $this->siteOrFail();

        $data = $request->validate([
            // الحدّان ليسا اعتباطًا: ما زاد عنهما يُقصّ في نتائج البحث
            'title' => ['nullable', 'string', 'max:70'],
            'description' => ['nullable', 'string', 'max:170'],
            'image' => ['nullable', 'string', 'max:500'],
            'index' => ['required', 'boolean'],
        ]);

        $site->update(['seo' => [
            'title' => (string) ($data['title'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'image' => (string) ($data['image'] ?? ''),
            'index' => (bool) $data['index'],
        ]]);
        $site->touchDraft();

        // والقديمة تتبع الجديدة: شاشة السيو القديمة تقرأ هذين المفتاحين
        MarketingSettings::save($this->bid(), 'seo', [
            'seo_title' => (string) ($data['title'] ?? ''),
            'seo_description' => (string) ($data['description'] ?? ''),
            'seo_index' => $data['index'] ? '1' : '0',
        ]);

        return back()->with('toast', ['msg' => __('حُفظت إعدادات الظهور في البحث'), 'type' => 'success']);
    }

    /** إعدادات الموقع نفسه: اسمُه ووجهتُه */
    public function saveSite(Request $request)
    {
        $site = $this->siteOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'goal' => ['required', Rule::in(array_keys(Blueprints::GOALS))],
        ]);

        /*
         * وتبديل الوجهة لا يهدم ما بُني.
         *
         * من يبدّل «متجر» إلى «تعريفيّ» تبقى صفحاتُه وأقسامُه كما هي، ويسقط
         * ما لا يصلح للوجهة الجديدة من العرض وحده. وحذفُ الأقسام عند التبديل
         * كان سيُضيّع عملَ يومٍ بضغطةٍ في قائمةٍ منسدلة.
         */
        $site->update($data);
        $site->touchDraft();

        return back()->with('toast', ['msg' => __('حُفظت إعدادات الموقع'), 'type' => 'success']);
    }

    /**
     * طرق الدفع المفعّلة في النظام — تُعرض ولا تُضبط.
     *
     * وتُقرأ بالدالّة التي تقرؤها نقطة البيع نفسها لا بشرطٍ مكتوبٍ هنا:
     * «الغياب يعني مفعّل» و«من أطفأ الثلاث يبقى له النقد» قاعدتان لو نُسختا
     * لافترقتا، فيعرض الموقعُ وسيلةً لا تقبلها نقطة البيع.
     */
    private function payments(int $bid): array
    {
        $settings = \App\Models\Setting::where('business_id', $bid)
            ->pluck('value', 'key')->all();

        $on = \App\Http\Controllers\Pos\PosController::enabledPaymentMethods($settings);

        return collect(['نقدي', 'بطاقة', 'تحويل بنكي'])->map(fn ($label) => [
            'label' => __($label),
            'on' => in_array($label, $on, true),
        ])->values()->all();
    }
}
