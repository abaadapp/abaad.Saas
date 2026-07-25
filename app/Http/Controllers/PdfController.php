<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use App\Support\Demo;
use Illuminate\Http\Request;
use Mpdf\Mpdf;

class PdfController extends Controller
{
    /** فاتورة طلب (نقطة البيع / لوحة النشاط) */
    public function orderReceipt($id)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $order = Order::where('business_id', $bid)->where('number', $id)->with('items')->firstOrFail();

        $html = view('pdf.receipt', [
            'order' => $order,
            'qr' => \App\Support\EInvoice::forOrder($order, Demo::vatSettings(), Demo::business($bid)),
        ])->render();
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => [80, 200],
            'margin_left' => 4, 'margin_right' => 4, 'margin_top' => 6, 'margin_bottom' => 6,
            'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $mpdf->WriteHTML($html);

        return response($mpdf->Output('receipt-' . $order->number . '.pdf', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-' . $order->number . '.pdf"',
        ]);
    }

    /** تقرير المبيعات الشهري (لوحة النشاط) */
    public function salesReport()
    {
        $html = view('pdf.sales-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::currentBranchName(),
            'stats' => Demo::adminStats(),
            'salesSeries' => Demo::salesSeries(),
            'payments' => Demo::paymentMethods(),
            'topProducts' => Demo::topProducts(),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر تقرير المبيعات (PDF)');

        return $this->pdf($html, 'sales-report-' . now()->format('Y-m-d'));
    }

    /** تقرير أداء المنصة (سوبر أدمن) */
    public function financeReport()
    {
        $html = view('pdf.finance-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::currentBranchName(),
            'stats' => Demo::financeStats(),
            'payments' => Demo::paymentMethods(),
            'transactions' => Demo::transactions(),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر التقرير المالي (PDF)');

        return $this->pdf($html, 'finance-report-' . now()->format('Y-m-d'));
    }

    public function platformReport()
    {
        $businesses = Demo::flowerShops();
        usort($businesses, fn ($a, $b) => $b['sales'] <=> $a['sales']);

        $html = view('pdf.platform-report', [
            'stats' => Demo::superStats(),
            'revenueSeries' => Demo::revenueSeries(),
            'growthSeries' => Demo::businessesGrowthSeries(),
            'planDistribution' => Demo::planDistribution(),
            'topBusinesses' => array_slice($businesses, 0, 8),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر تقرير أداء المنصة (PDF)', ['business_id' => null]);

        return $this->pdf($html, 'platform-report-' . now()->format('Y-m-d'));
    }

    /** تقرير التحليلات المتقدمة (PDF) */
    public function analyticsReport()
    {
        $customers = Demo::topCustomers();

        $html = view('pdf.analytics-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'comparison' => Demo::periodComparison(),
            'topProducts' => Demo::topProducts(),
            'topCustomers' => $customers,
            'categorySales' => Demo::categorySales(),
            'byWeekday' => Demo::salesByWeekday(),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر تقرير التحليلات (PDF)');

        return $this->pdf($html, 'analytics-' . now()->format('Y-m-d'));
    }

    /** كشف حساب عميل (PDF) */
    public function customerStatement($id)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $customer = \App\Models\Customer::where('business_id', $bid)->findOrFail($id);

        $orders = Order::where('business_id', $bid)->where('customer_id', $customer->id)->where('is_held', false)
            ->orderBy('ordered_at')->get();
        $returns = collect();

        $totalSpent = (float) $orders->sum('total');
        $totalReturned = 0.0;

        $html = view('pdf.customer-statement', [
            'customer' => $customer,
            'orders' => $orders,
            'returns' => $returns,
            'totalSpent' => $totalSpent,
            'totalReturned' => $totalReturned,
            'net' => $totalSpent - $totalReturned,
            'business' => Demo::business($bid),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر كشف حساب العميل: ' . $customer->name, ['subject_id' => $customer->id]);

        return $this->pdf($html, 'statement-' . $customer->id . '-' . now()->format('Y-m-d'));
    }

    /** تقرير قائمة الطلبات (PDF) */
    public function ordersReport()
    {
        $orders = Demo::orders();
        $html = view('pdf.orders-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::currentBranchName(),
            'orders' => $orders,
            'total' => array_sum(array_map(fn ($o) => (float) $o['total'], $orders)),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر قائمة الطلبات (PDF)');

        return $this->pdf($html, 'orders-report-' . now()->format('Y-m-d'));
    }

    /** تقرير المنتجات (PDF) */
    public function productsReport()
    {
        $products = Demo::products();
        $html = view('pdf.products-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::currentBranchName(),
            'products' => $products,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر قائمة المنتجات (PDF)');

        return $this->pdf($html, 'products-report-' . now()->format('Y-m-d'));
    }

    /** تقرير جرد المخزون (PDF) */
    public function inventoryReport()
    {
        $inventory = Demo::inventory();
        $html = view('pdf.inventory-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::currentBranchName(),
            'inventory' => $inventory,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر جرد المخزون (PDF)');

        return $this->pdf($html, 'inventory-report-' . now()->format('Y-m-d'));
    }

    /** تقرير المصروفات (PDF) */
    public function expensesReport()
    {
        $expenses = Demo::expenses();
        $html = view('pdf.expenses-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::currentBranchName(),
            'expenses' => $expenses,
            'total' => array_sum(array_map(fn ($e) => (float) $e['amount'], $expenses)),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر المصروفات (PDF)');

        return $this->pdf($html, 'expenses-report-' . now()->format('Y-m-d'));
    }

    /** تقرير الشركات (PDF) — لوحة المنصة */
    public function businessesReport()
    {
        $businesses = Demo::businesses();
        $html = view('pdf.businesses-report', [
            'businesses' => $businesses,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر الشركات (PDF)');

        return $this->pdf($html, 'businesses-report-' . now()->format('Y-m-d'));
    }

    /** تقرير فواتير الاشتراكات (PDF) — لوحة المنصة */
    public function invoicesReport()
    {
        $invoices = Demo::invoices();
        $html = view('pdf.invoices-report', [
            'invoices' => $invoices,
            'total' => array_sum(array_map(fn ($i) => (float) $i['amount'], $invoices)),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر فواتير الاشتراكات (PDF)');

        return $this->pdf($html, 'invoices-report-' . now()->format('Y-m-d'));
    }

    public function vatReport(Request $request)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $period = $request->query('period', 'quarter');
        $report = Demo::vatReport($period);

        $html = view('pdf.vat-report', [
            'report' => $report,
            'vat' => Demo::vatSettings(),
            'business' => Demo::business($bid),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'صدّر تقرير ضريبة القيمة المضافة (' . $report['label'] . ')');

        return $this->pdf($html, 'vat-' . $period . '-' . now()->format('Y-m-d'));
    }

    public function taxInvoice($id)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $order = Order::where('business_id', $bid)->where('number', $id)->with('items')->firstOrFail();

        $vat = Demo::vatSettings();
        $business = Demo::business($bid);

        $html = view('pdf.tax-invoice', [
            'order' => $order,
            'vat' => $vat,
            'business' => $business,
            'qr' => \App\Support\EInvoice::forOrder($order, $vat, $business),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        \App\Support\Activity::log('report', 'أصدر فاتورة ضريبية للطلب: ' . $order->number, ['subject_id' => $order->id]);

        return $this->pdf($html, 'tax-invoice-' . $order->number);
    }

    /** مولّد A4 عربي/RTL */
    private function pdf(string $html, string $name)
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 14, 'margin_bottom' => 14,
            'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $mpdf->WriteHTML($html);

        return response($mpdf->Output($name . '.pdf', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '.pdf"',
        ]);
    }

    /** فاتورة اشتراك المنصة (سوبر أدمن) */
    public function platformInvoice($number)
    {
        $invoice = Invoice::where('number', $number)->with('business', 'plan')->firstOrFail();

        $html = view('pdf.invoice', ['invoice' => $invoice])->render();
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $mpdf->WriteHTML($html);

        return response($mpdf->Output('invoice-' . $invoice->number . '.pdf', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="invoice-' . $invoice->number . '.pdf"',
        ]);
    }
}
