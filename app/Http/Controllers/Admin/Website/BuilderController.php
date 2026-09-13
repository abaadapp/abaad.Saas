<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Support\Activity;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\MerchantData;
use App\Support\Website\Preview;
use App\Support\Website\Publication;
use App\Support\Website\Publisher;
use App\Support\Website\Templates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الباب: اختيارٌ لمن لا موقع له، ولوحةٌ لمن له موقع.
 *
 * وهذا الفرق هو المهمّة كلّها. من يفتح «الموقع الإلكتروني» أوّل مرّة لا يُقدَّم
 * له عشرون حقلًا يملؤها ولا ثلاثُ خطواتٍ يمرّ بها — يرى متجرَه مرسومًا بثلاثة
 * أشكال، يضغط أحدَها فيدخل المحرّر. ومن له موقعٌ لا يُقذف في الإعدادات — يرى
 * حاله ورابطه وأربعة أبواب.
 *
 * والشاشتان في متحكّمٍ واحد لأنّهما بابٌ واحد: `‎/website‎` يعرف بنفسه أيّهما
 * يعرض. وعنوانان يعني تاجرًا يحفظ عنوان الاختيار ويعود إليه بعد أن صار له
 * موقع، أو يحفظ عنوان اللوحة قبل أن يكون له موقعٌ فيرى شاشةً فارغة.
 */
class BuilderController extends Controller
{
    use Concerns;

    /**
     * البابُ الواحد — ومن خلفه شاشتان لا ثالثةَ لهما.
     *
     * من لا موقع له يرى الاختيار، ومن له موقعٌ يرى **لوحةَ تشغيله** لا
     * إعداداته: الشريطُ الجانبيّ للعمل اليوميّ، والضبطُ في «الإعدادات ‹
     * الموقع الإلكتروني». انظر `HubController`.
     *
     * والنداءُ مباشرٌ لا تحويل: تحويلةٌ في أوّل كلّ فتحةٍ تُكلّف رحلةً كاملة
     * إلى الخادم، ورابطُ `‎/website‎` يبقى هو العنوان الذي يُحفظ ويُشارَك.
     */
    public function index(): Response
    {
        if (! $this->site()) {
            /*
             * ومن لا يملك ضبطَ الموقع لا يُعرض عليه إنشاؤه.
             *
             * شاشةُ الاختيار تنتهي بزرٍّ يكتب موقعًا في القاعدة — وهو فعلُ
             * صاحب المتجر. وعرضُها على موظّفٍ لا يملكه بابٌ يُفتح ليُغلق في
             * وجهه عند الضغط.
             */
            $this->mayConfigure();

            return $this->wizard();
        }

        return app(HubController::class)->index();
    }

    /**
     * الاختيار — شكلٌ واحدٌ من ثلاثة، وزرٌّ واحد.
     *
     * ═══ ولماذا لم يبقَ معالجًا ═══
     *
     * كان ثلاثَ خطوات: «ماذا تريد من موقعك؟» ثمّ «اختر شكلًا» ثمّ «أكّد
     * بياناتك». وكلُّ خطوةٍ تُضاف تُسقط ربعَ من بدأ، والخطوتان الأولى
     * والثالثة لا تُخرجان قرارًا يملكه صاحبُ متجر: الأولى تسأله عن بنيةٍ
     * لا يعرفها (وجهةُ الموقع)، والثالثة تعرض عليه بياناتٍ أدخلها بنفسه
     * يوم فُتح حسابه ليضغط «التالي».
     *
     * فبقي القرارُ الوحيد الذي يملكه ويراه: أيُّ شكلٍ يعجبه. والوجهةُ
     * تُستنتج — من يفتح موقعًا من نظامٍ لإدارة متجرٍ يريد متجرًا — وتبقى
     * قابلةً للتبديل في «المتجر» بعد الإنشاء.
     *
     * ═══ وثلاثةُ قوالبَ يُعرض كلٌّ منها متجرًا لا مربّعَ لون ═══
     *
     * `Builder::proposal` تبني الموقع كما سيُبنى بالحرف — بلا أن تكتب صفًّا
     * — و`Preview::resolve` تصله بمنتجات التاجر وأسعارها وشعاره. فما يراه
     * في البطاقة هو ما سيدخل عليه المحرّر.
     */
    private function wizard(): Response
    {
        $bid = $this->bid();
        $business = Business::findOrFail($bid);

        /*
         * والوجهةُ `store` ولا تُسأل.
         *
         * أبعادُ نظامُ إدارة متجر: من يفتح موقعه منها عنده منتجاتٌ وأسعارٌ
         * وطلبات. و«كتالوجٌ بلا طلب» و«تعريفيّ» حالان تُبلغان من «المتجر»
         * بعد الإنشاء (`SettingsController::saveSite`) — بلغة ما يراه
         * الزائر لا بلغة بنية الموقع.
         */
        $goal = Blueprints::STORE;

        return Inertia::render('Admin/Website/Wizard', [
            'goal' => $goal,
            'templates' => collect(Templates::FEATURED)->map(fn ($key) => [
                'key' => $key,
                'label' => __(Templates::CATALOGUE[$key]['label']),
                'hint' => __(Templates::CATALOGUE[$key]['hint']),
                'document' => Preview::resolve(Builder::proposal($business, $goal, $key), $bid),
            ])->values()->all(),
            'identity' => MerchantData::identity($bid),
            'counts' => [
                'products' => Product::where('business_id', $bid)->where('active', true)->count(),
                'categories' => Category::where('business_id', $bid)->count(),
                'reviews' => Review::where('business_id', $bid)->where('status', 'منشور')->count(),
            ],
            'domain' => $this->domainState(),
        ]);
    }

