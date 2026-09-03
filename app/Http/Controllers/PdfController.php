<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PosPeripheral;
use App\Models\Setting;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\EInvoice;
use App\Support\GoogleReviews;
use App\Support\OrderStatus;
use App\Support\Pdf;
use App\Support\PosTerminal;
use App\Support\PublicDocument;
use App\Support\ReceiptTemplate;
use App\Support\Reports;

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
        $width = $this->stripWidth((string) ($tpl['paper'] ?? '80mm'));

        $html = view($onA4 ? 'pdf.invoice' : 'pdf.receipt', [
            'order' => $order,
            'qr' => EInvoice::forOrder($order, Demo::vatSettings(), Demo::business($bid)),
            'tpl' => $tpl,
            // عرضُ الشريط يصل القالب: القياس يتبع الورق — انظر strip-style
            'width' => $width,
            /*
             * ورمزُ الورقة أونلاين.
             *
             * الإيصال الحراريّ يبهت في جيبٍ خلال أشهر، ويُبلَّل، ويضيع. وزبونٌ
             * يعود بضمانٍ بعد سنةٍ يحمل قصاصةً لا تُقرأ. فيحمل أسفلُه رمزًا
             * يفتح نسختَه الحيّة — رابطٌ دائم لا يُخمَّن (انظر PublicDocument).
             */
            'paperUrl' => PublicDocument::url($order) ?? '',
            // رقم المشتري الضريبي: تحتاجه منشأةٌ مسجَّلة لتخصم ضريبة شرائها
            'customerTax' => $order->customer_id
                ? optional(Customer::find($order->customer_id))->tax_number
                : null,
            /*
             * رمزُ تقييم Google — أو null فلا يُطبع شيء.
             *
             * والشرطان في `onReceipt` معًا: مقبضٌ مُشغَّل ومعرّفٌ مقروء.
             * فمقبضٌ يعمل بلا معرّف يطبع مربّعًا أسود يمسحه الزبون فلا يجد.
             */
            'googleReview' => GoogleReviews::onReceipt($bid),
        ])->render();

        $name = 'receipt-'.$order->number;

        return $onA4 ? Pdf::a4($html, $name) : Pdf::strip($html, $name, $width);
    }

    /**
     * عرضُ ورق هذه الطابعة بالمليمتر.
     *
     * وطابعةُ هذا الصندوق تغلب قالب المتجر: القالب إعدادٌ واحد للمتجر كلّه،
     * والصناديق تختلف — صندوق المدخل بورق ٨٠ وصندوق التغليف بورق ٥٨. فمن
     * يطبع من صندوقٍ يطبع بمقاس ورقه هو، وورقٌ لا يطابق الطابعة يخرج
     * مقصوصًا من الحافة، ويُكتشف بعد أن يأخذه الزبون.
     *
     * وA4 لا تُمسّ: من اختارها اختار فاتورةً كاملة لا شريطًا.
     */
    private function stripWidth(string $paper): int
    {
        if ($paper === 'A4') {
            return 0;
        }

        $terminal = PosTerminal::current()
            ?->peripherals()->where('active', true)
            ->where('type', PosPeripheral::PRINTER)
            ->value('paper_width');

        return (int) ($terminal ?: ($paper === '58mm' ? 58 : 80));
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
            // ورمزُ الورقة أونلاين — كما في فاتورة البيع وإيصالها
            'paperUrl' => PublicDocument::url($order) ?? '',
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'أصدر فاتورة ضريبية للطلب: '.$order->number, ['subject_id' => $order->id]);

        return $this->pdf($html, 'tax-invoice-'.$order->number);
    }

    /** ورقةُ A4 — بالمحرّك الواحد لا بإعدادٍ يخصّ هذا الملفّ */
    private function pdf(string $html, string $name)
    {
        return Pdf::a4($html, $name);
    }

    /** فاتورة اشتراك المنصة (سوبر أدمن) */
    public function platformInvoice($number)
    {
        $invoice = Invoice::where('number', $number)->with('business', 'plan')->firstOrFail();

        /*
         * ورقةُ المنصّة لها قالبها — لا قالب فاتورة المبيعات.
         *
         * كان القالب يُقرأ بـ`$order` (أصناف، فرع، زبون، طريقة دفع) ويُمرَّر
         * له `$invoice`، فكانت كل ضغطةٍ على «عرض» أو «تحميل» في شاشة الفواتير
         * ٥٠٠ صامتة.
         */
        $settings = Setting::whereNull('business_id')
            ->whereIn('key', ['app_name', 'company', 'official_email', 'phone', 'website'])
            ->pluck('value', 'key');

        $html = view('pdf.platform-invoice', [
            'invoice' => $invoice,
            'platform' => [
                'app_name' => trim((string) $settings->get('app_name')) ?: __('أبعاد'),
                'company' => trim((string) $settings->get('company')),
                'email' => trim((string) $settings->get('official_email')),
                'phone' => trim((string) $settings->get('phone')),
                'website' => trim((string) $settings->get('website')),
            ],
        ])->render();

        return Pdf::a4($html, 'invoice-'.$invoice->number);
    }
}
