<?php

namespace App\Support\Store;

use App\Models\PaymentGateway;
use App\Support\Activity;
use App\Support\Website\Commerce;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * إعدادُ Paymob لمتجرٍ واحد — يُكتب في موضعٍ واحد ويُقرأ في كلّ موضع.
 *
 * ═══ ولمَ ملفٌّ واحد ═══
 *
 * كانت المفاتيحُ تُحرَّر في شاشتين (إعداداتُ المتجر، وشاشةُ متجر الواجهة
 * الخاصّة) بحفظٍ واحدٍ تحت «أدوات التسويق». فصار لها بيتٌ واحدٌ يُحرَّر
 * فيه: «التطبيقات التكاملية ← Paymob» (`IntegrationsController::paymob`).
 * وما سواه يقرأ الحالَ من `summary` هنا ويُحيل إلى ذلك البيت.
 *
 * ═══ ومفاتيحُ من؟ ═══
 *
 * مفاتيحُ صاحب المحلّ من حسابه في Paymob — والمالُ يصل حسابَه ولا يمرّ
 * بأبعاد. والصفُّ يُقرأ ويُكتب بمتجر الجلسة وحده (`business_id`)، ولا
 * يُقرأ معرّفُ متجرٍ من الطلب أبدًا. ولا حسابَ مشتركًا ولا رقمَ تكاملٍ
 * مشتركًا بين متجرين.
 */
final class PaymobSettings
{
    /** صفُّ هذا المتجر — مكتملًا كان أو لا */
    public static function row(int $businessId): ?PaymentGateway
    {
        return PaymentGateway::where('business_id', $businessId)
            ->where('provider', PaymentGateway::PAYMOB)->first();
    }

    /**
     * حالُ الربط — `off` أو `partial` أو `ready`.
     *
     * ولا تسأل عن سلّة الموقع: من ربط حسابَه قبل أن تكون في موقعه سلّةٌ
     * ربطُه صحيحٌ كامل. واستعمالُه على الموقع سؤالٌ آخر (`Paymob::enabled`).
     *
     *   off     ← لا صفّ، أو صفٌّ لم يُكتب فيه شيء.
     *   partial ← كُتب فيه شيءٌ ولم يكتمل، أو اكتمل وهو مطفأ.
     *   ready   ← `PaymentGateway::ready`.
     */
    public static function state(int $businessId): string
    {
        $row = self::row($businessId);

        if ($row === null) {
            return 'off';
        }

        if ($row->ready()) {
            return 'ready';
        }

        $touched = $row->active
            || filled($row->public_key)
            || filled($row->secret_key)
            || filled($row->hmac_secret)
            || filled($row->card_integration_id)
            || filled($row->apple_pay_integration_id);

        return $touched ? 'partial' : 'off';
    }

    /**
     * ما تعرضه شاشاتُ الموقع — حالٌ لا مفاتيح، ورابطٌ إلى بيتها.
     *
     * و Apple Pay «مُضاف» أو «غير مُضاف» لا «مفعّل»: رقمٌ محفوظٌ لا يقول إنّ
     * Paymob فعّلته لهذا الحساب — وذاك لا نعرفه من هنا.
     *
     * @return array{state: string, card_ready: bool, apple_pay: bool, checkout: bool, online: bool}
     */
    public static function summary(int $businessId): array
    {
        $row = self::row($businessId);
        $state = self::state($businessId);
        $checkout = Commerce::checkout($businessId);

        return [
            'state' => $state,
            'card_ready' => $state === 'ready',
            'apple_pay' => filled(trim((string) ($row?->apple_pay_integration_id ?? ''))),
            'checkout' => $checkout,
            // أيدفع الزبونُ بها على الموقع الآن؟ — بوّابةٌ جاهزةٌ وسلّة
            'online' => $state === 'ready' && $checkout,
        ];
    }

    /**
     * ما تعرضه شاشةُ الربط لصاحبها — والسرّان حالُهما لا نصُّهما.
     *
     * المفتاحُ العامّ ورقما التكامل ليست أسرارًا: الأوّلُ يُكتب في رابط صفحة
     * الدفع ويراه الزائر، والرقمان يُرسَلان في الطلب. أمّا السرّان فلا
     * يخرجان من الخادم — خصائصُ Inertia تُقرأ في مصدر الصفحة.
     *
     * @return array<string, mixed>
     */
    public static function view(int $businessId): array
    {
        $row = self::row($businessId);

        return [
            'active' => (bool) ($row?->active ?? false),
            'public_key' => (string) ($row?->public_key ?? ''),
            'card_integration_id' => (string) ($row?->card_integration_id ?? ''),
            'apple_pay_integration_id' => (string) ($row?->apple_pay_integration_id ?? ''),
            'has_secret' => filled($row?->secret_key),
            'has_hmac' => filled($row?->hmac_secret),
        ] + self::summary($businessId) + [
            // عنوانُ الإشعار كما يبنيه `Paymob::open` — من الخادم لا من المتصفّح
            'webhook' => self::webhook(),
        ];
    }

