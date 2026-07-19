<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use App\Support\Demo;
use Mpdf\Mpdf;

class PdfController extends Controller
{
    /** فاتورة طلب (نقطة البيع / لوحة النشاط) */
    public function orderReceipt($id)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $order = Order::where('business_id', $bid)->where('number', $id)->with('items')->firstOrFail();

        $html = view('pdf.receipt', ['order' => $order])->render();
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
        $orderNumbers = $orders->pluck('number');
        $returns = \App\Models\OrderReturn::where('business_id', $bid)->whereIn('order_number', $orderNumbers)
            ->orderBy('created_at')->get();

        $totalSpent = (float) $orders->sum('total');
        $totalReturned = (float) $returns->sum('amount');

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

    public function commissionStatement($id)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $data = Demo::employeeCommission($id);

        $html = view('pdf.commission-statement', array_merge($data, [
            'business' => Demo::business($bid),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]))->render();

        \App\Support\Activity::log('report', 'صدّر كشف عمولة الموظف: ' . $data['employee']->name, ['subject_id' => $data['employee']->id]);

        return $this->pdf($html, 'commission-' . $id . '-' . now()->format('Y-m'));
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
