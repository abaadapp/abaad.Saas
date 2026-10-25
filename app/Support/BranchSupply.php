<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchOperationEvent;
use App\Models\BranchStock;
use App\Models\BranchSupplyRequest;
use App\Models\BranchTransfer;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * طلباتُ تزويد الفروع — الفرعُ يطلب، والإدارةُ تعتمد وتختار من يزوّده.
 *
 * ═══ ولا يحرّك الطلبُ رفًّا ═══
 *
 * الطلبُ ورقة، والاعتمادُ ورقة: يُكتب سندُ نقلٍ معتمد لكلّ فرعٍ مزوِّد
 * (`BranchTransfers::create`)، والبضاعةُ تخرج عند إرسال السند وحده — بالخصم
 * المشروط. فموافقةٌ على خمسٍ وعشرين قطعةً من فرعٍ فيه عشرٌ لا تُنشئ خمسَ عشرة:
 * يُرسل الفرعُ ما عنده أو يُرَدّ إرسالُه.
 *
 * والسندُ يحمل رقمَ الطلب، والطلبُ يقرأ تقدّمه من سنداته
 * (`BranchSupplyRequest::progress`) — فلا حالتان تفترقان.
 */
final class BranchSupply
{
    public static function licensed(int $businessId): bool
    {
        return PlanFeatures::allows(Business::find($businessId), 'enterprise_replenishment');
    }

    public static function canView(User $user, BranchSupplyRequest $request): bool
    {
        if ((int) $request->business_id !== (int) $user->business_id) {
            return false;
        }

        if (Permissions::isOwner($user) || $user->may(Permissions::TRANSFER_VIEW_ALL)) {
            return true;
        }

        if (! BranchTransfers::participates($user)) {
            return false;
        }

        // صاحبُ الطلب، أو موظّفُ الفرع الطالب، أو من يعتمد — والمعتمدُ يرى طلباتِ كلّ فرع
        return (int) $request->requested_by === (int) $user->id
            || BranchTransfers::covers($user, $request->branch_id)
            || $user->may(Permissions::TRANSFER_APPROVE);
    }

    public static function visibleTo(User $user)
    {
        $q = BranchSupplyRequest::where('business_id', $user->business_id);

        if (Permissions::isOwner($user) || $user->may(Permissions::TRANSFER_VIEW_ALL) || $user->may(Permissions::TRANSFER_APPROVE)) {
            return $q;
        }

        if (! BranchTransfers::participates($user)) {
            return $q->whereRaw('1 = 0');
        }

        $allowed = CostsAndLosses::allowedBranches((int) $user->business_id, $user);

        return $allowed === null ? $q : $q->where(fn ($w) => $w->whereIn('branch_id', $allowed)->orWhere('requested_by', $user->id));
    }

    /** @return array<string, bool> */
    public static function abilities(User $user, BranchSupplyRequest $request): array
    {
        return [
            'approve' => self::refusal($user, 'approve', $request) === null,
            'reject' => self::refusal($user, 'reject', $request) === null,
            'cancel' => self::refusal($user, 'cancel', $request) === null,
        ];
    }

    public static function refusal(User $user, string $step, BranchSupplyRequest $request): ?string
    {
        if ((int) $request->business_id !== (int) $user->business_id) {
            return __('هذا الطلب ليس من نشاطك.');
        }

        if ($request->status !== BranchSupplyRequest::PENDING) {
            return __('الطلب ليس بانتظار المراجعة.');
        }

        if ($step === 'cancel' && (int) $request->requested_by === (int) $user->id) {
            return null;
        }

        if ($step === 'approve' && (! self::licensed((int) $request->business_id) || ! BranchTransfers::licensed((int) $request->business_id))) {
            return PlanFeatures::refusal(Business::find($request->business_id), self::licensed((int) $request->business_id) ? 'enterprise_transfers' : 'enterprise_replenishment');
        }

        return match (true) {
            ! $user->may(Permissions::TRANSFER_APPROVE) => __('ليست لديك صلاحية مراجعة طلبات التزويد.'),
            $step === 'approve' && (int) $request->requested_by === (int) $user->id && ! Permissions::isOwner($user) => __('لا يعتمد صاحبُ الطلب طلبَه — يعتمده غيرُه.'),
            default => null,
        };
    }

