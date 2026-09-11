<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Review;
use App\Support\Activity;
use App\Support\BranchGoogle;
use App\Support\Demo;
use App\Support\GooglePlaces;
use App\Support\GoogleReviews;
use App\Support\Integrations;
use App\Support\MarketingSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * التطبيقات التكاملية — الربطُ وحده، لا ما يُفعَل بعده.
 *
 * الشاشاتُ هنا تجيب سؤالًا واحدًا: «هل الأداةُ موصولة؟» — المفاتيحُ
 * والمعرّفات والمراحل. وما تفعله الأداةُ بعد الوصل يبقى في قسمه: إشعاراتُ
 * واتساب في «أدوات التسويق» لأنّها تسويقٌ لا ربط، وتقييماتُ العملاء في
 * شاشتها.
 *
 * وكانت الأداتان تحت «أدوات التسويق» كلتاهما. فمن أراد أن يعرف «بمَ رُبط
 * متجري؟» لم يجد شاشةً تجيب، ومن أراد ربطَ بوّابة دفعٍ لم يجد أين يبحث:
 * الدفعُ ليس تسويقًا. فصار للربط بابُه.
 */
class IntegrationsController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * لوحةُ التكاملات — بطاقةٌ لكلّ أداةٍ في الدليل.
     *
     * وتُعرض الأدواتُ كلُّها لا المربوطةُ وحدها: من لم يربط شيئًا كان يفتح
     * لوحةً فارغة لا تقول له ما الذي يستطيع ربطه. والدليلُ هو الجواب.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/Integrations/Index', [
            'apps' => Integrations::cards(Business::findOrFail($this->bid())),
        ]);
    }

    /**
     * «ربط مع أبعاد» — يُسجَّل البدء ثمّ تُفتح المراحل.
     *
     * والتسجيل فعلٌ لا استنتاج: كلُّ ما كان يمكن أن يُستنتج منه البدءُ يكذب.
     * `whatsapp_enabled` افتراضُه `true` في القاعدة فكلُّ متجرٍ «بدأ» من
     * لحظة إنشائه، ومفتاحُ الخرائط في المنصّة يُتمّ الخطوة الأولى للجميع.
     *
     * وهو POST لا رابط: يكتب في القاعدة. ورابطٌ يكتب يُنفَّذ بزيارةٍ من
     * محرّك بحثٍ أو بجلبٍ مسبقٍ من المتصفّح.
     */
    public function connect(Request $request)
    {
        $tool = $request->route('tool');

        [$key, $back] = match ($tool) {
            'whatsapp' => ['wa_setup_started', 'admin.integrations.whatsapp'],
            'google' => ['google_setup_started', 'admin.integrations.google'],
            default => abort(404),
        };

        MarketingSettings::save($this->bid(), 'connect', [$key => '1']);
        Activity::log('updated', 'بدأ ربط '.$tool);

        return redirect()->route($back);
    }

    /* --------------------------- خرائط Google --------------------------- */

    public function google(): Response
    {
        $bid = $this->bid();

        $settings = MarketingSettings::group($bid, 'google');

        // المفتاح لا يُرسل إلى الشاشة — آخرُ أربعةِ أحرفٍ تكفي ليعرف أيَّه حفظ
        unset($settings['google_api_key']);

        $pulled = GoogleReviews::pull($bid);

        return Inertia::render('Admin/Integrations/Google', [
            'settings' => $settings,
            'link' => GoogleReviews::forBusiness($bid),
            'keyHint' => GoogleReviews::keyHint($bid),
            /*
             * أَلأبعادَ مفتاحٌ يقرأ به كلُّ تاجرٍ لم يلصق مفتاحه؟
             *
             * تقرؤه الشاشةُ لتقول الصدق في بطاقة المفتاح: «اختياريّ» حين
             * يكون لأبعادَ مفتاح، و«مطلوب» حين لا يكون. وبلا هذا الحقل كانت
             * تقول «تُقرأ تقييماتك بمفتاح أبعاد» لمنصّةٍ بلا مفتاح — فينتظر
             * التاجر قراءةً لا تأتي، ولا يشكو لأنّه صُدِّق.
             *
             * ولا يُرسَل المفتاح ولا طرفٌ منه — نعم أو لا وحسب.
             */
            'platformKey' => GoogleReviews::platformKey() !== null,
            /*
             * عنوانُ خادمنا — ليقيّد التاجر مفتاحه به.
             *
             * والشاشةُ تطلب منه التقييد منذ أوّل نسخة، ولم تكن تقول بأيّ
             * عنوان. فإمّا أن يسألنا — فينتقض أنّه يُتمّها وحده — وإمّا أن
             * يترك مفتاحه بلا قيد، فيُنفِق غيرُه رصيده يومَ يُسرَّب.
             */
            'serverIp' => config('services.outbound_ip') ?: null,
            'google' => $pulled,
            // مراحلُ الربط — شكلُها شكلُ واتساب، انظر App\Support\Integration
            'readiness' => GoogleReviews::readiness($bid, $pulled),
            /* عددُ ما في النظام من تقييمات — ليُقرأ الفرق بين الاثنين */
            'internal' => Review::where('business_id', $bid)->count(),
            /*
             * وفروعُ المتجر وحالُ كلٍّ منها — لكلّ فرعٍ ملفُّه.
             *
             * والمزامنةُ للقديم وحده (اثنتا عشرةَ ساعة): الشاشةُ تُفتح كثيرًا،
             * ونداءُ Google لكلّ فرعٍ في كلّ فتحةٍ فاتورةٌ تكبر بلا رقمٍ جديد.
             */
            'branches' => BranchGoogle::branches($bid),
            'searchMin' => GooglePlaces::MIN_QUERY,
        ]);
    }

    /* ------------------------- الفرعُ ومكانُه على الخريطة ------------------------- */

    /**
     * البحثُ عن محلٍّ بالاسم أو الرقم — والنداءُ من خادمنا لا من المتصفّح.
     *
     * ولا يصل المفتاحُ إلى الشاشة بحال: لو أُرسل لَقرأه أيُّ زائرٍ من مصدر
     * الصفحة، والنداءاتُ تُحتسب على من يملكه — أبعادَ أو التاجر.
     */
    public function searchPlaces(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:120'],
        ]);

        $key = GoogleReviews::apiKey($this->bid());

        if ($key === null) {
            return response()->json([
                'ok' => false,
                'error' => __('خدمة Google Maps غير مفعلة حاليًا.'),
                'results' => [],
            ]);
        }

        return response()->json(GooglePlaces::search($data['q'], $key));
    }

    /**
     * ربطُ فرعٍ بمكان — والمُرسَل معرّفٌ وحده.
     *
     * وما يُحفظ يُقرأ من ردّ Google لا من الطلب: من بدّل الاسمَ أو المعدّل في
     * المتصفّح لا يجعل شاشتَنا تشهد بما لم تقله Google.
     */
    public function linkBranch(Request $request, int $branch)
    {
        $data = $request->validate([
            'place_id' => ['required', 'string', 'max:255'],
        ]);

        $result = BranchGoogle::link($this->branch($branch), $data['place_id'], $request->user());

        if (! $result['ok']) {
            return back()->withErrors(['place_id' => $result['error']]);
        }

        return back()->with('toast', [
            'msg' => __('تم ربط المتجر بنجاح'),
            'type' => 'success',
        ]);
    }

    public function unlinkBranch(int $branch)
    {
        BranchGoogle::unlink($this->branch($branch));

        return back()->with('toast', ['msg' => __('أُلغي الربط'), 'type' => 'warning']);
    }

    /** سحبٌ جديدٌ لهذا الفرع — يتخطّى حدَّ التقادم */
    public function refreshBranch(int $branch)
    {
        $place = BranchGoogle::for($this->branch($branch));

        if (! $place) {
            return back()->withErrors(['branch' => __('هذا الفرع غير مربوط.')]);
        }

        return back()->with('toast', BranchGoogle::sync($place, force: true)
            ? ['msg' => __('حُدِّثت التقييمات'), 'type' => 'success']
            : ['msg' => __('تعذر الاتصال بـ Google حاليًا. حاول مرة أخرى.'), 'type' => 'error']);
    }

    /**
     * فرعٌ من فروع هذا المتجر — أو ٤٠٤.
     *
     * والحصرُ في الاستعلام لا في فحصٍ بعده: `findOrFail` ثمّ مقارنةُ
     * `business_id` تُفرِّق بين «ليس لك» و«غير موجود»، وكلاهما يجب أن يُقال
     * الشيءَ نفسه — وإلّا صار رقمُ الفرع يُخمَّن بالردّ.
     */
    private function branch(int $id): Branch
    {
        return Branch::where('business_id', $this->bid())->findOrFail($id);
    }

    /**
     * حفظُ مفتاح Places — أو محوُه.
     *
     * وحقلٌ فارغٌ لا يمحو: الشاشة لا تعرض المفتاح المحفوظ (لا يُرسل أصلًا)،
     * فحفظُ الصفحة لتبديل شيءٍ آخر يصل بحقلٍ فارغ — ولو عُدّ محوًا لَفقد
     * التاجر مفتاحه كلّما حفظ. فالمحو يُطلب بزرّه.
     */
    public function saveGoogleKey(Request $request)
    {
        $request->validate([
            'google_api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $key = trim((string) $request->input('google_api_key'));

        if ($key === '') {
            return back()->withErrors(['google_api_key' => __('الصق المفتاح، أو اضغط «حذف المفتاح» لإزالته.')]);
        }

        GoogleReviews::storeKey($this->bid(), $key);
        // ولا يُكتب المفتاح في السجلّ — السجلّ يُقرأ في شاشة النشاط
        Activity::log('updated', 'حدّث مفتاح Google Places');

        return back()->with('toast', ['msg' => __('حُفظ المفتاح'), 'type' => 'success']);
    }

    public function forgetGoogleKey()
    {
        GoogleReviews::storeKey($this->bid(), null);
        Activity::log('updated', 'حذف مفتاح Google Places');

        return back()->with('toast', ['msg' => __('حُذف المفتاح'), 'type' => 'warning']);
    }

    /**
     * سحبٌ جديدٌ الآن — يتخطّى الذاكرة.
     *
     * ولولاه لَبقي التاجر ستَّ ساعاتٍ يرى ردًّا قديمًا بعد أن صحّح مفتاحه أو
     * ردّ على تقييم، فيظنّ أنّ إصلاحه لم ينفع ويعيده.
     */
    public function refreshGoogle()
    {
        $result = GoogleReviews::pull($this->bid(), refresh: true);

        return back()->with('toast', $result['state'] === 'ok'
            ? ['msg' => __('حُدِّثت التقييمات'), 'type' => 'success']
            : ['msg' => $result['error'] ?? __('لم تُسحب التقييمات'), 'type' => 'error']);
    }

    /**
     * مقبضُ رمز التقييم على الإيصال — ولا معرّفَ يُلصق بيد.
     *
     * ═══ ولمَ رُفع حقلُ اللصق ═══
     *
     * كان التاجر يلصق «Place ID» نصًّا فيُحفظ بلا أن تُسأل Google عنه. ومعرّفٌ
     * خاطئ لا يُخطئ أحدًا في الشاشة: الحفظ ينجح، والرمز يُطبع، ويمسحه الزبون
     * فيفتح ملفَّ محلٍّ آخر — أو لا يفتح شيئًا. عطبٌ لا يراه صاحبه أبدًا لأنّه
     * لا يمسح إيصاله بنفسه.
     *
     * فصار الطريقُ واحدًا: يبحث عن محلّه بالاسم أو الرقم، ويختار، ويُنادى
     * Google بالمعرّف فتشهد بالاسم قبل أن يُكتب صفّ. انظر `linkBranch`.
     */
    public function saveGoogle(Request $request)
    {
        $request->validate([
            'google_review_on_receipt' => ['nullable', 'boolean'],
            'google_show_on_site' => ['nullable', 'boolean'],
        ]);

        MarketingSettings::save($this->bid(), 'google', [
            'google_review_on_receipt' => $request->boolean('google_review_on_receipt'),
            'google_show_on_site' => $request->boolean('google_show_on_site'),
        ]);

        Activity::log('updated', $request->boolean('google_review_on_receipt')
            ? 'شغّل رمز تقييم Google على الإيصال'
            : 'أطفأ رمز تقييم Google على الإيصال');

        return back()->with('toast', ['msg' => __('حُفظت الإعدادات'), 'type' => 'success']);
    }

    /* ---------------------------- واتساب بزنس ---------------------------- */

    /**
     * ربطُ واتساب — الوصلةُ ووضعُ الإرسال والحصّة، ولا مقبضَ حدثٍ واحد.
     *
     * ومقابضُ الأحداث («عند تأكيد الطلب»، «عند خروجه للتوصيل») بقيت في
     * «إشعارات واتساب» تحت أدوات التسويق: تلك تُفتح كلّما بُدّلت خطّةُ
     * المتجر في مخاطبة زبائنه، وهذه تُفتح مرّةً عند الربط ثمّ لا تُفتح.
     * وجمعُهما في شاشةٍ واحدة كان يعني أنّ من يريد إطفاء رسالةٍ يمرّ على
     * رمز تفعيلٍ من ميتا لا شأن له به.
     */
    public function whatsapp(): Response
    {
        $business = Business::findOrFail($this->bid());

        return Inertia::render('Admin/Integrations/Whatsapp', [
            /*
             * حال الأتمتة كما تراها المنصّة — لا كما يظنّها التاجر.
             *
             * ولا يخرج منها رمزٌ ولا معرّف وصلة أبعاد: الوضع المشترك يقول
             * «يُرسل عبر أبعاد» ولا يقول بأيّ حسابٍ ولا بأيّ مفتاح.
             */
            'automation' => WhatsAppController::view($business),
        ]);
    }
}
