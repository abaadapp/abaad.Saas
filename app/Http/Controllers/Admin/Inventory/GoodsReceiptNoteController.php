<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptNote;
use App\Support\Demo;
use App\Support\Pagination;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إشعار استلام بضاعة — ورقةُ ما دخل المخزن ومن استلمه.
 *
 * توأمُ إشعار التسليم بالاتجاه المعاكس، وقراءةٌ فقط: هذه الأوراق لا تُكتب
 * بيدٍ ولا تُحذف — تُنشئها لحظةُ استلام أمر الشراء
 * (`PurchaseOrderController::receive`) شاهدةً على واقعةٍ جرت. ونموذجٌ يُنشئ
 * إشعارًا بلا استلامٍ يجعل الورقة تقول ما لم يقله المخزون.
 *
 * ولا تمسّ المخزون: الاستلام أدخل الكمية، وهذه ورقتُه. ولو أدخلتها ثانيةً
 * لدخلت الشحنة مرّتين — وهي القاعدة نفسها في إشعار التسليم المربوط بطلب.
 */
class GoodsReceiptNoteController extends Controller
{
    /** ما يُرتَّب في إشعارات الاستلام */
    private const SORTS = [
        'number' => 'number',
        'receiver' => 'receiver',
        'date' => 'received_at',
    ];

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $q = GoodsReceiptNote::where('business_id', $bid)
            ->with(['supplier', 'branch', 'purchaseOrder', 'items']);

        if ($s = trim((string) $request->query('q'))) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('number', $like, "%{$s}%")
                ->orWhere('receiver', $like, "%{$s}%")
                ->orWhereHas('supplier', fn ($x) => $x->where('name', $like, "%{$s}%"))
                ->orWhereHas('purchaseOrder', fn ($x) => $x->where('number', $like, "%{$s}%")));
        }

        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('received_at')->orderByDesc('id'));

        $notes = $q->paginate((int) $request->query('per_page', 20))->withQueryString();

        return Inertia::render('Admin/Inventory/Receipts', [
            'notes' => collect($notes->items())->map(fn ($n) => [
                'id' => $n->id,
                'number' => $n->number,
                'supplier' => $n->supplier?->name,
                'order' => $n->purchaseOrder?->number,
                'branch' => $n->branch?->name,
                'received_at' => optional($n->received_at)->format('Y-m-d'),
                'receiver' => $n->receiver,
                'notes' => $n->notes,
                // قيمة ما دخل بهذه الورقة — تُقابَل بفاتورة المورّد
                'value' => round($n->items->sum(fn ($i) => (float) $i->quantity * (float) $i->cost), 3),
                'items' => $n->items->map(fn ($i) => [
                    'name' => $i->name,
                    'quantity' => (float) $i->quantity,
                    'cost' => (float) $i->cost,
                ])->all(),
            ])->all(),
            'pagination' => Pagination::meta($notes),
            'filters' => $request->only('q') + Sort::params($request, self::SORTS),
            'sorts' => Sort::keys(self::SORTS),
        ]);
    }
}
