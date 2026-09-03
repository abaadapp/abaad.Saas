<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DomainRequest;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\DomainOptions;
use App\Support\MarketingSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * الطرق الثلاث إلى عنوان المتجر — اختيارُها وما يتبع كلًّا منها.
 *
 * وهي خارج `MarketingController::saveWebsite` عمدًا: ذاك يحفظ الحقول الثمانية
 * دفعةً واحدة (النطاق والوصف وواتساب وإنستغرام…)، وهذه أفعالٌ لكلٍّ منها
 * تحقّقُها الخاصّ — اسمُ نطاقٍ فرعيّ لا يُقاس بمقياس نطاقٍ كامل، وطلبُ شراءٍ
 * ليس إعدادًا يُحفظ بل صفٌّ يُنشأ ويُتابَع.
 */
class DomainController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * اختيار الطريق — أو العودة إلى الاختيار من جديد.
     *
     * والعودة ممكنةٌ دائمًا: من اختار «نطاقًا فرعيًّا» ثمّ اشترى نطاقه يجب أن
     * يجد بابًا يبدّل به، لا شاشةً أقفلها اختيارُ يومٍ مضى.
     *
     * ولا يُمحى شيءٌ عند التبديل: النطاق المكتوب يبقى مكتوبًا والاسم المحجوز
     * يبقى محجوزًا. فمن بدّل ليرى الخيار الآخر ثمّ عاد يجد إعداده كما تركه،
     * ومن بدّل ليغيّر فعلًا يكتب الجديد فوق القديم بيده.
     */
    public function mode(Request $request)
    {
        $data = $request->validate([
            'site_domain_mode' => ['present', 'nullable', 'string', Rule::in(DomainOptions::MODES)],
        ]);

        /*
         * و«لا خيار» تصل null لا ''.
         *
         * وسيط `ConvertEmptyStringsToNull` يحوّل الفراغ قبل أن يبلغ التحقّق،
         * فقاعدةٌ تشترط نصًّا كانت ترفض زرَّ «تغيير الطريقة» نفسه — وهو أكثر
         * ما يُضغط في الشاشة بعد الاختيار الأوّل. والفراغ هنا قصدٌ لا نقص:
         * «أعِد عرض الخيارات عليّ».
         */
        MarketingSettings::save($this->bid(), 'website', [
            'site_domain_mode' => $data['site_domain_mode'] ?? '',
        ]);

        return back()->with('toast', ['msg' => __('اختير مسار الدومين'), 'type' => 'success']);
    }

    /**
     * حجز نطاقٍ فرعيّ تابع لأبعاد.
     *
     * يُحجز الاسم ولا يُفتح: لا شيء على الخادم يقدّم هذا العنوان بعد. والشاشة
     * تقول ذلك بشارة «قيد التجهيز» ولا تعرض زرّ «فتح الموقع» — وعدٌ بعنوانٍ
     * لا يردّ أسوأ من ألّا يُعرض الخيار أصلًا.
     */
    public function subdomain(Request $request)
    {
        $data = $request->validate([
            /*
             * قواعد اسم النطاق في DNS لا أقلّ: حروفٌ لاتينية صغيرة وأرقام
             * وشرطة بينها، من ٣ إلى ٦٣ محرفًا، ولا تبدأ بشرطة ولا تنتهي بها.
             *
             * والحرف الكبير والعربية والنقطة تُرفض هنا لا لاحقًا: اسمٌ يُحجز
             * اليوم ويتعذّر إنشاؤه يوم تُوصَل الاستضافة يعني تاجرًا انتظر
             * شهرًا ليُقال له «اختر اسمًا آخر».
             */
            'site_subdomain' => [
                'required', 'string', 'min:3', 'max:63',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
            ],
        ], [
            'site_subdomain.regex' => __('حروف لاتينية صغيرة وأرقام وشرطة بينها — مثل: my-store'),
        ]);

        $label = $data['site_subdomain'];

        if (in_array($label, DomainOptions::RESERVED, true)) {
            return back()->withErrors([
                'site_subdomain' => __('هذا الاسم محجوز للمنصة — اختر غيره.'),
            ]);
        }

        /*
         * والاسم لا يشير إلى متجرين.
         *
         * لا استضافة اليوم، فالتصادم لا يظهر اليوم: يُحجز الاسم نفسه لمتجرين
         * بهدوء، ولا يُكتشف إلا يوم تُوصَل النطاقات — حين يكون لكلٍّ منهما
         * ورقٌ ولافتةٌ تحمل العنوان نفسه.
         */
        if (DomainOptions::subdomainTaken($label, $this->bid())) {
            return back()->withErrors([
                'site_subdomain' => __('هذا الاسم محجوز لمتجر آخر — اختر غيره.'),
            ]);
        }

        MarketingSettings::save($this->bid(), 'website', [
            'site_subdomain' => $label,
            'site_domain_mode' => DomainOptions::SUBDOMAIN,
        ]);

        Activity::log('updated', 'حجز نطاقًا فرعيًّا: '.DomainOptions::host($label));

        return back()->with('toast', [
            'msg' => __('حُجز :host — سنُبلغك حين يصير جاهزًا.', ['host' => DomainOptions::host($label)]),
            'type' => 'success',
        ]);
    }

    /**
     * طلبُ أن تشتري أبعاد نطاقًا وتجهّزه.
     *
     * ولا طلبان معلّقان لمتجرٍ واحد: من ضغط مرّتين — أو ظنّ أنّ طلبه ضاع
     * فأعاده — يصنع صفّين يقرؤهما المشغّل طلبين، فيشتري نطاقين أو يسأل
     * التاجر أيّهما يريد.
     */
    public function requestDomain(Request $request)
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*\.[a-zA-Z]{2,}$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'domain.regex' => __('اكتب النطاق وحده بلا https:// ولا مسار — مثل: mystore.om'),
        ]);

        $pending = DomainRequest::where('business_id', $this->bid())
            ->where('status', DomainRequest::PENDING)->first();

        if ($pending) {
            return back()->withErrors([
                'domain' => __('لديك طلبٌ معلّق على :domain — انتظر ردّنا عليه أو ألغِه.', ['domain' => $pending->domain]),
            ]);
        }

        DomainRequest::create([
            'business_id' => $this->bid(),
            'domain' => mb_strtolower(trim($data['domain'])),
            'note' => $data['note'] ?? null,
            'status' => DomainRequest::PENDING,
        ]);

        MarketingSettings::save($this->bid(), 'website', [
            'site_domain_mode' => DomainOptions::SERVICE,
        ]);

        Activity::log('created', 'طلب تجهيز نطاق: '.$data['domain']);

        return back()->with('toast', [
            'msg' => __('وصلنا طلبك — سنتواصل معك قريبًا.'),
            'type' => 'success',
        ]);
    }

    /**
     * سحبُ طلبٍ معلّق.
     *
     * ومن غيّر رأيه قبل أن يبدأ المشغّل يجب أن يملك سحبَه: بدون ذلك يبقى
     * الطلب في اللوحتين إلى الأبد — التاجر ينتظر ما لم يعد يريده، والمشغّل
     * يشتري نطاقًا لم يعد أحدٌ يريده.
     */
    public function cancelRequest(int $id)
    {
        $req = DomainRequest::where('business_id', $this->bid())
            ->where('status', DomainRequest::PENDING)->findOrFail($id);

        $req->delete();

        Activity::log('deleted', 'سحب طلب تجهيز نطاق: '.$req->domain);

        return back()->with('toast', ['msg' => __('سُحب الطلب'), 'type' => 'success']);
    }
}