    /** عنوانُ الإشعار — واحدٌ لتكامل البطاقة ولتكامل Apple Pay */
    public static function webhook(): string
    {
        return rtrim((string) config('app.url'), '/').'/webhooks/paymob';
    }

    /**
     * يحفظ مفاتيحَ هذا المتجر — والفراغُ في السرّين يعني «لا تبدّله».
     *
     * الشاشةُ لا تعرض السرَّ فلا يُعاد إرسالُه، وحفظُ اسمٍ أو رقمٍ بجانبه
     * كان يمحوه لو قُرئ الفراغُ محوًا.
     *
     * ورقما التكامل أرقامٌ لا نصوص: يُرسَلان في `payment_methods` أعدادًا،
     * ونصٌّ فيهما يفتح دفعةً بلا وسيلة. ورقمُ Apple Pay لا يكون رقمَ
     * البطاقة: من نسخ رقمَ البطاقة إليه لم يكتب رقمَ Apple Pay.
     *
     * @throws ValidationException
     */
    public static function save(int $businessId, Request $request): PaymentGateway
    {
        $request->merge([
            'card_integration_id' => trim((string) $request->input('card_integration_id', '')),
            'apple_pay_integration_id' => trim((string) $request->input('apple_pay_integration_id', '')),
        ]);

        $data = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'public_key' => ['nullable', 'string', 'max:255'],
            'card_integration_id' => ['nullable', 'regex:/^[0-9]{1,32}$/'],
            'apple_pay_integration_id' => ['nullable', 'regex:/^[0-9]{1,32}$/', 'different:card_integration_id'],
            'secret_key' => ['nullable', 'string', 'max:255'],
            'hmac_secret' => ['nullable', 'string', 'max:255'],
        ], [
            'card_integration_id.regex' => __('رقم تكامل البطاقة أرقامٌ فقط — كما يظهر في لوحة Paymob.'),
            'apple_pay_integration_id.regex' => __('رقم تكامل Apple Pay أرقامٌ فقط — كما يظهر في لوحة Paymob.'),
            'apple_pay_integration_id.different' => __('رقم تكامل Apple Pay غيرُ رقم تكامل البطاقة — لكلٍّ منهما رقمُه في لوحة Paymob.'),
        ], [
            'public_key' => __('المفتاح العامّ'),
            'card_integration_id' => __('رقم تكامل البطاقة'),
            'apple_pay_integration_id' => __('رقم تكامل Apple Pay'),
            'secret_key' => __('المفتاح السرّي'),
            'hmac_secret' => __('سرّ التوقيع'),
        ]);

        $gateway = PaymentGateway::firstOrNew([
            'business_id' => $businessId,
            'provider' => PaymentGateway::PAYMOB,
        ]);

        $gateway->public_key = trim((string) ($data['public_key'] ?? ''));
        $gateway->card_integration_id = (string) ($data['card_integration_id'] ?? '');
        $gateway->apple_pay_integration_id = ($data['apple_pay_integration_id'] ?? '') !== ''
            ? (string) $data['apple_pay_integration_id']
            : null;

        foreach (['secret_key', 'hmac_secret'] as $secret) {
            if (filled($data[$secret] ?? null)) {
                $gateway->{$secret} = trim((string) $data[$secret]);
            }
        }

        $gateway->active = $request->boolean('active');
        $gateway->save();

        /*
         * ولا تُشغَّل بوّابةٌ ناقصة.
         *
         * زبونٌ يختار «بطاقة» على بوّابةٍ بلا سرِّ توقيعٍ يدفع ولا يُصدَّق
         * إشعارُه — فيخرج مالُه ولا يصله طلب. والشاشةُ تمنعه، ومن أرسل
         * الحمولةَ بيده لا. وما كُتب يبقى محفوظًا: التاجرُ يُكمل ولا يُعيد.
         */
        if ($gateway->active && ! $gateway->ready()) {
            $gateway->forceFill(['active' => false])->save();

            throw ValidationException::withMessages([
                'active' => __('أكمل المفاتيح الأربعة قبل تشغيل الدفع بالبطاقة — بوّابةٌ ناقصة تأخذ المال ولا تُنشئ طلبًا.'),
            ]);
        }

        Activity::log('updated', 'حدّث ربط Paymob');

        return $gateway;
    }
}
