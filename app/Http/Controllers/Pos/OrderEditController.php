<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Demo;
use App\Support\NotesAndEdits;
use App\Support\OrderCorrection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * تصحيح فاتورةٍ صدرت — من شاشة الكاشير ومن شاشة المبيعات معًا.
 *
 * الكتابة كلّها في `OrderCorrection`: الشاشة تقول ماذا يريد، والدالّة تعرف
 * ما الذي يتحرّك معه — المخزون والضريبة والنقاط والمعاملة المالية.
 *
 * ويُنادى من مسارين باسمين (`pos.orders.*` و`admin.orders.*`) وهو واحد: الاسم
 * يُقرأ منه القسمُ الذي يُؤذَن به — صندوقٌ أو مبيعات — والحكمُ على **الفعل**
 * نفسِه واحدٌ تحتهما: `order.edit` ويومُ البيع. فلا يفترق ما يجوز للكاشير
 * عمّا يجوز للمحاسب لأنّ كلًّا جاء من باب.
 */
class OrderEditController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /**
     * من يُصحّح فاتورةً صدرت — ومن سواه يُردّ.
     *
     * كان البابُ مفتوحًا لكلّ من يفتح نقطة البيع: الكاشير في يومه الأوّل
     * يعيد كتابة فاتورةٍ ضريبيّة بلا أن يمرّ بأحد. وصلاحيةُ «نقطة البيع»
     * تقول «يبيع» لا «يُعيد كتابة ما بيع».
     *
     * والسؤال عن **الحساب الداخل** لا عن الاسم المختار في الترويسة: مبدِّلُ
     * الكاشير في نقطة البيع لافتةٌ لا بوّابة — يضبطه أيُّ واقفٍ على الجهاز
     * بلا كلمة سرّ، فلو قُرئ منه الإذن لاختار الكاشيرُ اسمَ المدير ومضى.
     */
    private function mayEdit(): bool
    {
        return (bool) auth()->user()?->may('order.edit');
    }

    /**
     * الفاتورة داخل متجر المستخدم **وفرعه** — لا `Order::where(number)` عاريةً.
     *
     * كان الفرع ساقطًا من هنا وحده: شاشةُ المبيعات تُرشِّح بالفرع، و`OrderDetailController::find`
     * يُرشِّح به — فمن يقف على مسقط يفتح طلبَ صلالة بعنوانٍ يُكتب، فيُردّ عن
     * نقل الحالة وعن الإرسال وعن ورقة التفاصيل، **ويُقبل منه تصحيحُ الفاتورة**:
     * كميّةٌ تُكتب من جديد، ورصيدُ صلالة يتحرّك، ومعاملةٌ ماليّة تُصحَّح — من
     * فرعٍ لا يراه ولا يظهر له في قائمته. وأخطرُ الأبواب كان أوسعَها.
     *
     * والقاعدة هي قاعدةُ أخيه حرفًا بحرف: «من يعمل على فرعٍ بعينه لا يُحرّك
     * طلبات فرعٍ لا يراه». و«كل الفروع» يبقى على المتجر كلِّه.
     *
     * وفي نقطة البيع الفرعُ فرعُ الجهاز (`BindPosBranch`) لا اختيارَ فيه —
     * فيصير الحدُّ هنا: لا تُصحَّح على هذا الصندوق فاتورةُ صندوقٍ آخر.
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
     * الردّ الواحد لمن لا يملكها — نصٌّ يقول ماذا يفعل لا «ممنوع».
     *
     * ومفتاحُه `permission` لا `reason`: خلطُه بخطأ حقلٍ يجعل رسالة المنع
     * تظهر تحت خانة السبب كأنّ ما كُتب فيها هو العطب.
     */
    private function refuse()
    {
        return back()->withErrors([
            'permission' => __('تصحيحُ فاتورةٍ صدرت يحتاج صلاحية — اطلبها من صاحب المتجر، أو اطلب منه التصحيح.'),
        ]);
    }

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
        ] + $this->settleRules(), [
            'reason.required' => __('اكتب سبب التعديل — بدونه لا يُعرف لماذا تغيّرت الفاتورة.'),
            'reason.min' => __('السبب قصير جدًّا — اكتب ما يفهمه من يقرأ الفاتورة لاحقًا.'),
        ]);

        $order = $this->find($number);

        $item = OrderItem::where('order_id', $order->id)->findOrFail($itemId);

        if (! $this->mayEdit()) {
            return $this->refuse();
        }

        try {
            OrderCorrection::setQuantity($order, $item, (int) $data['quantity'], trim($data['reason']),
                $data['settle'] ?? null, $data['payment_method'] ?? null);
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
        ] + $this->settleRules(), [
            'reason.required' => __('اكتب سبب التعديل — بدونه لا يُعرف لماذا تغيّرت الفاتورة.'),
            'reason.min' => __('السبب قصير جدًّا — اكتب ما يفهمه من يقرأ الفاتورة لاحقًا.'),
        ]);

        $order = $this->find($number);

        // البند من هذه الفاتورة، والإضافة من ذلك البند — سلسلةٌ تُفحص حلقةً
        // حلقة، وإلّا صحّح متجرٌ إضافةً في فاتورة متجرٍ آخر بمعرّفٍ مُخمَّن
        $item = OrderItem::where('order_id', $order->id)->findOrFail($itemId);
        $row = \App\Models\OrderItemAddon::where('order_item_id', $item->id)->findOrFail($addonId);

        if (! $this->mayEdit()) {
            return $this->refuse();
        }

        try {
            OrderCorrection::setAddonQuantity($order, $row, (int) $data['quantity'], trim($data['reason']),
                $data['settle'] ?? null, $data['payment_method'] ?? null);
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

        $order = $this->find($number);

        if (! $this->mayEdit()) {
            return $this->refuse();
        }

        try {
            OrderCorrection::setPaymentMethod($order, $data['payment_method'], trim($data['reason']));
        } catch (RuntimeException $e) {
            return back()->withErrors(['payment_method' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('صُحّحت وسيلة الدفع'), 'type' => 'success']);
    }

    /* ═══════════════ إضافةُ صنفٍ واستبدالُه وملاحظتُه وتحصيلُ المتبقّي ═══════════════ */

    /**
     * والميزةُ لنشاطٍ فُتحت له (`NotesAndEdits::on`) — والبابُ مغلقٌ لسواه
     * كأنّه لم يكن، ولو عُرف شكلُ الحمولة.
     */
    private function feature(): void
    {
        abort_unless(NotesAndEdits::on($this->bid()), 404);
    }

    /** @return array<string, array<int, mixed>> */
    private function lineRules(): array
    {
        return [
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'addons' => ['nullable', 'array', 'max:20'],
            'addons.*.addon_id' => ['required', 'integer'],
            'addons.*.qty' => ['required', 'integer', 'min:0', 'max:99'],
            'note' => ['nullable', 'string', 'max:'.NotesAndEdits::PRODUCT_NOTE_MAX],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ] + $this->settleRules();
    }

    /**
     * فرقُ فاتورةٍ مدفوعة: يبقى، أو حُصِّل الآن، أو رُدّ الآن — والأخيران بوسيلة.
     *
     * تقرؤه الإضافةُ والاستبدالُ وتصحيحُ الكمّيّة؛ والخدمةُ لا تسأل عنه إلّا
     * لنشاطٍ فُتحت له الميزة (`NotesAndEdits::on`)، وتسأل إن كانت الوسيلة مأذونة.
     *
     * @return array<string, array<int, mixed>>
     */
    private function settleRules(): array
    {
        return [
            'settle' => ['nullable', Rule::in(OrderCorrection::SETTLES)],
            'payment_method' => ['nullable', 'string', 'max:50', 'required_if:settle,'.OrderCorrection::SETTLE_COLLECTED.','.OrderCorrection::SETTLE_REFUNDED],
        ];
    }

    /** @return array<string, string> */
    private function reasonMessages(): array
    {
        return [
            'reason.required' => __('اكتب سبب التعديل — بدونه لا يُعرف لماذا تغيّرت الفاتورة.'),
            'reason.min' => __('السبب قصير جدًّا — اكتب ما يفهمه من يقرأ الفاتورة لاحقًا.'),
        ];
    }

    /** ردُّ الخدمة يُقال تحت الحوار — سعرٌ أو مخزونٌ أو قيد */
    private function failed(\Throwable $e)
    {
        $message = $e instanceof ValidationException
            ? (string) collect($e->errors())->flatten()->first()
            : $e->getMessage();

        return back()->withErrors(['line' => $message]);
    }

    /**
     * إضافةُ صنفٍ إلى فاتورةٍ صدرت.
     *
     * والسعرُ لا يُقرأ من الطلب: يُرسَل الصنفُ ومقاسُه وكمّيّتُه وإضافاتُه،
     * ويُسعَّر في الخادم (`OrderCorrection::addLine`).
     */
    public function store(Request $request, string $number)
    {
        $this->feature();
        $data = $request->validate($this->lineRules(), $this->reasonMessages());

        $order = $this->find($number);

        if (! $this->mayEdit()) {
            return $this->refuse();
        }

        try {
            OrderCorrection::addLine($order, $data, trim($data['reason']), $data['settle'] ?? null, $data['payment_method'] ?? null);
        } catch (RuntimeException|ValidationException $e) {
            return $this->failed($e);
        }

        return back()->with('toast', ['msg' => __('أُضيف الصنف وصُحّحت الفاتورة'), 'type' => 'success']);
    }

    /** استبدالُ صنفٍ بآخر — حذفٌ وكتابةٌ في معاملةٍ واحدة (`OrderCorrection::replaceLine`) */
    public function replace(Request $request, string $number, int $itemId)
    {
        $this->feature();
        $data = $request->validate($this->lineRules(), $this->reasonMessages());

        $order = $this->find($number);
        $item = OrderItem::where('order_id', $order->id)->findOrFail($itemId);

        if (! $this->mayEdit()) {
            return $this->refuse();
        }

        try {
            OrderCorrection::replaceLine($order, $item, $data, trim($data['reason']), $data['settle'] ?? null, $data['payment_method'] ?? null);
        } catch (RuntimeException|ValidationException $e) {
            return $this->failed($e);
        }

        return back()->with('toast', ['msg' => __('استُبدل الصنف وصُحّحت الفاتورة'), 'type' => 'success']);
    }

    /** ملاحظةُ منتجٍ في فاتورة — نصٌّ وحده (`OrderCorrection::setNote`) */
    public function note(Request $request, string $number, int $itemId)
    {
        $this->feature();
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:'.NotesAndEdits::PRODUCT_NOTE_MAX],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], $this->reasonMessages());

        $order = $this->find($number);
        $item = OrderItem::where('order_id', $order->id)->findOrFail($itemId);

        if (! $this->mayEdit()) {
            return $this->refuse();
        }

        try {
            OrderCorrection::setNote($order, $item, $data['note'] ?? null, trim($data['reason']));
        } catch (RuntimeException $e) {
            return $this->failed($e);
        }

        return back()->with('toast', ['msg' => __('عُدّلت ملاحظة المنتج'), 'type' => 'success']);
    }

    /** تحصيلُ ما بقي على فاتورةٍ مدفوعة (`OrderCorrection::collectBalance`) */
    public function collect(Request $request, string $number)
    {
        $this->feature();
        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:50'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], $this->reasonMessages());

        $order = $this->find($number);

        if (! $this->mayEdit()) {
            return $this->refuse();
        }

        try {
            OrderCorrection::collectBalance($order, $data['payment_method'], trim($data['reason']));
        } catch (RuntimeException $e) {
            return $this->failed($e);
        }

        return back()->with('toast', ['msg' => __('حُصِّل المتبقّي'), 'type' => 'success']);
    }
}
