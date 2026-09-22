<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\WhatsAppConnection;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\WhatsAppAutoReply;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppEmbeddedSignup;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppOnboarding;
use Illuminate\Http\Request;

/**
 * بابُ التسجيل المدمج — يستقبل الكود من شاشة التاجر، ولا يستقبل رمزًا قطّ.
 *
 * ═══ ولمَ جلسةٌ وCSRF لا مسارُ `api/` ═══
 *
 * في هذا التطبيق لا وجود لـ`routes/api.php` أصلًا: كلُّ ما يفعله التاجر
 * يمرّ بجلسته وبرمز CSRF وبـ`CheckAbility`. ومسارٌ تحت `api/` كان سيخرج من
 * هذه الطبقات الثلاث جميعًا — فيصير بابًا يُنادى بكودٍ مسروقٍ من متصفّحٍ
 * آخر بلا ما يقول من صاحبُه. الأضيقُ هنا هو الأأمن.
 *
 * ═══ ومعرّفُ المتجر من الجلسة لا من الطلب ═══
 *
 * ولو قُرئ ممّا يصل لَاستطاع تاجرٌ أن يربط رقمًا لمتجر غيره بتبديل رقمٍ في
 * الحمولة — أو أن ينتزع رقمَ غيره إلى نفسه. والحمولةُ قد تحمل `business_id`
 * من ميتا؛ وهو معرّفُ **حساب الأعمال عند ميتا** لا معرّفُ متجرٍ عندنا،
 * ولا يُخلط بينهما ولا يُقرأ منه إذن.
 */
