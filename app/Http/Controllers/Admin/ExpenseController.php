<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Support\Demo;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request)
    {
        $bid = $this->bid();
        $q = Expense::where('business_id', $bid);

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('reference', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%")
                ->orWhere('type', 'like', "%{$s}%"));
        }
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        $expenses = $q->orderByDesc('spent_at')->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 10))->withQueryString();

        return view('admin.expenses.index', [
            'expenses' => $expenses,
            'types' => Demo::expenseTypes(),
            'filters' => $request->only('q', 'type', 'status', 'tab'),
            'totalAmount' => (float) Expense::where('business_id', $bid)->sum('amount'),
            'totalCount' => Expense::where('business_id', $bid)->count(),
        ]);
    }

    public function store(Request $request)
    {
        $bid = $this->bid();
        $data = $request->validate([
            'type' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'method' => ['nullable', 'string', 'max:50'],
            'spent_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:50'],
            'attachment' => ['nullable', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf,webp,heic'],
        ], [
            'attachment.extensions' => 'الصيغ المدعومة: JPG، PNG، PDF، WEBP، HEIC.',
            'attachment.max' => 'أقصى حجم للمرفق 10 ميجابايت.',
        ], ['attachment' => 'المرفق']);

        // رفع المرفق (إن وُجد)
        $attachment = null;
        $attachmentName = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $attachment = $file->store("expenses/{$bid}", 'public');
        }

        $data['business_id'] = $bid;
        $data['method'] = $data['method'] ?? 'نقدي';
        $data['status'] = $data['status'] ?? 'مدفوع';
        $data['spent_at'] = $data['spent_at'] ?? now();
        $data['employee_name'] = auth()->user()->name;
        $data['reference'] = $this->nextReference($bid);
        $data['attachment'] = $attachment;
        $data['attachment_name'] = $attachmentName;

        Expense::create($data);
        \App\Support\Activity::log('created', 'سجّل مصروف ' . $data['type'] . ' بقيمة ' . $data['amount']);

        return redirect()->route('admin.expenses.index')->with('toast', ['msg' => 'تم تسجيل المصروف بنجاح', 'type' => 'success']);
    }

    public function destroy($id)
    {
        $expense = Expense::where('business_id', $this->bid())->findOrFail($id);
        \App\Support\Activity::log('deleted', 'حذف المصروف: ' . $expense->reference, ['subject_id' => $expense->id]);

        // حذف الملف المرفق مع المصروف
        if ($expense->attachment) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($expense->attachment);
        }
        $expense->delete();

        return back()->with('toast', ['msg' => 'تم حذف المصروف', 'type' => 'warning']);
    }

    /** توليد الرقم المرجعي التالي للنشاط */
    private function nextReference(int $bid): string
    {
        $last = Expense::where('business_id', $bid)->whereNotNull('reference')->orderByDesc('id')->value('reference');
        $n = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int) $m[1] + 1) : 1001;

        return 'EXP-' . $n;
    }
}
