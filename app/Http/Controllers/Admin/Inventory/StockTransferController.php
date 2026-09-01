<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Pagination;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * النقل بين الفروع — البابُ الذي لم يكن.
 *
 * كان طريقُ التاجر حركتين يدويّتين: صرفٌ من فرع وإضافةٌ في آخر، لا شيء
 * يربطهما. فإن نسي الثانية نقص مخزونُه بلا سبب، وإن كتبها بكميّةٍ أخرى اختلّ
 * الرصيدان — ولا يُكتشف الفرق إلّا في جردٍ آخر السنة حين يكون سببُه قد نُسي.
 * وكانت رسالةُ رفض حذف الفرع تحيله إلى «فرعٍ آخر» ولا باب.
 *
 * والنقل ليس تعديلًا: لا مال دخل ولا خرج، فلا قيدَ له في الدفتر. البضاعةُ
 * أصلٌ في الميزانية أينما وقفت، وقيدُها لا يعرف الفروع أصلًا — فقيدٌ هنا
 * يكتب سطرين متساويين على الحساب نفسه: ضجيجٌ يُقرأ حركةً ولم يقع شيء.
 *
 * وكميّةُ المنتج لا تتحرّك: الثابت «مجموع الفروع = كمية المنتج» يبقى قائمًا
 * لأنّ ما نقص من فرعٍ زاد في آخر في المعاملة نفسها.
 */
class StockTransferController extends Controller
{
    /** ما يُرتَّب في سندات النقل */
    private const SORTS = [
        'number' => 'number',
        'quantity' => 'quantity',
        'date' => 'transferred_at',
    ];

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $q = StockTransfer::where('business_id', $bid)->with(['product', 'creator']);

