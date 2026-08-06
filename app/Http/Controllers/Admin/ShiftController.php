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
                'counted' => $s->isOpen() ? null : (float) $s->actual_balance,
                'difference' => $s->isOpen() ? null : (float) $s->difference,
                'note' => $s->note,
                'status' => $s->status,
            ])->all(),
            'openShiftId' => $open?->id,
        ]);
    }
}
