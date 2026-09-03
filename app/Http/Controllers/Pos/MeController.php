<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PayrollLine;
use App\Support\Demo;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «حسابي» — ما يخصّ الموظّف وحده.
 *
 * الكاشير لا يدخل لوحة النشاط، فكان زرُّ «لوحة النشاط» يغيب عنه ولا بديل:
 * لا يعرف راتبه، ولا كم باع، ولا متى التحق، ولا كيف يبدّل كلمة مروره. وسؤالُ
 * صاحب المحلّ عن راتبه في آخر الشهر ليس ميزةً في نظام.
 *
 * والحدُّ هنا صارم: **كلُّ رقمٍ في هذه الصفحة رقمُ صاحبها**. لا مبيعات
 * زملائه، ولا حصيلة المحلّ، ولا رواتب غيره. والاستعلامات كلّها مقيّدةٌ
 * بـ`auth()->id()` وبمتجره — لا بمعرّفٍ يأتي من الرابط، فلا يُبدَّل رقمٌ في
 * العنوان فتُقرأ صفحةُ زميل.
 */
class MeController extends Controller
{
    /** كم مسيرةً يراها — سنةٌ تكفي لمراجعة راتبٍ، وما قبلها يُطلب من المحاسب */
    private const PAYSLIPS = 12;

    public function show(): Response
    {
        $user = auth()->user();
        $bid = Demo::bid();

        return Inertia::render('Pos/Me', [
            'me' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'jobTitle' => $user->job_title ?: $user->roleLabel(),
                'roleLabel' => $user->roleLabel(),
                'branch' => $user->branch,
                'status' => $user->status,
                'joined' => optional($user->created_at)->format('Y-m-d'),
            ],
            /*
             * الراتب كما هو مسجَّل في ملفّه — لا كما يتذكّره.
             *
             * والمسيرات تُقرأ من سطوره هو: `PayrollLine` مقيَّدةٌ بـuser_id،
             * ومسيرتُها تُقرأ للتاريخ والحالة لا لإجماليّات المحلّ.
             */
            'salary' => [
                'basic' => (float) $user->basic_salary,
                'allowances' => (float) $user->allowances,
                'monthly' => (float) $user->basic_salary + (float) $user->allowances,
            ],
            'payslips' => $this->payslips((int) $user->id, $bid),
            'sales' => $this->sales((int) $user->id, $bid),
        ]);
    }

    /** مسيرات راتبه — والمسودّة لا تُعرض: رقمٌ لم يُعتمد بعدُ يُقرأ وعدًا */
    private function payslips(int $userId, int $bid): array
    {
        return PayrollLine::where('user_id', $userId)
            ->whereHas('run', fn ($q) => $q->where('business_id', $bid)->where('status', '!=', 'مسودة'))
            ->with('run:id,period,status')
            ->latest('id')
            ->limit(self::PAYSLIPS)
            ->get()
            ->map(fn ($line) => [
                'id' => $line->id,
                'period' => $line->run?->period
                    ? Carbon::parse($line->run->period)->translatedFormat('F Y')
                    : '—',
                'basic' => (float) $line->basic,
                'allowances' => (float) $line->allowances,
                'overtime' => (float) $line->overtime,
                'deductions' => (float) $line->deductions,
                'net' => (float) $line->net,
                'paid' => (bool) $line->paid,
                'paidAt' => optional($line->paid_at)->format('Y-m-d'),
                'status' => $line->run?->status,
            ])->values()->all();
    }

    /** ما باعه هو — الطلبات المنسوبة إليه لا طلبات المحلّ */
    private function sales(int $userId, int $bid): array
    {
        $mine = fn () => Order::where('business_id', $bid)->where('user_id', $userId)->sold();

        return [
            'todayTotal' => round((float) $mine()->whereDate('ordered_at', today())->sum('total'), 3),
            'todayCount' => (int) $mine()->whereDate('ordered_at', today())->count(),
            'monthTotal' => round((float) $mine()
                ->whereBetween('ordered_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('total'), 3),
            'monthCount' => (int) $mine()
                ->whereBetween('ordered_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'allCount' => (int) $mine()->count(),
        ];
    }
}
