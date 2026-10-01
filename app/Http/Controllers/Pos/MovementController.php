<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ExpenseType;
use App\Support\Activity;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\PosCashier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * قيدٌ مبسّط من نقطة البيع — «ماذا حدث؟» عند الدرج.
 *
 * ═══ وصفةٌ واحدة لبابين ═══
 *
 * الموظّفُ لا يختار مدينًا ولا دائنًا: يختار ما حدث (مصروفٌ نقديّ، دخلٌ
 * آخر، إيداعٌ أو سحبٌ للمالك، تحويلٌ بين الدرج والبنك)، والقيدُ يُبنى من
 * `Books::MOVEMENTS` نفسها التي تقرأ منها شاشةُ المالية. فالمصروفُ من هنا
 * يظهر في «المصروفات» وفي «صافي الربح» كما لو سُجّل من هناك، ولا وصفةَ
 * ثانية تفترق يومًا.
 *
 * ═══ وما يُبسَّط ═══
 *
 *   الجهةُ الدرجُ دائمًا   ← ما يُسجَّل عند الصندوق مالٌ في الصندوق
 *   التاريخُ الآن          ← قيدٌ بتاريخٍ قديم عملُ المحاسب لا الكاشير
 *   الفرعُ فرعُ البيع       ← ما يُقرأ منه فرعُ الفاتورة نفسُه
 *   والاسمُ اسمُ الواقف     ← `PosCashier` كما تُنسب البيعة
 *
 * ═══ والفعلُ يُسأل هنا ═══
 *
 * قسمُ «نقطة البيع» يفتح البيع. وإخراجُ مالٍ من الدرج فعلٌ آخر
 * (`pos.movement`): الزرُّ يغيب عمّن لا يملكه، والبابُ يردّه — الطلبُ قد
 * يصل من غير الزرّ.
 */
class MovementController extends Controller
{
    public function store(Request $request)
    {
        abort_unless($request->user()?->may(Permissions::POS_MOVEMENT), 403);

        $bid = Demo::bid();

        $data = $request->validate([
            'kind' => ['required', Rule::in(Books::manualKinds())],
            'amount' => ['required', 'numeric', 'min:0.001', 'max:99999999'],
            'description' => ['nullable', 'string', 'max:255'],
            // من أنواع مصروفات المتجر وحدها — القائمةُ نفسُها التي تعرضها النافذة
            'expense_type' => ['nullable', 'string', Rule::in(self::expenseTypes($bid))],
            // معرّف النافذة: ضغطتان على «حفظ» لا تُخرجان المالَ من الدرج مرّتين
            'client_uuid' => ['nullable', 'string', 'max:64'],
        ], [
            'kind.in' => __('اختر ما حدث.'),
            'amount.min' => __('المبلغ يجب أن يكون أكبر من صفر'),
        ]);

        // فرعُ البيع نفسُه — لا فرعٌ يُرسَل من الطلب
        $branchId = Branch::where('business_id', $bid)->find(Demo::activeBranchId())?->id;

        try {
            $transaction = Books::recordMovement(
                $bid,
                [
                    'kind' => $data['kind'],
                    'amount' => $data['amount'],
                    'side' => Books::CASH,
                    'description' => $data['description'] ?? null,
                    'expense_type' => $data['expense_type'] ?? null,
                    'client_uuid' => $data['client_uuid'] ?? null,
                    'branch_id' => $branchId,
                ],
                PosCashier::id(),
                PosCashier::name(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        Activity::log(
            'created',
            'سجّل من نقطة البيع حركة '.Books::label($data['kind']).' بقيمة '.$data['amount'],
            ['subject_id' => $transaction->id],
        );

        return back()->with('toast', ['msg' => __('سُجّل القيد ورُحّل إلى الدفتر'), 'type' => 'success']);
    }

    /**
     * ما تعرضه النافذة للموظّف — أو لا شيء إن لم يملك الفعل.
     *
     * `null` يُخفي الزرّ كلَّه: لا زرٌّ يُضغط فيُردّ بـ403.
     *
     * @return array{kinds: list<array<string, mixed>>, expenseTypes: list<string>}|null
     */
    public static function props(): ?array
    {
        if (! auth()->user()?->may(Permissions::POS_MOVEMENT)) {
            return null;
        }

        return [
            'kinds' => Books::movementOptions(),
            'expenseTypes' => self::expenseTypes(Demo::bid()),
        ];
    }

    /** أسماءُ أنواع المصروفات — بلا مجاميعها: النافذةُ تسأل عن الاسم وحده */
    private static function expenseTypes(int $bid): array
    {
        return ExpenseType::where('business_id', $bid)->orderBy('name')->pluck('name')->all();
    }
}
