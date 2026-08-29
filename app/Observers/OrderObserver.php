<?php

namespace App\Observers;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * من يسمع تغيّر الحالة — عند الكتابة لا عند المتحكّم.
 *
 * والإعلان بعد تثبيت المعاملة: بيعُ الصندوق يكتب الطلب وبنودَه ومخزونَه
 * وقيدَه المالي في معاملةٍ واحدة قد تُلغى. ولو أُعلن داخلها لَخرجت رسالة عن
 * طلبٍ لا وجود له بعد ثوانٍ.
 *
 * والمعلَّق لا يُعلَن عنه: السلّة المعلّقة ليست طلبًا بعد.
 */
class OrderObserver
{
    public function created(Order $order): void
    {
        if ($order->is_held) {
            return;
        }

        $this->announce($order, null, (string) $order->status);
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status') || $order->is_held) {
            return;
        }

        $this->announce($order, $order->getOriginal('status'), (string) $order->status);
    }

    private function announce(Order $order, ?string $from, string $to): void
    {
        DB::afterCommit(fn () => OrderStatusChanged::dispatch($order, $from, $to));
    }
}
