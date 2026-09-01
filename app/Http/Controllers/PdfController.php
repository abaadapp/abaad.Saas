<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PosPeripheral;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\EInvoice;
use App\Support\OrderStatus;
use App\Support\PosTerminal;
use App\Support\ReceiptTemplate;
use App\Support\Reports;
use Mpdf\Mpdf;

class PdfController extends Controller
{
    /** فاتورة طلب (نقطة البيع / لوحة النشاط) */
    public function salesReport()
    {
        // الفترة تُورَث من الشاشة وتُطبع في الترويسة: ورقةٌ مطبوعة لا مبدّل
        // فوقها، فإن لم تقل فترتها قُرئت على أنها فترة قارئها
        // الورقة من حمولة الشاشة نفسها — انظر Support\Reports::salesReport
        $report = Reports::salesReport(request()->query('range'));
        $range = $report['range'];

        $html = view('pdf.sales-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(false),
            'stats' => Reports::summaryRows($report['summary']),
            'salesSeries' => $report['salesSeries'],
            'payments' => Demo::paymentBreakdown($range),
            'topProducts' => $report['topSellingProducts'],
            'rangeLabel' => Demo::rangeLabel($range),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر تقرير المبيعات (PDF)');

        return $this->pdf($html, 'sales-report-'.$range.'-'.now()->format('Y-m-d'));
    }

    public function orderReceipt($number)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $order = Order::where('business_id', $bid)->where('number', $number)->with('items')->firstOrFail();

        $tpl = ReceiptTemplate::forBusiness($bid);

        /*
         * A4 ورقةٌ أخرى لا شريطٌ مُمدَّد.
         *
         * كانت تُرسم بقالب الإيصال نفسه، فتخرج بمحتوًى منكمشٍ في أعلى الصفحة
         * وثلثيها بياض — وهي الورقة التي تُرسَل إلى شركةٍ تطلب فاتورة ضريبية.
         * والقالب واحدٌ يحكم الاثنين، فلا تفترق ورقتان لطلبٍ واحد.
         */
        $onA4 = ($tpl['paper'] ?? '80mm') === 'A4';

        $html = view($onA4 ? 'pdf.invoice' : 'pdf.receipt', [
            'order' => $order,
            'qr' => EInvoice::forOrder($order, Demo::vatSettings(), Demo::business($bid)),
            'tpl' => $tpl,
            // رقم المشتري الضريبي: تحتاجه منشأةٌ مسجَّلة لتخصم ضريبة شرائها
            'customerTax' => $order->customer_id
                ? optional(Customer::find($order->customer_id))->tax_number
                : null,
        ])->render();

        // «A4» فاتورة كاملة و«58mm» شريط أضيق — ورقٌ لا يطابق الطابعة يخرج مقصوصًا
        $format = match ($tpl['paper'] ?? '80mm') {
            'A4' => 'A4',
            '58mm' => [58, 200],
            default => [80, 200],
        };

        /*
         * وطابعة هذا الصندوق تغلب قالب المتجر.
         *
         * القالب إعدادٌ واحد للمتجر كلّه، والصناديق تختلف: صندوق المدخل بورق
         * ٨٠ وصندوق التغليف بورق ٥٨. فمن يطبع من صندوقٍ يطبع بمقاس ورقه هو،
         * لا بمقاسٍ يخصّ صندوقًا آخر — وورقٌ لا يطابق الطابعة يخرج مقصوصًا
         * من الحافة، ويُكتشف بعد أن يأخذه الزبون.
         *
         * وA4 لا تُمسّ: من اختارها اختار فاتورةً كاملة لا شريطًا.
         */
        if (! $onA4
            && ($width = PosTerminal::current()
                ?->peripherals()->where('active', true)
                ->where('type', PosPeripheral::PRINTER)
                ->value('paper_width'))
        ) {
            $format = [(int) $width, 200];
        }

        // هوامش الورقة لا هوامش الشريط: ٤mm على A4 تجعل النصّ يلامس الحافة
        $margin = $onA4 ? 14 : 4;

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'margin_left' => $margin, 'margin_right' => $margin,
            'margin_top' => $onA4 ? 14 : 6, 'margin_bottom' => $onA4 ? 14 : 6,
            'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $mpdf->WriteHTML($html);

        return response($mpdf->Output('receipt-'.$order->number.'.pdf', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-'.$order->number.'.pdf"',
        ]);
    }

