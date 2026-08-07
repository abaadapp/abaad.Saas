<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Product;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;

/**
 * المحذوفات — الزرّ الذي يردّ ما أذهبته ضغطة.
 *
 * الحذف الناعم وحده لا يكفي: صفٌّ باقٍ في القاعدة لا يراه أحد إلا من يملك
 * وصولًا إليها، فيصير الاسترداد مكالمةً مع الدعم لا فعلًا يفعله صاحب المتجر.
 * هذه الشاشة هي ما يجعل «الحذف قابل للتراجع» جملةً صحيحة.
 */
class TrashController extends Controller
{
    /** كم يومًا تُعرض المحذوفات — بعدها تبقى في القاعدة ولا تُزحم الشاشة */
    private const WINDOW_DAYS = 90;

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(): \Inertia\Response
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $products = Product::onlyTrashed()
            ->where('business_id', $this->bid())
            ->where('deleted_at', '>=', $since)
            ->orderByDesc('deleted_at')
            ->get(['id', 'name', 'sku', 'price', 'quantity', 'deleted_at'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'price' => (float) $p->price,
                'quantity' => (int) $p->quantity,
                'deletedAt' => $p->deleted_at?->format('Y-m-d H:i'),
            ]);

        $expenses = Expense::onlyTrashed()
            ->where('business_id', $this->bid())
            ->where('deleted_at', '>=', $since)
            ->orderByDesc('deleted_at')
            ->get(['id', 'reference', 'type', 'description', 'amount', 'spent_at', 'deleted_at'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'reference' => $e->reference,
                'title' => $e->description ?: $e->type,
                'amount' => (float) $e->amount,
                'spentAt' => $e->spent_at?->format('Y-m-d'),
                'deletedAt' => $e->deleted_at?->format('Y-m-d H:i'),
            ]);

        /*
         * الفروع لا تُعرض هنا.
         *
         * حذفها نادرٌ في عمر المتجر، فيبقى القسم فارغًا دائمًا تقريبًا —
         * وثلاثة جداول فارغة تجعل الشاشة تُقرأ «لا شيء هنا» فلا تُفتح أصلًا.
         * والحماية باقية: الفرع يُخفى ولا يُمحى، ويُردّ من زرّ «تراجع» في
         * إشعار الحذف (مسار admin.branches.restore).
         */
        return \Inertia\Inertia::render('Admin/Settings/Trash', [
            'products' => $products,
            'expenses' => $expenses,
            'windowDays' => self::WINDOW_DAYS,
        ]);
    }

    /**
     * يردّ صفًّا مخفيًّا.
     *
     * النوع لا يصل من المتصفّح: كل مسارٍ يحمله في `defaults` — فيقع المسار
     * داخل مجموعة قسمه، ويرث حارسه. من يملك حذف المنتجات يردّها، ومن يملك
     * الفروع يردّ الفرع. ولو جاء النوع من الطلب لصار مسارًا واحدًا يحرسه
     * قسمٌ واحد، ولوجب أن يكون «الإعدادات» — فيرى مَن حذف زرَّ «تراجع» ويُردّ.
     */
    public function restore(Request $request, int $id)
    {
        $type = (string) $request->route()->defaults['type'];

        $model = match ($type) {
            'product' => Product::class,
            'expense' => Expense::class,
            'branch' => Branch::class,
            default => abort(404),
        };

        // المتجر يُقرأ من الجلسة لا من الطلب: معرّفٌ من متجر الجار يُردّ ٤٠٤
        $row = $model::onlyTrashed()->where('business_id', $this->bid())->findOrFail($id);
        $row->restore();

        $label = match ($type) {
            'expense' => $row->reference ?: $row->description ?: $row->type,
            default => $row->name,
        };

        Activity::log('restore', match ($type) {
            'product' => 'استعاد المنتج: ',
            'expense' => 'استعاد المصروف: ',
            'branch' => 'استعاد الفرع: ',
        }.$label, ['subject_id' => $row->id]);

        return back()->with('toast', [
            'msg' => __('تمت الاستعادة: :name', ['name' => $label]),
            'type' => 'success',
        ]);
    }
}
