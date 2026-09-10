<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Review;
use App\Support\Activity;
use App\Support\Demo;
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
            'google' => $pulled,
            // مراحلُ الربط — شكلُها شكلُ واتساب، انظر App\Support\Integration
            'readiness' => GoogleReviews::readiness($bid, $pulled),
            /* عددُ ما في النظام من تقييمات — ليُقرأ الفرق بين الاثنين */
            'internal' => Review::where('business_id', $bid)->count(),
        ]);
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

    public function saveGoogle(Request $request)
    {
        $data = $request->validate([
            'google_maps_url' => ['nullable', 'string', 'max:500'],
            'google_review_on_receipt' => ['nullable', 'boolean'],
        ]);

        $input = trim((string) ($data['google_maps_url'] ?? ''));

        /*
         * الرابط يُقرأ قبل أن يُحفظ، ولا يُقبل ما لا يُقرأ.
         *
         * ومعرّفٌ خاطئ لا يُخطئ أحدًا في الشاشة: الحفظ ينجح، والرمز يُطبع،
         * ويمسحه الزبون فيفتح ملفَّ محلٍّ آخر — أو لا يفتح شيئًا. عطبٌ لا
         * يراه صاحبه أبدًا لأنّه لا يمسح إيصاله بنفسه.
         */
        if ($input !== '' && ! GoogleReviews::readable($input)) {
            return back()->withInput()->withErrors([
                'google_maps_url' => __('لم أستطع قراءة معرّف المكان من هذا الرابط. الصق «Place ID» نفسه، أو رابطًا يحمل place_id.'),
            ]);
        }

        $bid = $this->bid();

        MarketingSettings::save($bid, 'google', [
            'google_maps_url' => $input,
            'google_place_id' => GoogleReviews::placeId($input) ?? '',
            'google_review_on_receipt' => $request->boolean('google_review_on_receipt'),
        ]);

        /*
         * والمسحوبُ يسقط بعد الكتابة — بالمعرّف الجديد.
         *
         * ولا يخلط معرّفٌ بآخر: موضعُ الذاكرة يحمل المعرّف في اسمه، فلا يرث
         * محلٌّ تقييماتِ محلٍّ آخر أبدًا. وإنّما هو التقادم: من ربط الآن يقصد
         * أن يرى ما عند Google الآن، لا ما بقي في الذاكرة من قبل.
         */
        GoogleReviews::forget($bid);

        Activity::log('updated', $input === '' ? 'فكّ ربط خرائط Google' : 'ربط خرائط Google');

        return back()->with('toast', [
            'msg' => $input === '' ? __('أُلغي الربط') : __('حُفظ الربط'),
            'type' => 'success',
        ]);
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
