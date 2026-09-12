<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Http;

/**
 * نداءُ Claude — والمفتاحُ من البيئة لا من قاعدة البيانات.
 *
 * ═══ وما لا يُفعل هنا ═══
 *
 * لا يُكتب المفتاحُ في سجلٍّ ولا في رسالةِ خطأ ولا يُعاد إلى شاشة. ورسالةُ
 * المزوّد تُقصّ: ردودُ الخطأ تحمل أحيانًا صدى ما أُرسل.
 */
final class AnthropicProvider implements AiProvider
{
    public function ready(): bool
    {
        return filled(config('ai.anthropic.key'));
    }

    public function name(): string
    {
        return 'anthropic:'.(string) config('ai.anthropic.model');
    }

    public function complete(string $system, array $messages): AiReply
    {
        if (! $this->ready()) {
            return AiReply::fail(__('لا مفتاح للمزوّد على الخادم.'));
        }

        try {
            $response = Http::timeout((int) config('ai.timeout', 20))
                ->withHeaders([
                    'x-api-key' => (string) config('ai.anthropic.key'),
                    'anthropic-version' => (string) config('ai.anthropic.version'),
                    'content-type' => 'application/json',
                ])
                ->post((string) config('ai.anthropic.url'), [
                    'model' => (string) config('ai.anthropic.model'),
                    'max_tokens' => (int) config('ai.max_tokens', 600),
                    'system' => $system,
                    'messages' => $messages,
                ]);
        } catch (\Throwable $e) {
            /*
             * ولا يُرفع الاستثناء: تعذّرُ شبكةٍ حالٌ متوقّعة، وصفحةُ خمسمئة
             * تُفقد موظّفَ المبيعات ما كتبه في المُحرِّر.
             *
             * ورسالةُ الاستثناء لا تُعرض: قد تحمل العنوانَ ورؤوسَه.
             */
            report($e);

            return AiReply::fail(__('تعذّر الوصول إلى المزوّد.'));
        }

        if (! $response->successful()) {
            return AiReply::fail(__('ردّ المزوّد برمز :code.', ['code' => $response->status()]));
        }

        /* ونصُّ الجواب من كتل `text` وحدَها — وما سواها يُهمَل بهدوء */
        $text = '';
        foreach ((array) $response->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        $text = trim($text);

        /*
         * وجوابٌ فارغٌ فشلٌ لا نجاح.
         *
         * زرُّ «استخدام الردّ» على فراغٍ يُرسل رسالةً فارغةً إلى عميل — أو
         * يُردّ بخطأ تحقّقٍ لا يفهمه أحد.
         */
        return $text === ''
            ? AiReply::fail(__('ردّ المزوّد بلا نصّ.'))
            : AiReply::ok($text, $this->name());
    }
}
