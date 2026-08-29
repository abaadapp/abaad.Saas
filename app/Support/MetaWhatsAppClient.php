<?php

namespace App\Support;

use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Http;

/**
 * النداء على واجهة ميتا — الموضع الوحيد الذي يعرف شكلها.
 *
 * ونسخة الواجهة من الإعداد لا من الكود: ميتا تُصدر نسخةً كلّ بضعة أشهر
 * وتُوقف القديمة بعد نحو عامين، فترقيتُها سطرٌ في ملفّ الخادم لا نشرةُ كود.
 *
 * ولا يُكتب رمزٌ في سجلّ ولا في رسالة خطأ: ما يُبلَّغ عنه هو رمز الخطأ ونصّه
 * كما ردّتهما ميتا، ولا شيء من الاعتماد.
 */
class MetaWhatsAppClient
{
    /**
     * إرسال رسالة قالب.
     *
     * @param  array<int, string>  $variables  قيم {{1}}, {{2}} … بترتيبها
     * @return array{ok:bool, id:?string, code:?string, message:?string, retryable:bool}
     */
    public static function sendTemplate(
        WhatsAppConnection $connection,
        string $to,
        string $template,
        string $language,
        array $variables = [],
    ): array {
        $url = rtrim((string) config('whatsapp.graph_url'), '/')
            .'/'.config('whatsapp.api_version')
            .'/'.$connection->phone_number_id.'/messages';

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
            ],
        ];

        if ($variables !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    fn ($v) => ['type' => 'text', 'text' => (string) $v],
                    array_values($variables),
                ),
            ]];
        }

        try {
            $response = Http::withToken($connection->access_token)
                ->timeout((int) config('whatsapp.timeout', 15))
                ->acceptJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            // انقطاع شبكة أو مهلة — يُعاد، لا يُقيَّد فشلًا نهائيًّا
            return self::failure('network_error', $e->getMessage(), retryable: true);
        }

        $body = $response->json() ?? [];

        if ($response->successful()) {
            $id = $body['messages'][0]['id'] ?? null;

            return $id
                ? ['ok' => true, 'id' => $id, 'code' => null, 'message' => null, 'retryable' => false]
                // ردٌّ ناجحٌ بلا معرّف: لا تُعدّ مقبولةً — لا شيء يُتابَع به
                : self::failure('no_message_id', __('لم يُعِد المزوّد معرّفًا للرسالة.'), retryable: false);
        }

        $error = $body['error'] ?? [];

        return self::failure(
            (string) ($error['code'] ?? $response->status()),
            (string) ($error['message'] ?? __('تعذّر الإرسال.')),
            /*
             * ما يُعاد وما لا يُعاد.
             *
             * 429 حدُّ معدّل، و5xx عطلٌ عندهم — كلاهما يزول بالانتظار.
             * ورقمٌ خاطئ أو قالبٌ غير معتمَد أو رمزٌ مسحوب لا يُصلحه تكرار:
             * إعادةُ المحاولة عليه استهلاكٌ للطابور ولحدّ المعدّل معًا.
             */
            retryable: $response->status() === 429 || $response->serverError(),
        );
    }

    private static function failure(string $code, string $message, bool $retryable): array
    {
        return ['ok' => false, 'id' => null, 'code' => $code, 'message' => $message, 'retryable' => $retryable];
    }

    /**
     * تحقّق توقيع الإشعار الوارد.
     *
     * ميتا توقّع الجسم بـHMAC-SHA256 بمفتاح سرّ التطبيق وترسله في ترويسة
     * `X-Hub-Signature-256`. وبدونه يستطيع أيّ أحدٍ أن يُرسل إلينا «فشلت» أو
     * «سُلّمت» عن أيّ رسالة.
     *
     * والمقارنة بـ`hash_equals` لا بـ`===`: المقارنة العادية تخرج عند أوّل
     * حرفٍ مختلف، وفارقُ الزمن يُقرأ فيُخمَّن التوقيع حرفًا حرفًا.
     */
    public static function verifySignature(?string $header, string $rawBody): bool
    {
        $secret = (string) config('whatsapp.app_secret');

        // بلا سرٍّ لا تحقّق — ولا قبول: الأمان لا يُفتح بغياب إعداد
        if ($secret === '' || blank($header)) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }
}
