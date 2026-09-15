<?php

namespace App\Support\Archive;

/**
 * قيمةٌ تُكتب نصًّا ولو بدت رقمًا.
 *
 * أرقامُ المستندات أوّلُ ضحايا إكسل: «0012» تصير ١٢، و«2026-08» تصير
 * تاريخًا، و«+5» تصير ٥. فيبحث المحاسبُ عن الفاتورة برقمها ولا يجدها،
 * ويظنّ أنّ النظام أسقطها.
 */
final class Text
{
    public function __construct(public readonly string $value) {}

    public static function of(?string $value): self
    {
        return new self((string) $value);
    }
}
