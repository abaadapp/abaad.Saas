<?php

namespace App\Support\Paymob;

use App\Models\PaymentGateway;
use App\Support\Store\Paymob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * روابطُ Paymob (QuickLink) — الواجهةُ الرسميّة كما توثّقها Paymob، بحساب التاجر.
 *
 * ═══ المسالك (developers.paymob.com ← QuickLink APIs) ═══
 *
 *   POST /api/auth/tokens                   { api_key }        ← { token }
 *   POST /api/ecommerce/payment-links       multipart، Bearer  ← { id, shorten_url, client_url, order, expires_at, state }
 *   POST /api/ecommerce/payment-links/cancel { payment_link_id } ← { success }
 *
 * على نطاق عُمان (`Paymob::BASE`) — المفاتيحُ إقليميّة، ومفتاحُ عُمان على نطاقٍ
 * آخر يُردّ. والرمزُ يُطلب لكلّ عمليّة ولا يُحفظ: لا يُكتب في قاعدةٍ ولا سجلّ.
 *
 * ═══ والخطأُ يُقال بلا سرّ ═══
 *
 * ما يُكتب عن ردٍّ فاشل حالتُه ورسالتُه المقروءة وحدها — لا جسمُه كاملًا
 * ولا الترويسة: جسمُ ردّ الرمز هو الرمز.
 */
final class QuickLink
{
    public const TIMEOUT = 20;

    /**
     * رابطٌ جديد — ويردّ ما يُحفظ منه، أو يرمي `QuickLinkFailed` بسببٍ يُقرأ.
     *
     * @param  array{amount_cents: int, currency: string, reference_id: string, expires_at: string, notification_url: string, full_name?: string, email?: string, phone_number?: string, description?: string}  $fields
     * @return array{id: string, short_url: string, order: ?string, expires_at: ?string, state: ?string}
     */
    public static function create(PaymentGateway $gateway, array $fields): array
    {
        $token = self::token($gateway);

        /*
         * والحقولُ أجزاءٌ في نموذج (multipart) كما توثّقها Paymob — لا JSON.
         *
         * ولكلّ رقمِ تكاملٍ جزءٌ باسم `payment_methods`: البطاقةُ ثمّ OmanNet
         * ثمّ Apple Pay (للحيّ وحده — `PaymentGateway::linkMethodIds`).
         */
        $parts = [];
        foreach ($fields as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = ['name' => $name, 'contents' => (string) $value];
        }
        foreach ($gateway->linkMethodIds() as $id) {
            $parts[] = ['name' => 'payment_methods', 'contents' => (string) $id];
        }
        $parts[] = ['name' => 'is_live', 'contents' => $gateway->isLive() ? 'true' : 'false'];

        $response = self::send(fn () => Http::withToken($token)->acceptJson()->asMultipart()
            ->timeout(self::TIMEOUT)
            ->post(Paymob::BASE.'/api/ecommerce/payment-links', $parts));

        $id = trim((string) $response->json('id', ''));
        $url = trim((string) $response->json('shorten_url', ''));

        // ورابطٌ بلا عنوانٍ قصير لا يُعطى للتاجر — لا يُرسَل للعميل رابطٌ لا يُفتح
        if ($id === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new QuickLinkFailed(__('ردّت Paymob بلا رابطٍ صالح — لم يُنشأ الرابط.'));
        }

        return [
            'id' => $id,
            'short_url' => $url,
            'order' => ($order = trim((string) $response->json('order', ''))) !== '' ? $order : null,
            'expires_at' => $response->json('expires_at'),
            'state' => $response->json('state'),
        ];
    }

    /** إلغاءُ رابطٍ عند Paymob — `true` إن قالت إنّه أُلغي */
    public static function cancel(PaymentGateway $gateway, string $linkId): bool
    {
        $token = self::token($gateway);

        $response = self::send(fn () => Http::withToken($token)->acceptJson()->asJson()
            ->timeout(self::TIMEOUT)
            ->post(Paymob::BASE.'/api/ecommerce/payment-links/cancel', ['payment_link_id' => $linkId]));

        return filter_var($response->json('success'), FILTER_VALIDATE_BOOL);
    }

    /** رمزُ Paymob من مفتاح API — لا يُحفظ ولا يُكتب */
    private static function token(PaymentGateway $gateway): string
    {
        $key = (string) $gateway->api_key;

        if ($key === '') {
            throw new QuickLinkFailed(__('أكمل إعداد Paymob أولًا.'));
        }

        $response = self::send(fn () => Http::acceptJson()->asJson()->timeout(self::TIMEOUT)
            ->post(Paymob::BASE.'/api/auth/tokens', ['api_key' => $key]), auth: true);

        $token = (string) $response->json('token', '');

        if ($token === '') {
            throw new QuickLinkFailed(__('رفضت Paymob مفتاح API — راجعه في إعدادات Paymob.'));
        }

        return $token;
    }

    /** يرسل ويترجم الفشل إلى سببٍ يُقرأ — بلا جسم الردّ ولا ترويسته */
    private static function send(callable $call, bool $auth = false): Response
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            throw new QuickLinkFailed(__('تعذّر الاتصال بـ Paymob — حاول مرّة أخرى بعد قليل.'));
        }

        if ($response->successful()) {
            if (! is_array($response->json())) {
                throw new QuickLinkFailed(__('ردّت Paymob بما لا يُقرأ — لم يُنشأ الرابط.'));
            }

            return $response;
        }

        $status = $response->status();

        throw new QuickLinkFailed(match (true) {
            $status === 401 || $status === 403 || $auth => __('رفضت Paymob مفتاح API — راجعه في إعدادات Paymob.'),
            $status === 404 => __('رقمُ تكاملٍ لا يطابق بيئة الحساب (Test أو Live) — راجع إعدادات Paymob.'),
            $status >= 500 => __('Paymob غير متاحة الآن — حاول مرّة أخرى بعد قليل.'),
            default => __('رفضت Paymob الطلب: :why', ['why' => self::reason($response)]),
        }, $status);
    }

    /** رسالةُ الرفض المقروءة وحدها — حقلٌ نصّيٌّ قصير، لا الجسمُ كلُّه */
    private static function reason(Response $response): string
    {
        $json = $response->json();
        $message = is_array($json)
            ? ($json['detail'] ?? $json['message'] ?? collect($json)->flatten()->filter(fn ($v) => is_string($v))->first())
            : null;

        return Str::limit(is_string($message) ? strip_tags($message) : __('خطأٌ غير معروف'), 160);
    }
}
