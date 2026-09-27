<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Models\PaymentGateway;
use App\Models\StorePaymentIntent;
use App\Support\Storefront;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * الدفعُ بالبطاقة عبر Paymob — بحساب صاحب المحلّ.
 *
 * ═══ ولمَ Paymob ═══
 *
 * مرخَّصةٌ من البنك المركزيّ العُمانيّ ومربوطةٌ بـOmanNet، فالمالُ يصل
 * حسابَ التاجر في عُمان بالريال. ونطاقُها هنا `oman.paymob.com` لا
 * `accept.paymob.com` — المفاتيحُ إقليميّة، ومفتاحُ عُمان على نطاق مصر
 * يُردّ بلا كلمةٍ تُفهَم.
 *
 * ═══ والإشعارُ هو الحقيقة لا العودة ═══
 *
 * الزائرُ يعود إلى متجرنا برابطٍ فيه نتيجةُ الدفع. وذلك الرابطُ **غيرُ
 * موثَّق**: من كتبه بيده «نجح» صار طلبُه مدفوعًا. فالحقيقةُ في الإشعار
 * الذي ترسله Paymob إلى خادمنا موقَّعًا — وصفحةُ العودة تقرأ ما كُتب
 * بالإشعار ولا تكتب شيئًا.
 */
final class Paymob
{
    /** نطاقُ عُمان — والوضعُ (تجربةٌ أو حقيقة) تقرّره المفاتيح لا النطاق */
    public const BASE = 'https://oman.paymob.com';

    /** ثوانٍ تبقى فيها نيّةُ الشراء صالحةً — ساعةٌ تكفي لدفعةٍ متعثّرة */
    public const EXPIRES = 3600;

    /**
     * حقولُ التوقيع بترتيبها الذي تفرضه Paymob — لا ترتيبَ حروفٍ ولا ترتيبَنا.
     *
     * تُقرأ قيمُها من `obj` في الإشعار، وتُوصَل بلا فاصل، ويُحسب عليها
     * HMAC-SHA512. وحرفٌ في غير موضعه يجعل كلَّ إشعارٍ صحيحٍ يُردّ —
     * فيُقبض المالُ ولا يُنشأ طلبٌ أبدًا، ولا يظهر العطبُ إلّا من زبونٍ
     * دفع ولم يصله شيء.
     *
     * و`error_occured` مكتوبةٌ هكذا عندهم — بحرفٍ ناقص. تُنسخ كما هي.
     */
    public const HMAC_FIELDS = [
        'amount_cents', 'created_at', 'currency', 'error_occured',
        'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
        'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
        'is_voided', 'order.id', 'owner', 'pending',
        'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success',
    ];

    /* ═══════════ البوّابة ═══════════ */

    /** بوّابةُ هذا المحلّ إن كانت مكتملةً — أو `null` */
    public static function gateway(int $businessId): ?PaymentGateway
    {
        $row = PaymentGateway::where('business_id', $businessId)
            ->where('provider', PaymentGateway::PAYMOB)->first();

        return $row?->ready() === true ? $row : null;
    }

    /** أيقبل هذا المتجر البطاقةَ الآن؟ */
    public static function enabled(int $businessId): bool
    {
        return self::gateway($businessId) !== null;
    }

    /* ═══════════ التوقيع ═══════════ */

    /**
     * توقيعُ إشعارٍ كما تحسبه Paymob.
     *
     * والقيمُ تُقرأ خامًا كما وصلت: لا يُعاد تنسيق تاريخٍ ولا يُقرَّب رقم.
     * والمنطقيُّ يُكتب `true`/`false` نصًّا — وهو ما يكسر الحسبةَ إن تُرك
     * لـPHP تحوّله (`(string) false` فراغ).
     *
     * @param  array<string, mixed>  $obj
     */
    public static function signature(array $obj, string $secret): string
    {
        $flat = '';

        foreach (self::HMAC_FIELDS as $path) {
            $value = data_get($obj, $path);

            $flat .= match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => '',
                default => (string) $value,
            };
        }

