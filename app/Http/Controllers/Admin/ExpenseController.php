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

        return \Inertia\Inertia::render('Admin/Expenses/Index', [
            'expenses' => collect($expenses->items())->map(fn ($e) => [
                'id' => $e->id,
                'reference' => $e->reference,
                'due_date' => optional($e->due_date)->format('Y-m-d'),
                'type' => $e->type,
                'amount' => (float) $e->amount,
                'status' => $e->status,
                // رابط المرفق يُبنى هنا؛ المسار وحده لا يفتحه المتصفح
                'attachment' => $e->attachment ? \Illuminate\Support\Facades\Storage::url($e->attachment) : null,
                'attachment_name' => $e->attachment_name,
                'description' => $e->description,
            ])->all(),
            'pagination' => \App\Support\Pagination::meta($expenses),
            'types' => Demo::expenseTypes(),
            'filters' => $request->only('q', 'type', 'status', 'tab'),
            'totalAmount' => (float) Expense::where('business_id', $bid)->sum('amount'),
            'totalCount' => Expense::where('business_id', $bid)->count(),
            'today' => now()->format('Y-m-d'),
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
            'attachment.extensions' => __('الصيغ المدعومة: JPG، PNG، PDF، WEBP، HEIC.'),
            'attachment.max' => __('أقصى حجم للمرفق 10 ميجابايت.'),
        ], ['attachment' => __('المرفق')]);

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

        /*
         * المصروف يظهر في دفتر المالية أيضًا.
         *
         * كان لكلّ منهما جدولُه: مصروفٌ من هذه الشاشة لا يُرى في المالية،
         * ومصروفٌ من المالية لا ينقص الربح. فصار المصدر واحدًا والدفتر
         * يعرضهما معًا — والربح يُقرأ من جدول المصروفات كما كان، فلا يُعدّ
         * المبلغ مرّتين.
         */
        $transaction = \App\Models\Transaction::create([
            'business_id' => $bid,
            'reference' => \App\Models\Transaction::nextReference($bid),
            // الوصف اختياريّ فقد يغيب عن الطلب أصلًا — لا يكفي أن يكون nullable
            'description' => $data['type'] . (($data['description'] ?? '') !== '' ? ' — ' . $data['description'] : ''),
            'method' => $data['method'],
            'type' => 'مصروف',
            'amount' => $data['amount'],
            'employee_name' => $data['employee_name'],
            'occurred_at' => $data['spent_at'],
        ]);
        $data['transaction_id'] = $transaction->id;

        Expense::create($data);
        \App\Support\Activity::log('created', 'سجّل مصروف ' . $data['type'] . ' بقيمة ' . $data['amount']);

        return redirect()->route('admin.expenses.index')->with('toast', ['msg' => __('تم تسجيل المصروف بنجاح'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $expense = Expense::where('business_id', $this->bid())->findOrFail($id);
        \App\Support\Activity::log('deleted', 'حذف المصروف: ' . $expense->reference, ['subject_id' => $expense->id, 'subject_type' => 'expense']);

        /*
         * المرفق يبقى مع المصروف المحذوف.
         *
         * صار الحذف ناعمًا يُستدرَك من «المحذوفات»، ومصروفٌ يعود بلا فاتورته
         * نصفُ استعادة: القيد يظهر في التقرير ولا شيء يُقدَّم للمحاسب.
         * والملفات تُنظَّف مع المسح النهائي لا مع الإخفاء.
         */
        $expense->delete();

        return back()->with('toast', [
            'msg' => __('تم حذف المصروف'),
            'type' => 'warning',
            'undo' => ['url' => route('admin.expenses.restore', $expense->id), 'label' => $expense->reference ?: $expense->type],
        ]);
    }

    /** توليد الرقم المرجعي التالي للنشاط */
    private function nextReference(int $bid): string
    {
        $last = Expense::where('business_id', $bid)->whereNotNull('reference')->orderByDesc('id')->value('reference');
        $n = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int) $m[1] + 1) : 1001;

        return 'EXP-' . $n;
    }
}
