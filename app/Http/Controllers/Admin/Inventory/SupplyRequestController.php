<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Models\BranchSupplyRequest;
use App\Models\User;
use App\Support\BranchSupply;
use App\Support\BranchTransfers;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\PlanFeatures;
use App\Support\Search;
use App\Support\TransferRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * طلباتُ تزويد الفروع — الفرعُ يطلب، والمخوَّلُ يعتمد ويختار من يزوّد.
 *
 * والاعتمادُ يكتب سنداتِ نقلٍ معتمدة (`BranchSupply::approve`)؛ والإرسالُ
 * والاستلامُ على السند في «نقل المخزون بين الفروع».
 */
class SupplyRequestController extends Controller
{
    private function user(): User
    {
        return auth()->user();
    }

    private function enter(): void
    {
        $user = $this->user();

        abort_unless(Permissions::isOwner($user) || BranchTransfers::participates($user), 403,
            __('ليست لديك صلاحية على طلبات التزويد.'));

        if (! BranchSupply::licensed((int) $user->business_id)
            && ! BranchSupplyRequest::where('business_id', $user->business_id)->exists()) {
            abort(403, PlanFeatures::refusal($user->business, 'enterprise_replenishment'));
        }
    }

    public function index(Request $request): Response
    {
        $this->enter();
        $user = $this->user();
        $bid = (int) $user->business_id;

        $q = BranchSupply::visibleTo($user)->with('transfers:id,branch_supply_request_id,status,number')
            ->withCount('lines')->withSum('lines', 'requested_qty');

        if (($status = $request->query('status')) && is_string($status)) {
            $q->where('status', $status);
        }
        if (($branch = (int) $request->query('branch')) > 0) {
            $q->where('branch_id', $branch);
        }
        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('number', $like, "%{$s}%")
                ->orWhereHas('lines', fn ($l) => $l->where('product_name', $like, "%{$s}%")));
        }

        $page = $q->orderByDesc('id')->paginate(Pagination::perPage($request, 20))->withQueryString();

        return Inertia::render('Admin/Inventory/Supply/Index', [
            'requests' => collect($page->items())->map(fn (BranchSupplyRequest $r) => self::row($r))->all(),
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('q', 'status', 'branch'),
            'pendingCount' => (clone BranchSupply::visibleTo($user))->where('status', BranchSupplyRequest::PENDING)->count(),
            'branches' => BranchTransferController::branchOptions($user),
            'licensed' => BranchSupply::licensed($bid),
            'canCreate' => BranchSupply::licensed($bid) && $user->may(Permissions::SUPPLY_CREATE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->enter();
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'client_key' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $supply = BranchSupply::create($this->user(), [
                'branch_id' => (int) $data['branch_id'],
                'lines' => $data['items'],
                'notes' => $data['notes'] ?? null,
                'client_key' => $data['client_key'] ?? null,
            ]);
        } catch (TransferRefused $e) {
            return back()->withInput()->withErrors(['supply' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.supply.show', $supply->id)
            ->with('toast', ['msg' => __('أُرسل طلب التزويد :number', ['number' => $supply->number]), 'type' => 'success']);
    }

    public function show(int $id): Response
    {
        $this->enter();
        $user = $this->user();
        $supply = BranchSupplyRequest::where('business_id', $user->business_id)
            ->with(['lines', 'events', 'transfers'])->findOrFail($id);

        abort_unless(BranchSupply::canView($user, $supply), 403, __('هذا الطلب لا يخصّ فرعًا مسندًا إليك.'));

        $balances = BranchSupply::balances((int) $supply->business_id, $supply->lines->pluck('product_id')->filter()->all());

        return Inertia::render('Admin/Inventory/Supply/Show', [
            'supply' => self::row($supply) + [
                'notes' => $supply->notes,
                'decision_reason' => $supply->decision_reason,
                'decided_by' => $supply->decided_by_name,
                'decided_at' => optional($supply->decided_at)->format('Y-m-d H:i'),
                'lines' => $supply->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'product_id' => $l->product_id,
                    'name' => $l->product_name,
                    'sku' => $l->sku,
                    'requested' => $l->requested_qty,
                    'approved' => $l->approved_qty,
                    'source_branch_id' => $l->source_branch_id,
                    // رصيدُه في كلّ فرع — من يعتمد يرى من عنده ما يكفي
                    'balances' => $l->product_id ? ($balances[$l->product_id] ?? []) : [],
                ])->all(),
                'transfers' => $supply->transfers->map(fn ($t) => [
                    'id' => $t->id, 'number' => $t->number, 'status' => $t->status,
                    'from' => $t->from_branch_name, 'to' => $t->to_branch_name,
                ])->all(),
                'events' => $supply->events->map(fn ($e) => [
                    'id' => $e->id, 'action' => $e->action, 'by' => $e->user_name, 'note' => $e->note,
                    'data' => $e->data, 'at' => optional($e->created_at)->format('Y-m-d H:i'),
                ])->all(),
            ],
            'branches' => BranchTransferController::branchOptions($user),
            'can' => BranchSupply::abilities($user, $supply),
            'isOwner' => Permissions::isOwner($user),
            'isRequester' => (int) $supply->requested_by === (int) $user->id,
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'supply' => ['required', 'array', 'min:1'],
            'supply.*.source_branch_id' => ['nullable', 'integer'],
            'supply.*.quantity' => ['required', 'integer', 'min:0'],
            'self_approve' => ['nullable', 'boolean'],
        ]);

        $plan = [];
        foreach ($data['supply'] as $lineId => $row) {
            $plan[(int) $lineId] = ['source_branch_id' => isset($row['source_branch_id']) ? (int) $row['source_branch_id'] : null, 'quantity' => (int) $row['quantity']];
        }

        return $this->step($id, fn ($r, $u) => BranchSupply::approve($r, $u, $plan, (bool) ($data['self_approve'] ?? false)), __('اعتُمد الطلب وكُتبت سندات النقل'));
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->step($id, fn ($r, $u) => BranchSupply::reject($r, $u, $data['reason']), __('رُفض الطلب'));
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->step($id, fn ($r, $u) => BranchSupply::cancel($r, $u, $data['reason']), __('أُلغي الطلب'));
    }

    private function step(int $id, callable $do, string $done): RedirectResponse
    {
        $user = $this->user();
        $supply = BranchSupplyRequest::where('business_id', $user->business_id)->findOrFail($id);

        try {
            $do($supply, $user);
        } catch (TransferRefused $e) {
            return back()->withErrors(['supply' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => $done, 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private static function row(BranchSupplyRequest $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status,
            'progress' => $r->progress(),
            'branch' => $r->branch_name,
            'branch_id' => $r->branch_id,
            'requested_by' => $r->requested_by_name,
            'lines_count' => (int) ($r->lines_count ?? $r->lines()->count()),
            'quantity' => (int) ($r->lines_sum_requested_qty ?? $r->lines()->sum('requested_qty')),
            'created_at' => optional($r->created_at)->format('Y-m-d H:i'),
        ];
    }
}
