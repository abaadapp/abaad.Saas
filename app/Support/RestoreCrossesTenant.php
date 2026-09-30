<?php

namespace App\Support;

/**
 * نسخةٌ تُشير إلى صفٍّ ليس لمتجرها — فلا تُثبَّت الاستعادة.
 *
 * تُرمى داخل معاملة الاستعادة فتُلغيها كلَّها: المحوُ والإدراجُ معًا. والرسالةُ
 * تحمل الجدولَ والعمود للسجلّ، لا للتاجر.
 */
class RestoreCrossesTenant extends \RuntimeException
{
    public function __construct(public readonly string $where)
    {
        parent::__construct('restore crosses tenant: '.$where);
    }
}
