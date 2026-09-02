<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptNote;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;

/**
 * أوراقُ النظام تخرج على ورق.
 *
 * وكانت تُنشأ ولا تُطبع: أمرُ شراءٍ يُرسل إلى مورّد بالهاتف، وسندُ استلامٍ
 * يُوقَّع على ورقةٍ تُكتب باليد، وشحنةٌ تمشي بلا سندٍ يُوقّعه مستلمها. فما
 * في النظام لا يُثبت شيئًا عند خلاف.
 *
 * والقيدُ في كلّ دالّة واحد: `business_id` في الاستعلام لا في الشاشة. ورقمٌ
 * مُخمَّن في العنوان يفتح ورقةَ جارٍ إن غاب — ولا يظهر ذلك في أيّ سجلّ.
 */
class DocumentPrintController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /** سند تسليم لطلب — يمشي مع الشحنة ويُوقَّع عند الاستلام */
    public function delivery(string $number)
    {
        $bid = $this->bid();
        $order = Order::where('business_id', $bid)->where('number', $number)
            ->with('items')->firstOrFail();

        Activity::log('report', 'طبع سند تسليم للطلب: '.$order->number, ['subject_id' => $order->id]);

        return DocumentRenderer::pdf(
            DocumentRenderer::generic($bid, 'delivery', DocumentPaper::forDelivery($order)),
            'delivery-'.$order->number,
        );
    }

    public function purchase(int $id)
    {
        $bid = $this->bid();
        $po = PurchaseOrder::where('business_id', $bid)->whereKey($id)
            ->with('items', 'supplier')->firstOrFail();

        Activity::log('report', 'طبع أمر الشراء: '.$po->number, ['subject_id' => $po->id]);

        return DocumentRenderer::pdf(
            DocumentRenderer::generic($bid, 'purchase', DocumentPaper::forPurchase($po)),
            'purchase-'.$po->number,
        );
    }

    public function grn(int $id)
    {
        $bid = $this->bid();
        $note = GoodsReceiptNote::where('business_id', $bid)->whereKey($id)
            ->with('items', 'supplier', 'branch', 'purchaseOrder')->firstOrFail();

        Activity::log('report', 'طبع سند الاستلام: '.$note->number, ['subject_id' => $note->id]);

        return DocumentRenderer::pdf(
            DocumentRenderer::generic($bid, 'grn', DocumentPaper::forGrn($note)),
            'grn-'.$note->number,
        );
    }
}
