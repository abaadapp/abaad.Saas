<?php

namespace App\Support;

use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Log;

/**
 * ما يخرج من الطلب الآليّ — يُرسل من رقم المتجر ويُكتب في صندوق محادثاته.
 *
 * كلُّ رسالةٍ صفٌّ في `whatsapp_chat_messages` كردِّ موظّف: تظهر في المحادثة
 * وفي سجلّ واتساب، وتحمل حالَها (أُرسلت، وصلت، قُرئت، فشلت) من إشعارات ميتا
 * بمعرّفها — بالباب نفسه الذي يحدّث ردودَ الموظّفين (`WhatsAppInbox::applyStatus`).
 * والمرسِلُ «الطلب الآلي» لا موظّف: لا `sender_user_id`.
 *
 * والرسالةُ مصفوفةٌ بنوعها:
 *  - `['kind' => 'text', 'body' => …]`
 *  - `['kind' => 'buttons', 'body' => …, 'buttons' => [[id, title], …]]` (ثلاثةٌ على الأكثر)
 *  - `['kind' => 'list', 'body' => …, 'button' => …, 'rows' => [[id, title, description?], …]]` (عشرةٌ على الأكثر)
 *  - `['kind' => 'cta', 'body' => …, 'label' => …, 'url' => …]`
 */
class WhatsAppOrderReplies
{
    /** حدودُ ميتا للرسائل التفاعليّة */
    public const BODY_MAX = 1024;

    public const TEXT_MAX = 4096;

    public const BUTTONS_MAX = 3;

    public const BUTTON_TITLE_MAX = 20;

    public const ROWS_MAX = 10;

    public const ROW_TITLE_MAX = 24;

    public const ROW_DESCRIPTION_MAX = 72;

    /**
     * يُرسل رسالةً ويكتبها — والنداءُ خارجَ أيّ معاملة (`WhatsAppInbox::deliverPart`).
     *
     * والصفُّ يُكتب أوّلًا «في الطابور» ثمّ يُحدَّث بما ردّت ميتا: رسالةٌ لم
     * تخرج تبقى في المحادثة «فشلت» بسببها، ولا تختفي.
     *
     * @param  array<string, mixed>  $message
     */
    public static function send(WhatsAppChat $chat, WhatsAppConnection $line, array $message, string $lang): WhatsAppChatMessage
    {
        $record = WhatsAppChatMessage::create([
            'business_id' => $chat->business_id,
            'chat_id' => $chat->id,
            'direction' => WhatsAppChatMessage::OUT,
            'body' => self::readable($message),
            'whatsapp_connection_id' => $line->id,
            'phone_number_id' => (string) $line->phone_number_id,
            'sender_user_id' => null,
            'sender_name' => mb_substr(self::sender($lang), 0, 150),
            'status' => WhatsAppStatus::QUEUED,
        ]);

        $to = (string) $chat->customer_phone;
        $result = $message['kind'] === 'text'
            ? MetaWhatsAppClient::sendText($line, $to, mb_substr((string) $message['body'], 0, self::TEXT_MAX))
            : MetaWhatsAppClient::sendInteractive($line, $to, self::interactive($message));

        $record->forceFill($result['ok']
            ? ['status' => WhatsAppStatus::SENT, 'wamid' => $result['id'], 'sent_at' => now()]
            : [
                'status' => WhatsAppStatus::FAILED,
                'failed_at' => now(),
                'error_code' => mb_substr((string) ($result['code'] ?: 'provider_failed'), 0, 60),
                'error_message' => mb_substr((string) $result['message'], 0, 500),
            ])->save();

        $chat->forceFill([
            'last_message_at' => now(),
            'last_preview' => mb_substr((string) $message['body'], 0, 120),
            'last_direction' => WhatsAppChatMessage::OUT,
        ])->save();

        if (! $result['ok']) {
            // ولا نصَّ ولا رقمَ في السجلّ — الرمزُ والسببُ وحدهما
            Log::warning('whatsapp.order_reply_failed', [
                'business_id' => $chat->business_id,
                'meta_code' => $result['code'],
                'meta_message' => mb_substr((string) $result['message'], 0, 200),
            ]);
        }

        return $record;
    }

    /** اسمُ المرسِل في المحادثة — ليعرف الموظّفُ أنّ الآليَّ كتبها لا زميلُه */
    public static function sender(string $lang): string
    {
        return __('الطلب الآلي');
    }

    /**
     * كائنُ `interactive` كما تطلبه ميتا — بحدودها مقصوصةً هنا لا مرفوضةً هناك.
     *
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    public static function interactive(array $m): array
    {
        $body = ['text' => WhatsAppOrderTexts::fit((string) $m['body'], self::BODY_MAX)];

        return match ($m['kind']) {
            'buttons' => [
                'type' => 'button',
                'body' => $body,
                'action' => ['buttons' => array_map(fn ($b) => [
                    'type' => 'reply',
                    'reply' => ['id' => (string) $b[0], 'title' => WhatsAppOrderTexts::fit((string) $b[1], self::BUTTON_TITLE_MAX)],
                ], array_slice(array_values($m['buttons']), 0, self::BUTTONS_MAX))],
            ],
            'list' => [
                'type' => 'list',
                'body' => $body,
                'action' => [
                    'button' => WhatsAppOrderTexts::fit((string) $m['button'], self::BUTTON_TITLE_MAX),
                    'sections' => [[
                        'title' => WhatsAppOrderTexts::fit((string) $m['button'], self::ROW_TITLE_MAX),
                        'rows' => array_map(fn ($r) => array_filter([
                            'id' => (string) $r[0],
                            'title' => WhatsAppOrderTexts::fit((string) $r[1], self::ROW_TITLE_MAX),
                            'description' => filled($r[2] ?? null) ? WhatsAppOrderTexts::fit((string) $r[2], self::ROW_DESCRIPTION_MAX) : null,
                        ], fn ($v) => $v !== null), array_slice(array_values($m['rows']), 0, self::ROWS_MAX)),
                    ]],
                ],
            ],
            'cta' => [
                'type' => 'cta_url',
                'body' => $body,
                'action' => ['name' => 'cta_url', 'parameters' => [
                    'display_text' => WhatsAppOrderTexts::fit((string) $m['label'], self::BUTTON_TITLE_MAX),
                    'url' => (string) $m['url'],
                ]],
            ],
            default => throw new \InvalidArgumentException('Unknown interactive kind: '.$m['kind']),
        };
    }

    /**
     * ما يقرؤه الموظّفُ في المحادثة — النصُّ وما عُرض من خيارات.
     *
     * فمن فتح المحادثة يعرف ما سُئل عنه الزبون وبماذا أجاب، لا «رسالةٌ تفاعليّة» وحدها.
     *
     * @param  array<string, mixed>  $m
     */
    public static function readable(array $m): string
    {
        $lines = [(string) $m['body']];

        foreach (match ($m['kind']) {
            'buttons' => array_map(fn ($b) => $b[1], $m['buttons']),
            'list' => array_map(fn ($r) => $r[1].(filled($r[2] ?? null) ? ' — '.$r[2] : ''), $m['rows']),
            'cta' => [$m['label'].': '.$m['url']],
            default => [],
        } as $option) {
            $lines[] = '• '.$option;
        }

        return mb_substr(implode("\n", $lines), 0, self::TEXT_MAX);
    }
}
