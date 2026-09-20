<?php

namespace App\Support;

use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\Response;
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

        return self::interpret($response);
    }

    /**
     * إرسال نصٍّ حرّ — لا قالب.
     *
     * ولا يصلح لمخاطبة زبونٍ ابتداءً: ميتا تمنع النصّ الحرّ خارج نافذة
     * الأربعِ والعشرين ساعةً من آخر رسالةٍ وصلت منه، وتردّه بالخطأ ١٣١٠٤٧.
     * فالنافذةُ تُفحص عند صاحب القرار (`SupportWhatsApp::windowOpen`) قبل
     * النداء — لا ليُخفى الخطأ، بل لأنّ نداءً يُعرف ردُّه سلفًا وقتٌ ضائع
     * وحالٌ تُكتب «فشل الإرسال» عن منعٍ نعرفه.
     *
     * @return array{ok:bool, id:?string, code:?string, message:?string, retryable:bool}
     */
    /**
     * حالُ قوالب هذا الحساب عند ميتا — كما قالتها هي.
     *
     * ═══ ولمَ تُسأل أصلًا ═══
     *
     * `enabled` في جدولنا مقبضُنا نحن، ولا يعني أنّ ميتا اعتمدت القالب.
     * وقالبٌ `PENDING` يُنادى به فيُردّ — فتُبنى رسالةٌ وتُحجز حصّةٌ ويُقيَّد
     * فشل، وينتظر الزبون رسالةً لا تأتي.
     *
     * وبلا `waba_id` لا سؤال: الوصلةُ تعرف رقمَها ولا تعرف حسابَ الأعمال
     * الذي تحته القوالب — وقائمةُ القوالب تُقرأ من الحساب لا من الرقم.
     *
     * @return array{ok:bool, templates:array<string, string>, message:?string}
     *                                                                          الاسم ← الحال، بحروف ميتا (APPROVED · PENDING · …)
     */
    public static function templates(WhatsAppConnection $connection): array
    {
        if (blank($connection->waba_id)) {
            return ['ok' => false, 'templates' => [], 'message' => __('لا معرّف حساب أعمال على هذه الوصلة.')];
        }

        $url = rtrim((string) config('whatsapp.graph_url'), '/')
            .'/'.config('whatsapp.api_version')
            .'/'.$connection->waba_id.'/message_templates';

        try {
            $response = Http::withToken($connection->access_token)
                ->timeout((int) config('whatsapp.timeout', 15))
                ->acceptJson()
                ->get($url, ['fields' => 'name,status,language', 'limit' => 200]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'templates' => [], 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'templates' => [],
                'message' => (string) ($response->json('error.message') ?? $response->body()),
            ];
        }

        /*
         * والاسمُ الواحد يعود صفًّا لكلّ لغة — فيُحفظ بلغته لا يُطوى.
         *
         * كان يُكتب `اسم ← حال` فيبقى آخرُ صفٍّ وحدَه: نسخةٌ إنجليزيّة أُضيفت
         * أمس وهي PENDING كانت تمحو APPROVED العربيّةَ — فتُقرأ العربيّة غيرَ
         * معتمَدة وتُتخطّى رسائلُ كلّ المتاجر. وصفٌّ بلا لغة (كما تردّه بعضُ
         * الوهميّات) يُحفظ تحت `*` فيصلح لكلّ لغة.
         */
        $out = [];

        foreach ((array) $response->json('data', []) as $row) {
            if (filled($row['name'] ?? null)) {
                $out[(string) $row['name']][(string) ($row['language'] ?? '*')] = (string) ($row['status'] ?? '');
            }
        }

        return ['ok' => true, 'templates' => $out, 'message' => null];
    }

    public static function sendText(WhatsAppConnection $connection, string $to, string $body): array
    {
        $url = rtrim((string) config('whatsapp.graph_url'), '/')
            .'/'.config('whatsapp.api_version')
            .'/'.$connection->phone_number_id.'/messages';

        try {
            $response = Http::withToken($connection->access_token)
                ->timeout((int) config('whatsapp.timeout', 15))
                ->acceptJson()
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'text',
                    /*
                     * ومعاينةُ الروابط مطفأة.
                     *
                     * ردُّ الدعم قد يحمل رابطَ شاشةٍ في لوحة التاجر؛ ومعاينةُ
                     * ميتا تفتح الرابط من خوادمها لتسحب عنوانه وصورته.
                     */
                    'text' => ['preview_url' => false, 'body' => $body],
                ]);
        } catch (\Throwable $e) {
            return self::failure('network_error', $e->getMessage(), retryable: true);
        }

        return self::interpret($response);
    }

    /**
     * إرسالُ ملفٍّ رُفع سلفًا — صورةً أو مستندًا بمعرّفه عند ميتا.
     *
     * والمعرّفُ يُرفع أوّلًا بـ`WhatsAppMedia::upload`؛ ولا يُرسَل رابطٌ
     * بدلًا منه: الرابطُ يعني أن تفتح ميتا خادمَنا من الخارج، ومرفقُ الدعم
     * على قرصٍ خاصٍّ لا بابَ له إلّا متحكّمٌ يسأل عن صاحب الجلسة.
     *
     * @param  'image'|'document'  $kind
     * @return array{ok:bool, id:?string, code:?string, message:?string, retryable:bool}
     */
    public static function sendMedia(
        WhatsAppConnection $connection,
        string $to,
        string $kind,
        string $mediaId,
        ?string $caption = null,
        ?string $filename = null,
    ): array {
        if (! in_array($kind, ['image', 'document'], true)) {
            return self::failure('unsupported_kind', __('نوعٌ لا يُرسَل.'), retryable: false);
        }

        $media = ['id' => $mediaId];

        /*
         * والتعليقُ يُقصّ عند ألفٍ وأربعةٍ وعشرين حرفًا.
         *
         * وهو حدُّ ميتا للتعليق على الوسائط. ونصٌّ أطولُ منه يُردّ كلُّه —
         * فتُقيَّد الرسالةُ «فشلت» وقد كان يكفي أن يُقصَّ سطر.
         */
        if (filled($caption)) {
            $media['caption'] = mb_substr((string) $caption, 0, 1024);
        }

        // واسمُ المستند يُرسَل: بدونه يصل التاجرَ ملفٌّ اسمُه رقمٌ لا يُفهم
        if ($kind === 'document' && filled($filename)) {
            $media['filename'] = mb_substr((string) $filename, 0, 240);
        }

        $url = rtrim((string) config('whatsapp.graph_url'), '/')
            .'/'.config('whatsapp.api_version')
            .'/'.$connection->phone_number_id.'/messages';

        try {
            $response = Http::withToken($connection->access_token)
                ->timeout((int) config('whatsapp.timeout', 15))
                ->acceptJson()
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => $kind,
                    $kind => $media,
                ]);
        } catch (\Throwable $e) {
            return self::failure('network_error', $e->getMessage(), retryable: true);
        }

        return self::interpret($response);
    }

    /**
     * قراءةُ ردِّ ميتا على رسالةٍ — نجاحًا أو فشلًا.
     *
     * وثلاثةُ نداءاتٍ تقرأ الردَّ نفسَه: قالبٌ ونصٌّ وملفّ. «فحصان لسؤالٍ
     * واحد يفترقان يوم يُبدَّل أحدهما» — فتُقرأ هنا مرّةً واحدة.
     *
     * @return array{ok:bool, id:?string, code:?string, message:?string, retryable:bool}
     */
    private static function interpret(Response $response): array
    {
        $payload = $response->json() ?? [];

        if ($response->successful()) {
            $id = $payload['messages'][0]['id'] ?? null;

            return $id
                ? ['ok' => true, 'id' => $id, 'code' => null, 'message' => null, 'retryable' => false]
                // ردٌّ ناجحٌ بلا معرّف: لا تُعدّ مقبولةً — لا شيء يُتابَع به
                : self::failure('no_message_id', __('لم يُعِد المزوّد معرّفًا للرسالة.'), retryable: false);
        }

        $error = $payload['error'] ?? [];

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
