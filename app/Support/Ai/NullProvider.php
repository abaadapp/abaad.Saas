<?php

namespace App\Support\Ai;

/**
 * لا مزوّد — حالٌ صريحةٌ لا عطل.
 *
 * ولا يردّ نصًّا مُصطنعًا: «مرحبًا، كيف أساعدك؟» من مزوّدٍ وهميّ يُرسَل إلى
 * عميلٍ حقيقيّ على واتساب. فيُردّ الفشلُ بسببه، وتقول الشاشةُ إنّ المساعد
 * غير مفعَّل.
 */
final class NullProvider implements AiProvider
{
    public function complete(string $system, array $messages): AiReply
    {
        return AiReply::fail(__('المساعد الذكيّ غير مفعَّل — لا مزوّد مضبوط على الخادم.'));
    }

    public function ready(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'null';
    }
}
