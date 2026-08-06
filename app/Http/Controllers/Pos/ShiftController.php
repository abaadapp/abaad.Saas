<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Support\Demo;
use App\Support\Shifts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    /**
     * شاشة الوردية: فتحٌ إن لم تكن مفتوحة، وإقفالٌ إن كانت.
     *
     * المبالغ لا تصل إلا لمن يملك صلاحية «المالية». والعدّ أعمى عن قصد: من
     * يرى الرقم المتوقّع قبل العدّ يميل — بلا قصدٍ غالبًا — إلى كتابته بدل
     * ما عدّه، فيختفي النقص ولا يُكتشف أبدًا. وهذا يُبطل الغرض من الإقفال.
     */
    public function show(): Response
    {
        $shift = Shifts::current();
        $showsAmounts = \App\Support\ReceiptVisibility::showsAmounts();

        $payload = null;
        if ($shift) {
            $totals = Shifts::totals($shift);
            $payload = [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at?->format('Y-m-d H:i'),
                'opened_by' => $shift->employee_name ?: $shift->openedBy?->name,
                'count' => $totals['count'],
                // المبالغ لمن يملك «المالية» وحده — العدّ يبقى أعمى لغيره
                'opening_balance' => $showsAmounts ? (float) $shift->opening_balance : null,
                'byMethod' => $showsAmounts ? $totals['byMethod'] : null,
                'sales' => $showsAmounts ? $totals['sales'] : null,
                'expected' => $showsAmounts ? Shifts::expectedCash($shift) : null,
                'movements' => \App\Models\ShiftMovement::where('shift_id', $shift->id)
                    ->latest('id')->limit(20)->get()
                    ->map(fn ($m) => [
                        'id' => $m->id,
                        'type' => $m->type,
                        // المبلغ لمن يرى المبالغ؛ والسبب ومَن سجّله يبقيان
                        // ظاهرين — الكاشير يحتاج أن يرى أنّ سحبه سُجّل
                        'amount' => $showsAmounts ? $m->amount : null,
                        'reason' => $m->reason,
                        'by' => $m->employee_name,
                        'at' => $m->created_at?->format('H:i'),
                    ])->all(),
            ];
        }

        return Inertia::render('Pos/Shift', [
            'shift' => $payload,
            'showsAmounts' => $showsAmounts,
            'branchName' => Demo::currentBranchName(),
            'lastClosed' => \App\Models\Shift::where('business_id', Demo::bid())
                ->where('branch_id', Demo::activeBranchId())
                ->where('status', \App\Models\Shift::CLOSED)
                ->latest('closed_at')->first()?->only(['closed_at', 'actual_balance']),
        ]);
    }

    public function open(Request $request)
    {
        $data = $request->validate([
            'opening_balance' => ['required', 'numeric', 'min:0'],
        ], [], ['opening_balance' => __('الرصيد الابتدائي')]);

        if (Shifts::isOpen()) {
            return back()->with('toast', ['msg' => __('الوردية مفتوحة بالفعل'), 'type' => 'info']);
        }

        $shift = Shifts::open((float) $data['opening_balance']);
        \App\Support\Activity::log('shift', 'فتح وردية الصندوق', ['subject_id' => $shift->id]);

        return redirect()->route('pos.index')->with('toast', ['msg' => __('فُتحت الوردية'), 'type' => 'success']);
    }

    /**
     * سحبٌ من الدرج أو إيداعٌ فيه.
     *
     * السبب إلزامي: مبلغٌ بلا سبب لا يُراجَع ولا يُسأل عنه أحد، فيصير الباب
     * الذي يُخرَج منه النقد بلا أثر.
     */
    public function move(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:in,out'],
            'amount' => ['required', 'numeric', 'min:0.001'],
            'reason' => ['required', 'string', 'max:255'],
        ], [], [
            'amount' => __('المبلغ'),
            'reason' => __('السبب'),
        ]);

        $shift = Shifts::current();
        if (! $shift) {
            return back()->withErrors(['amount' => __('لا توجد وردية مفتوحة.')]);
        }

        /*
         * لا يُسحب من الدرج أكثر ممّا فيه.
         *
         * المتوقّع السالب لا معنى له: لا أحد يُخرج خمسين من درجٍ فيه اثنان.
         * وقبولُه يُفسد الفرق ويجعل الإقفال يقرأ رقمًا مستحيلًا — ويفتح بابًا
         * لتغطية نقصٍ بسحبٍ وهمي.
         */
        $amount = (float) $data['amount'];
        if ($data['type'] === \App\Models\ShiftMovement::OUT) {
            $available = Shifts::expectedCash($shift);
            if ($amount > $available) {
                return back()->withErrors([
                    'amount' => __('لا يمكن سحب أكثر ممّا في الدرج (:n).', [
                        'n' => number_format($available, 3),
                    ]),
                ]);
            }
        }

        Shifts::move($shift, $data['type'], $amount, $data['reason']);

        return back()->with('toast', [
            'msg' => $data['type'] === 'out' ? __('سُجّل السحب') : __('سُجّل الإيداع'),
            'type' => 'success',
        ]);
    }

    public function close(Request $request)
    {
        $data = $request->validate([
            'counted' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['counted' => __('المبلغ المعدود')]);

        $shift = Shifts::current();
        if (! $shift) {
            return back()->withErrors(['counted' => __('لا توجد وردية مفتوحة.')]);
        }

        $closed = Shifts::close($shift, (float) $data['counted'], $data['note'] ?? null);

        // الفرق لا يُعرض إلا لمن يرى المبالغ — وإلا كشفه رسالةُ النجاح
        $msg = \App\Support\ReceiptVisibility::showsAmounts()
            ? __('أُقفلت الوردية · الفرق: :n', ['n' => number_format((float) $closed->difference, 3)])
            : __('أُقفلت الوردية');

        return redirect()->route('pos.shift')->with('toast', ['msg' => $msg, 'type' => 'success']);
    }
}