        if ($s = trim((string) $request->query('q'))) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('number', $like, "%{$s}%")
                ->orWhere('notes', $like, "%{$s}%")
                ->orWhere('from_branch_name', $like, "%{$s}%")
                ->orWhere('to_branch_name', $like, "%{$s}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', $like, "%{$s}%")));
        }

        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('transferred_at')->orderByDesc('id'));

        $transfers = $q->paginate((int) $request->query('per_page', 20))->withQueryString();

        $branches = Branch::where('business_id', $bid)->orderBy('id')->get(['id', 'name']);

        return Inertia::render('Admin/Inventory/Transfers', [
            'transfers' => collect($transfers->items())->map(fn ($t) => [
                'id' => $t->id,
                'number' => $t->number,
                'product' => $t->product?->name ?? '—',
                'sku' => $t->product?->sku,
                // الاسم المنسوخ لا اسم الفرع اليوم: فرعٌ حُذف يبقى مقروءًا في تاريخه
                'from' => $t->from_branch_name,
                'to' => $t->to_branch_name,
                'quantity' => (int) $t->quantity,
                'notes' => $t->notes,
                'author' => $t->creator?->name,
                'at' => optional($t->transferred_at)->format('Y-m-d'),
            ])->all(),
            'pagination' => Pagination::meta($transfers),
            'filters' => $request->only('q') + Sort::params($request, self::SORTS),
            'sorts' => Sort::keys(self::SORTS),
            'branches' => $branches->all(),
            'currentBranchId' => Demo::currentBranchId(),
            /*
             * الرصيد لكل فرع مع كل صنف — تقرؤه الشاشة لتقول ما في الفرع
             * المُصدِّر قبل الإرسال.
             *
             * ورقمٌ يُعرض قبل الكتابة يمنع نصفَ الأخطاء: من يرى «في مسقط ٣»
             * لا يكتب ٥. والحارس في الخادم على كلّ حال.
             */
            'products' => Product::where('business_id', $bid)->orderBy('name')
                ->get(['id', 'name', 'sku', 'quantity'])
                ->map(fn ($p) => [
                    'value' => $p->id,
                    'label' => $p->sku ? $p->name.' — '.$p->sku : $p->name,
                    'quantity' => (int) $p->quantity,
                    'byBranch' => BranchStock::where('business_id', $bid)
                        ->where('product_id', $p->id)
                        ->pluck('quantity', 'branch_id')
                        ->map(fn ($n) => (int) $n)
                        ->all(),
                ])->all(),
            'today' => now()->format('Y-m-d'),
        ]);
    }

    public function store(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            /*
             * الفرعان صريحان ومختلفان.
             *
             * نقلٌ من فرعٍ إلى نفسه لا يقع في الواقع، وقبولُه يكتب سندًا
             * وحركتين تُلغيان بعضهما — سطرٌ في السجلّ يقول إنّ شيئًا حدث ولم
             * يحدث، ويُقرأ لاحقًا بحثًا عن بضاعةٍ تحرّكت.
             */
            'from_branch_id' => ['required', 'integer', 'different:to_branch_id'],
            'to_branch_id' => ['required', 'integer'],
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $bid)],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
            'transferred_at' => ['required', 'date'],
        ], [
            'from_branch_id.different' => __('لا يُنقل الصنف من الفرع إلى نفسه.'),
            'from_branch_id.required' => __('حدّد الفرع المُرسِل.'),
            'to_branch_id.required' => __('حدّد الفرع المستلِم.'),
            'quantity.min' => __('الكمية المنقولة قطعةٌ واحدة على الأقل.'),
            'quantity.integer' => __('الكمية أعدادٌ صحيحة — لا كسور'),
        ], [
            'from_branch_id' => __('الفرع المُرسِل'),
            'to_branch_id' => __('الفرع المستلِم'),
            'quantity' => __('الكمية'),
        ]);

        // الفرعان من هذا المتجر — ومعرّفٌ يصل من الطلب لا يُصدَّق
        $from = Branch::where('business_id', $bid)->find($data['from_branch_id']);
        $to = Branch::where('business_id', $bid)->find($data['to_branch_id']);

        if (! $from || ! $to) {
            return back()->withInput()->withErrors([
                'from_branch_id' => __('الفرع المحدد غير صالح.'),
            ]);
        }

        $product = Product::where('business_id', $bid)->findOrFail($data['product_id']);
        $quantity = (int) $data['quantity'];
        $shortfall = null;

        /*
         * القراءة والكتابة معاملةٌ واحدة، والمنتج مقفولٌ بينهما.
         *
         * الرصيد يُقرأ ثمّ يُنقص: نقلان يقعان معًا — أو نقلٌ وبيعةٌ — يقرآن
         * «في مسقط ثلاثة» فيخرج منه ستّة. وما خرج من رفٍّ لا يعود، ولا يُكتشف
         * إلّا في جرد.
         */
        DB::transaction(function () use ($bid, $from, $to, $product, $quantity, $data, &$shortfall) {
            $locked = Product::where('business_id', $bid)->lockForUpdate()->findOrFail($product->id);

            // التوزيع قبل التغيير لا بعده — الترتيب نفسه في الاستلام والبيع والتعديل
            BranchStock::ensureAllocated($bid, $locked->id, (int) $locked->quantity);

            $available = BranchStock::bookOf($bid, $locked->id, $from->id);

            if ($quantity > $available) {
                /*
                 * ولا يُنقل من فرعٍ أكثر ممّا فيه.
                 *
                 * رصيدٌ سالب يُفسد كلّ ما يُبنى عليه: قيمة المخزون تصير سالبة،
                 * و«المنخفض» يمتلئ بأصنافٍ لا وجود لها، ونقطة البيع تبيع ما
                 * ليس عندها.
                 */
                $shortfall = __('رصيد :branch من هذا الصنف :n فقط — لا يمكن نقل أكثر منه.', [
                    'branch' => $from->name, 'n' => $available,
                ]);

                return;
            }

            $transfer = StockTransfer::create([
                'business_id' => $bid,
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                // الاسم يُنسخ: السجلّ يُقرأ بعد حذف الفرع
                'from_branch_name' => $from->name,
                'to_branch_name' => $to->name,
                'product_id' => $locked->id,
                'number' => StockTransfer::nextNumber($bid),
                'quantity' => $quantity,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
                'transferred_at' => $data['transferred_at'],
            ]);

            BranchStock::adjust($bid, $from->id, $locked->id, -$quantity);
            BranchStock::adjust($bid, $to->id, $locked->id, $quantity);

            /*
             * وكميّةُ المنتج لا تُمسّ.
             *
             * الثابت «مجموع الفروع = كمية المنتج» يبقى قائمًا: ما نقص من فرعٍ
             * زاد في آخر. وتحريكُ الإجماليّ هنا يكسره في صمت.
             */

            foreach ([[$from->id, -$quantity], [$to->id, $quantity]] as [$branchId, $delta]) {
                InventoryMovement::create([
                    'business_id' => $bid,
                    'branch_id' => $branchId,
                    'product_id' => $locked->id,
                    'product_name' => $locked->name,
                    'sku' => $locked->sku,
                    'type' => $delta > 0 ? 'نقل وارد' : 'نقل صادر',
                    // رقم السند يجمع الحركتين — بدونه تُقرآن حادثتين لا واحدة
                    'reference' => $transfer->number,
                    'quantity' => ($delta > 0 ? '+' : '').$delta,
                    'employee_name' => auth()->user()->name,
                ]);
            }

            Activity::log('created', 'نقل مخزون: '.$locked->name.' — من '.$from->name.' إلى '.$to->name, [
                'subject_id' => $transfer->id,
            ]);
        });

        if ($shortfall !== null) {
            return back()->withInput()->withErrors(['quantity' => $shortfall]);
        }

        return back()->with('toast', ['msg' => __('سُجّل سند النقل'), 'type' => 'success']);
    }
}