    /**
     * طلبُ تزويد لفرعٍ مسندٍ إلى الطالب.
     *
     * @param  array{branch_id: int, lines: list<array{product_id: int, quantity: int}>, notes?: ?string, client_key?: ?string}  $data
     */
    public static function create(User $user, array $data): BranchSupplyRequest
    {
        $bid = (int) $user->business_id;

        if (! self::licensed($bid)) {
            throw new TransferRefused(PlanFeatures::refusal($user->business, 'enterprise_replenishment'));
        }
        if (! $user->may(Permissions::SUPPLY_CREATE)) {
            throw new TransferRefused(__('ليست لديك صلاحية إنشاء طلب تزويد.'));
        }

        $branch = Branch::where('business_id', $bid)->whereKey((int) ($data['branch_id'] ?? 0))->first();
        if (! $branch) {
            throw new TransferRefused(__('الفرع ليس من نشاطك.'));
        }
        if (! BranchTransfers::covers($user, $branch->id)) {
            throw new TransferRefused(__('لا تطلب التزويد إلا لفرعٍ مسندٍ إليك.'));
        }

        $lines = BranchTransfers::lines($bid, $data['lines'] ?? []);
        $key = trim((string) ($data['client_key'] ?? ''));
        $key = $key === '' ? null : mb_substr($key, 0, 64);
        $notes = trim((string) ($data['notes'] ?? ''));

        return DB::transaction(function () use ($bid, $user, $branch, $lines, $key, $notes) {
            Business::whereKey($bid)->lockForUpdate()->first();

            if ($key !== null && ($existing = BranchSupplyRequest::where('business_id', $bid)->where('client_key', $key)->first())) {
                return $existing;
            }

            $request = BranchSupplyRequest::create([
                'business_id' => $bid,
                'number' => self::nextNumber($bid),
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'status' => BranchSupplyRequest::PENDING,
                'notes' => $notes === '' ? null : mb_substr($notes, 0, 1000),
                'requested_by' => $user->id,
                'requested_by_name' => $user->name,
                'client_key' => $key,
            ]);

            foreach ($lines as $line) {
                $request->lines()->create([
                    'business_id' => $bid,
                    'product_id' => $line['product']->id,
                    'product_name' => $line['product']->name,
                    'sku' => $line['product']->sku,
                    'requested_qty' => $line['quantity'],
                ]);
            }

            self::log($request, $user, 'requested', null, BranchSupplyRequest::PENDING);

            return $request;
        });
    }

    /**
     * الاعتمادُ — لكلّ سطرٍ فرعٌ يزوّده وكميّةٌ لا تزيد على المطلوبة.
     *
     * ويُكتب سندُ نقلٍ معتمد لكلّ فرعٍ مزوِّد، باسم الطالب طلبًا وباسم المعتمِد
     * اعتمادًا. وسطرٌ كميّتُه صفر لا يُزوَّد ويبقى في الطلب كما طُلب.
     *
     * @param  array<int, array{source_branch_id: ?int, quantity: int}>  $plan  [معرّف السطر => …]
     */
    public static function approve(BranchSupplyRequest $request, User $user, array $plan, bool $selfApprove = false): void
    {
        DB::transaction(function () use ($request, $user, $plan, $selfApprove) {
            $locked = BranchSupplyRequest::whereKey($request->id)->where('business_id', $user->business_id)
                ->lockForUpdate()->first();
            if (! $locked) {
                throw new TransferRefused(__('هذا الطلب ليس من نشاطك.'));
            }

            $why = self::refusal($user, 'approve', $locked);
            if ($why !== null) {
                throw new TransferRefused($why);
            }

            $self = (int) $locked->requested_by === (int) $user->id;
            if ($self && ! $selfApprove) {
                throw new TransferRefused(__('أنت من طلب هذا التزويد — اعتمادُه بنفسك يحتاج تأكيدًا صريحًا.'));
            }

            $bid = (int) $locked->business_id;
            $groups = [];

            foreach ($locked->lines as $line) {
                $row = $plan[$line->id] ?? null;
                $qty = (int) ($row['quantity'] ?? 0);
                $source = (int) ($row['source_branch_id'] ?? 0);

                if ($qty < 0 || $qty > (int) $line->requested_qty) {
                    throw new TransferRefused(__('الكمية المعتمدة من «:name» بين صفر و:max.', ['name' => $line->product_name, 'max' => $line->requested_qty]));
                }

                if ($qty > 0) {
                    if ($source === (int) $locked->branch_id) {
                        throw new TransferRefused(__('«:name»: لا يزوّد الفرعُ نفسَه — اختر فرعًا آخر.', ['name' => $line->product_name]));
                    }
                    if (! Branch::where('business_id', $bid)->whereKey($source)->exists()) {
                        throw new TransferRefused(__('«:name»: اختر الفرع الذي سيزوّده.', ['name' => $line->product_name]));
                    }
                    if (! BranchTransfers::covers($user, $source)) {
                        throw new TransferRefused(__('لست مُسنَدًا إلى الفرع المزوِّد.'));
                    }
                    if (! $line->product_id) {
                        throw new TransferRefused(__('«:name» حُذف من المنتجات — لا يُزوَّد.', ['name' => $line->product_name]));
                    }
                    $groups[$source][] = ['product_id' => (int) $line->product_id, 'quantity' => $qty];
                }

                $line->update(['approved_qty' => $qty, 'source_branch_id' => $qty > 0 ? $source : null]);
            }

            if (! $groups) {
                throw new TransferRefused(__('لا كميةَ معتمدة — ارفض الطلب بدل اعتماده فارغًا.'));
            }

            $requester = User::where('business_id', $bid)->find($locked->requested_by) ?? $user;
            $numbers = [];

            foreach ($groups as $source => $lines) {
                $transfer = BranchTransfers::create($user, [
                    'from_branch_id' => $source,
                    'to_branch_id' => (int) $locked->branch_id,
                    'lines' => $lines,
                    'notes' => __('تزويد :number', ['number' => $locked->number]),
                ], $locked->id, $requester, preApproved: true);

                if ($self) {
                    $transfer->update(['self_approved' => true]);
                }
                $numbers[] = $transfer->number;
            }

            $locked->update([
                'status' => BranchSupplyRequest::APPROVED,
                'decided_by' => $user->id,
                'decided_by_name' => $user->name,
                'decided_at' => now(),
            ]);

            self::log($locked, $user, 'approved', BranchSupplyRequest::PENDING, BranchSupplyRequest::APPROVED,
                $self ? __('اعتمده صاحب النشاط بنفسه بقرارٍ صريح') : null, ['transfers' => $numbers]);
        });

        $request->refresh();
    }

