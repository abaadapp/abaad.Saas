<?php

namespace App\Support;

use App\Models\InstagramConnection;
use Illuminate\Support\Facades\Cache;

/**
 * ‏‏ما يخرج من أبعاد إلى إنستقرام — رسالةٌ خاصّة، أو ردٌّ خاصٌّ على تعليق، أو ردٌّ علنيٌّ تحته — وقراءةُ المنشورات.
 *
 * ═══ حدودُ Meta كما في وثائقها ═══
 *
 *  - نصُّ الرسالة ١٠٠٠ **بايت** لا حرف: نحو خمسمئة حرفٍ عربيّ (`TEXT_BYTES`).
 *  - الردُّ الخاصّ على التعليق: رسالةٌ واحدة لكلّ تعليق، في سبعة أيّام من كتابته، ونصٌّ لا غير.
 *  - أزرارُ الردّ السريع: نصٌّ بعشرين حرفًا، ولا تظهر على الحاسوب. وزرُّ الرابط: نصٌّ فوقه بـ٦٤٠ حرفًا.
 *
 * والجوابُ من كلّ إرسالٍ واحد: خرج بمعرّفه، أو يُعاد لاحقًا (`later`)، أو لم يخرج بسببه. ورمزٌ رفضته Meta
 * يجعل الوصلة «أعد الربط» هنا، فلا تُطرق ثانيةً برمزٍ ميّت.
 */
class InstagramMessenger
{
    public const TEXT_BYTES = 1000;

    public const BUTTON_TEXT = 640;

    public const COMMENT_TEXT = 300;

    /** ‏‏آخرُ المنشورات في منتقي المعالج */
    public const PICKER_POSTS = 50;

    public const PICKER_TTL_SECONDS = 120;

    /**
     * ‏‏رسالةٌ خاصّة لزبونٍ كتب إلينا — في نافذة الأربع والعشرين.
     *
     * @param  array<string, mixed>  $message  كما في `ChannelAutomations::message`
     * @return array{ok: bool, later: bool, message_id: ?string, code: ?string}
     */
    public static function send(InstagramConnection $c, string $to, array $message): array
    {
        return self::post($c, ['id' => $to], self::payload($message));
    }

    /**
     * ‏‏ردٌّ خاصٌّ على تعليق — نصٌّ لا غير: الوثيقةُ لا تذكر فيه أزرارًا.
     *
     * @return array{ok: bool, later: bool, message_id: ?string, code: ?string}
     */
    public static function privateReply(InstagramConnection $c, string $commentId, string $text): array
    {
        return self::post($c, ['comment_id' => $commentId], ['text' => self::cut($text)]);
    }

    /** @return array{ok: bool, code: ?string} */
    public static function publicReply(InstagramConnection $c, string $commentId, string $text): array
    {
        $r = InstagramGraph::json('/'.rawurlencode($commentId).'/replies', (string) $c->access_token, ['message' => mb_substr($text, 0, self::COMMENT_TEXT)]);

        return ['ok' => $r['ok'], 'code' => $r['ok'] ? null : self::code($r)];
    }

    /**
     * ‏‏شكلُ الرسالة عند Meta: نصٌّ، أو نصٌّ بأزرار ردٍّ سريع، أو نصٌّ بزرّ رابط.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function payload(array $message): array
    {
        $body = (string) ($message['body'] ?? '');

        return match ($message['kind'] ?? 'text') {
            'buttons' => ['text' => self::cut($body), 'quick_replies' => array_map(
                fn ($b) => ['content_type' => 'text', 'title' => mb_substr((string) $b[1], 0, 20), 'payload' => (string) $b[0]],
                array_values((array) $message['buttons']),
            )],
            'cta' => ['attachment' => ['type' => 'template', 'payload' => [
                'template_type' => 'button',
                'text' => mb_substr($body, 0, self::BUTTON_TEXT),
                'buttons' => [['type' => 'web_url', 'url' => (string) $message['url'], 'title' => mb_substr((string) $message['label'], 0, 20)]],
            ]]],
            default => ['text' => self::cut($body)],
        };
    }

    /** ‏‏يُقصّ بالبايت ولا يُكسر حرف — والنصُّ المكتوبُ يُرفض نشرُه قبل أن يبلغ هنا (`ChannelAutomations::unpublishable`) */
    public static function cut(string $text): string
    {
        return rtrim(mb_strcut($text, 0, self::TEXT_BYTES, 'UTF-8'));
    }

