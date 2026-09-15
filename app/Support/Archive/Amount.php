<?php

namespace App\Support\Archive;

/**
 * مبلغٌ يُكتب رقمًا ويُنسَّق بثلاث منازل.
 *
 * ورقمًا لا نصًّا: المحاسبُ يجمع العمودَ في إكسل، ونصٌّ يبدو رقمًا يُخرج
 * مجموعًا صفرًا بلا شكوى. والتنسيقُ يمنع `132.02000000000001` من الظهور —
 * وهو ما يجعل ورقةً صحيحةً تبدو معطوبة.
 */
final class Amount
{
    public function __construct(public readonly float $value) {}

    public static function of(mixed $value): self
    {
        return new self((float) $value);
    }
}
