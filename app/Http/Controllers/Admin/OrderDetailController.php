<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Support\Activity;
use App\Support\BranchGoogle;
use App\Support\Demo;
use App\Support\FlowerOrder;
use App\Support\OrderNotice;
use App\Support\OrderStatus;
use App\Support\OrderTransition;
use App\Support\WhatsAppPhone;
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

    /**
     * إرسالُ الفاتورة إلى الزبون — عبر واتساب التاجر لا عبر مُرسِلٍ ثانٍ.
     *
     * والنصُّ يُكتب هنا لا في الشاشة: رقمُ الطلب وإجماليُّه واسمُ المتجر
     * تُقرأ من الخادم، فلا تُرسَل أرقامٌ صنعتها واجهةٌ يفتحها من يشاء.
     *
     * ولا يُرسَل رابطُ الورقة: ملفُّ الفاتورة خلف تسجيل دخول، ورابطٌ يفتح
     * صفحةَ دخولٍ في يد الزبون أسوأ من ألّا يُرسَل شيء. فالنصُّ يذهب،
     * والورقةُ تُحمَّل وتُرفَق بيد من يرسل — وهو ما يفعله فعلًا.
     */
    public function send(string $number)
    {
        $order = $this->find($number);

        $phone = WhatsAppPhone::normalize(
            $order->customer?->phone ?: $order->recipient_phone
        );

        if (! $phone) {
            /*
             * ورسالةٌ تُرى لا خطأُ نموذجٍ لا يرسمه أحد.
             *
             * `withErrors` تكتب في `errors` — ومن لم يرسم الحقلَ في الشاشة
             * لا يظهر عنده شيء: يضغط التاجر «إرسال» فلا يقع شيءٌ ولا يُقال
             * لماذا. وهو ما كان يقع في «تذكير بالسداد» حرفًا بحرف.
             */
            return back()->with('toast', [
                'msg' => __('لا رقم واتساب لهذا الطلب — أضِفه في صفحة العميل.'), 'type' => 'danger',
            ]);
        }

        $text = __(':shop — فاتورتك رقم :number بمبلغ :amount. شكرًا لك.', [
            'shop' => Demo::businessName(),
            'number' => $order->number,
            'amount' => number_format((float) $order->total, 3),
        ]);

        Activity::log('updated', 'أعدّ إرسال فاتورة الطلب '.$order->number, [
            'subject_id' => $order->id, 'subject_type' => 'order',
        ]);

        return back()->with('toast', [
            'msg' => __('افتح واتساب وأرسل الفاتورة'),
            'type' => 'success',
            'link' => ['url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($text), 'label' => __('فتح واتساب')],
        ]);
    }

    /**
     * طلبُ تقييمٍ على Google — بملفّ **فرع هذا الطلب** لا بملفّ المتجر.
     *
     * ═══ ولمَ بعد التسليم وحده ═══
     *
     * التقييمُ حكمٌ على تجربة، ولا تجربةَ قبل أن يصل الورد. وطلبُه عن طلبٍ في
     * التجهيز يسأل الزبونَ عمّا لم يقع — وأسوأ ما فيه أن يُكتب حكمٌ قبل أن
     * يُرى المنتج.
     *
     * ═══ ولمَ الفرع ═══
     *
     * لكلّ فرعٍ ملفُّه عند Google. وإرسالُ رابط المتجر «عمومًا» يعني تقييمًا
     * يُحسب لفرعٍ لم يشترِ منه هذا الزبون.
     *
     * ═══ وما يقع فعلًا ═══
     *
     * يُفتح واتساب التاجر بنصٍّ مكتوب، ويضغط هو «إرسال». لا مُرسِلَ آليّ
     * ولا قالبَ ميتا: قوالبُ واتساب تُعتمد عندهم واحدةً واحدة، وقالبُ
     * تقييمٍ غيرُ معتمد. فلا يُقال «أُرسل» — يُقال «طُلب»، وهو ما جرى.
     *
     * ولا يُرسَل رقمُ الزبون ولا بريدُه إلى Google بحال: الذاهبُ إليه رابطٌ
     * عامٌّ يفتحه من يفتحه.
     */
    public function reviewRequest(string $number)
    {
        $order = $this->find($number);

        /* بعد التسليم أو الاستلام أو الإتمام — وما دون ذلك سؤالٌ عمّا لم يقع */
        if (! in_array($order->status, [OrderStatus::DELIVERED, OrderStatus::PICKED_UP, OrderStatus::COMPLETED], true)) {
            return back()->with('toast', [
                'msg' => __('يُطلب التقييم بعد تسليم الطلب.'), 'type' => 'danger',
            ]);
        }

        $branch = $order->branch_id
            ? Branch::where('business_id', $this->bid())->find($order->branch_id)
            : null;

        $url = $branch ? BranchGoogle::reviewUrl(BranchGoogle::for($branch)) : null;

        if (! $url) {
            return back()->with('toast', [
                'msg' => __('اربط فرع هذا الطلب بخرائط Google أوّلًا.'), 'type' => 'danger',
            ]);
        }

        $phone = WhatsAppPhone::normalize($order->customer?->phone ?: $order->recipient_phone);

        if (! $phone) {
            return back()->with('toast', [
                'msg' => __('لا رقم واتساب لهذا الطلب — أضِفه في صفحة العميل.'), 'type' => 'danger',
            ]);
        }

        /* والنصُّ ثلاثةُ مفاتيحَ لا مفتاحٌ فيه أسطر: مفتاحٌ بسطرٍ جديدٍ لا يُترجَم */
        $text = __('شكرًا لطلبك من :shop 🌷', ['shop' => Demo::businessName()])
            ."\n".__('يسعدنا تقييم تجربتك معنا على Google:')
            ."\n".$url;

        $order->forceFill(['review_request_sent_at' => now()])->save();

        Activity::log('updated', 'أعدّ طلب تقييم Google للطلب '.$order->number, [
            'subject_id' => $order->id, 'subject_type' => 'order',
        ]);

        return back()->with('toast', [
            'msg' => __('افتح واتساب وأرسل طلب التقييم'),
            'type' => 'success',
            'link' => ['url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($text), 'label' => __('فتح واتساب')],
        ]);
    }

    /**
     * إبلاغُ الزبون بحالة طلبه — بيدِ التاجر حين لا تعمل اليد الآليّة.
     *
     * ═══ وما يقع فعلًا ═══
     *
     * يُفتح واتساب التاجر بنصٍّ مكتوب، ويضغط هو «إرسال». لا مُرسِلَ آليّ ولا
     * قالبَ ميتا ولا مفتاح — فهذا الطريق لا يمرّ بهم أصلًا، وهو يعمل والحظرُ
     * قائم.
     *
     * ولا يُكتب صفٌّ في `whatsapp_messages`: ذاك سجلُّ ما مرّ بميتا، وصفٌّ
     * فيه بلا معرّفٍ منها يكذب على شاشة التاجر وعلى `WhatsAppHealth` معًا —
     * فتُطفَأ التحذيراتُ عن عطبٍ لم يُصلَح.
     *
     * ═══ والشروطُ تُقاس هنا ═══
     *
     * الشاشةُ تُخفي الزرَّ أو تُعطّله، لكنّها شاشة: من ينادي المسار مباشرةً
     * لا يمرّ بها. فيُعاد قياسُ الحال هنا، ويُردّ بسببه مكتوبًا لا بصمت.
     */
    public function statusNotice(string $number)
    {
        $order = $this->find($number);
        $state = OrderNotice::state($order);

        if (! $state['show']) {
            return back()->with('toast', [
                'msg' => $state['reason'] ?? __('لا إشعار لحالة هذا الطلب.'),
                'type' => 'danger',
            ]);
        }

        $event = (string) $state['event'];

        $order->forceFill([
            'status_notice_at' => now(),
            'status_notice_event' => $event,
        ])->save();

        Activity::log('updated', 'أعدّ إبلاغ الزبون بحالة الطلب '.$order->number, [
            'subject_id' => $order->id, 'subject_type' => 'order',
        ]);

        return back()->with('toast', [
            'msg' => __('افتح واتساب وأرسل الإشعار'),
            'type' => 'success',
            'link' => [
                'url' => 'https://wa.me/'.OrderNotice::phone($order)
                    .'?text='.rawurlencode(OrderNotice::text($order, $event)),
                'label' => __('فتح واتساب'),
            ],
        ]);
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

        $from = $order->status;

        // بابٌ واحد للنقل تدخل منه هذه الشاشة ولوحة التجهيز — انظر OrderTransition
        if ($error = OrderTransition::apply($order, $data['status'])) {
            return back()
                ->with('toast', ['msg' => $error, 'type' => 'danger'])
                ->withErrors(['status' => $error]);
        }

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
