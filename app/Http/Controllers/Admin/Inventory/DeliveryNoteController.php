<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Models\BranchStock;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Support\Demo;
use App\Support\Pagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إشعار تسليم شحنة — ورقةُ ما خرج من المخزن ومن استلمه.
 *
 * وهو مستند حركةٍ لا مستند مال: لا يُنشئ ذمّةً ولا قيدًا. الذمّة نشأت
 * بالفاتورة، وخلطُهما يُحمّل العميل مرّتين.
 *
 * وأمّا المخزون فيتبع مصدر الإشعار، وهذا أدقّ ما فيه:
 *
 * - إشعارٌ مربوطٌ بطلب: البضاعة خرجت من المخزون يوم البيع (نقطة البيع تُنقص
 *   الكمية عند الدفع). فالإشعار ورقةٌ تُطبع وتُوقَّع لا غير — ولو أنقص
 *   الكمية ثانيةً لخرج الصنف مرّتين من رصيدٍ واحد.
 * - إشعارٌ بلا طلب: شحنةٌ تخرج بلا بيعٍ مسجَّل (تحويلٌ، عيّنة، استبدال).
 *   فهنا وحده يُنقص التسليمُ المخزون، وإلا خرجت بضاعةٌ ولا أثر لها.
 */
class DeliveryNoteController extends Controller
{
    /** ما يُرتَّب في إشعارات التسليم */
    private const SORTS = [
        'number' => 'number',
        'recipient' => 'recipient',
        'driver' => 'driver',
        'status' => 'status',
        'date' => 'delivered_at',
    ];

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $q = DeliveryNote::where('business_id', $bid)->with(['customer', 'items', 'order', 'branch']);

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhere('recipient', 'like', "%{$s}%")
                ->orWhere('driver', 'like', "%{$s}%"));
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        \App\Support\Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('delivered_at')->orderByDesc('id'));

        $notes = $q->paginate((int) $request->query('per_page', 20))->withQueryString();

        return Inertia::render('Admin/Inventory/Deliveries', [
            'notes' => collect($notes->items())->map(fn ($n) => [
                'id' => $n->id,
                'number' => $n->number,
                'customer' => $n->customer?->name,
                'order' => $n->order?->number,
                'branch' => $n->branch?->name,
                'delivered_at' => optional($n->delivered_at)->format('Y-m-d'),
                'recipient' => $n->recipient,
                'driver' => $n->driver,
                'address' => $n->address,
                'status' => $n->status,
                'editable' => $n->isEditable(),
                // الإشعار المربوط لا يمسّ المخزون — يُقال في الشاشة لا يُخمَّن
                'moves_stock' => $n->order_id === null,
                'notes' => $n->notes,
                'items' => $n->items->map(fn ($i) => [
                    'name' => $i->name,
                    'quantity' => (float) $i->quantity,
                    'unit' => $i->unit,
                ])->all(),
            ])->all(),
            'pagination' => Pagination::meta($notes),
            'filters' => $request->only('q', 'status')
                + \App\Support\Sort::params($request, self::SORTS),
            'sorts' => \App\Support\Sort::keys(self::SORTS),
            'customers' => Customer::where('business_id', $bid)->orderBy('name')->limit(500)
                ->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all(),
            'products' => Product::where('business_id', $bid)->orderBy('name')
                ->get(['id', 'name', 'sku', 'quantity'])
                ->map(fn ($p) => [
                    'value' => $p->id,
                    'label' => $p->sku ? $p->name.' — '.$p->sku : $p->name,
                    'quantity' => (int) $p->quantity,
                ])->all(),
            'orders' => Order::where('business_id', $bid)->orderByDesc('id')->limit(100)
                ->get(['id', 'number'])->map(fn ($o) => ['value' => $o->id, 'label' => $o->number])->all(),
            'summary' => [
                'drafts' => DeliveryNote::where('business_id', $bid)->where('status', 'مسودة')->count(),
                'delivered' => DeliveryNote::where('business_id', $bid)->where('status', 'مُسلَّم')->count(),
            ],
            'today' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * الكميّة التي تمسّ المخزون لا تكون كسرًا.
     *
     * رصيد المنتج عمودٌ صحيح في كل النظام — نقطة البيع والشراء ورصيد الفرع —
     * وحقلُ الإشعار `decimal(12,3)` يقبل الكسر. فكان التسليم يقصّه عند
     * الخصم: ورقةٌ تقول «٢٫٥ كجم» ورصيدٌ ينقص ٢، وورقةٌ تقول «٠٫٥» ورصيدٌ لا
     * ينقص شيئًا — بضاعةٌ تخرج من المخزن ولا أثر لها في رقمٍ ولا في حركة.
     *
     * والكسر يبقى مأذونًا لسطرٍ بلا منتج: بندٌ يُكتب بيده («رملٌ ٢٫٥ متر»)
     * ليس في الجرد أصلًا، فلا شيء يُقصّ.
     */
    private function rejectFractionalStock(array $items): ?string
    {
        $bad = [];

        foreach ($items as $item) {
            if (empty($item['product_id'])) {
                continue;
            }
            if (fmod((float) $item['quantity'], 1.0) !== 0.0) {
                $bad[] = $item['name'].' ('.rtrim(rtrim(number_format((float) $item['quantity'], 3, '.', ''), '0'), '.').')';
            }
        }

        return $bad
            ? __('الكمية المخصومة من المخزون لا تكون كسرًا — الرصيد يُعدّ بالوحدة: :items', ['items' => implode('، ', $bad)])
            : null;
    }

    /**
     * الإشعار المربوط بطلبٍ لا يحمل غير ما فيه.
     *
     * القاعدة أنّ المربوط لا يمسّ المخزون — البضاعة خرجت يوم البيع. وكان
     * الربط بلا فحص: تختار طلبًا ثمّ تكتب في الإشعار مئةً من صنفٍ ليس فيه،
     * فتخرج البضاعة بورقةٍ موقّعة **ولا يُنقص منها شيء** — لا من الإجماليّ
     * ولا من الفرع ولا في سجلّ الحركات. أوسعُ ثغرةٍ في الشاشة: لا تُكتشف إلا
     * في جردٍ بعد أشهر.
     *
     * ولا يُربط إلا بطلبٍ بِيع فعلًا: طلبٌ معلَّق أو ملغى لم تُنقص نقطةُ
     * البيع بضاعته، فالربط به يعني الإعفاء من الخصم بلا خصمٍ سابق.
     *
     * والمقارنة بمجموع ما سُلّم قبله: طلبٌ من عشرة لا يُسلَّم بإشعارين
     * كلٌّ منهما عشرة.
     */
    private function rejectWhatTheOrderDoesNotHold(int $bid, int $orderId, array $items): ?string
    {
        $order = Order::where('business_id', $bid)->with('items')->find($orderId);

        if (! $order) {
            return __('الطلب غير صالح للربط.');
        }

        if ($order->is_held || $order->status === Order::CANCELLED) {
            return __('لا يُربط الإشعار بطلبٍ معلّق أو ملغى — بضاعته لم تُنقص من المخزون.');
        }

        $sold = $order->items->groupBy('product_id')->map(fn ($g) => (float) $g->sum('quantity'));

        $already = \App\Models\DeliveryNoteItem::whereHas('note', fn ($q) => $q
            ->where('business_id', $bid)->where('order_id', $orderId)->where('status', '!=', 'ملغى'))
            ->get()->groupBy('product_id')->map(fn ($g) => (float) $g->sum('quantity'));

        $bad = [];

        foreach ($items as $item) {
            if (empty($item['product_id'])) {
                // بندٌ بلا منتج لا يُقاس بالطلب — ولا يمسّ المخزون أصلًا
                continue;
            }

            $pid = (int) $item['product_id'];
            $left = ($sold[$pid] ?? 0.0) - ($already[$pid] ?? 0.0);

            if ((float) $item['quantity'] > $left) {
                $bad[] = $item['name'].' ('.__('المتبقّي في الطلب').' '.rtrim(rtrim(number_format(max(0, $left), 3, '.', ''), '0'), '.').')';
            }
        }

        return $bad
            ? __('الإشعار المربوط بطلبٍ لا يحمل أكثر ممّا فيه: :items', ['items' => implode('، ', $bad)])
            : null;
    }

    public function store(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('business_id', $bid)],
            'order_id' => ['nullable', Rule::exists('orders', 'id')->where('business_id', $bid)],
            'delivered_at' => ['required', 'date'],
            'recipient' => ['nullable', 'string', 'max:255'],
            'driver' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', Rule::exists('products', 'id')->where('business_id', $bid)],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
        ]);

        if ($error = $this->rejectFractionalStock($data['items'])) {
            return back()->withInput()->withErrors(['items' => $error]);
        }

        if (! empty($data['order_id']) && ($error = $this->rejectWhatTheOrderDoesNotHold($bid, (int) $data['order_id'], $data['items']))) {
            return back()->withInput()->withErrors(['items' => $error]);
        }

        $note = DB::transaction(function () use ($bid, $data) {
            $note = DeliveryNote::create([
                'business_id' => $bid,
                /*
                 * فرعٌ بعينه لا «كل الفروع».
                 *
                 * `currentBranchId` تُرجع null حين يكون المعروض «كل الفروع» —
                 * وهو عرضٌ لا مخزنٌ تخرج منه بضاعة. فكان التسليم يُنقص الإجماليّ
                 * ولا يُنقص رصيد فرعٍ واحد (`BranchStock::adjust` تنصرف عند
                 * null)، فيفترق الإجماليّ عن مجموع الفروع ولا يعودان يلتقيان:
                 * جردٌ يقول تسعين وفروعٌ تجمع مئة، ولا سطر يقول لماذا.
                 */
                'branch_id' => Demo::activeBranchId(),
                'customer_id' => $data['customer_id'] ?? null,
                'order_id' => $data['order_id'] ?? null,
                'number' => DeliveryNote::nextNumber($bid),
                'delivered_at' => $data['delivered_at'],
                'recipient' => $data['recipient'] ?? null,
                'driver' => $data['driver'] ?? null,
                'address' => $data['address'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $note->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    // الاسم منسوخ: حذف المنتج لا يُفرّغ إشعارًا سُلّم
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ?? null,
                ]);
            }

            return $note;
        });

        \App\Support\Activity::log('created', 'أنشأ إشعار تسليم '.$note->number);

        return back()->with('toast', ['msg' => __('أُنشئ الإشعار مسودةً'), 'type' => 'success']);
    }

    /**
     * التسليم — يُقفل الإشعار، ويُنقص المخزون إن لم يكن مربوطًا بطلب.
     *
     * والمتاح يُقاس بالفرع لا بالنشاط كلّه — وهذا ما كان مكسورًا: الفحص كان
     * على `products.quantity`، وهو مجموع الفروع. ففرعٌ فارغ يُسلَّم منه
     * أربعون ما دام في الفرع الآخر مئة: يمرّ الفحص، ويهوي رصيد الفرع إلى
     * سالبٍ تبيع عليه نقطة البيع ما ليس عندها.
     *
     * والقياس داخل المعاملة بقفل (`lock: true`) كما في نقطة البيع: فحصٌ
     * قبلها يُقاس على رصيدٍ يتغيّر بينه وبين الخصم — بيعةٌ تقع في تلك الثغرة
     * تُخرج الصنف مرّتين.
     */
    public function deliver($id)
    {
        $bid = $this->bid();

        try {
            $note = DB::transaction(function () use ($bid, $id) {
                /*
                 * الحالة تُقرأ بقفلٍ داخل المعاملة لا خارجها.
                 *
                 * ضغطتان متتاليتان — أو نقرةٌ مزدوجة — كانتا تمرّان بالفحص
                 * معًا ثم تخصمان البضاعة مرّتين من إشعارٍ واحد.
                 */
                $note = DeliveryNote::where('business_id', $bid)->with('items')
                    ->lockForUpdate()->findOrFail($id);

                if ($note->status !== 'مسودة') {
                    throw new \RuntimeException(__('الإشعار سُلّم أو أُلغي'));
                }

                if ($note->order_id === null) {
                    $ids = $note->items->pluck('product_id')->filter()->all();
                    $products = Product::where('business_id', $bid)->whereIn('id', $ids)->get()->keyBy('id');
                    $available = \App\Support\Stock::availabilityResolver($bid, $note->branch_id, $ids, lock: true);

                    $short = [];
                    foreach ($note->items as $item) {
                        $product = $item->product_id ? ($products[$item->product_id] ?? null) : null;
                        if (! $product) {
                            continue;
                        }
                        $here = $available($product->id, (int) $product->quantity);
                        if ((int) $item->quantity > $here) {
                            $short[] = $product->name.' ('.$here.')';
                        }
                    }

                    if ($short) {
                        throw new \RuntimeException(__('المتوفّر في هذا الفرع أقلّ من المُسلَّم: :items', ['items' => implode('، ', $short)]));
                    }

                    foreach ($note->items as $item) {
                        $product = $item->product_id ? ($products[$item->product_id] ?? null) : null;
                        if (! $product) {
                            continue;
                        }

                        // صحيحٌ دائمًا: الكسر مرفوض عند الإنشاء لكل بندٍ له منتج
                        $qty = (int) $item->quantity;
                        BranchStock::ensureAllocated($bid, $product->id, (int) $product->quantity);
                        $product->decrement('quantity', $qty);
                        BranchStock::adjust($bid, $note->branch_id, $product->id, -$qty);

                        InventoryMovement::create([
                            'business_id' => $bid,
                            'branch_id' => $note->branch_id,
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'sku' => $product->sku,
                            'type' => 'خصم كمية',
                            'quantity' => '-'.$qty,
                            'employee_name' => auth()->user()->name,
                        ]);
                    }
                }

                $note->update(['status' => 'مُسلَّم']);

                return $note;
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['deliver' => $e->getMessage()]);
        }

        \App\Support\Activity::log('updated', 'سلّم الإشعار '.$note->number, ['subject_id' => $note->id]);

        return back()->with('toast', ['msg' => __('سُجّل التسليم'), 'type' => 'success']);
    }

    /**
     * الإلغاء — لمسودةٍ لم تخرج بعد.
     *
     * وإشعارٌ سُلّم لا يُلغى: البضاعة عند العميل، وإلغاؤه يُعيد إلى الرصيد
     * ما ليس في المخزن.
     */
    public function cancel($id)
    {
        $note = DeliveryNote::where('business_id', $this->bid())->findOrFail($id);

        if ($note->status === 'مُسلَّم') {
            return back()->with('toast', [
                'msg' => __('الإشعار سُلّم — البضاعة عند العميل ولا تعود بإلغاء ورقة'),
                'type' => 'warning',
            ]);
        }

        $note->update(['status' => 'ملغى']);

        return back()->with('toast', ['msg' => __('أُلغي الإشعار'), 'type' => 'warning']);
    }

    public function destroy($id)
    {
        $note = DeliveryNote::where('business_id', $this->bid())->findOrFail($id);

        if ($note->status === 'مُسلَّم') {
            return back()->with('toast', ['msg' => __('إشعارٌ سُلّم لا يُحذف'), 'type' => 'warning']);
        }

        \App\Support\Activity::log('deleted', 'حذف إشعار التسليم '.$note->number, ['subject_id' => $note->id]);
        $note->delete();

        return back()->with('toast', ['msg' => __('حُذف الإشعار'), 'type' => 'warning']);
    }
}
