<?php

namespace App\Support;

use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * الملفّاتُ على واتساب — رفعًا وتنزيلًا وإرسالًا.
 *
 * ═══ ولمَ موضعٌ واحد ═══
 *
 * ميتا لا تقبل ملفًّا في جسم الرسالة. الطريقُ ثلاثُ خطوات: يُرفع الملفُّ إلى
 * `/media` فيُعاد معرّف، ثمّ تُرسل رسالةٌ تحمل المعرّف، ثمّ — في الوارد —
 * يُسأل المعرّفُ عن رابطٍ مؤقّتٍ ويُنزَّل به بالرمز نفسِه.
 *
 * وثلاثُ خطواتٍ مكتوبةٌ في مكانين تفترقان يومَ تُبدَّل نسخةُ الواجهة.
 *
 * ═══ وحدُّ ما يُقبل ═══
 *
 * صورةٌ وملفُّ PDF — لا صوتَ ولا فيديو ولا ملصق. وليس ذلك تقصيرًا يُعتذر
 * عنه: ما لا تعرضه شاشةٌ ولا يُقرأ في خيطٍ لا يُنزَّل ولا يُخزَّن. والواردُ
 * من نوعٍ آخر يُكتب بنوعه سطرًا يُقرأ — انظر `SupportWhatsApp::inboundBody`.
 *
 * ═══ والقائمةُ واحدة ═══
 *
 * هنا تُكتب الامتدادات والحدود، ومنها يقرأ الدعمُ والمبيعات والتحقّقُ
 * والشاشة. «حقلان يقولان الشيء نفسه يفترقان يومًا»: قائمةٌ في الدعم وأخرى
 * في المبيعات تعني ملفًّا يُقبل رفعُه ثمّ تردّه ميتا.
 */
final class WhatsAppMedia
{
    /* ═══════════════════ ما يُقبل ═══════════════════ */

    /** الامتدادات — ومنها تُبنى قواعدُ التحقّق ونصُّ الشاشة */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /**
     * بالكيلوبايت — عشرةُ ميجابايت.
     *
     * وهو أقلُّ من سقف ميتا للمستندات (مئة) وأعلى من سقفها للصور (خمسة).
     * فالصورةُ الكبيرة تُردّ من ميتا لا من عندنا — ويُنظر `IMAGE_MAX_KB`.
     */
    public const MAX_KB = 10240;

    /** سقفُ ميتا للصورة — خمسةُ ميجابايت، ويُفحص قبل النداء لا بعده */
    public const IMAGE_MAX_KB = 5120;

    /** وثلاثةٌ تكفي صورةَ شاشةٍ وسجلًّا وورقة */
    public const MAX_FILES = 3;

