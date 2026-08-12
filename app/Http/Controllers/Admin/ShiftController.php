<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Support\Demo;
use App\Support\Shifts;
use Inertia\Inertia;
use Inertia\Response;

/**
 * مراجعة الورديات: من أقفل، وكم توقّع، وكم وجد، والفرق بينهما.
 *
 * هنا تُقرأ ثمرة الإقفال. الكاشير يُدخل ما عدّه ولا يرى الفرق — وصاحب النشاط
 * يراه هنا منسوبًا إلى وردية بعينها وإلى من أقفلها.
 */
class ShiftController extends Controller
{
    public function index(): Response
    {
        $shifts = Shift::where('business_id', Demo::bid())
            ->with(['openedBy:id,name', 'closedBy:id,name'])
            ->latest('opened_at')
            ->limit(100)
            ->get();

        $branches = \App\Models\Branch::where('business_id', Demo::bid())->pluck('name', 'id');
        $open = Shifts::current();

        return Inertia::render('Admin/Shifts', [
            'shifts' => $shifts->map(fn ($s) => [
                'id' => $s->id,
                'branch' => $branches[$s->branch_id] ?? '—',
                'opened_at' => $s->opened_at?->format('Y-m-d H:i'),
                'closed_at' => $s->closed_at?->format('Y-m-d H:i'),
                'opened_by' => $s->employee_name ?: $s->openedBy?->name ?? '—',
                'closed_by' => $s->closedBy?->name ?? '—',
                'opening' => (float) $s->opening_balance,
                'cash' => (float) $s->cash_sales,
                // الوردية المفتوحة لم تُقفل بعد: المتوقّع يُحسب حيًّا ولا فرق لها
                'expected' => $s->isOpen() ? Shifts::expectedCash($s) : (float) $s->expected_balance,
                // فارغٌ يعني «لم يُعدّ» — للمفتوحة وللمُقفلة بلا عدّ معًا
                'counted' => $s->actual_balance === null ? null : (float) $s->actual_balance,
                'difference' => $s->difference === null ? null : (float) $s->difference,
                'note' => $s->note,
                'status' => $s->status,
                'closedKind' => $s->closed_kind,
                // وردية طال فتحُها: تُعرَض لصاحبها ليُقفلها قبل أن تبتلع يومًا آخر
                'stale' => $s->isStale(Shifts::maxHours()),
            ])->all(),
            'openShiftId' => $open?->id,
            'maxHours' => Shifts::maxHours(),
        ]);
    }

    /**
     * إقفال وردية بيد صاحب النشاط.
     *
     * الكاشير ذهب ولم يُقفل، والدرج لا يُعدّ بأثرٍ رجعيّ. فتُقفل بلا عدّ:
     * تُوقَف عن ابتلاع مبيعات الغد، **وفرقُها يبقى مجهولًا** — لا يُنتحل له
     * صفرٌ يقول «طابق» عن درجٍ لم يفتحه أحد.
     */
    public function close(\Illuminate\Http\Request $request, $id)
    {
        $shift = Shift::where('business_id', Demo::bid())->findOrFail($id);

        if (! $shift->isOpen()) {
            return back()->with('toast', ['msg' => __('الوردية مُقفلة أصلًا'), 'type' => 'warning']);
        }

        $data = $request->validate([
            // السبب مطلوب: إقفالٌ بلا عدّ يترك فجوةً في الرقابة، ومن أحدثها يُسمّيها
            'note' => ['required', 'string', 'max:255'],
        ], [], ['note' => __('السبب')]);

        Shifts::closeWithoutCount($shift, Shift::BY_ADMIN, $data['note']);

        return back()->with('toast', [
            'msg' => __('أُقفلت الوردية بلا عدّ — الفرق يبقى مجهولًا'),
            'type' => 'warning',
        ]);
    }
}
