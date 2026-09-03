<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Pagination;
use App\Support\Search;
use App\Support\Sort;
use App\Support\Waste;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * تعديلات المخزون — تلفٌ وفقدٌ وتصحيحُ عدّ.
 *
 * التعديل ليس تصحيحًا للرقم وحده: قطعةٌ تلفت مالٌ ضاع، فتنقص من المخزون
 * وتُقيَّد خسارةً في الدفتر. والاكتفاء بتنقيص العدد يُبقي قيمة المخزون في
 * الميزانية كما كانت — فيظهر المتجر أغنى ممّا هو بقيمة كلّ ما تلف عنده.
 *
 * والتكلفة تُنسخ لحظةَ التعديل: تكلفة المنتج متوسّطٌ يتحرّك مع كل شراء،
 * فقراءتها اليوم عن تلفٍ وقع قبل سنة تُعطي رقمًا لم يقع.
 */
class StockAdjustmentController extends Controller
{
    /** ما يُرتَّب في تعديلات المخزون */
    private const SORTS = [
        'number' => 'number',
        'reason' => 'reason',
        'delta' => 'quantity_delta',
        'date' => 'adjusted_at',
    ];

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        Ledger::ensureSystemAccounts($bid);

        $q = StockAdjustment::where('business_id', $bid)->with(['product', 'creator', 'branch']);

        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('number', $like, "%{$s}%")
                ->orWhere('notes', $like, "%{$s}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', $like, "%{$s}%")));
        }
        if ($reason = $request->query('reason')) {
            $q->where('reason', $reason);
        }

        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('adjusted_at')->orderByDesc('id'));

        $adjustments = $q->paginate((int) $request->query('per_page', 20))->withQueryString();

        $all = StockAdjustment::where('business_id', $bid)->get();

        return Inertia::render('Admin/Inventory/Adjustments', [
            'adjustments' => collect($adjustments->items())->map(fn ($a) => [
                'id' => $a->id,
                'number' => $a->number,
                'product' => $a->product?->name ?? '—',
                'sku' => $a->product?->sku,
                'branch' => $a->branch?->name,
                'delta' => (float) $a->quantity_delta,
                'cost' => (float) $a->cost_at_time,
                'value' => $a->valueImpact(),
                'reason' => $a->reason,
                'notes' => $a->notes,
                'author' => $a->creator?->name,
                'at' => optional($a->adjusted_at)->format('Y-m-d'),
            ])->all(),
            'pagination' => Pagination::meta($adjustments),
            'filters' => $request->only('q', 'reason')
                + Sort::params($request, self::SORTS),
            'sorts' => Sort::keys(self::SORTS),
            'reasons' => StockAdjustment::REASONS,
            // التعديل يقع على فرعٍ بعينه، فالنموذج يلزمه اختياره — والفرع
            // الحاليّ في الجلسة قيمةٌ ابتدائية لا أكثر
            'branches' => Branch::where('business_id', $bid)->orderBy('id')->get(['id', 'name'])->all(),
            'currentBranchId' => Demo::currentBranchId(),
            'products' => Product::where('business_id', $bid)->orderBy('name')
                ->get(['id', 'name', 'sku', 'quantity', 'cost'])
                ->map(fn ($p) => [
                    'value' => $p->id,
                    'label' => $p->sku ? $p->name.' — '.$p->sku : $p->name,
                    'quantity' => (int) $p->quantity,
                    'cost' => (float) $p->cost,
                ])->all(),
            'summary' => [
                'count' => $all->count(),
                // الخسارة موجبةٌ في العرض: «خسرتَ ٤٠» أوضح من «‏−٤٠»
                'loss' => round(abs($all->where('quantity_delta', '<', 0)->sum(fn ($a) => $a->valueImpact())), 3),
                'gain' => round($all->where('quantity_delta', '>', 0)->sum(fn ($a) => $a->valueImpact()), 3),
            ],
            'today' => now()->format('Y-m-d'),
        ]);
    }

    public function store(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            /*
             * الفرع صريحٌ لا مأخوذٌ من الجلسة.
             *
             * كان يُقرأ من `Demo::currentBranchId()`، وهي تُرجع null في وضع
             * «كل الفروع» — وهو وضع الجلسة الافتراضيّ. فالتعديل كان يزيد
             * إجماليّ الشركة ولا يمسّ رصيد فرعٍ واحد، فينكسر الثابت «مجموع
             * الفروع = كمية المنتج» بلا خطأ ولا أثرٍ في أيّ تقرير.
             *
             * والجرد — وهو العملية الشقيقة — يطلب الفرع صراحةً منذ البداية.
             */
            'branch_id' => ['required', 'integer'],
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $bid)],
            /*
             * عددٌ صحيح لا كسر.
             *
             * `products.quantity` و`branch_stocks.quantity` عمودان صحيحان،
             * فنصفُ قطعةٍ لا موضع لها فيهما. وقبولُها ثمّ قصُّها إلى صفرٍ
             * بصمتٍ أسوأ من ردّها: من كتب «٢٫٥» يُنتظر أن يتحرّك المخزون
             * بشيء، فلا يتحرّك — ولا يُقال له لماذا.
             */
            'quantity_delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', Rule::in(StockAdjustment::REASONS)],
            'notes' => ['nullable', 'string', 'max:500'],
            'adjusted_at' => ['required', 'date'],
        ], [
            'quantity_delta.not_in' => __('تعديلٌ بصفرٍ لا يُغيّر شيئًا'),
            'quantity_delta.integer' => __('الكمية أعدادٌ صحيحة — لا كسور'),
            // النصّ نفسه الذي تستعمله حركة المخزون اليدوية — لا صياغةٌ ثانية
            // لنفس المعنى تُترجم مرّتين وتفترقان
            'branch_id.required' => __('يجب تحديد الفرع قبل أي إضافة أو تعديل على المخزون.'),
        ]);

        $branch = Branch::where('business_id', $bid)->find($data['branch_id']);
        if (! $branch) {
            return back()->withInput()->withErrors(['branch_id' => __('الفرع المحدد غير صالح.')]);
        }

        $product = Product::where('business_id', $bid)->findOrFail($data['product_id']);
        /*
         * رقمٌ واحد صحيح يتحرّك به كلُّ شيء: الكمية، ورصيد الفرع، وسجلّ
         * التعديل، وحركة المخزون، والقيد المالي.
         *
         * وسببُ الهالك ينقص المخزون دائمًا: كان النموذج يقبل «تلف ‎+٦» فيزيد
         * المخزون بحجّة أنّ بضاعةً تلفت — وهو ما لا معنى له في أيّ قراءة،
         * ويسمّم كلّ تقريرٍ يجمع الخسائر. والتصحيح في الخادم لا في الشاشة:
         * يكتب المستخدم «٦» كما ينطقها، ويجعلها الخادم ‎−٦، فلا يُطلب من أحدٍ
         * أن يتذكّر إشارةً.
         *
         * والصفوف القديمة لا تُمسّ — تُقرأ وتُبلَّغ في شاشة التحليلات.
         */
        $delta = (int) Waste::normalizeDelta($data['reason'], (int) $data['quantity_delta']);

        /*
         * الكمية لا تنزل تحت الصفر.
         *
         * رصيدٌ سالب يُفسد كلّ ما يُبنى عليه: قيمة المخزون تصير سالبة،
         * و«المنخفض» يمتلئ بأصنافٍ لا وجود لها، ونقطة البيع تبيع ما ليس عندها.
         */
        if ($delta < 0 && abs($delta) > (float) $product->quantity) {
            return back()->withInput()->withErrors([
                'quantity_delta' => __('المتوفّر :n فقط', ['n' => (int) $product->quantity]),
            ]);
        }

        $cost = round((float) $product->cost, 3);
        $value = round(abs($delta) * $cost, 3);

        try {
            DB::transaction(function () use ($bid, $branch, $product, $delta, $cost, $value, $data) {
                $adjustment = StockAdjustment::create([
                    'business_id' => $bid,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'number' => StockAdjustment::nextNumber($bid),
                    'quantity_delta' => $delta,
                    // تُنسخ لحظتها: المتوسّط يتحرّك مع كل شراء
                    'cost_at_time' => $cost,
                    'reason' => $data['reason'],
                    'notes' => $data['notes'] ?? null,
                    'created_by' => auth()->id(),
                    'adjusted_at' => $data['adjusted_at'],
                ]);

                /*
                 * التوزيع قبل التغيير لا بعده.
                 *
                 * `ensureAllocated` تُعطى الكمية التي تُنقل إلى الفرع الأوّل
                 * حين لا يكون للمنتج صفُّ فرعٍ بعد. وكانت تُنادى بعد
                 * `increment`، فتُعطى الكمية الجديدة: فمنتجٌ كميّته صفر —
                 * وكلّ نسخةٍ من زرّ «نسخ المنتج» كذلك — يُعدَّل بخمسة، فيصير
                 * الإجماليّ خمسة ويُنشأ له صفُّ فرعٍ بخمسة ثمّ تُضاف خمسةٌ
                 * أخرى: عشرةٌ في الفروع وخمسةٌ في الإجماليّ.
                 *
                 * وهو الترتيب نفسه في الاستلام والبيع وإشعار التسليم.
                 */
                BranchStock::ensureAllocated($bid, $product->id, (int) $product->quantity);

                $product->increment('quantity', $delta);

                BranchStock::adjust($bid, $branch->id, $product->id, $delta);

                InventoryMovement::create([
                    'business_id' => $bid,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'type' => $delta > 0 ? 'إضافة كمية' : 'خصم كمية',
                    'quantity' => ($delta > 0 ? '+' : '').$delta,
                    'employee_name' => auth()->user()->name,
                ]);

                /*
                 * القيد يتبع الحركة — إن كان لها قيمة.
                 *
                 * النقص خسارة: المخزون يُنقص ومصروفٌ يُقيَّد. والزيادة عكسها:
                 * بضاعةٌ وُجدت لم تكن مسجَّلة، فتزيد الأصول ويقلّ ما حُمّل على
                 * المصروف. ومنتجٌ بلا تكلفة لا قيد له — لا مبلغ يُقيَّد.
                 */
                if ($value > 0) {
                    Ledger::post(
                        $bid,
                        __('تعديل مخزون: ').$data['reason'].' — '.$product->name,
                        $delta < 0
                            ? [
                                ['account' => 'other_expenses', 'debit' => $value, 'memo' => $product->name],
                                ['account' => 'inventory', 'credit' => $value],
                            ]
                            : [
                                ['account' => 'inventory', 'debit' => $value, 'memo' => $product->name],
                                ['account' => 'other_expenses', 'credit' => $value],
                            ],
                        Carbon::parse($data['adjusted_at']),
                        'تعديل مخزون',
                        $branch->id,
                        auth()->id(),
                        $adjustment,
                    );
                }
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['quantity_delta' => $e->getMessage()]);
        }

        Activity::log(
            'created',
            'عدّل مخزون '.$product->name.' بمقدار '.$delta.' — '.$data['reason'],
            ['subject_id' => $product->id]
        );

        return back()->with('toast', ['msg' => __('سُجّل التعديل'), 'type' => 'success']);
    }
}