    /** الأنواعُ التي نرفعها ونُنزّلها — وما سواها يُكتب بنوعه ولا يُحفظ */
    public const KINDS = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'image/webp' => 'image',
        'application/pdf' => 'document',
    ];

    /** امتدادُ كلّ نوعٍ — للوارد، إذ يأتي بلا اسمٍ أحيانًا */
    public const SUFFIXES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * أيُّ نوعٍ عند ميتا يحمل هذا الـmime — أو لا شيء.
     *
     * و`image/jpg` ليست mime صحيحة لكنّ متصفّحاتٍ ترسلها، فتُقبل بحرفها:
     * ردُّ ملفٍّ سليمٍ لخطأٍ في المتصفّح يُقرأ عطبًا عندنا.
     */
    public static function kind(?string $mime): ?string
    {
        $mime = strtolower(trim((string) $mime));

        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        return self::KINDS[$mime] ?? null;
    }

    /** الامتدادُ المناسب لنوعٍ — و`bin` لما لا نعرفه */
    public static function suffix(?string $mime): string
    {
        $mime = strtolower(trim((string) $mime));

        return self::SUFFIXES[$mime === 'image/jpg' ? 'image/jpeg' : $mime] ?? 'bin';
    }

    /**
     * قواعدُ التحقّق لحقلِ ملفّاتٍ اسمه $field.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $field): array
    {
        return [
            $field => ['nullable', 'array', 'max:'.self::MAX_FILES],
            $field.'.*' => ['file', 'max:'.self::MAX_KB, 'extensions:'.implode(',', self::EXTENSIONS)],
        ];
    }

    /* ═══════════════════ الطريق إلى ميتا ═══════════════════ */

    private static function base(): string
    {
        return rtrim((string) config('whatsapp.graph_url'), '/')
            .'/'.config('whatsapp.api_version');
    }

    /**
     * مهلةُ نداءِ الملفّات — أطولُ من مهلة النصّ.
     *
     * رفعُ عشرةِ ميجابايت من خادمٍ في أوروبا لا يشبه إرسالَ سطر، وخمسَ عشرةَ
     * ثانيةً تكفي النصَّ وتقطع الملفَّ في منتصفه فيُقيَّد «فشل» عن اتّصالٍ
     * كان يعمل.
     */
    private static function client(WhatsAppConnection $connection): PendingRequest
    {
        return Http::withToken($connection->access_token)
            ->timeout((int) config('whatsapp.timeout', 15) * 4);
    }

    /**
     * رفعُ ملفٍّ إلى ميتا — ويُعاد معرّفُه.
     *
     * والمعرّفُ يعيش عند ميتا ثلاثين يومًا ثمّ يُحذف؛ ونحن نستعمله في
     * اللحظة نفسِها فلا يُخزَّن ولا يُعاد استعماله بعد أسبوع.
     *
     * @return array{ok:bool, id:?string, code:?string, message:?string}
     */
    public static function upload(
        WhatsAppConnection $connection,
        string $disk,
        string $path,
        string $mime,
        string $name,
    ): array {
        $kind = self::kind($mime);

        if ($kind === null) {
            return self::no('unsupported_type', __('نوعُ الملفّ لا يُرسَل على واتساب.'));
        }

        if (! Storage::disk($disk)->exists($path)) {
            return self::no('file_missing', __('لم يُعثر على الملفّ المرفوع.'));
        }

        /*
         * وحدُّ الصورة يُقاس قبل النداء لا بعده.
         *
         * ميتا تردّ صورةً فوق خمسةِ ميجابايت بخطأٍ عامّ، فيُكتب في الشاشة
         * نصٌّ إنجليزيٌّ لا يقول للموظّف ما يفعل. والقياسُ هنا يقول له.
         */
        $bytes = (int) Storage::disk($disk)->size($path);

        if ($kind === 'image' && $bytes > self::IMAGE_MAX_KB * 1024) {
            return self::no('image_too_large', __('الصورةُ أكبرُ من :n ميجابايت — وهو حدُّ واتساب للصور.', [
                'n' => (int) (self::IMAGE_MAX_KB / 1024),
            ]));
        }

        try {
            $response = self::client($connection)
                ->attach('file', Storage::disk($disk)->get($path), $name, ['Content-Type' => $mime])
                ->post(self::base().'/'.$connection->phone_number_id.'/media', [
                    'messaging_product' => 'whatsapp',
                    'type' => $mime,
                ]);
        } catch (\Throwable $e) {
            return self::no('network_error', $e->getMessage());
        }

        $id = $response->successful() ? $response->json('id') : null;

        if (filled($id)) {
            return ['ok' => true, 'id' => (string) $id, 'code' => null, 'message' => null];
        }

        return self::no(
            (string) ($response->json('error.code') ?? $response->status()),
            (string) ($response->json('error.message') ?? __('تعذّر رفعُ الملفّ.')),
        );
    }

    /**
     * تنزيلُ ملفٍّ واردٍ بمعرّفه — سؤالٌ عن الرابط ثمّ سحبٌ به.
     *
     * والرابطُ المُعاد لا يُفتح بلا رمز: ميتا تطلب الترويسةَ نفسَها عليه،
     * وهو ينتهي بعد دقائق. فلا يُخزَّن رابطٌ في صفٍّ ولا يُعرض للمتصفّح —
     * ما يُخزَّن هو الملفُّ نفسُه على قرصنا الخاصّ.
     *
     * @return array{ok:bool, contents:?string, mime:?string, message:?string}
     */
    public static function download(WhatsAppConnection $connection, string $mediaId): array
    {
        if (trim($mediaId) === '') {
            return ['ok' => false, 'contents' => null, 'mime' => null, 'message' => __('لا معرّفَ للملفّ.')];
        }

        try {
            $lookup = self::client($connection)->acceptJson()->get(self::base().'/'.$mediaId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'contents' => null, 'mime' => null, 'message' => $e->getMessage()];
        }

        $url = $lookup->successful() ? (string) $lookup->json('url', '') : '';

        if ($url === '') {
            return [
                'ok' => false, 'contents' => null, 'mime' => null,
                'message' => (string) ($lookup->json('error.message') ?? __('لم تُعِد ميتا رابطًا للملفّ.')),
            ];
        }

        $mime = (string) $lookup->json('mime_type', '');

        try {
            $file = self::client($connection)->get($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'contents' => null, 'mime' => null, 'message' => $e->getMessage()];
        }

        if (! $file->successful()) {
            return ['ok' => false, 'contents' => null, 'mime' => null, 'message' => __('تعذّر تنزيلُ الملفّ.')];
        }

        $body = $file->body();

        /*
         * وملفٌّ فارغٌ ليس ملفًّا.
         *
         * حفظُ صفرِ بايتٍ يعني مرفقًا في الشاشة يُضغط فلا يُفتح — وذلك أسوأُ
         * من سطرٍ يقول «أرسل صورةً لم تُنزَّل».
         */
        if ($body === '') {
            return ['ok' => false, 'contents' => null, 'mime' => null, 'message' => __('وصل الملفُّ فارغًا.')];
        }

        return ['ok' => true, 'contents' => $body, 'mime' => $mime !== '' ? $mime : null, 'message' => null];
    }

    /* ═══════════════════ الإخراج ═══════════════════ */

    /**
     * إخراجُ ردٍّ كاملٍ إلى واتساب — نصُّه ثمّ ملفّاته، بالترتيب.
     *
     * ═══ ولمَ رسائلُ ميتا عدّةٌ لا واحدة ═══
     *
     * واتساب لا يحمل ملفَّين في رسالة، ولا نصًّا طويلًا مع ملفّ: التعليقُ
     * يقف عند ألفٍ وأربعةٍ وعشرين حرفًا. فالردُّ الواحد عندنا يخرج عدّةَ
     * رسائل عندهم — والنصُّ أوّلًا ليقرأ التاجرُ لماذا يصله ملفّ.
     *
     * ═══ وما يُكتب حين يسقط بعضُه ═══
     *
     * خرج النصُّ وسقط المرفق: ليست «أُرسلت» — فالتاجرُ لم يرَ الصورة. وليست
     * «لم تُرسل» — فقد قرأ النصّ، ومن أعاد الإرسالَ عليها أرسله مرّتين.
     * فحالٌ ثالثة تُقال بحرفها: `partial`. «تقريرُ حالٍ كاذب أسوأ من غياب
     * التقرير».
     *
     * @param  list<array{disk:string,path:string,mime:string,name:string}>  $files
     * @return array{state:string, id:?string, message:?string, sent:int}
     */
    public static function deliver(
        WhatsAppConnection $line,
        string $to,
        ?string $body,
        array $files = [],
    ): array {
        $text = trim((string) $body);
        $sent = 0;
        $first = null;

        if ($text !== '') {
            $result = MetaWhatsAppClient::sendText($line, $to, $text);

            if (! $result['ok']) {
                return ['state' => 'failed', 'id' => null, 'message' => (string) $result['message'], 'sent' => 0];
            }

            $first = $result['id'];
            $sent++;
        }

        foreach ($files as $file) {
            $kind = self::kind($file['mime'] ?? null);

            /*
             * وملفٌّ لا يحمله واتساب لا يُسكت عنه.
             *
             * التحقّقُ عند الرفع يمنع غيرَ المسموح، لكنّ هذا المسارَ يُنادى
             * من أكثرَ من باب. وتخطّي الملفّ صامتًا يعني موظّفًا يظنّ أنّه
             * أرسله.
             */
            if ($kind === null) {
                return self::stopped($first, $sent, __('نوعُ الملفّ لا يُرسَل على واتساب.'));
            }

            $upload = self::upload($line, $file['disk'], $file['path'], $file['mime'], $file['name']);

            if (! $upload['ok']) {
                return self::stopped($first, $sent, (string) $upload['message']);
            }

            $result = MetaWhatsAppClient::sendMedia(
                $line,
                $to,
                $kind,
                (string) $upload['id'],
                caption: null,
                filename: $file['name'],
            );

            if (! $result['ok']) {
                return self::stopped($first, $sent, (string) $result['message']);
            }

            $first ??= $result['id'];
            $sent++;
        }

        // لا نصَّ ولا ملفّ — ولا يُدّعى أنّ شيئًا خرج
        if ($sent === 0) {
            return ['state' => 'failed', 'id' => null, 'message' => __('لا شيءَ يُرسَل.'), 'sent' => 0];
        }

        return ['state' => 'sent', 'id' => $first, 'message' => null, 'sent' => $sent];
    }

    /**
     * @return array{state:string, id:?string, message:string, sent:int}
     */
    private static function stopped(?string $first, int $sent, string $reason): array
    {
        return [
            'state' => $sent > 0 ? 'partial' : 'failed',
            'id' => $first,
            'message' => $reason,
            'sent' => $sent,
        ];
    }

    /**
     * @return array{ok:bool, id:null, code:string, message:string}
     */
    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'id' => null, 'code' => $code, 'message' => $message];
    }
}
