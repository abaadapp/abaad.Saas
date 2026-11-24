<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\BoutiqueInvoiceReview;
use App\Support\Boutiques;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * بابُ المراجعة — فواتيرُ عملاءٍ فيها صنفُ بوتيكٍ لم يدخل حسابَه. قراءةٌ وحدها.
 *
 * حارسُه ثلاث: المتجرُ يؤوي بوتيكات، والمنتجاتُ على المسار، ثمّ «المصروفات» —
 * فمن مُنح المنتجاتِ ليُدخل أصنافَ بوتيك لا يقرأ فواتيرَ المتجر.
 */
class BoutiqueInvoiceReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = Business::findOrFail((int) (auth()->user()->business_id ?? Demo::bid()));
        Boutiques::enforce($business);
        abort_unless((bool) auth()->user()?->allows('expenses'), 403);

        $f = BoutiqueInvoiceReview::filters($request, (int) $business->id);
        $summary = BoutiqueInvoiceReview::summary((int) $business->id, $f);
        $canOpen = (bool) auth()->user()?->allows('orders');
        // ولا صفوفَ حين لا شيء، ولا حين يُسأل عن العدد وحده
        $page = $summary['lines'] === 0 || $request->boolean('summary') ? null : BoutiqueInvoiceReview::rows((int) $business->id, $f);

        return response()->json([
            'summary' => $summary,
            'rows' => collect($page?->items() ?? [])->map(fn (array $r) => $r + [
                // وفتحُ الفاتورة بصلاحيّة قسمها
                'url' => $canOpen ? route('admin.customerInvoices.show', $r['invoice_id']) : null,
            ])->all(),
            'page' => $page?->currentPage() ?? 1,
            'last_page' => $page?->lastPage() ?? 1,
            'total' => $page?->total() ?? 0,
        ]);
    }
}
