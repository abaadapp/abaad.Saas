<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Demo;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * ما يُرتَّب في قائمة المبيعات.
     *
     * و«رقم الطلب» يُرتَّب بالرقم المتسلسل لا بنصّه: النصّ يرتّب #١٠ قبل #٩.
     */
    private const SORTS = [
        'id' => 'number',
        'customer' => 'customer_name',
        'employee' => 'employee_name',
        'items_count' => 'items_count',
        'total' => 'total',
        'payment' => 'payment_method',
        'date' => 'ordered_at',
        'scheduled' => 'scheduled_for',
    ];

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request)
    {
        $q = Order::where('business_id', $this->bid())->where('is_held', false)->withCount('items')
            ->when(Demo::currentBranchId(), fn ($w) => $w->where('branch_id', Demo::currentBranchId()));

        if ($s = trim((string) $request->query('q'))) {
            // والموظف من البحث: «من باع هذه الفاتورة؟» سؤالٌ يُبحث به كثيرًا
            $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhere('customer_name', 'like', "%{$s}%")
                ->orWhere('employee_name', 'like', "%{$s}%"));
        }
        if ($pm = $request->query('payment')) { $q->where('payment_method', $pm); }
        // الملغى كان يجلس بين المكتمل بلا تمييز ولا فرز
        if ($st = $request->query('status')) { $q->where('status', $st); }
        // مدًى لا يومًا واحدًا: من أراد مبيعات أسبوع كان يفتح الشاشة سبع مرّات.
        // وحقل «التاريخ» المفرد حُذف — كان ثالثًا بجانب الطرفين يفعل ما يفعلانه
        if ($from = $request->query('from')) { $q->whereDate('ordered_at', '>=', $from); }
        if ($to = $request->query('to')) { $q->whereDate('ordered_at', '<=', $to); }

        /*
         * مُرشِّح الموعد — على `scheduled_for` لا على `ordered_at`.
         *
         * السؤال الذي يُسأل كلّ صباح هو «ما الذي يُسلَّم اليوم؟» لا «ما الذي
         * سُجّل اليوم». وطلبٌ سُجّل الاثنين لتسليمه الجمعة يقع في يومين
         * مختلفين بحسب أيّ عمودٍ يُقرأ — فيُفصَل المُرشِّحان ولا يُستبدل أحدهما
         * بالآخر: `from`/`to` يبقيان على `ordered_at` لأنّ التقارير عليهما.
         *
         * و«المتأخّر» يستثني المغلق: طلبٌ سُلّم أمس ليس متأخّرًا اليوم.
         */
        if ($when = $request->query('when')) {
            match ($when) {
                'today' => $q->whereBetween('scheduled_for', [now()->startOfDay(), now()->endOfDay()]),
                'tomorrow' => $q->whereBetween('scheduled_for', [
                    now()->addDay()->startOfDay(), now()->addDay()->endOfDay(),
                ]),
                'upcoming' => $q->where('scheduled_for', '>', now()->addDay()->endOfDay()),
                'overdue' => $q->where('scheduled_for', '<', now())
                    ->whereNotIn('status', \App\Support\OrderStatus::CLOSED),
                default => null,
            };
        }

        /*
         * مجموع ما رُشّح لا مجموع الصفحة.
         *
         * الجدول يعرض عشرة صفوف من مئة، فجمعُ المعروض يقول رقمًا لا معنى له.
         * ويُحسب قبل الترقيم على النسخة نفسها من الاستعلام.
         */
        $filtered = (clone $q);
        $totalAmount = (float) $filtered->clone()->sold()->sum('total');
        $totalCount = $filtered->clone()->count();
        $cancelledCount = $filtered->clone()->where('status', Order::CANCELLED)->count();

        \App\Support\Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('ordered_at'));

        $orders = $q->paginate(10)->withQueryString()->through(fn ($o) => [
            'id' => $o->number, 'customer' => \App\Support\Demo::customerLabel($o->customer_name),
            'employee' => $o->employee_name ?? '—', 'branch' => $o->branch,
            'items_count' => $o->items_count, 'total' => (float) $o->total,
            'payment' => $o->payment_method, 'status' => $o->status,
            'date' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
            // الموعد يُرسل ليُقرأ في العمود، و'—' لبيعة المنضدة التي لا موعد لها
            'scheduled' => optional($o->scheduled_for)->format('Y-m-d H:i') ?? '—',
            'fulfillment' => $o->fulfillment_type,
        ]);

        return \Inertia\Inertia::render('Admin/Orders/Index', [
            'orders' => $orders->items(),
            'pagination' => \App\Support\Pagination::meta($orders),
            'filters' => $request->only('q', 'payment', 'status', 'from', 'to', 'when')
                + \App\Support\Sort::params($request, self::SORTS),
            'sorts' => \App\Support\Sort::keys(self::SORTS),
            // المبلغ من المُباع وحده، والعدد من الكلّ — والملغى يُذكر صراحةً
            // كي لا يُقرأ الفرقُ بينهما خطأً في الجمع
            'totalAmount' => $totalAmount,
            'totalCount' => $totalCount,
            'cancelledCount' => $cancelledCount,
            // قائمة الحالات من مصدرها الواحد — لا تُكتب في الشاشة مرّةً ثانية
            'statusOptions' => \App\Support\OrderStatus::options(),
        ]);
    }

}
