<?php

namespace App\Support;

use RuntimeException;

/**
 * تغيّر المبلغُ بين الملخّص وكتابة الفاتورة — يُعاد الملخّصُ ويُسأل الزبونُ ثانيةً.
 */
final class WhatsAppOrderPriceChanged extends RuntimeException
{
    /** @param  array{quote: array<string, mixed>, items: list<array<string, mixed>>, totals: array<string, mixed>}  $preview */
    public function __construct(public readonly array $preview)
    {
        parent::__construct('The order total changed since the customer confirmed it.');
    }
}
