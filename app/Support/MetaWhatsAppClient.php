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
     * @param  array{id: string, filename: string}|null  $document  ملفٌّ رُفع إلى ميتا لرأس قالبٍ من نوع «مستند»
     * @return array{ok:bool, id:?string, code:?string, message:?string, retryable:bool}
     */
    public static function sendTemplate(
        WhatsAppConnection $connection,
        string $to,
        string $template,
        string $language,
        array $variables = [],
        ?array $document = null,
    ): array {
        $account = self::account($connection);

        if ($account['refusal'] !== null) {
            return $account['refusal'];
        }

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

        $components = [];

        // والرأسُ قبل النصّ — بترتيب أجزاء القالب عند ميتا
        if ($document !== null) {
            $components[] = [
                'type' => 'header',
                'parameters' => [['type' => 'document', 'document' => [
                    'id' => $document['id'], 'filename' => $document['filename'],
                ]]],
            ];
        }

        if ($variables !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($v) => ['type' => 'text', 'text' => (string) $v],
                    array_values($variables),
                ),
            ];
        }

        if ($components !== []) {
            $payload['template']['components'] = $components;
        }

        try {
            $response = Http::withToken($connection->access_token)
                ->timeout((int) config('whatsapp.timeout', 15))
                ->acceptJson()
                ->post($url, $payload + $account['field']);
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
     * ═══ ونصُّ BODY معه ═══
     *
     * `components` تُطلب مع الحال لتُحفظ صيغةُ BODY المعتمَدة لكلّ لغة —
     * منها يُكتب نصُّ الرسالة كما قرأه الزبون (`WhatsAppTemplates::snapshot`).
     * وهي مفتاحٌ مستقلّ في الجواب (`bodies`): من يقرأ `templates` لا يتغيّر
     * عليه شيء، وقالبٌ بلا BODY نصّيٍّ لا يُكتب له نصّ.
     *
     * @return array{ok:bool, templates:array<string, string>, bodies:array<string, array<string, string>>, message:?string}
     *                                                                                                                       الاسم ← الحال، بحروف ميتا (APPROVED · PENDING · …)
     */
    public static function templates(WhatsAppConnection $connection): array
    {
        if (blank($connection->waba_id)) {
            return ['ok' => false, 'templates' => [], 'bodies' => [], 'message' => __('لا معرّف حساب أعمال على هذه الوصلة.')];
        }

        $url = rtrim((string) config('whatsapp.graph_url'), '/')
            .'/'.config('whatsapp.api_version')
            .'/'.$connection->waba_id.'/message_templates';

        try {
            $response = Http::withToken($connection->access_token)
                ->timeout((int) config('whatsapp.timeout', 15))
                ->acceptJson()
                ->get($url, ['fields' => 'name,status,language,components', 'limit' => 200]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'templates' => [], 'bodies' => [], 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'templates' => [],
                'bodies' => [],
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
        $bodies = [];

        foreach ((array) $response->json('data', []) as $row) {
            if (filled($row['name'] ?? null)) {
                $language = (string) ($row['language'] ?? '*');
                $out[(string) $row['name']][$language] = (string) ($row['status'] ?? '');

                foreach ((array) ($row['components'] ?? []) as $component) {
                    if (strtoupper((string) ($component['type'] ?? '')) === 'BODY' && filled($component['text'] ?? null)) {
                        $bodies[(string) $row['name']][$language] = (string) $component['text'];
                    }
                }
            }
        }

        return ['ok' => true, 'templates' => $out, 'bodies' => $bodies, 'message' => null];
    }

    public static function sendText(WhatsAppConnection $connection, string $to, string $body): array
    {
        $account = self::account($connection);

        if ($account['refusal'] !== null) {
            return $account['refusal'];
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
                    'type' => 'text',
                    /*
                     * ومعاينةُ الروابط مطفأة.
                     *
                     * ردُّ الدعم قد يحمل رابطَ شاشةٍ في لوحة التاجر؛ ومعاينةُ
                     * ميتا تفتح الرابط من خوادمها لتسحب عنوانه وصورته.
                     */
                    'text' => ['preview_url' => false, 'body' => $body],
                ] + $account['field']);
        } catch (\Throwable $e) {
            return self::failure('network_error', $e->getMessage(), retryable: true);
        }

        return self::interpret($response);
    }

    /**
     * رسالةٌ تفاعليّة — أزرارُ ردٍّ أو قائمةٌ أو زرُّ رابط — داخل نافذة الخدمة.
     *
     * والجسمُ يُبنى عند من يسأل (`WhatsAppOrderReplies::interactive`): هنا
     * البابُ وحده، بحارسه نفسِه — وصلةُ تاجرٍ لم تُراجَع فوترتُها لا يخرج منها
     * شيء، وحسابُ الرسائل يُسمّى فيها كما يُسمّى في النصّ والقالب.
     *
     * @param  array<string, mixed>  $interactive  كائنُ `interactive` كما تطلبه ميتا
     * @return array{ok:bool, id:?string, code:?string, message:?string, retryable:bool}
     */
    public static function sendInteractive(WhatsAppConnection $connection, string $to, array $interactive): array
    {
        $account = self::account($connection);

        if ($account['refusal'] !== null) {
            return $account['refusal'];
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
                    'type' => 'interactive',
                    'interactive' => $interactive,
                ] + $account['field']);
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
        $account = self::account($connection);

        if ($account['refusal'] !== null) {
            return $account['refusal'];
        }

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
                ] + $account['field']);
        } catch (\Throwable $e) {
            return self::failure('network_error', $e->getMessage(), retryable: true);
        }

        return self::interpret($response);
    }

    /**
     * ‏‏إرسالُ قالبِ عرضٍ إلى Meta لمراجعته — على حساب أعمال الوصلة نفسِه.
     *
     * ورسالةُ الرفض تُؤخذ بلغة صاحبها (`error_user_msg`) إن كتبتها Meta: هي التي تقول ما في النصّ.
     *
     * @param  array<string, mixed>  $template
     * @return array{ok: bool, id: ?string, status: ?string, category: ?string, code: ?string, message: ?string}
     */
    public static function createTemplate(WhatsAppConnection $connection, array $template): array
    {
        $r = self::graph($connection, 'post', $connection->waba_id.'/message_templates', $template);

        return [
            'ok' => $r['ok'] && filled($r['body']['id'] ?? null),
            'id' => filled($r['body']['id'] ?? null) ? (string) $r['body']['id'] : null,
            'status' => filled($r['body']['status'] ?? null) ? (string) $r['body']['status'] : null,
            'category' => filled($r['body']['category'] ?? null) ? (string) $r['body']['category'] : null,
            'code' => $r['code'],
            'message' => $r['message'],
        ];
    }

    /** @return array{ok: bool, code: ?string, message: ?string} */
    public static function removeTemplate(WhatsAppConnection $connection, string $name): array
    {
        $r = self::graph($connection, 'delete', $connection->waba_id.'/message_templates?'.http_build_query(['name' => $name]));

        return ['ok' => $r['ok'], 'code' => $r['code'], 'message' => $r['message']];
    }

    /**
     * ‏‏قوالبُ الحساب صفًّا صفًّا — بحالها وسبب رفضها وجودتها، صفحةً بعد صفحة.
     *
     * @return array{ok: bool, rows: list<array<string, mixed>>, message: ?string}
     */
    public static function templateRows(WhatsAppConnection $connection, int $pages = 5): array
    {
        $rows = [];
        $after = null;

        for ($i = 0; $i < $pages; $i++) {
            $r = self::graph($connection, 'get', $connection->waba_id.'/message_templates', array_filter([
                'fields' => 'id,name,status,category,language,rejected_reason,quality_score', 'limit' => 200, 'after' => $after,
            ]));

            if (! $r['ok']) {
                return ['ok' => false, 'rows' => $rows, 'message' => $r['message']];
            }

            $rows = [...$rows, ...array_values((array) ($r['body']['data'] ?? []))];
            $after = filled($r['body']['paging']['next'] ?? null) ? ($r['body']['paging']['cursors']['after'] ?? null) : null;

            if ($after === null) {
                break;
            }
        }

        return ['ok' => true, 'rows' => $rows, 'message' => null];
    }

    /**
     * ‏‏كم عميلًا في اليوم تسمح Meta لهذا الرقم أن يبدأ معهم، وجودتُه عندها — أو `null` لما لم تقله.
     *
     * @return array{tier: ?string, quality: ?string}
     */
    public static function limits(WhatsAppConnection $connection): array
    {
        $r = self::graph($connection, 'get', (string) $connection->phone_number_id, ['fields' => 'whatsapp_business_manager_messaging_limit,quality_rating']);

        return [
            'tier' => $r['ok'] && filled($r['body']['whatsapp_business_manager_messaging_limit'] ?? null) ? (string) $r['body']['whatsapp_business_manager_messaging_limit'] : null,
            'quality' => $r['ok'] && filled($r['body']['quality_rating'] ?? null) ? (string) $r['body']['quality_rating'] : null,
        ];
    }

    /** @return array{ok: bool, body: array, code: ?string, message: ?string} */
    private static function graph(WhatsAppConnection $connection, string $method, string $path, array $data = []): array
    {
        $url = rtrim((string) config('whatsapp.graph_url'), '/').'/'.config('whatsapp.api_version').'/'.$path;

        try {
            $response = Http::withToken($connection->access_token)->timeout((int) config('whatsapp.timeout', 15))->acceptJson()->{$method}($url, $data);
        } catch (\Throwable $e) {
            return ['ok' => false, 'body' => [], 'code' => 'network_error', 'message' => __('تعذّر الاتصال بواتساب — حاول بعد قليل.')];
        }

        $body = (array) ($response->json() ?? []);
        $error = (array) ($body['error'] ?? []);

        return [
            'ok' => $response->successful() && $error === [],
            'body' => $body,
            'code' => $error === [] && $response->successful() ? null : (string) ($error['code'] ?? $response->status()),
            'message' => $error === [] && $response->successful() ? null
                : mb_substr((string) ($error['error_user_msg'] ?? $error['message'] ?? 'HTTP '.$response->status()), 0, 300),
        ];
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

    /**
     * على أيّ حساب رسائل تُحتسب هذه الرسالة — يُسمّى فيها، أو لا تخرج.
     *
     * آخرُ بابٍ قبل النداء، في الدوالّ الثلاث التي تُخرج رسالة: وصلةُ تطبيق
     * تاجرٍ لم يُراجَع دليلُ فوترتها، أو لا يُعرف حسابُ رسائلها بأمان، لا يخرج
     * منها شيء. وما يُسمّى هو الحسابُ الذي رُوجع دليلُه (`WhatsAppOwnApp::sendAccount`).
     *
     * @return array{refusal: ?array{ok:bool, id:?string, code:?string, message:?string, retryable:bool}, field: array<string, string>}
     */
    private static function account(WhatsAppConnection $connection): array
    {
        $bind = WhatsAppOwnApp::sendAccount($connection);

        return [
            'refusal' => $bind['problem'] === null ? null
                : self::failure($bind['problem'], WhatsAppOwnApp::say($bind['problem']), retryable: false),
            'field' => $bind['account'] === null ? [] : ['messaging_account_id' => $bind['account']],
        ];
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
    public static function verifySignature(?string $header, string $rawBody, ?string $secret = null): bool
    {
        // وإشعارُ تطبيق التاجر يُقاس بسرّ تطبيقه هو — لا بسرّ أبعاد (`WhatsAppOwnApp`)
        $secret ??= (string) config('whatsapp.app_secret');

        // بلا سرٍّ لا تحقّق — ولا قبول: الأمان لا يُفتح بغياب إعداد
        if ($secret === '' || blank($header)) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }
}
