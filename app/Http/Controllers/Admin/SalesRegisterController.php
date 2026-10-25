<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Exports\Exports;
use App\Support\Exports\PdfRows;
use App\Support\Lists\SalesRegister;
use App\Support\Pagination;
use App\Support\Pdf;
use App\Support\SalesChannel;
use App\Support\TenantExportGuard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «جميع عمليات البيع» — تبويبٌ في «فواتير العملاء» يقرأ `SalesRegister`.
 *
 * ثلاثة أبوابٍ لسجلٍّ واحد: الكلّ، ومبيعات الصندوق، وطلبات الموقع. والمصدرُ
 * من الباب لا من الرابط وحده، فلا يقول تبويبُ «نقطة البيع» غيرَ ما يعرض.
 *
 * ولا يكتب شيئًا: لا فاتورة ولا طلب ولا قيد. والقسمُ قسمُ «فواتير العملاء»
 * نفسُه (`customerInvoices` ← `orders`)، فمن يجهّز وحده لا يفتحه.
 */
class SalesRegisterController extends Controller
{
    private const PER_PAGE = [20, 50, 100];

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function pos(Request $request): Response
    {
        return $this->render($request, SalesChannel::POS);
    }

    public function website(Request $request): Response
    {
        return $this->render($request, SalesChannel::WEBSITE);
    }

    private function render(Request $request, ?string $fixed): Response
    {
        $bid = (int) (auth()->user()->business_id ?? Demo::bid());
        $per = (int) $request->integer('per_page');
        $per = in_array($per, self::PER_PAGE, true) ? $per : self::PER_PAGE[0];
        $page = SalesRegister::page($request, $per, $fixed);

        return Inertia::render('Admin/CustomerInvoices/Sales', [
            'rows' => $page->items(),
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('q', 'seller', 'source', 'payment_state', 'prep', 'state', 'per_page'),
            'period' => SalesRegister::period($request)->screen($bid),
            'source' => $fixed,
            'totals' => SalesRegister::totals($request, $fixed),
            'sellers' => SalesRegister::sellers($bid),
            'paymentStates' => SalesRegister::PAYMENT_STATES,
            'prepStages' => SalesRegister::PREP_LABELS,
            /*
             * والفرعُ المختار يُقال فوق الجدول.
             *
             * من لا يقرأ الترويسة يظنّ مبيعاتِ الفرع الآخر ضاعت. وهكذا وصلت
             * الشكوى أوّلَ مرّة: كان فتحُ الصندوق يكتب فرعَ جهازه في جلسة
             * الإدارة (#165)، فتصير اللوحةُ على فرعٍ لم يختره أحد.
             */
            'branch' => Demo::currentBranchId() ? Demo::currentBranchName() : null,
            'mayPrepare' => (bool) auth()->user()?->allows('preparation'),
            /*
             * وبيعاتٌ قبل أن يُحفظ مصدرُ البيع (`orders.channel` منذ 2026-09-22
             * بلا ملءٍ لما قبله) لا يُعرف أكانت من الصندوق أم من الموقع. فلا
             * تُخمَّن هنا: يُقال عددُها في التبويب، وهي في «جميع عمليات البيع».
             */
            'unknownCount' => $fixed === null ? null : SalesRegister::filtered($request, SalesChannel::UNKNOWN)->count(),
        ]);
    }

    public function xlsx(Request $request)
    {
        Activity::log('report', 'صدّر سجلّ عمليات البيع (Excel)');

        return Exports::xlsx(SalesRegister::export($request));
    }

    public function csv(Request $request)
    {
        return Exports::csv(SalesRegister::export($request));
    }

    public function pdf(Request $request)
    {
        if ($refused = PdfRows::refuse(SalesRegister::filtered($request)->count())) {
            return $refused;
        }

        $html = view('pdf.sales-register', [
            'business' => TenantExportGuard::business(),
            'branch' => Demo::scopeName(true),
            'rows' => SalesRegister::rows($request),
            'totals' => SalesRegister::totals($request),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر سجلّ عمليات البيع (PDF)');

        return Pdf::a4($html, 'sales-register-'.now()->format('Y-m-d'));
    }
}
