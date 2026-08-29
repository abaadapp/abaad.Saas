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
use Inertia\Inertia;

/**
 * لوحة التجهيز — شاشةُ من يصنع الباقة، لا من يحاسب عليها.
 *
 * العامل يقف أمام الطاولة ويسأل سؤالين: ما التالي؟ وما الذي فيه؟ فتُعرض
 * الطلبات مرتّبةً بموعدها، ويُعرض في كلٍّ منها ما يُصنَع به: الأصناف
 * وكمّياتها، والمناسبة، ونصّ البطاقة، وإلى أين تذهب ومتى.
 *
 * ولا يُعرض ثمنٌ ولا تكلفةٌ ولا ربح. ليست شاشة محاسبة، ومن يجهّز الورد لا
 * يحتاج أن يعرف هامش المحلّ ليضع ساقًا في مزهرية — وعرضُه يعني أنّ كلّ من
 * يقف عند الطاولة يقرأ أرباح صاحبه.
 *
 * وصلاحيّتها قسمٌ مستقلّ: «المبيعات» تفتح الفواتير والإجماليّات ومجموع
 * المرشَّح، وهي أوسع بكثير ممّا يحتاجه من يجهّز. فيُمنح التجهيز وحده.
 */
class PreparationController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(Request $request)
    {
        $filter = $request->query('when');

        /*
         * مرشّح التنفيذ — بُعدٌ ثانٍ لا صفٌّ ثانٍ من التبويبات.
         *
         * ضربُه في النوافذ الأربع يعني ثمانية تبويباتٍ على شاشةٍ واحدة، ولا
         * أحد يقرأ ثمانية. فيبقى الزمن تبويبات ويصير التنفيذ مبدّلًا بجانبها،
         * والاثنان يعملان معًا: «توصيل اليوم» اختيارٌ واحد من كلٍّ منهما.
         *
         * والمجهول يعني «الكلّ»: عنوانٌ يُكتب بيدٍ لا يُفرغ اللوحة بلا سبب.
         */
        $type = in_array($request->query('type'), FlowerOrder::FULFILLMENT, true)
            ? $request->query('type')
            : null;

        /*
         * الاستعلام لا يحمل تاريخًا لا يلزم.
         *
         * `awaitingPreparation` يستبعد المغلق والمعلَّق وما لا موعد له، فلا
         * تُحمَّل ستّمئة فاتورةٍ أُغلقت منذ شهور. والفهرس المركّب
         * [business_id, scheduled_for] يخدم الترشيح والترتيب معًا.
         *
         * و`with('items')` لا حلقةٌ على الطلبات: بدونها استعلامٌ لكل طلب —
         * عشرون طلبًا على اللوحة تعني واحدًا وعشرين استعلامًا.
         */
        $q = $this->base()
            ->when($type, fn ($w) => $w->where('fulfillment_type', $type))
            ->with([
                'items:id,order_id,name,quantity,note,product_id',
                // الصورة وحدها من المنتج — لا سعرَه ولا تكلفتَه
                'items.product:id,image',
            ]);

        $this->applyWindow($q, $filter);

        // المتأخّر أوّلًا لأنّه أقدم موعدًا — والترتيب التصاعديّ يُقدّمه وحده
        $orders = $q->orderBy('scheduled_for')->limit(200)->get();

        return Inertia::render('Admin/Preparation/Index', [
            'orders' => $orders->map(fn ($o) => $this->card($o))->values()->all(),
            'filters' => ['when' => $filter, 'type' => $type],
            'counts' => $this->counts($type),
            'typeCounts' => $this->typeCounts($filter),
        ]);
    }

    /** ما تنتظره اللوحة: متجرُ المستخدم، وفرعُه، وما لم يُغلق بعد */
    private function base()
    {
        return Order::where('business_id', $this->bid())
            ->awaitingPreparation()
            ->when(Demo::currentBranchId(), fn ($w) => $w->where('branch_id', Demo::currentBranchId()));
    }

    /** نافذة الوقت المطلوبة — والمجهول يعني «الكلّ» لا يعني خطأً */
    private function applyWindow($q, ?string $when): void
    {
        match ($when) {
            'overdue' => $q->where('scheduled_for', '<', now()),
            'today' => $q->whereBetween('scheduled_for', [now()->startOfDay(), now()->endOfDay()]),
            'tomorrow' => $q->whereBetween('scheduled_for', [
                now()->addDay()->startOfDay(), now()->addDay()->endOfDay(),
            ]),
            // «قادم» ما بعد الغد: النوافذ الأربع تقسم اللوحة ولا تتداخل،
            // فطلبُ الغد يُعدّ مرّةً في تبويبه لا مرّتين
            'upcoming' => $q->where('scheduled_for', '>', now()->addDay()->endOfDay()),
            default => null,
        };
    }

    /**
     * أعداد التبويبات — استعلامٌ واحد لا أربعة.
     *
     * أربع عدّاتٍ منفصلة تمسح الفهرس نفسه أربع مرّات لتُجيب عن سؤالٍ واحد.
     * والجمع الشرطيّ يفعلها في مسحةٍ واحدة، ويعمل على PostgreSQL وSQLite معًا
     * (`case when` قياسيّ، خلافًا لـ`FILTER` التي لا يعرفها الثاني).
     *
     * وتُعدّ تحت مرشّح التنفيذ المختار: رقمٌ على تبويبٍ يجب أن يكون عدد ما
     * يظهر عند الضغط عليه، لا عدد ما كان يظهر قبل مرشّحٍ آخر.
     *
     * @return array<string, int>
     */
    private function counts(?string $type): array
    {
        $when = fn (string $expr) => "sum(case when {$expr} then 1 else 0 end)";

        $row = $this->base()
            ->when($type, fn ($w) => $w->where('fulfillment_type', $type))
            ->selectRaw(
                'count(*) as all_count, '
                .$when('scheduled_for < ?').' as overdue_count, '
                .$when('scheduled_for between ? and ?').' as today_count, '
                .$when('scheduled_for between ? and ?').' as tomorrow_count',
                [
                    now(),
                    now()->startOfDay(), now()->endOfDay(),
                    now()->addDay()->startOfDay(), now()->addDay()->endOfDay(),
                ]
            )->first();

        return [
            'all' => (int) ($row->all_count ?? 0),
            'overdue' => (int) ($row->overdue_count ?? 0),
            'today' => (int) ($row->today_count ?? 0),
            'tomorrow' => (int) ($row->tomorrow_count ?? 0),
        ];
    }

    /**
     * أعداد مبدّل التنفيذ — تحت النافذة الزمنية المختارة، للسبب نفسه.
     *
     * و«الكلّ» ليس مجموع الاثنين: طلبٌ له موعدٌ ولم يُحدَّد تنفيذه ليس
     * توصيلًا ولا استلامًا، فيسقط من كليهما ويبقى في «الكلّ» وحده — وهو
     * الموضع الذي يُرى فيه أنّ في اللوحة ما ينقصه شيء.
     *
     * @return array<string, int>
     */
    private function typeCounts(?string $when): array
    {
        $q = $this->base();
        $this->applyWindow($q, $when);

        // قيمتان ثابتتان، ومع ذلك تُربَط لا تُدمَج: راويةُ SQL لا تُفتح لعادة
        $case = 'sum(case when fulfillment_type = ? then 1 else 0 end)';

        $row = $q->selectRaw(
            'count(*) as all_count, '
            .$case.' as delivery_count, '
            .$case.' as pickup_count',
            [FlowerOrder::DELIVERY, FlowerOrder::PICKUP]
        )->first();

        return [
            'all' => (int) ($row->all_count ?? 0),
            'delivery' => (int) ($row->delivery_count ?? 0),
            'pickup' => (int) ($row->pickup_count ?? 0),
        ];
    }

    /**
     * بطاقة الطلب على اللوحة — ما يُصنَع به لا ما يُحاسَب عليه.
     *
     * لا `price` ولا `cost` ولا `total`: العمود موجودٌ في البند، وإرسالُه
     * إلى الشاشة يجعله مقروءًا لكلّ من يفتح أدوات المتصفّح — سواءٌ رُسم أم
     * لم يُرسم.
     */
    private function card(Order $o): array
    {
        return [
            'number' => $o->number,
            'status' => $o->status,
            'fulfillment' => $o->fulfillment_type,
            'scheduled_for' => optional($o->scheduled_for)->format('Y-m-d H:i'),
            'overdue' => $o->scheduled_for && $o->scheduled_for->isPast(),
            'recipient' => $o->recipient_name,
            'recipient_phone' => $o->recipient_phone,
            'address' => $o->delivery_address,
            'occasion' => FlowerOrder::occasionLabel($o->occasion_type),
            'card_message' => $o->card_message,
            // اسم المُهدي يبقى للموظّف: هو يكتب البطاقة، والإخفاء عن المستلِم
            // لا عن من يصنعها — انظر FlowerOrder::cardForRecipient
            'sender' => $o->sender_name,
            'hide_sender' => (bool) $o->hide_sender,
            'delivery_notes' => $o->delivery_notes,
            'internal_notes' => $o->internal_notes,
            'branch' => $o->branch,
            'items' => $o->items->map(fn ($i) => [
                'name' => $i->name,
                'qty' => (int) $i->quantity,
                'note' => $i->note,
                'image' => $i->product?->image,
            ])->values()->all(),
            // ما يجوز الانتقال إليه من هنا — تُبنى منه أزرار البطاقة
            'next' => OrderStatus::nextFrom($o->status),
        ];
    }

    /**
     * «ابدأ التجهيز» و«جاهز» — والحارس هو نفسه حارس شاشة المبيعات.
     *
     * مصدرٌ واحد للانتقالات المسموحة (`OrderStatus`) لا حارسان: لو كُتب هنا
     * حارسٌ ثانٍ لَافترق عن أخيه عند أول تعديل، فأجاز أحدهما ما يمنعه الآخر
     * — والعامل يستطيع من لوحته ما لا يستطيعه صاحبُ المحلّ من شاشته.
     */
    public function move(Request $request, string $number)
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(OrderStatus::ALL)],
        ]);

        $order = Order::where('business_id', $this->bid())
            ->where('is_held', false)
            ->when(Demo::currentBranchId(), fn ($w) => $w->where('branch_id', Demo::currentBranchId()))
            ->where('number', $number)
            ->firstOrFail();

        if (! OrderStatus::canMove($order->status, $data['status'])) {
            return back()->withErrors(['status' => __(
                'لا يمكن نقل الطلب من «:from» إلى «:to».',
                ['from' => $order->status, 'to' => $data['status']]
            )]);
        }

        $from = $order->status;
        $order->update(['status' => $data['status']]);

        Activity::log('status', 'التجهيز: نقل الطلب '.$order->number.' من «'.$from.'» إلى «'.$data['status'].'»', [
            'subject_id' => $order->id,
            'subject_type' => 'order',
        ]);

        return back()->with('toast', [
            'msg' => __('حالة الطلب: :status', ['status' => $data['status']]),
            'type' => 'success',
        ]);
    }
}
