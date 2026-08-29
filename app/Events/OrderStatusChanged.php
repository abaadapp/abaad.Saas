<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حالةُ طلبٍ تغيّرت — يُعلَن مرّةً ويسمعه من يعنيه.
 *
 * ولا يُطلَق من المتحكّمات: ثلاثة مواضع تكتب الحالة اليوم (شاشة الطلب،
 * لوحة التجهيز، الصندوق)، ورابعٌ يُضاف غدًا فينساه من يكتبه. فمصدرُه
 * `OrderObserver` — يسمع الكتابة نفسها، فلا موضعَ يفوته.
 */
class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public Order $order,
        public ?string $from,
        public string $to,
    ) {}
}
