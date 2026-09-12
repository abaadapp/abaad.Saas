<?php

namespace App\Support\Ai;

/**
 * جوابُ المزوّد — نجاحٌ بنصّ، أو فشلٌ بسببٍ يُقرأ.
 *
 * ولا تُرفع استثناءات: فشلُ نداءٍ خارجيّ حالٌ متوقّعة لا عطبُ برمجة، وصفحةُ
 * خمسمئة على تعذّرِ اقتراحٍ تُفقد موظّفَ المبيعات ما كتبه في المُحرِّر.
 */
final class AiReply
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $text,
        public readonly ?string $error,
        public readonly ?string $model,
    ) {}

    public static function ok(string $text, ?string $model = null): self
    {
        return new self(true, $text, null, $model);
    }

    public static function fail(string $error): self
    {
        return new self(false, '', $error, null);
    }
}