    /** تقرير أداء المنصة (سوبر أدمن) */
    public function financeReport()
    {
        // فترةٌ واحدة لكل ما في الورقة، وتُكتب فيها — انظر financeXlsx
        $range = Demo::range(request()->query('range'));

        $html = view('pdf.finance-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(false),
            'stats' => Demo::financeStats($range),
            'payments' => Demo::paymentMethods($range),
            'transactions' => Demo::transactions($range, null),
            'rangeLabel' => Demo::rangeLabel($range),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر التقرير المالي (PDF)');

        return $this->pdf($html, 'finance-report-'.now()->format('Y-m-d'));
    }

    public function platformReport()
    {
        $businesses = Demo::businessPerformance();
        usort($businesses, fn ($a, $b) => $b['sales'] <=> $a['sales']);

        $html = view('pdf.platform-report', [
            'stats' => Demo::superStats(),
            'revenueSeries' => Demo::revenueSeries(),
            'growthSeries' => Demo::businessesGrowthSeries(),
            'planDistribution' => Demo::planDistribution(),
            'topBusinesses' => array_slice($businesses, 0, 8),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر تقرير أداء المنصة (PDF)', ['business_id' => null]);

        return $this->pdf($html, 'platform-report-'.now()->format('Y-m-d'));
    }

    /** كشف حساب عميل (PDF) */
    public function customerStatement($id)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $customer = Customer::where('business_id', $bid)->findOrFail($id);

        $orders = Order::where('business_id', $bid)->where('customer_id', $customer->id)->sold()
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

        Activity::log('report', 'صدّر كشف حساب العميل: '.$customer->name, ['subject_id' => $customer->id]);

        return $this->pdf($html, 'statement-'.$customer->id.'-'.now()->format('Y-m-d'));
    }

    /** تقرير قائمة الطلبات (PDF) */
    public function ordersReport()
    {
        $orders = Demo::orders(request());
        $html = view('pdf.orders-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(true),
            'orders' => $orders,
            // الملغى خارج المجموع كما في الشاشة — انظر ReportExportController::ordersXlsx
            'total' => array_sum(array_map(
                fn ($o) => $o['status'] === OrderStatus::CANCELLED ? 0.0 : (float) $o['total'],
                $orders,
            )),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر قائمة الطلبات (PDF)');

        return $this->pdf($html, 'orders-report-'.now()->format('Y-m-d'));
    }

    /** تقرير المنتجات (PDF) */
    public function productsReport()
    {
        $products = Demo::products(null, request());
        $html = view('pdf.products-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(false),
            'products' => $products,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر قائمة المنتجات (PDF)');

        return $this->pdf($html, 'products-report-'.now()->format('Y-m-d'));
    }

    /** تقرير جرد المخزون (PDF) */
    public function inventoryReport()
    {
        $inventory = Demo::inventory();
        $html = view('pdf.inventory-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(true),
            'inventory' => $inventory,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر جرد المخزون (PDF)');

        return $this->pdf($html, 'inventory-report-'.now()->format('Y-m-d'));
    }

    /** تقرير المصروفات (PDF) */
    public function expensesReport()
    {
        $expenses = Demo::expenses(request());
        $html = view('pdf.expenses-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(false),
            'expenses' => $expenses,
            'total' => array_sum(array_map(fn ($e) => (float) $e['amount'], $expenses)),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر المصروفات (PDF)');

        return $this->pdf($html, 'expenses-report-'.now()->format('Y-m-d'));
    }

    /** تقرير الشركات (PDF) — لوحة المنصة */
    public function businessesReport()
    {
        $businesses = Demo::businesses();
        $html = view('pdf.businesses-report', [
            'businesses' => $businesses,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر الشركات (PDF)');

        return $this->pdf($html, 'businesses-report-'.now()->format('Y-m-d'));
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

        Activity::log('report', 'صدّر فواتير الاشتراكات (PDF)');

        return $this->pdf($html, 'invoices-report-'.now()->format('Y-m-d'));
    }

    public function taxInvoice($number)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $order = Order::where('business_id', $bid)->where('number', $number)->with('items')->firstOrFail();

        $vat = Demo::vatSettings();
        $business = Demo::business($bid);

        $html = view('pdf.tax-invoice', [
            'order' => $order,
            'vat' => $vat,
            'business' => $business,
            // القالب نفسه الذي يحكم فاتورة الطلب — «الإعدادات ‹ قوالب الفواتير»
            'tpl' => ReceiptTemplate::forBusiness($bid),
            'customerTax' => $order->customer_id ? optional(Customer::find($order->customer_id))->tax_number : null,
            'qr' => EInvoice::forOrder($order, $vat, $business),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'أصدر فاتورة ضريبية للطلب: '.$order->number, ['subject_id' => $order->id]);

        return $this->pdf($html, 'tax-invoice-'.$order->number);
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

        return response($mpdf->Output($name.'.pdf', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
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

        return response($mpdf->Output('invoice-'.$invoice->number.'.pdf', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="invoice-'.$invoice->number.'.pdf"',
        ]);
    }
}
