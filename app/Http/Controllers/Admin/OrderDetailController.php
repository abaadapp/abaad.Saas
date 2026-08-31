<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\FlowerOrder;
use App\Support\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * تعديل تفاصيل الطلب وحالته.
 *
 * منفصلٌ عن `OrderEditController`: ذاك يُصحّح الفاتورة — كميّاتٍ ووسيلةَ دفع
 * — فيُحرّك المخزون والضريبة والنقاط والمعاملة المالية، ويشترط سببًا مكتوبًا.
 * وهذا يُعدّل بيانات التنفيذ: من المستلِم، ومتى، وإلى أين. لا يمسّ ريالًا
 * واحدًا ولا قطعةً في الرفّ.
 *
 * وخلطُهما كان سيُلزم من يصحّح رقم هاتفٍ بكتابة «سبب تصحيح الفاتورة» في سجلّ
 * التدقيق المالي — فيمتلئ السجلّ بما ليس منه، ويضيع فيه ما يهمّ.
 */
class OrderDetailController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * الطلب داخل متجر المستخدم وفرعه — لا `Order::find` عاريةً.
     *
     * الرقم يصل من شريط العنوان، وطلبُ متجرٍ آخر بالرقم نفسه ليس مستبعدًا:
     * الترقيم يبدأ من واحدٍ عند كل تاجر. والفرع يُحترم كما تحترمه شاشة
     * المبيعات — من يعمل على فرعٍ بعينه لا يُحرّك طلبات فرعٍ لا يراه.
     */
    private function find(string $number): Order
    {
        return Order::where('business_id', $this->bid())
            ->where('is_held', false)
            ->when(Demo::currentBranchId(), fn ($w) => $w->where('branch_id', Demo::currentBranchId()))
            ->where('number', $number)
            ->firstOrFail();
    }

    /** تعديل بيانات التنفيذ — المستلِم والموعد والمناسبة والبطاقة والتوصيل */
    public function update(Request $request, string $number)
    {
        $order = $this->find($number);

        $data = $request->validate(
            FlowerOrder::rules() + ['delivery_fee' => ['sometimes', 'nullable', 'numeric', 'min:0']],
            FlowerOrder::messages()
        );

        if ($errors = FlowerOrder::afterValidation($data, $order->only([
            'fulfillment_type', 'recipient_name', 'recipient_phone', 'delivery_address',
            // الموعد واسم العميل يدخلان في حكم «طلبٌ يُجهَّز» — وقيمتُهما
            // المحفوظة هي المعتبَرة حين لا تفتحهما الشاشة
            'scheduled_for', 'customer_name',
        ]))) {
            return back()->withInput()->withErrors($errors);
        }

        $attrs = FlowerOrder::attributes($data);
        if (array_key_exists('delivery_fee', $data)) {
            /*
             * رسوم التوصيل تُعدَّل هنا ولا تُعاد حسبة الإجمالي معها.
             *
             * الإجمالي رقمٌ محاسبيّ قُيّد في معاملةٍ ماليّة وفي وردية وفي
             * تقرير — وتغييرُه من هذه الشاشة يجعل الفاتورة تقول غير ما يقوله
             * الدفتر. من أراد تغيير المبلغ يُصحّح الفاتورة من بابها، وهناك
             * يُطلب السبب وتُحرَّك المعاملة معه.
             */
            $attrs['delivery_fee'] = (float) ($data['delivery_fee'] ?? 0);
        }

        $before = $order->only(array_keys($attrs));
        $order->update($attrs);

        $this->logChanges($order, $before, $attrs);

        return back()->with('toast', ['msg' => __('حُفظت تفاصيل الطلب'), 'type' => 'success']);
    }

    /**
     * نقل الحالة — بحارسٍ في الخادم لا في الشاشة.
     *
     * الشاشة تعرض ما يجوز، والطلب يصل من عنوانٍ يُكتب. و«تم التسليم ← قيد
     * التجهيز» ليست خطأً في الترتيب: هي باقةٌ خرجت من المحلّ تُعاد إلى
     * طاولة العمل، فتُجهَّز مرّتين وتُحسب مرّتين.
     */
    public function status(Request $request, string $number)
    {
        $order = $this->find($number);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(OrderStatus::ALL)],
        ]);

        if (! OrderStatus::canMove($order->status, $data['status'])) {
            return back()->withErrors(['status' => __(
                'لا يمكن نقل الطلب من «:from» إلى «:to».',
                ['from' => $order->status, 'to' => $data['status']]
            )]);
        }

        $from = $order->status;
        $order->update(['status' => $data['status']]);

        Activity::log('status', 'نقل الطلب '.$order->number.' من «'.$from.'» إلى «'.$data['status'].'»', [
            'subject_id' => $order->id,
            'subject_type' => 'order',
        ]);

        return back()->with('toast', [
            'msg' => __('حالة الطلب: :status', ['status' => $data['status']]),
            'type' => 'success',
        ]);
    }

    /**
     * ما تغيّر يُقيَّد بقيمته القديمة والجديدة.
     *
     * «عُدّل الطلب» سطرٌ لا يُدقَّق: صاحب النشاط يقرأه فلا يعرف أنُقل الموعد
     * يومًا أم غُيّر العنوان بعد خروج السائق. والمقيَّد ما يُغيّر التنفيذ —
     * لا بطاقة الإهداء ولا المناسبة: تلك تُصحَّح مرّاتٍ قبل الطباعة، وقيدُها
     * يُغرق السجلّ بما لا يُسأل عنه أحد.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function logChanges(Order $order, array $before, array $after): void
    {
        $watched = [
            'scheduled_for' => 'موعد التسليم',
            'recipient_name' => 'اسم المستلِم',
            'recipient_phone' => 'هاتف المستلِم',
            'fulfillment_type' => 'نوع التنفيذ',
            'delivery_address' => 'عنوان التوصيل',
            'delivery_fee' => 'رسوم التوصيل',
        ];

        foreach ($watched as $field => $label) {
            if (! array_key_exists($field, $after)) {
                continue;
            }
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            // المقارنة نصًّا: التاريخ كائنٌ قبل الحفظ ونصٌّ بعده، و`!==`
            // عليهما تقول «تغيّر» عن قيمةٍ لم تتغيّر
            if ((string) $old === (string) $new) {
                continue;
            }

            Activity::log('updated', 'الطلب '.$order->number.' — '.__($label).': «'
                .($old === null || $old === '' ? '—' : $old).'» ← «'
                .($new === null || $new === '' ? '—' : $new).'»', [
                    'subject_id' => $order->id,
                    'subject_type' => 'order',
                ]);
        }
    }
}
