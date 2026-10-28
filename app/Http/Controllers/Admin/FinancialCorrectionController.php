<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinancialCorrection;
use App\Support\Demo;
use App\Support\FinancialCorrections;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * طلباتُ التصحيح الماليّ بانتظار شخصٍ ثانٍ — قائمتُها والبتُّ فيها.
 *
 * المنطقُ كلُّه في `FinancialCorrections`، والمتحكّمُ يقرأ ويحوّل الرفضَ
 * رسالةً: ٤٠٣ لما لا يملكه الفاعل، ورسالةٌ على الشاشة لما يمنع الطلبَ نفسَه.
 */
class FinancialCorrectionController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function find(int|string $id): FinancialCorrection
    {
        return FinancialCorrection::where('business_id', $this->bid())->whereKey($id)->firstOrFail();
    }

    /** من يقرأ الطلبات: من يعتمدها، ومن يقرأ سجلّ التعديلات، ومن قدّم طلبًا */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $readsAll = $user->may(Permissions::FINANCIAL_CORRECTION_APPROVE) || $user->may(Permissions::CUSTOMER_INVOICE_HISTORY);

        $rows = FinancialCorrection::where('business_id', $this->bid())
            ->when(! $readsAll, fn ($q) => $q->where('requested_by', $user->id))
            ->with(['invoice:id,number', 'requester:id,name', 'decider:id,name', 'revision:id,revision'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('id')
            ->limit(200)
            ->get();

        return Inertia::render('Admin/CustomerInvoices/Corrections', [
            'corrections' => $rows->map(fn (FinancialCorrection $c) => FinancialCorrections::present($c, $user))->values(),
            'enabled' => FinancialCorrections::required($this->bid()),
            'mayApprove' => $user->may(Permissions::FINANCIAL_CORRECTION_APPROVE),
        ]);
    }

    public function approve(Request $request, int|string $correction)
    {
        // من لا يعتمد يُردّ قبل أن يُسأل عن الطلب — فلا يعرف أيّ الأرقام موجود
        abort_unless($request->user()->may(Permissions::FINANCIAL_CORRECTION_APPROVE), 403);
        $row = $this->find($correction);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return $this->decide(fn () => FinancialCorrections::approve($row, $request->user(), $data['note'] ?? null),
            __('اعتُمد التصحيح ونُفّذ على الفاتورة.'), $row);
    }

    public function reject(Request $request, int|string $correction)
    {
        abort_unless($request->user()->may(Permissions::FINANCIAL_CORRECTION_APPROVE), 403);
        $row = $this->find($correction);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [
            'reason.required' => __('اكتب سبب رفض التصحيح — يراه مقدّم الطلب.'),
        ]);

        return $this->decide(fn () => FinancialCorrections::reject($row, $request->user(), $data['reason']),
            __('رُفض طلب التصحيح، ولم تتغيّر الفاتورة.'), $row);
    }

    public function withdraw(Request $request, int|string $correction)
    {
        // ولا يطلب تصحيحًا — فلا يسحبه — إلّا من يعدّل فاتورةً صادرة أو مدفوعة
        abort_unless(
            $request->user()->may(Permissions::CUSTOMER_INVOICE_EDIT_ISSUED) || $request->user()->may(Permissions::CUSTOMER_INVOICE_EDIT_PAID),
            403,
        );
        $row = $this->find($correction);

        return $this->decide(fn () => FinancialCorrections::withdraw($row, $request->user()),
            __('سُحب طلب التصحيح، ولم تتغيّر الفاتورة.'), $row);
    }

    private function decide(callable $act, string $done, FinancialCorrection $row)
    {
        try {
            $act();
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (ValidationException $e) {
            $why = collect($e->errors())->flatten()->first();

            return back()->withErrors(['correction' => $why])->with('toast', ['msg' => $why, 'type' => 'danger']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['correction' => $e->getMessage()])->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        return redirect()->route('admin.customerInvoices.show', $row->customer_invoice_id)
            ->with('toast', ['msg' => $done, 'type' => 'success']);
    }
}
