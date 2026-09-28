<?php

namespace App\Support;

/**
 * نسخةٌ يتجاوز ما فيها بعد فكّ الضغط حدَّ القراءة — فلا تُقرأ ولا تُستعاد.
 */
class BackupTooLarge extends \RuntimeException
{
    public function __construct(public readonly int $max)
    {
        parent::__construct('backup payload exceeds '.$max.' bytes once decompressed');
    }
}
