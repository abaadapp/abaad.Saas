<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchSupplyRequest;
use App\Models\BranchTransfer;
use App\Models\User;

/**
 * تنبيهاتُ الفروع المركزيّة — للمؤسسات، في جرس اللوحة نفسِه.
 *
 * ═══ ولا نظامَ تنبيهاتٍ ثانٍ ═══
 *
 * التنبيهُ يُبنى من مصدره في كلّ طلب (`Demo::buildNotifications`)، ومفتاحُه
 * هويّتُه: لا يتكرّر ما دام سببُه واحدًا، ويُغلق وحده حين يزول سببُه
 * (`Notifications::closeWhatIsGone`) — سندٌ اعتُمد يختفي تنبيهُ اعتماده، وفرقٌ
 * سُوّي يختفي تنبيهُه. والقراءةُ والتأجيلُ والإنجازُ من `notification_states`.
 *
 * ولا رسالةَ تخرج من هنا: لا واتساب ولا بريد. التنبيهُ داخل اللوحة وحدها.
 *
 * ═══ ولكلّ موظّفٍ ما يخصّه ═══
 *
 * يُعرض التنبيهُ لمن يملك الفعلَ الذي يُنجزه (`action` يفحصه الجرس) وفي فرعٍ
 * مسندٍ إليه (`BranchTransfers::covers`) — لا كلُّ سندٍ في النشاط لكلّ من يفتح
 * «المخزون».
 */
final class BranchAlerts
{
    /** كم سندًا بفرقٍ في ٣٠ يومًا يجعل استلامَ فرعٍ «مشكلةً متكرّرة» */
    public const REPEATED = 2;

