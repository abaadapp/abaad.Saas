<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Demo;
use App\Support\OrderCorrection;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * تصحيح فاتورةٍ من شاشة الكاشير.
 *
 * الكتابة كلّها في `OrderCorrection`: الشاشة تقول ماذا يريد، والدالّة تعرف
 * ما الذي يتحرّك معه — المخزون والضريبة والنقاط والمعاملة المالية.
 */
class OrderEditController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function update(Request $request, string $number, int $itemId)
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:9999'],
            /*
             * السبب مطلوبٌ لا اختياريّ.
             *
             * تصحيحٌ بلا سببٍ سطرٌ لا يُدقَّق: يقرأ صاحب النشاط أن الكميّة
             * نقصت من ثلاثةٍ إلى واحد ولا يعرف أخطأً كان أم عودةَ زبونٍ أم
             * شيئًا آخر — والفرق بين الثلاثة هو كلّ ما يهمّه.
             */
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => __('اكتب سبب التعديل — بدونه لا يُعرف لماذا تغيّرت الفاتورة.'),
            'reason.min' => __('السبب قصير جدًّا — اكتب ما يفهمه من يقرأ الفاتورة لاحقًا.'),
        ]);

        $order = Order::where('business_id', $this->bid())
            ->where('is_held', false)
            ->where('number', $number)
            ->firstOrFail();

        $item = OrderItem::where('order_id', $order->id)->findOrFail($itemId);

        try {
            OrderCorrection::setQuantity($order, $item, (int) $data['quantity'], trim($data['reason']));
        } catch (RuntimeException $e) {
            return back()->withErrors(['quantity' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => (int) $data['quantity'] === 0 ? __('حُذف البند وصُحّحت الفاتورة') : __('صُحّحت الفاتورة'),
            'type' => 'success',
        ]);
    }

    /**
     * تصحيح كميّة إضافةٍ على بند — والصفر يحذفها.
     *
     * وردُّ المخزون بلقطة البند لا بإعداد الإضافة اليوم: انظر
     * `OrderCorrection::setAddonQuantity`.
     */
    public function addon(Request $request, string $number, int $itemId, int $addonId)
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:9999'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => __('اكتب سبب التعديل — بدونه لا يُعرف لماذا تغيّرت الفاتورة.'),
            'reason.min' => __('السبب قصير جدًّا — اكتب ما يفهمه من يقرأ الفاتورة لاحقًا.'),
        ]);

        $order = Order::where('business_id', $this->bid())
            ->where('is_held', false)
            ->where('number', $number)
            ->firstOrFail();

        // البند من هذه الفاتورة، والإضافة من ذلك البند — سلسلةٌ تُفحص حلقةً
        // حلقة، وإلّا صحّح متجرٌ إضافةً في فاتورة متجرٍ آخر بمعرّفٍ مُخمَّن
        $item = OrderItem::where('order_id', $order->id)->findOrFail($itemId);
        $row = \App\Models\OrderItemAddon::where('order_item_id', $item->id)->findOrFail($addonId);

        try {
            OrderCorrection::setAddonQuantity($order, $row, (int) $data['quantity'], trim($data['reason']));
        } catch (RuntimeException $e) {
            return back()->withErrors(['quantity' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => (int) $data['quantity'] === 0 ? __('حُذفت الإضافة وصُحّحت الفاتورة') : __('صُحّحت الفاتورة'),
            'type' => 'success',
        ]);
    }

    /**
     * تصحيح وسيلة الدفع.
     *
     * أثرها في الدرج لا في الرفّ: «نقدي» سُجّل على دفعةٍ بالبطاقة يجعل
     * الإقفال يطلب مالًا لم يدخل الصندوق.
     */
    public function payment(Request $request, string $number)
    {
        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:50'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => __('اكتب سبب التصحيح — بدونه لا يُعرف لماذا تغيّرت الفاتورة.'),
            'reason.min' => __('السبب قصير جدًّا — اكتب ما يفهمه من يقرأ الفاتورة لاحقًا.'),
        ]);

        $order = Order::where('business_id', $this->bid())
            ->where('is_held', false)
            ->where('number', $number)
            ->firstOrFail();

        try {
            OrderCorrection::setPaymentMethod($order, $data['payment_method'], trim($data['reason']));
        } catch (RuntimeException $e) {
            return back()->withErrors(['payment_method' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('صُحّحت وسيلة الدفع'), 'type' => 'success']);
    }
}
