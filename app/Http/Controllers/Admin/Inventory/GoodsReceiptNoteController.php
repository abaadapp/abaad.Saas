<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptNote;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\Demo;
use App\Support\Document\PaperSize;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use App\Support\GoodsReceipts;
use App\Support\Pagination;
use App\Support\Permissions;
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
 * ولا تمسّ المخزون من هنا: `GoodsReceipts::approve` هي التي تُدخل الكمية.
 * وهذه الشاشةُ تعرض الطابور وتقود إلى الاعتماد لا تنفّذه.
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
        /*
         * والقراءةُ نفسُها فعلٌ يُمنح: هذه الأوراق تحمل تكلفةَ كلّ صنفٍ
         * اشتراه المتجر، وقسمُ «المخزون» يُمنح لمن يعدّ الرفوف لا لمن يقرأ
         * بكم اشتُريت.
         */
        if (! auth()->user()?->may(Permissions::RECEIPT_VIEW)) {
            abort(403);
        }

        $bid = $this->bid();

        $q = GoodsReceiptNote::where('business_id', $bid)
            ->with(['supplier', 'branch', 'purchaseOrder', 'items']);

        // ترشيحٌ بالحال — و«بانتظار الاعتماد» أوّلُ ما يُفتح عليه
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('number', $like, "%{$s}%")
                ->orWhere('receiver', $like, "%{$s}%")
                ->orWhereHas('supplier', fn ($x) => $x->where('name', $like, "%{$s}%"))
                ->orWhereHas('purchaseOrder', fn ($x) => $x->where('number', $like, "%{$s}%")));
        }

        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('received_at')->orderByDesc('id'));

        $notes = $q->paginate(Pagination::perPage($request, 20))->withQueryString();

        return Inertia::render('Admin/Inventory/Receipts', [
            'notes' => collect($notes->items())->map(fn ($n) => [
                'id' => $n->id,
                'number' => $n->number,
                'supplier' => $n->supplier?->name,
                'order' => $n->purchaseOrder?->number,
                // ومفتاحُ الأمر معه: الرقمُ يُقرأ، والمفتاحُ يفتح
                'order_id' => $n->purchase_order_id,
                'branch' => $n->branch?->name,
                'received_at' => optional($n->received_at)->format('Y-m-d'),
                'receiver' => $n->receiver,
                'notes' => $n->notes,
                'status' => $n->status,
                'approved_at' => optional($n->approved_at)->format('Y-m-d'),
                'rejected_at' => optional($n->rejected_at)->format('Y-m-d'),
                'rejection_reason' => $n->rejection_reason,
                // اسمُ المرفق كما سمّاه صاحبُه — والمخزَّنُ عشوائيّ
                'attachment' => $n->attachment ? ($n->attachment_name ?: __('ورقة المورّد')) : null,
                // قيمة ما دخل بهذه الورقة — تُقابَل بفاتورة المورّد
                'value' => round($n->items->sum(fn ($i) => (float) $i->quantity * (float) $i->cost), 3),
                /*
                 * وكلُّ سطرٍ يقول أين موضعُه من أمره: كم طُلب، وكم استُلم
                 * قبله، وكم في هذه الورقة، وكم يبقى. ومن يعتمد بلا هذه
                 * الأربعة يعتمد رقمًا لا يعرف نسبته إلى شيء.
                 */
                'items' => $n->items->map(function ($i) {
                    $line = $i->purchase_order_item_id
                        ? PurchaseOrderItem::find($i->purchase_order_item_id)
                        : null;

                    return [
                        'name' => $i->name,
                        'quantity' => (float) $i->quantity,
                        'cost' => (float) $i->cost,
                        'ordered' => $line ? (float) $line->quantity : null,
                        'received_before' => $line ? (float) $line->received_quantity : null,
                        'remaining' => $line ? (float) $line->remaining : null,
                    ];
                })->all(),
            ])->all(),
            'pagination' => Pagination::meta($notes),
            'filters' => $request->only('q', 'status') + Sort::params($request, self::SORTS),
            'sorts' => Sort::keys(self::SORTS),
            'pendingCount' => GoodsReceiptNote::where('business_id', $bid)
                ->where('status', GoodsReceipts::PENDING)->count(),
            // من يرى الزرّ هو من يملك الفعل — وبابٌ يُعرض ولا يُفتح أسوأ من غيابه
            'canApprove' => (bool) auth()->user()?->may(Permissions::RECEIPT_APPROVE),
            'canReject' => (bool) auth()->user()?->may(Permissions::RECEIPT_REJECT),
            // ولا `canSeeAttachment` هنا: ورقةُ المورّد تُقرأ في صفحة السند وحدها
        ]);
    }

    /**
     * سندُ استلامٍ واحد — بنودُه وأمرُه وورقتُه كما تُطبع.
     *
     * ═══ والبابُ الذي لم يكن ═══
     *
     * كان السندُ يُقرأ في نافذةٍ فوق القائمة، ورقمُه في شاشة أمر الشراء يقود
     * إلى **ملفّ PDF** في لسانٍ آخر. فلا عنوانَ له يُرسَل، ولا رجوعَ منه إلى
     * أمره، ولا موضعَ يقول من اعتمده ومتى أو لمَ رُفض. ومن يراجع خلافًا مع
     * مورّدٍ يحتاج الثلاثة معًا.
     *
     * ═══ والقيدُ في الاستعلام لا في الشاشة ═══
     *
     * `business_id` في `where` قبل المفتاح: رقمٌ مُخمَّن في العنوان كان
     * سيفتح سندَ متجرٍ آخر — وفيه تكلفةُ شرائه صنفًا صنفًا.
     */
    public function show(int|string $id): Response
    {
        if (! auth()->user()?->may(Permissions::RECEIPT_VIEW)) {
            abort(403);
        }

        $bid = $this->bid();

        $note = GoodsReceiptNote::where('business_id', $bid)
            ->with(['supplier', 'branch', 'purchaseOrder', 'items' => fn ($q) => $q->orderBy('id')])
            ->findOrFail($id);

        $mayAttachment = (bool) auth()->user()?->may(Permissions::ATTACHMENT_VIEW);

        /*
         * وأسماءُ من وقّعوا تُقرأ دفعةً واحدة — لا استعلامًا لكلّ اسم.
         */
        $actors = User::whereIn('id', array_filter([
            $note->submitted_by, $note->approved_by, $note->rejected_by,
        ]))->pluck('name', 'id');

        return Inertia::render('Admin/Inventory/ReceiptShow', [
            'note' => [
                'id' => $note->id,
                'number' => $note->number,
                'status' => $note->status,
                'supplier' => $note->supplier?->name,
                'branch' => $note->branch?->name,
                'received_at' => optional($note->received_at)->format('Y-m-d'),
                'receiver' => $note->receiver,
                'notes' => $note->notes,
                'approved_at' => optional($note->approved_at)->format('Y-m-d'),
                'rejected_at' => optional($note->rejected_at)->format('Y-m-d'),
                'rejection_reason' => $note->rejection_reason,
                'submitted_by' => $actors[$note->submitted_by] ?? null,
                'approved_by' => $actors[$note->approved_by] ?? null,
                'rejected_by' => $actors[$note->rejected_by] ?? null,
                'value' => round($note->items->sum(fn ($i) => (float) $i->quantity * (float) $i->cost), 3),
                /*
                 * ووجودُ المرفق سؤالٌ غيرُ «هل تقرؤه؟».
                 *
                 * من لا يملك فتحَ المرفقات لا يُبنى له رابط — وتقول له الشاشة
                 * إنّ ثمّة ورقةً لا تُفتح، لا إنّه لا ورقة.
                 */
                'has_attachment' => $note->attachment !== null,
                'attachment' => $note->attachment && $mayAttachment
                    ? route('admin.inventory.receipts.attachment', $note->id) : null,
                'attachment_name' => $note->attachment ? ($note->attachment_name ?: __('ورقة المورّد')) : null,
                'items' => $note->items->map(function ($i) {
                    $line = $i->purchase_order_item_id
                        ? PurchaseOrderItem::find($i->purchase_order_item_id)
                        : null;

                    return [
                        'name' => $i->name,
                        'quantity' => (float) $i->quantity,
                        'cost' => (float) $i->cost,
                        'total' => round((float) $i->quantity * (float) $i->cost, 3),
                        'ordered' => $line ? (float) $line->quantity : null,
                        'received_before' => $line ? (float) $line->received_quantity : null,
                        'remaining' => $line ? (float) $line->remaining : null,
                    ];
                })->all(),
            ],
            /* وأمرُه: طريقٌ يعود إليه — لا رقمٌ يُقرأ ولا يُفتح */
            'order' => $note->purchaseOrder ? [
                'id' => $note->purchaseOrder->id,
                'number' => $note->purchaseOrder->number,
            ] : null,
            'can' => [
                'approve' => (bool) auth()->user()?->may(Permissions::RECEIPT_APPROVE),
                'reject' => (bool) auth()->user()?->may(Permissions::RECEIPT_REJECT),
            ],
            /* والورقةُ إلى جانب تفاصيلها — من بانيها الذي يُطبع منه */
            'paper' => [
                'html' => DocumentRenderer::generic($bid, 'grn', DocumentPaper::forGrn($note)),
                'size' => PaperSize::A4,
            ],
        ]);
    }
}