        return hash_hmac('sha512', $flat, $secret);
    }

    /**
     * أهذا الإشعارُ من Paymob حقًّا؟
     *
     * و`hash_equals` لا `===`: موازنةُ نصَّين تقف عند أوّل حرفٍ مختلف، وزمنُ
     * وقوفها يقول للمهاجم كم حرفًا أصاب.
     *
     * @param  array<string, mixed>  $obj
     */
    public static function verify(array $obj, ?string $given, string $secret): bool
    {
        // وبلا سرٍّ لا تحقّق — ولا قبول: الأمانُ لا يُفتح بغياب إعداد
        if ($secret === '' || ! is_string($given) || $given === '') {
            return false;
        }

        return hash_equals(self::signature($obj, $secret), strtolower($given));
    }

    /* ═══════════ فتحُ الدفعة ═══════════ */

    /**
     * يحفظ نيّةَ الشراء ويفتح صفحةَ الدفع — ويردّ رابطَها ونيّتَها.
     *
     * والمبلغُ من التسعير لا من المتصفّح، وبالبيسة لا بالريال: Paymob تقرأ
     * الأصغر دائمًا (`amount_cents`). وألفُ بيسةٍ في الريال العُمانيّ.
     *
     * ═══ ولمَ تُردّ النيّةُ مع الرابط ═══
     *
     * ما يُحجز على الكوبون يُربط بنيّةٍ بعينها: نيّةٌ واحدةٌ حجزٌ واحد، ويُطرح
     * حجزُها إن فشل دفعُها. ولا يُعرف ذلك من رابطٍ نصًّا — فتُردّ معه.
     *
     * @param  array<string, mixed>  $payload  حمولةُ الطلب كما أرسلها الزائر
     * @param  array<string, mixed>  $quote  التسعيرُ المحسوب في الخادم
     * @return array{url: string, intent: StorePaymentIntent}
     */
    public static function open(Business $business, array $payload, array $quote, string $lang = 'ar'): array
    {
        $bid = (int) $business->id;
        $gateway = self::gateway($bid);

        if ($gateway === null) {
            throw new \RuntimeException('بوّابةُ الدفع غير مكتملة');
        }

        $currency = Storefront::currency($business);
        $total = round((float) ($quote['total'] ?? 0), 3);

        $intent = StorePaymentIntent::create([
            'business_id' => $bid,
            /*
             * والمرجعُ حروفٌ صغيرةٌ كلُّه — لا زينةً.
             *
             * يعود الزائرُ إلى `/paying/<المرجع>`، ونمطُ مسارات المتجر
             * (`Storefront::PATH`) لا يقبل حرفًا كبيرًا. فمرجعٌ فيه `WEB`
             * يردّ الزبونَ العائدَ من البنك إلى صفحةِ «غير موجود» — وقد
             * خرج مالُه.
             */
            'reference' => 'web-'.$bid.'-'.Str::lower(Str::random(18)),
            'payload' => $payload,
            'lang' => $lang,
            'amount' => $total,
            'currency' => $currency['code'] ?? 'OMR',
            'status' => StorePaymentIntent::PENDING,
            /*
             * ومهلةُ الجلسة تُكتب هنا لا تُحسب في موضعين.
             *
             * `expiration` أدناه تُعطى للبوّابة، وحجزُ فرصة الكوبون يُقرأ من
             * هذا العمود. فهما مهلةٌ واحدة، ولا يفترقان يوم تتبدّل.
             */
            'expires_at' => now()->addSeconds(self::EXPIRES),
        ]);

        /*
         * وعنوانُ المتجر كما يفتحه زبونُه — لا `app.url`.
         *
         * للمتجر ثلاثُ طرقٍ إلى عنوانه (نطاقٌ فرعيّ، أو مسارٌ تحت أبعاد، أو
         * نطاقٌ يملكه). ومن عاد إلى غير الذي خرج منه يرى متجرًا آخر — أو
         * صفحةَ خطأ.
         */
        $store = rtrim((string) Storefront::canonical($business->site_slug, $bid), '/');
        $hook = rtrim(config('app.url'), '/').'/webhooks/paymob';

        $response = Http::withHeaders(['Authorization' => 'Token '.$gateway->secret_key])
            ->acceptJson()
            ->timeout(20)
            ->post(self::BASE.'/v1/intention/', [
                // بالأصغر: الريالُ ألفُ بيسة، ولا كسرَ يُرسَل
                'amount' => (int) round($total * 1000),
                'currency' => $intent->currency,
                'payment_methods' => [(int) $gateway->card_integration_id],
                'items' => self::items($quote),
                'billing_data' => self::billing($payload),
                'special_reference' => $intent->reference,
                'expiration' => self::EXPIRES,
                // الإشعارُ إلى النظام، والعودةُ إلى المتجر — وجهتان لا واحدة
                'notification_url' => $hook,
                'redirection_url' => $store.'/paying/'.$intent->reference,
            ]);

        $secret = (string) $response->json('client_secret', '');

        if (! $response->successful() || $secret === '') {
            $intent->update(['status' => StorePaymentIntent::FAILED, 'error' => Str::limit((string) $response->body(), 500)]);

            throw new \RuntimeException('تعذّر فتحُ صفحة الدفع');
        }

        $intent->update(['provider_order_id' => (string) $response->json('intention_order_id', '')]);

        return [
            'url' => self::BASE.'/unifiedcheckout/?publicKey='.urlencode((string) $gateway->public_key)
                .'&clientSecret='.urlencode($secret),
            'intent' => $intent->refresh(),
        ];
    }

    /* ═══════════ ردُّ المال ═══════════ */

    /**
     * يردّ دفعةً قُبضت — مرّةً واحدةً، ويُكتب ما جرى.
     *
     * ═══ ومتى يُردّ من نفسه ═══
     *
     * حين يتبدّل المبلغُ بعد الدفع فلا يصير الطلبُ بالسعر الذي وافق عليه
     * الزبون (انظر `PriceMovedAfterPayment`). وما عدا ذلك يبقى قرارَ صاحب
     * المحلّ: نفد الصنفُ فيجهّز بديلًا أو يردّ بيده.
     *
     * ═══ ولا ردَّ مرّتين ═══
     *
     * الطلبُ يُطالَب به قبل الإرسال بتحديثٍ شرطيّ على `refund_status`: أوّلُ
     * إشعارٍ يأخذه، والثاني يقرأ أنّه مأخوذٌ فينصرف. فإشعاران متقاربان — أو
     * إشعارٌ أُعيد إرسالُه بعد دقائق — لا يُرسلان ردَّين على المال نفسِه.
     *
     * ولا يُكتب «رُدّ» إلّا إن قالت البوّابةُ ذلك: ما لم تُجب، أو أجابت
     * بخطأ، يُكتب `failed` ويبقى الجرسُ يرنّ لصاحب المحلّ — طمأنينةٌ كاذبةٌ
     * بردٍّ لم يقع أسوأُ من غياب الردّ.
     *
     * @return bool هل قبلته البوّابة؟
     */
    public static function refund(StorePaymentIntent $intent, string $why): bool
    {
        $transaction = trim((string) $intent->provider_transaction_id);

        if ($transaction === '') {
            return false;
        }

        $gateway = self::gateway((int) $intent->business_id);

        if ($gateway === null) {
            $intent->forceFill([
                'refund_status' => StorePaymentIntent::REFUND_FAILED,
                'refund_error' => 'لا بوّابةَ يُردّ منها',
            ])->save();

            return false;
        }

        /*
         * والمطالبةُ شرطيّةٌ في القاعدة: من لم يكن حالُه فارغًا أو فاشلًا فقد
         * طالب به غيرُنا. و`failed` يُعاد طلبُه — محاولةٌ فشلت ليست ردًّا وقع.
         */
        $claimed = StorePaymentIntent::whereKey($intent->id)
            ->where(fn ($q) => $q->whereNull('refund_status')
                ->orWhere('refund_status', StorePaymentIntent::REFUND_FAILED))
            ->update([
                'refund_status' => StorePaymentIntent::REFUND_PENDING,
                'refund_error' => $why,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $cents = (int) round(((float) $intent->amount) * 1000);

        try {
            $response = Http::withHeaders(['Authorization' => 'Token '.$gateway->secret_key])
                ->acceptJson()
                ->timeout(20)
                ->post(self::BASE.'/api/acceptance/void_refund/refund', [
                    'transaction_id' => $transaction,
                    'amount_cents' => $cents,
                ]);
        } catch (\Throwable $e) {
            $intent->forceFill([
                'refund_status' => StorePaymentIntent::REFUND_FAILED,
                'refund_error' => Str::limit($why.' — تعذّر الاتّصال: '.$e->getMessage(), 500),
            ])->save();

            return false;
        }

        if (! $response->successful()) {
            $intent->forceFill([
                'refund_status' => StorePaymentIntent::REFUND_FAILED,
                'refund_error' => Str::limit($why.' — ردّت البوّابة: '.$response->body(), 500),
            ])->save();

            return false;
        }

        $intent->forceFill([
            'refund_status' => StorePaymentIntent::REFUND_SENT,
            // معرّفُ عمليّة الردّ عند البوّابة — به يُسأل عنها بعد شهر
            'provider_refund_id' => (string) ($response->json('id') ?? '') ?: null,
            'refunded_at' => now(),
            'refund_error' => $why,
        ])->save();

        return true;
    }

    /**
     * بنودُ الفاتورة كما تقرؤها Paymob — واسمٌ ومبلغٌ لكلٍّ منها واجبان.
     *
     * وبندٌ بلا أحدهما يُردّ الطلبُ كلُّه بـ400، فيقف الزبون على صفحةٍ
     * بيضاء ولا يعرف أنّ الخلل في اسمِ صنف.
     *
     * @param  array<string, mixed>  $quote
     * @return list<array<string, mixed>>
     */
    private static function items(array $quote): array
    {
        $out = [];

        foreach ($quote['lines'] ?? [] as $l) {
            $name = trim((string) ($l['name'] ?? ''));

            $out[] = [
                'name' => $name !== '' ? $name : 'صنف',
                'amount' => (int) round(((float) ($l['line'] ?? 0)) * 1000),
                'quantity' => max(1, (int) ($l['qty'] ?? 1)),
            ];
        }

        return $out;
    }

    /**
     * بياناتُ المشتري — والهاتفُ وحده واجب.
     *
     * وما لا يجمعه صاحبُ المحلّ من حقولٍ (انظر `CheckoutFields`) يُملأ
     * بـ`NA`: حقلٌ فارغٌ يردّه Paymob، وحقلٌ لم يُسأل عنه لا يُخترع له جواب.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private static function billing(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $parts = preg_split('/\s+/u', $name, 2) ?: [];

        return [
            'first_name' => $parts[0] ?? 'NA',
            'last_name' => $parts[1] ?? 'NA',
            'phone_number' => trim((string) ($payload['phone'] ?? '')),
            'email' => trim((string) ($payload['email'] ?? '')) ?: 'NA',
            'street' => trim((string) ($payload['address'] ?? '')) ?: 'NA',
            'building' => 'NA', 'floor' => 'NA', 'apartment' => 'NA',
            'city' => trim((string) ($payload['area'] ?? '')) ?: 'NA',
            'state' => trim((string) ($payload['area'] ?? '')) ?: 'NA',
            'country' => 'OMN',
        ];
    }
}