    /**
     * @return array<string, array<string, mixed>> [المفتاح => التنبيه]
     */
    public static function for(User $user, int $limit): array
    {
        $bid = (int) $user->business_id;
        $out = [];

        // طلباتُ التزويد بانتظار الموافقة
        foreach (BranchSupplyRequest::where('business_id', $bid)->where('status', BranchSupplyRequest::PENDING)
            ->orderBy('id')->limit($limit)->get() as $r) {
            $out['branch-supply-'.$r->id] = [
                'text' => __('طلب تزويد ينتظر الموافقة: :n — :b', ['n' => $r->number, 'b' => $r->branch_name]),
                'time' => optional($r->created_at)->format('Y-m-d'),
                'section' => 'inventory',
                'action' => Permissions::TRANSFER_APPROVE,
                'icon' => 'package-plus', 'color' => 'warning',
                'url' => route('admin.inventory.supply.show', $r->id),
            ];
        }

        $open = BranchTransfer::where('business_id', $bid)->whereIn('status', BranchTransfer::OPEN)
            ->orderBy('id')->limit($limit * 4)->get();

        foreach ($open as $t) {
            [$key, $item] = match ($t->status) {
                BranchTransfer::PENDING => ['branch-approve-', [
                    'text' => __('طلب نقل ينتظر الموافقة: :n (:from ← :to)', self::names($t)),
                    'action' => Permissions::TRANSFER_APPROVE, 'branch' => $t->from_branch_id,
                    'icon' => 'clipboard-check', 'color' => 'warning',
                ]],
                BranchTransfer::APPROVED, BranchTransfer::PREPARING => ['branch-dispatch-', [
                    'text' => __('سند نقل ينتظر الإرسال: :n (:from ← :to)', self::names($t)),
                    'action' => Permissions::TRANSFER_DISPATCH, 'branch' => $t->from_branch_id,
                    'icon' => 'truck', 'color' => 'info',
                ]],
                BranchTransfer::IN_TRANSIT => $t->dispatched_at && $t->dispatched_at->lt(now()->subDays(BranchReports::LATE_DAYS))
                    ? ['branch-late-', [
                        'text' => __('سند مرسل لم يُؤكَّد استلامه منذ :d يوم: :n (:from ← :to)', self::names($t) + ['d' => (int) $t->dispatched_at->diffInDays(now())]),
                        'action' => Permissions::TRANSFER_RECEIVE, 'branch' => $t->to_branch_id,
                        'icon' => 'clock', 'color' => 'danger',
                    ]]
                    : [null, null],
                BranchTransfer::DISCREPANCY => ['branch-gap-', [
                    'text' => __('فرق بين المرسل والمستلم بانتظار المعالجة: :n (:from ← :to)', self::names($t)),
                    'action' => Permissions::TRANSFER_RESOLVE, 'branch' => null,
                    'icon' => 'alert-triangle', 'color' => 'danger',
                ]],
                default => [null, null],
            };

            if ($key === null) {
                continue;
            }

            $branch = $item['branch'];
            unset($item['branch']);
            if (! ($branch === null ? BranchTransfers::covers($user, $t->from_branch_id, $t->to_branch_id) : BranchTransfers::covers($user, $branch))) {
                continue;
            }

            $out[$key.$t->id] = $item + [
                'time' => optional($t->updated_at)->format('Y-m-d'),
                'section' => 'inventory',
                'url' => route('admin.inventory.transfers.show', $t->id),
            ];
        }

        // طلبُ نقلٍ رُفض — خبرٌ لصاحبه في أسبوعه
        foreach (BranchTransfer::where('business_id', $bid)->where('status', BranchTransfer::REJECTED)
            ->where('requested_by', $user->id)->where('closed_at', '>=', now()->subDays(7))
            ->orderByDesc('id')->limit($limit)->get() as $t) {
            $out['branch-rejected-'.$t->id] = [
                'text' => __('رُفض طلب النقل :n: :reason', ['n' => $t->number, 'reason' => (string) $t->decision_reason]),
                'time' => optional($t->closed_at)->format('Y-m-d'),
                'section' => 'inventory',
                'icon' => 'x-circle', 'color' => 'danger',
                'url' => route('admin.inventory.transfers.show', $t->id),
            ];
        }

        // فرعٌ يتكرّر فرقُ استلامه — مشكلةٌ تُقرأ لا سندٌ يُسوّى
        if ($user->may(Permissions::TRANSFER_RESOLVE)) {
            $gaps = BranchTransfer::where('business_id', $bid)->whereNotNull('received_at')
                ->where('received_at', '>=', now()->subDays(30))
                ->whereHas('lines', fn ($q) => $q->whereColumn('received_qty', '<', 'sent_qty'))
                ->get(['id', 'to_branch_id', 'to_branch_name'])->groupBy('to_branch_id');

            foreach ($gaps as $branchId => $rows) {
                if ($rows->count() >= self::REPEATED && BranchTransfers::covers($user, (int) $branchId)) {
                    $out['branch-repeat-'.$branchId] = [
                        'text' => __('فروقات متكررة في استلام :b: :n سندات خلال 30 يومًا', ['b' => $rows->first()->to_branch_name, 'n' => $rows->count()]),
                        'time' => __('تنبيه فروع'),
                        'section' => 'inventory',
                        'icon' => 'alert-octagon', 'color' => 'danger',
                        'url' => route('admin.inventory.transfers', ['branch' => $branchId]),
                    ];
                }
            }
        }

        // ونقصُ الصنف في فرعٍ بعينه — والإجماليُّ له تنبيهه (`low-`)
        $names = Branch::where('business_id', $bid)->pluck('name', 'id');
        // وفي متجرٍ بفرعٍ واحد نقصُ الفرع هو نقصُ الإجماليّ: لا يُنبَّه عليه مرّتين
        if ($names->count() < 2) {
            return $out;
        }
        $count = 0;
        foreach (BranchReports::stockByBranch($bid) as $branchId => $stock) {
            if (! BranchTransfers::covers($user, $branchId)) {
                continue;
            }
            foreach ($stock['low_items'] as $item) {
                if ($count >= $limit) {
                    break 2;
                }
                $out['branch-low-'.$branchId.'-'.$item['id']] = [
                    'text' => __('مخزون منخفض في :b: :name (:qty من :alert)', ['b' => $names[$branchId] ?? '—', 'name' => $item['name'], 'qty' => $item['quantity'], 'alert' => $item['alert']]),
                    'time' => __('تنبيه فروع'),
                    'section' => 'inventory',
                    'icon' => 'alert-triangle', 'color' => $item['quantity'] <= 0 ? 'danger' : 'warning',
                    'url' => route('admin.inventory.index'),
                ];
                $count++;
            }
        }

        return $out;
    }

    /** @return array{n: string, from: string, to: string} */
    private static function names(BranchTransfer $t): array
    {
        return ['n' => $t->number, 'from' => $t->from_branch_name, 'to' => $t->to_branch_name];
    }
}
