<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\BranchTransfer;
use App\Models\Product;
use App\Models\User;
use App\Support\BranchTransfers;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\PlanFeatures;
use App\Support\Search;
use App\Support\TransferRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * شاشةُ نقل المخزون بين الفروع — القائمةُ والسندُ ومراحلُه.
 *
 * ولا يقرّر هذا المتحكّمُ شيئًا: يقرأ النموذجَ ويمرّره إلى `BranchTransfers`،
 * وهي تسأل الفاعلَ عن فعله وفرعه وتقفل السندَ وتكتب. فطلبٌ يُرسَل إلى المسار
 * مباشرةً يُسأل ما تسأله الشاشة — لا أقلّ.
 */
class BranchTransferController extends Controller
{
    private function user(): User
    {
        return auth()->user();
    }

    /**
     * يدخل الشاشةَ من له فعلٌ في التحويلات — في نشاطٍ تفتحه باقتُه، أو له سجلٌّ فيها.
     *
     * وبعد تخفيض الباقة تبقى الشاشةُ تُقرأ لمن له فيها سندات: ما أُرسل يُستلم
     * ويُسوّى، ولا يُنشأ جديد (`CheckPlanFeature`).
     */
    private function enter(): void
    {
        $user = $this->user();

        abort_unless(Permissions::isOwner($user) || BranchTransfers::participates($user), 403,
            __('ليست لديك صلاحية على تحويلات المخزون.'));

        if (! BranchTransfers::licensed((int) $user->business_id)
            && ! BranchTransfer::where('business_id', $user->business_id)->exists()) {
            abort(403, PlanFeatures::refusal($user->business, 'enterprise_transfers'));
        }
    }