    /** إنشاء الموقع — جوابان، ثمّ موقعٌ يصلح للنشر */
    public function store(Request $request)
    {
        if ($this->site()) {
            return redirect()->route('admin.website.index');
        }

        $data = $request->validate([
            /*
             * والوجهةُ تُقبل ولا تُطلب.
             *
             * الشاشةُ لم تعد تسأل عنها (انظر `wizard`)، فغيابُها يعني
             * «متجر». وما وصل منها يُفحص كما كان: بابٌ يقبل ما لم تُرسله
             * الشاشة يقبله من أيّ مرسِل.
             */
            'goal' => ['nullable', Rule::in(array_keys(Blueprints::GOALS))],
            'template' => ['required', Rule::in(array_keys(Templates::CATALOGUE))],
            // ما يُصحَّح عند الإنشاء: اسمٌ وجملةٌ — والباقي من بيانات النشاط
            'name' => ['nullable', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:255'],
        ]);

        $business = Business::findOrFail($this->bid());

        $site = Builder::create($business, $data['goal'] ?? Blueprints::STORE, $data['template'], auth()->id(), [
            'name' => $data['name'] ?? '',
            'tagline' => $data['tagline'] ?? '',
        ]);

        Activity::log('created', 'أنشأ الموقع الإلكتروني: '.$site->name);

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

        /*
         * وما يمنع النشر يُسأل عنه العقدُ لا هذا المتحكّم.
         *
         * الشروطُ تكثر مع الوقت — صفحةٌ منشورة، ورئيسيةٌ قائمة، وما يأتي —
         * وكتابتُها هنا تجعلها تُفحص في زرّ «انشر» وحده. ومن ينشر من أمرٍ
         * مجدول أو من طابور يتخطّاها كلَّها. انظر `Publication::problems`.
         */
        $problems = Publication::problems($site);

        if ($problems !== []) {
            return back()->with('toast', ['msg' => $problems[0], 'type' => 'warning']);
        }

        $note = $request->input('note');
        $version = Publisher::publish($site, auth()->id(), is_string($note) ? $note : null);

        Activity::log('updated', 'نشر الموقع — نشرة رقم '.$version->number);

        return back()->with('toast', ['msg' => __('نُشر موقعك'), 'type' => 'success']);
    }

    /** استعادة نشرةٍ سابقة إلى المسوّدة — يعاينها التاجر ثمّ ينشر إن رضي */
    public function restore($id)
    {
        $site = $this->siteOrFail();
        $version = $site->versions()->where('business_id', $site->business_id)->findOrFail($id);

        Publisher::restore($site, $version);

        Activity::log('updated', 'استعاد نشرة الموقع رقم '.$version->number);

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

        /*
         * ولا `touchDraft` معها — الصيانة ليست تغييرًا ينتظر النشر.
         *
         * العارض يفحص `websites.maintenance` حيًّا قبل أن ينظر في اللقطة، فرفعُ
         * المفتاح يُغلق الموقع في اللحظة. وكانت تُعدّ مع ذلك تغييرًا في المسوّدة:
         * فمن شغّل الصيانة ثمّ أطفأها تقول لوحتُه بعدها «فيه تغييرات لم تُنشر»
         * وليس فيه تغييرٌ واحد — فيضغط «انشر» فتُكتب نشرةٌ برقمٍ جديد لا تحمل
         * شيئًا. وتقريرُ حالٍ كاذب أسوأ من غياب التقرير.
         */

        return back()->with('toast', [
            'msg' => $data['maintenance']
                ? __('الموقع في وضع الصيانة — لن يصل إليه الزوّار')
                : __('عاد الموقع للزوّار'),
            'type' => $data['maintenance'] ? 'warning' : 'success',
        ]);
    }
}
