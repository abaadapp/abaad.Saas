<?php

namespace App\Http\Controllers;

use App\Support\CrmWebsiteInquiries;
use App\Support\WebsiteLeadWhatsAppAlert;
use App\Support\WhatsAppPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * «تواصل معنا» في الموقع التعريفيّ — البابُ العامّ الوحيد إلى دفتر مبيعات أبعاد.
 *
 * الموقعُ على abaadapp.om والنظامُ على app.abaadapp.om، فالطلبُ عابرٌ للأصل
 * وبلا جلسة: لا دخولَ ولا رمزَ CSRF ولا كعكة. ويحرسه ما يلي بترتيبه، لا CORS
 * وحده — فـCORS يمنع صفحةً غريبة من قراءة الردّ، لا سكربتًا من الإرسال:
 *
 *  1. الأصلُ (Origin) من قائمة `cors.allowed_origins` نفسِها، وإلّا 403.
 *  2. جسمٌ لا يتجاوز 16 كيلوبايت، وإلّا 413.
 *  3. خمسُ محاولاتٍ في الدقيقة لكلّ عنوان، وثلاثون في اليوم.
 *  4. حقلٌ مخفيٌّ (`contact_hp`) لا يراه إنسان: من ملأه يُردّ عليه بنجاحٍ ولا يُكتب شيء.
 *     واسمُه لا يشبه ما يملؤه المتصفّحُ تلقائيًّا (website، url): Safari يملأ
 *     «الموقع» من بطاقة صاحبه، فيُرمى طلبٌ حقيقيّ بصمت.
 *  5. تحقّقٌ صارم، والرقمُ بتطبيع `WhatsAppPhone` الذي يكتب به الدفترُ نفسُه.
 *  6. خمسةُ طلباتٍ في اليوم للرقم الواحد.
 *
 * والمقبولُ سبعةُ حقولٍ بأسمائها؛ والمرحلةُ والحالُ والمصدرُ والإسنادُ لا تُقرأ
 * من الطلب أصلًا. والكتابةُ في `CrmWebsiteInquiries`.
 */
class WebsiteContactController extends Controller
{
    private const MAX_BYTES = 16 * 1024;

    /** [مفتاح، أقصى، ثوانٍ] — على العنوان، قبل أيّ قراءة */
    private const IP_LIMITS = [['minute', 5, 60], ['day', 30, 86400]];

    private const PHONE_LIMIT = 5;

    private const PHONE_DECAY = 86400;

    public function store(Request $request): JsonResponse
    {
        if (! in_array($request->headers->get('Origin'), (array) config('cors.allowed_origins'), true)) {
            return response()->json(['ok' => false, 'error' => 'origin_not_allowed'], 403);
        }

        $bytes = max((int) $request->headers->get('Content-Length'), strlen((string) $request->getContent()));
        if ($bytes > self::MAX_BYTES) {
            return response()->json(['ok' => false, 'error' => 'payload_too_large'], 413);
        }

        foreach (self::IP_LIMITS as [$window, $max, $decay]) {
            $key = 'website-contact:'.$window.':'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return $this->tooMany(RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, $decay);
        }

        // البوتُ لا يعرف أنّه كُشف: الردُّ نفسُه، ولا دفترَ ولا بريد
        if (filled($request->input('contact_hp'))) {
            return response()->json(['ok' => true]);
        }

        $validator = Validator::make($request->only([
            'name', 'business_name', 'phone', 'email', 'activity_type', 'message', 'locale',
        ]), [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'business_name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'string', 'max:190', 'email:rfc'],
            'activity_type' => ['required', 'string', Rule::in(array_keys(CrmWebsiteInquiries::ACTIVITY_TYPES))],
            'message' => ['required', 'string', 'min:5', 'max:3000'],
            'locale' => ['required', 'string', Rule::in(array_keys(CrmWebsiteInquiries::LOCALES))],
        ], [], [
            'name' => __('الاسم'), 'business_name' => __('اسم النشاط'), 'phone' => __('رقم الهاتف'),
            'email' => __('البريد الإلكتروني'), 'activity_type' => __('نوع النشاط'),
            'message' => __('الرسالة'), 'locale' => __('اللغة'),
        ]);

        $validator->after(function ($v) use ($request) {
            if (is_string($request->input('phone')) && filled($request->input('phone'))
                && WhatsAppPhone::normalize($request->input('phone')) === null) {
                $v->errors()->add('phone', __('رقمٌ لا يصلح — اكتبه بصيغة 9XXXXXXX أو 968XXXXXXXX.'));
            }
        });

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()->toArray()], 422);
        }

        $data = $validator->validated();

        $phoneKey = 'website-contact:phone:'.WhatsAppPhone::normalize($data['phone']);
        if (RateLimiter::tooManyAttempts($phoneKey, self::PHONE_LIMIT)) {
            return $this->tooMany(RateLimiter::availableIn($phoneKey));
        }
        RateLimiter::hit($phoneKey, self::PHONE_DECAY);

        $inquiry = CrmWebsiteInquiries::receive($data);

        if ($inquiry['note']) {
            CrmWebsiteInquiries::notify($inquiry['lead'], $inquiry['note'], $data);
            // وتنبيهُ واتساب لفريق أبعاد — بعد الـcommit، ولا يُسقط الطلبَ شيءٌ منه
            WebsiteLeadWhatsAppAlert::queue($inquiry['lead'], $inquiry['note']);
        }

        // الردُّ واحدٌ لرقمٍ جديد ولرقمٍ معروف — لا يُعرف منه من في الدفتر
        return response()->json(['ok' => true]);
    }

    private function tooMany(int $seconds): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'too_many_attempts', 'retry_after' => $seconds], 429)
            ->header('Retry-After', (string) $seconds);
    }
}