class WhatsAppOnboardingController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    private function business(): Business
    {
        return Business::findOrFail($this->bid());
    }

    /**
     * من يملك أن يربط رقمَ المتجر أو يفصله.
     *
     * ═══ ولمَ فحصٌ ثانٍ وقد مرّ `CheckAbility` ═══
     *
     * ذاك يسأل «أيملك هذا الموظّف قسمَ التكاملات؟» — وقد يُمنح المحاسبُ
     * إيّاه ليربط الخرائط. وهذا يسأل «أهو صاحبُ الحساب أو مديره؟». وربطُ
     * رقمِ الواتساب ليس كضبطِ مفتاحِ خرائط: هو تفويضٌ باسم النشاط عند ميتا،
     * يُرسل برقمه ويقرأ رسائلَ زبائنه.
     */
    private function mayManage(): bool
    {
        /*
         * و«المدير» هنا أضيقُ من `isAdmin()`.
         *
         * تلك تشمل `manager`، وقسمُ التكاملات ممنوحٌ له — فيستطيع أن يضبط
         * مفتاحَ خرائط، وذاك إعداد. أمّا هذا فتفويضٌ **باسم النشاط** عند
         * ميتا: يُنشئ رمزًا يُرسل برقم المحلّ ويقرأ رسائل زبائنه، ويبقى
         * قائمًا بعد أن يترك المديرُ عمله. فهو لصاحب الحساب وحده.
         */
        return auth()->user()?->role === 'admin';
    }

    /**
     * الكودُ يصل، والرمزُ يُصنع هنا — ولا يعود إلى الشاشة منه حرف.
     *
     * والشاشةُ لا تُعلن نجاحًا قبل هذا الردّ: حدثُ `FINISH` من ميتا يقول
     * إنّ التاجر أتمّ خطواتِها، لا إنّ لدينا رمزًا يعمل على رقمٍ نملك
     * إدارته. والفرقُ بينهما هو كلُّ ما يفعله `WhatsAppOnboarding::connect`.
     */
    public function callback(Request $request)
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return back()->withErrors(['code' => __('ربط واتساب لصاحب الحساب أو مديره.')]);
        }

        if (! WhatsAppFeature::canUseOwnNumber($business)) {
            return back()->withErrors(['code' => __('ربط رقم المتجر ميزة غير مفعّلة لحسابك — راجع أبعاد.')]);
        }

        if (! WhatsAppEmbeddedSignup::configured()) {
            return back()->withErrors(['code' => __('ربط واتساب غير مهيّأ على الخادم — راجع أبعاد.')]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'min:10', 'max:2000'],
            /*
             * وما سواه اقتراحٌ يُفحص لا أمرٌ يُنفَّذ.
             *
             * المتصفّح يقول «حسابُ الأعمال كذا والرقم كذا»؛ والرمزُ وحدَه
             * يقول ما مُنحناه فعلًا. فيُقبلان نصًّا ويُقابلان بما تقوله ميتا
             * في `WhatsAppOnboarding::connect`.
             */
            'waba_id' => ['nullable', 'string', 'max:100'],
            'phone_number_id' => ['nullable', 'string', 'max:100'],
        ]);

        $result = WhatsAppOnboarding::connect(
            $business,
            $request->user(),
            $data['code'],
            $data['waba_id'] ?? null,
            $data['phone_number_id'] ?? null,
        );

        if (! $result['ok']) {
            return back()->withErrors([(string) $result['field'] => (string) $result['message']]);
        }

        return back()->with('toast', [
            'msg' => (string) $result['message'],
            'type' => $result['pending'] ? 'info' : 'success',
        ]);
    }

    /**
     * اختبارُ الاتصال — من الخادم، بالرمز المخزَّن، ولا يُعرض الرمز.
     *
     * ═══ ولمَ زرٌّ يُسأل به ═══
     *
     * الوصلةُ تنقطع صامتةً: رمزٌ يُسحب من لوحة ميتا، أو رقمٌ يُنقل إلى
     * حسابٍ آخر، أو حسابٌ يُوقَف. ولا شيء عندنا يعلم حتى تفشل أوّلُ رسالةٍ
     * لزبونٍ حقيقيّ. وهذا الزرُّ يسأل ميتا اليوم وبلا أن يُرسل لأحد.
     */
    public function test()
    {
        $business = $this->business();
        $connection = WhatsAppConnections::forBusiness($business->id);

        if (! $connection || blank($connection->phone_number_id) || blank($connection->access_token)) {
            return back()->withErrors(['code' => __('لا وصلة مكتملة بعد.')]);
        }

        $result = WhatsAppEmbeddedSignup::inspectNumber($connection->phone_number_id, $connection->access_token);

        if (! $result['ok']) {
            /*
             * وفشلُ الفحص يُكتب على الوصلة — لا يُقال في إشعارٍ يزول.
             *
             * من ضغط الزرّ قرأ الخبر؛ ومن يفتح الشاشة غدًا يحتاج أن يقرأه
             * أيضًا. والحالةُ `ERROR` تجعل البطاقةَ تقوله بلا أن يُسأل.
             */
            $connection->forceFill([
                'status' => WhatsAppConnection::ERROR,
                'last_error_code' => (string) $result['code'],
                'last_error_message' => mb_substr((string) $result['message'], 0, 500),
                'last_error_at' => now(),
            ])->save();

            return back()->withErrors(['code' => __('لم يردّ رقمك عند ميتا — راجع حسابك ثمّ أعد المحاولة.')]);
        }

        /*
         * ونجاحُ الفحص يُصحّح ما كُتب خطأً — والاسمُ يُحدَّث من ميتا.
         *
         * التاجر قد يُغيّر اسمَ العرض عندهم، وقد تُصلَح العلّةُ التي كُتبت
         * أمس. فبقاءُ «حدث خطأ» بعد أن زال الخطأ عطبٌ في نفسه.
         */
        $connection->forceFill([
            'status' => WhatsAppConnection::ACTIVE,
            'display_phone_number' => $result['display_phone_number'] ?? $connection->display_phone_number,
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        return back()->with('toast', [
            'msg' => __('رقمك يردّ عند ميتا: :name', ['name' => $result['verified_name'] ?: $connection->display_phone_number]),
            'type' => 'success',
        ]);
    }

    /**
     * الردّ التلقائيّ — يُحفظ، ولا يعمل حتى يُشعله صاحبُه.
     *
     * والحفظُ لا يُشعل: المفتاحُ في الحمولة، وافتراضُه في `settings()`
     * «مطفأ». فمن حفظ نصًّا ولم يُشعل لم يُرسل شيئًا.
     */
    public function autoReply(Request $request)
    {
        $business = $this->business();

        if (! $this->mayManage()) {
            return back()->withErrors(['ar' => __('هذا الإعداد لصاحب الحساب أو مديره.')]);
        }

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'ar' => ['nullable', 'string', 'max:'.WhatsAppAutoReply::MAX_TEXT],
            'en' => ['nullable', 'string', 'max:'.WhatsAppAutoReply::MAX_TEXT],
            'branch_id' => ['nullable', 'integer'],
            'cooldown_hours' => ['required', 'integer', 'min:1', 'max:168'],
        ]);

        /*
         * ولا يُشعَل بلا نصّ.
         *
         * مفتاحٌ مُشعَلٌ فوق حقلٍ فارغ يعني رسالةً بيضاءَ تصل الزبون — أو
         * ردًّا تردّه ميتا فيُقيَّد فشلٌ كلَّ مرّة. والمنعُ هنا قبل الحفظ.
         */
        if ($data['enabled'] && trim((string) ($data['ar'] ?? '')) === '' && trim((string) ($data['en'] ?? '')) === '') {
            return back()->withErrors(['ar' => __('اكتب نصّ الردّ قبل تشغيله.')]);
        }

        WhatsAppAutoReply::save($business->id, $data);

        Activity::log('settings', 'واتساب — الردّ التلقائيّ: '.($data['enabled'] ? 'شُغّل' : 'أُوقف'));

        return back()->with('toast', ['msg' => __('حُفظ الردّ التلقائي'), 'type' => 'success']);
    }
}