    /**
     * ‏‏آخرُ منشورات الحساب لمنتقي المعالج — محفوظةً دقيقتين، فلا يُطرق إنستقرام بكلّ فتحةٍ للنافذة.
     *
     * @return array{ok: bool, posts: list<array<string, mixed>>, error: ?string}
     */
    public static function posts(InstagramConnection $c): array
    {
        if (! $c->usable()) {
            return ['ok' => false, 'posts' => [], 'error' => __('حساب إنستقرام غير مربوط الآن — أعد ربطه.')];
        }

        $key = 'instagram_posts:'.$c->id.':'.$c->ig_user_id;
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return ['ok' => true, 'posts' => $cached, 'error' => null];
        }

        $r = InstagramGraph::get('/'.$c->ig_user_id.'/media', [
            'fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp',
            'limit' => self::PICKER_POSTS,
            'access_token' => (string) $c->access_token,
        ]);

        if (! $r['ok']) {
            self::dead($c, $r);

            return ['ok' => false, 'posts' => [], 'error' => __('لم نقرأ منشورات إنستقرام: :why', ['why' => $r['error']])];
        }

        $posts = array_values(array_map(fn ($m) => [
            'id' => (string) ($m['id'] ?? ''),
            'caption' => mb_substr(trim((string) ($m['caption'] ?? '')), 0, 120),
            // ‏‏والفيديو والريلز بصورة غلافه — ورابطُ الفيديو ليس صورة
            'image' => (string) ($m['thumbnail_url'] ?? (($m['media_type'] ?? '') === 'VIDEO' ? '' : ($m['media_url'] ?? ''))) ?: null,
            'reel' => ($m['media_product_type'] ?? '') === 'REELS',
            'link' => filled($m['permalink'] ?? null) ? (string) $m['permalink'] : null,
            'at' => filled($m['timestamp'] ?? null) ? (string) $m['timestamp'] : null,
        ], array_filter((array) ($r['body']['data'] ?? []), fn ($m) => filled($m['id'] ?? null))));

        Cache::put($key, $posts, self::PICKER_TTL_SECONDS);

        return ['ok' => true, 'posts' => $posts, 'error' => null];
    }

    /** @return array{ok: bool, later: bool, message_id: ?string, code: ?string} */
    private static function post(InstagramConnection $c, array $recipient, array $message): array
    {
        $r = InstagramGraph::json('/'.$c->ig_user_id.'/messages', (string) $c->access_token, ['recipient' => $recipient, 'message' => $message]);

        if ($r['ok'] && filled($r['body']['message_id'] ?? null)) {
            return ['ok' => true, 'later' => false, 'message_id' => mb_substr((string) $r['body']['message_id'], 0, 191), 'code' => null];
        }

        self::dead($c, $r);

        return ['ok' => false, 'later' => $r['later'], 'message_id' => null, 'code' => self::code($r)];
    }

    /** ‏‏رمزٌ رفضته Meta — يُقال للتاجر «أعد الربط» */
    private static function dead(InstagramConnection $c, array $r): void
    {
        if ($r['code'] === InstagramGraph::TOKEN_ERROR) {
            InstagramLink::needsRelink($c, $r['error']);
        }
    }

    private static function code(array $r): string
    {
        return $r['code'] !== null ? 'meta_'.$r['code'] : ($r['error'] === 'network_error' ? 'network_error' : 'http_'.$r['status']);
    }
}