    public function index(Request $request): Response
    {
        $this->enter();
        $user = $this->user();
        $bid = (int) $user->business_id;

        $q = BranchTransfers::visibleTo($user)->withCount('lines')->withSum('lines', 'requested_qty');

        if (($status = $request->query('status')) && is_string($status)) {
            $q->where('status', $status);
        }
        if (($branch = (int) $request->query('branch')) > 0) {
            $q->where(fn ($w) => $w->where('from_branch_id', $branch)->orWhere('to_branch_id', $branch));
        }
        if ($from = self::date($request->query('from'))) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = self::date($request->query('to'))) {
            $q->whereDate('created_at', '<=', $to);
        }
        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('number', $like, "%{$s}%")
                ->orWhereHas('lines', fn ($l) => $l->where('product_name', $like, "%{$s}%")->orWhere('sku', $like, "%{$s}%")));
        }

        $page = $q->orderByDesc('id')->paginate(Pagination::perPage($request, 20))->withQueryString();
        $visible = BranchTransfers::visibleTo($user);

        return Inertia::render('Admin/Inventory/Transfers/Index', [
            'transfers' => collect($page->items())->map(fn (BranchTransfer $t) => self::row($t))->all(),
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('q', 'status', 'branch', 'from', 'to'),
            'counts' => [
                'pending' => (clone $visible)->where('status', BranchTransfer::PENDING)->count(),
                'approved' => (clone $visible)->whereIn('status', [BranchTransfer::APPROVED, BranchTransfer::PREPARING])->count(),
                'in_transit' => (clone $visible)->where('status', BranchTransfer::IN_TRANSIT)->count(),
                'discrepancy' => (clone $visible)->where('status', BranchTransfer::DISCREPANCY)->count(),
            ],
            'inTransit' => BranchTransfers::inTransit($bid),
            'branches' => self::branchOptions($user),
            'employees' => User::where('business_id', $bid)->where('status', 'نشط')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'licensed' => BranchTransfers::licensed($bid),
            'can' => [
                'create' => BranchTransfers::licensed($bid) && $user->may(Permissions::TRANSFER_CREATE),
                // التنفيذُ المباشر: من يعتمد ويُرسل — وصاحبُ النشاط وحده يعتمد طلبَه
                'execute' => BranchTransfers::licensed($bid) && Permissions::isOwner($user),
            ],
        ]);
    }

    /**
     * أصنافٌ تُنقل — بحثٌ برصيدها في الفرع المصدر وفي كلّ فرع.
     *
     * والقائمةُ لا تُرسَل كاملةً مع الصفحة: متجرٌ بعشرة آلاف صنف لا يُحمّل
     * عشرةَ آلاف سطرٍ ليختار ثلاثة.
     */
    public function products(Request $request): JsonResponse
    {
        $this->enter();
        $bid = (int) $this->user()->business_id;
        $branch = (int) $request->query('branch');

        $q = Product::where('business_id', $bid)->where('tracks_stock', true)->whereDoesntHave('recipeItems');
        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('name', $like, "%{$s}%")->orWhere('name_en', $like, "%{$s}%")
                ->orWhere('sku', $like, "%{$s}%")->orWhere('barcode', $like, "%{$s}%"));
        }

        $products = $q->orderBy('name')->limit(20)->get(['id', 'name', 'sku', 'quantity']);
        $books = BranchStock::books($bid);

        return response()->json(['products' => $products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'available' => $branch > 0 ? (int) ($books[$p->id][$branch] ?? 0) : null,
            'total' => (int) $p->quantity,
            'branches' => $books[$p->id] ?? [],
        ])->all()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->enter();
        $data = $request->validate([
            'from_branch_id' => ['required', 'integer'],
            'to_branch_id' => ['required', 'integer', 'different:from_branch_id'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'responsible_user_id' => ['nullable', 'integer'],
            'client_key' => ['nullable', 'string', 'max:64'],
            'execute' => ['nullable', 'boolean'],
            'self_approve' => ['nullable', 'boolean'],
        ]);

        try {
            $transfer = BranchTransfers::create($this->user(), [
                'from_branch_id' => (int) $data['from_branch_id'],
                'to_branch_id' => (int) $data['to_branch_id'],
                'lines' => $data['items'],
                'notes' => $data['notes'] ?? null,
                'responsible_user_id' => $data['responsible_user_id'] ?? null,
                'client_key' => $data['client_key'] ?? null,
                'execute' => (bool) ($data['execute'] ?? false),
                'self_approve' => (bool) ($data['self_approve'] ?? false),
            ]);
        } catch (TransferRefused $e) {
            return back()->withInput()->withErrors(['transfer' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.transfers.show', $transfer->id)
            ->with('toast', ['msg' => __('سُجّل السند :number', ['number' => $transfer->number]), 'type' => 'success']);
    }

    public function show(int $id): Response
    {
        $this->enter();
        $user = $this->user();
        $transfer = BranchTransfer::where('business_id', $user->business_id)
            ->with(['lines', 'events', 'responsible:id,name', 'supplyRequest:id,number'])->findOrFail($id);

        abort_unless(BranchTransfers::canView($user, $transfer), 403, __('هذا السند لا يخصّ فرعًا مسندًا إليك.'));

        $books = BranchStock::books((int) $transfer->business_id);

        return Inertia::render('Admin/Inventory/Transfers/Show', [
            'transfer' => self::row($transfer) + [
                'notes' => $transfer->notes,
                'decision_reason' => $transfer->decision_reason,
                'self_approved' => $transfer->self_approved,
                'responsible' => $transfer->responsible?->name,
                'supply' => $transfer->supplyRequest ? ['id' => $transfer->supplyRequest->id, 'number' => $transfer->supplyRequest->number] : null,
                'stages' => [
                    'requested' => ['by' => $transfer->requested_by_name, 'at' => optional($transfer->created_at)->format('Y-m-d H:i')],
                    'approved' => ['by' => $transfer->approved_by_name, 'at' => optional($transfer->approved_at)->format('Y-m-d H:i')],
                    'prepared' => ['by' => $transfer->prepared_by_name, 'at' => optional($transfer->prepared_at)->format('Y-m-d H:i')],
                    'dispatched' => ['by' => $transfer->dispatched_by_name, 'at' => optional($transfer->dispatched_at)->format('Y-m-d H:i')],
                    'received' => ['by' => $transfer->received_by_name, 'at' => optional($transfer->received_at)->format('Y-m-d H:i')],
                ],
                'lines' => $transfer->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'product_id' => $l->product_id,
                    'name' => $l->product_name,
                    'sku' => $l->sku,
                    'requested' => $l->requested_qty,
                    'approved' => $l->approved_qty,
                    'sent' => $l->sent_qty,
                    'received' => $l->received_qty,
                    'lost' => $l->lost_qty,
                    'returned' => $l->returned_qty,
                    'late' => $l->late_qty,
                    'outstanding' => $l->outstanding(),
                    'unit_cost' => $l->unit_cost === null ? null : (float) $l->unit_cost,
                    // الرصيدُ المتاح الآن في المصدر — يقرؤه من يعتمد ومن يُرسل
                    'available' => $l->product_id ? (int) ($books[$l->product_id][$transfer->from_branch_id] ?? 0) : 0,
                ])->all(),
                'events' => $transfer->events->map(fn ($e) => [
                    'id' => $e->id,
                    'action' => $e->action,
                    'from' => $e->from_status,
                    'to' => $e->to_status,
                    'by' => $e->user_name,
                    'note' => $e->note,
                    'data' => $e->data,
                    'at' => optional($e->created_at)->format('Y-m-d H:i'),
                ])->all(),
            ],
            'can' => BranchTransfers::abilities($user, $transfer),
            'isOwner' => Permissions::isOwner($user),
            'isRequester' => (int) $transfer->requested_by === (int) $user->id,
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['integer', 'min:0'],
            'self_approve' => ['nullable', 'boolean'],
        ]);

        return $this->step($id, fn ($t, $u) => BranchTransfers::approve($t, $u, self::ints($data['quantities'] ?? []), (bool) ($data['self_approve'] ?? false)), __('اعتُمد السند'));
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->step($id, fn ($t, $u) => BranchTransfers::reject($t, $u, $data['reason']), __('رُفض الطلب'));
    }

    public function prepare(int $id): RedirectResponse
    {
        return $this->step($id, fn ($t, $u) => BranchTransfers::prepare($t, $u), __('السند قيد التحضير'));
    }

    public function send(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['integer', 'min:0'],
        ]);

        return $this->step($id, fn ($t, $u) => BranchTransfers::dispatch($t, $u, self::ints($data['quantities'] ?? [])), __('أُرسلت البضاعة — بانتظار الاستلام'));
    }

    public function receive(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'quantities' => ['required', 'array', 'min:1'],
            'quantities.*' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->step($id, fn ($t, $u) => BranchTransfers::receive($t, $u, self::ints($data['quantities']), $data['note'] ?? null), __('سُجّل الاستلام'));
    }

    public function resolve(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'line_id' => ['required', 'integer'],
            'kind' => ['required', 'string', Rule::in(BranchTransfers::RESOLUTIONS)],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->step($id, fn ($t, $u) => BranchTransfers::resolve($t, $u, (int) $data['line_id'], $data['kind'], (int) $data['quantity'], $data['reason']), __('سُوّي الفرق'));
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->step($id, fn ($t, $u) => BranchTransfers::cancel($t, $u, $data['reason']), __('أُلغي السند'));
    }

    /** مرحلةٌ واحدة — السندُ من نشاطه، والرفضُ رسالةٌ لا صفحةُ خطأ */
    private function step(int $id, callable $do, string $done): RedirectResponse
    {
        $user = $this->user();
        $transfer = BranchTransfer::where('business_id', $user->business_id)->findOrFail($id);

        try {
            $do($transfer, $user);
        } catch (TransferRefused $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => $done, 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    public static function row(BranchTransfer $t): array
    {
        return [
            'id' => $t->id,
            'number' => $t->number,
            'status' => $t->status,
            'from' => $t->from_branch_name,
            'to' => $t->to_branch_name,
            'from_id' => $t->from_branch_id,
            'to_id' => $t->to_branch_id,
            'lines_count' => (int) ($t->lines_count ?? $t->lines()->count()),
            'quantity' => (int) ($t->lines_sum_requested_qty ?? $t->lines()->sum('requested_qty')),
            'requested_by' => $t->requested_by_name,
            'approved_by' => $t->approved_by_name,
            'dispatched_by' => $t->dispatched_by_name,
            'received_by' => $t->received_by_name,
            'created_at' => optional($t->created_at)->format('Y-m-d H:i'),
            'dispatched_at' => optional($t->dispatched_at)->format('Y-m-d H:i'),
            'received_at' => optional($t->received_at)->format('Y-m-d H:i'),
        ];
    }

    /** فروعُ النشاط — ومعها هل الموظّفُ مسندٌ إليها */
    public static function branchOptions(User $user): array
    {
        return Branch::where('business_id', $user->business_id)->orderBy('id')->get(['id', 'name'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'mine' => BranchTransfers::covers($user, $b->id)])
            ->all();
    }

    /** @return array<int, int> */
    private static function ints(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(int) $k] = (int) $v;
        }

        return $out;
    }

    private static function date(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
    }
}