    public static function reject(BranchSupplyRequest $request, User $user, string $reason): void
    {
        self::decide($request, $user, 'reject', BranchSupplyRequest::REJECTED, $reason);
    }

    public static function cancel(BranchSupplyRequest $request, User $user, string $reason): void
    {
        self::decide($request, $user, 'cancel', BranchSupplyRequest::CANCELLED, $reason);
    }

    private static function decide(BranchSupplyRequest $request, User $user, string $step, string $status, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new TransferRefused(__('اكتب السبب — ثلاثة أحرف على الأقل.'));
        }

        DB::transaction(function () use ($request, $user, $step, $status, $reason) {
            $locked = BranchSupplyRequest::whereKey($request->id)->where('business_id', $user->business_id)
                ->lockForUpdate()->first();
            if (! $locked) {
                throw new TransferRefused(__('هذا الطلب ليس من نشاطك.'));
            }

            $why = self::refusal($user, $step, $locked);
            if ($why !== null) {
                throw new TransferRefused($why);
            }

            $locked->update([
                'status' => $status,
                'decided_by' => $user->id,
                'decided_by_name' => $user->name,
                'decided_at' => now(),
                'decision_reason' => mb_substr($reason, 0, 500),
            ]);

            self::log($locked, $user, $step === 'reject' ? 'rejected' : 'cancelled', BranchSupplyRequest::PENDING, $status, $reason);
        });

        $request->refresh();
    }

    /**
     * أرصدةُ الصنف في كلّ فرع — يقرؤها المعتمِدُ ليختار من يزوّد.
     *
     * @return array<int, array<int, int>> [معرّف المنتج][معرّف الفرع] => الكميّة
     */
    public static function balances(int $businessId, array $productIds): array
    {
        $books = BranchStock::books($businessId);

        return collect($productIds)->unique()->mapWithKeys(fn ($pid) => [(int) $pid => $books[(int) $pid] ?? []])->all();
    }

    public static function nextNumber(int $businessId): string
    {
        $last = BranchSupplyRequest::where('business_id', $businessId)->orderByDesc('id')->value('number');
        $n = $last ? ((int) substr($last, 4)) + 1 : 1;

        return 'SUP-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    private static function log(BranchSupplyRequest $request, User $user, string $action, ?string $from, ?string $to, ?string $note = null, ?array $data = null): void
    {
        BranchOperationEvent::create([
            'business_id' => $request->business_id,
            'subject_type' => BranchOperationEvent::SUPPLY,
            'subject_id' => $request->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'note' => $note,
            'data' => $data,
            'created_at' => now(),
        ]);
    }

    /** طلباتٌ مفتوحة: بانتظار المراجعة، أو معتمدةٌ ما زال سندٌ منها لم ينتهِ */
    public static function openCount(int $businessId, ?int $branchId = null): int
    {
        $base = BranchSupplyRequest::where('business_id', $businessId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $pending = (clone $base)->where('status', BranchSupplyRequest::PENDING)->count();
        $running = (clone $base)->where('status', BranchSupplyRequest::APPROVED)
            ->whereHas('transfers', fn ($q) => $q->whereNotIn('status', BranchTransfer::FINAL))->count();

        return $pending + $running;
    }
}
