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
