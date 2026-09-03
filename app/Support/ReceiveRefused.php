<?php

namespace App\Support;

/**
 * رفضٌ يُقال داخل معاملة — لا `return` من داخلها.
 *
 * فحصُ الاستلام صار تحت قفل، والقفل داخل المعاملة. والرفض هناك لا يُردّ
 * برسالةٍ إلى المتصفّح مباشرةً: لا بدّ أن تتراجع المعاملة أوّلًا وإلّا بقي
 * ما كُتب قبل الرفض. فيُرفع الرفض ويُلتقط خارجها.
 */
class ReceiveRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly string $tone = 'danger')
    {
        parent::__construct($message);
    }
}
