<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\BankAccount;
use App\Models\Business as BusinessModel;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PosPeripheral;
use App\Models\Setting;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\EInvoice;
use App\Support\GoogleReviews;
use App\Support\InvoiceBranding;
use App\Support\OrderStatus;
use App\Support\Paper;
use App\Support\Pdf;
use App\Support\PosTerminal;
use App\Support\PublicDocument;
use App\Support\ReceiptTemplate;
use App\Support\Receivables;
use App\Support\Reports;
use App\Support\ShopIdentity;
use Illuminate\Http\Request;

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
        /*
         * والاختيارُ بين الشريط والصفحة من سجلّ المقاسات لا بمقارنةٍ نصّية.
         *
         * `=== 'A4'` تعني أنّ كلّ مقاسٍ يُضاف — A5 مثلًا — يُقرأ شريطًا
         * حراريًّا فيخرج على ثمانين مليمترًا.
         */
        $paper = (string) ($tpl['paper'] ?? \App\Support\Document\PaperSize::T80);
        $onSheet = ! \App\Support\Document\PaperSize::isStrip($paper);
        $width = $this->stripWidth($paper);

        /*
         * والرسمُ من `DocumentRenderer` لا من قائمةٍ تُكتب هنا.
         *
         * كان هذا البابُ يبني متغيّراتِ القالب بيده، ومعاينةُ محرّر القوالب
         * تبنيها عندها — فما يُضاف لأحدهما لا يبلغ الآخر: يُضبط شيءٌ فيُرى
         * في المعاينة ويغيب عن الطابعة. وهو خلافٌ لا يُكتشف إلّا بعد أن
         * يأخذ الزبون ورقته.
         *
         * وقيمُ القالب من السجلّ (`DocumentTemplates`) لا من `ReceiptTemplate`:
         * تلك تردّ المفاتيح القديمة المسطَّحة (`tpl_show_logo`)، وقالبُ A4
         * الجديد يقرأ أسماءَ السجلّ. و`legacy()` تحوّلها للشريط وحده.
         */
        $values = DocumentTemplates::settings($bid, 'sale');

        $extra = [
            'qr' => EInvoice::forOrder($order, Demo::vatSettings(), Demo::business($bid)),
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
            'googleReview' => GoogleReviews::onReceipt($bid, $order->branch_id),
        ];

        $name = 'receipt-'.$order->number;

        if ($onSheet) {
            return Pdf::sheet(
                DocumentRenderer::saleSheet($bid, $order, $values, $extra + ['paper' => $paper]),
                $name,
                $paper,
                /*
                 * وسياقُ الورقة يتكرّر في تذييل كلّ صفحة.
                 *
                 * فاتورةٌ بخمسين صنفًا تمتدّ ثلاثَ صفحات، وصفحةٌ ثانيةٌ لا
                 * تحمل إلّا رقمَها ورقةٌ لا يُعرف إلى أيّ حزمةٍ تعود إن
                 * سقطت. انظر `MpdfDriver::pageNumbers`.
                 */
                context: self::context($order->number, $bid),
            );
        }

        return Pdf::strip(DocumentRenderer::saleStrip($bid, $order, $values, $width, $extra), $name, $width);
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
        if (! \App\Support\Document\PaperSize::isStrip($paper)) {
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

    /**
     * الفاتورةُ الضريبيّة — ولا تخرج باسمٍ يكتبه النظام.
     *
     * `Paper::brand()` تطبع اسمَ المتجر في رأسها، و`EInvoice` تضعه في رمز
     * الاستجابة. ومتجرٌ لم يُسمَّ بعدُ يخرج ورقُه باسمٍ لم يختره صاحبُه —
     * «متجري» أو «نظام Abad POS» — والزبونُ الذي يطلبها لمحاسبه يعيدها،
     * والرقمُ الضريبيّ عليها منسوبٌ إلى اسمٍ لا وجود له.
     *
     * والمنعُ هنا لا في الشاشة: هذا البابُ يُفتح برابطٍ مباشر، ولا زرَّ
     * يقوده اليوم أصلًا.
     */
    public function taxInvoice($number)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $order = Order::where('business_id', $bid)->where('number', $number)->with('items')->firstOrFail();

        if (! ShopIdentity::confirmed(BusinessModel::find($bid))) {
            abort(409, __('لم يُسمَّ متجرك بعد — والفاتورة الضريبية تحمل اسمه. أتمّ تهيئة المتجر من «الإعدادات ‹ تهيئة المتجر» ثمّ أصدِرها.'));
        }

        $vat = Demo::vatSettings();
        $business = Demo::business($bid);

        /*
         * والفاتورةُ الضريبية وجهٌ ثالثٌ للقالب نفسه لا قالبٌ رابع.
         *
         * ثلاثتُها — الإيصالُ الحراريّ وفاتورةُ A4 والضريبيةُ — يحكمها
         * `sale` في السجلّ. وقالبان لهما ترويسةٌ وتذييلٌ وجدولٌ واحد
         * يفترقان عند أوّل تعديل: لطلبٍ واحد تخرج ورقتان لا يجمعهما شكل.
         *
         * وما يخصّها وحدها — أنّ رقمَ البائع الضريبيّ **بلا مقبض** — علمٌ
         * يُمرَّر لا قالبٌ يُنسَخ. انظر رأس `documents/v1/sale.blade.php`.
         */
        $values = DocumentTemplates::settings($bid, 'sale');
        /* والضريبيةُ ورقةٌ لا شريط: من اختار ٨٠ مم يأخذها على A4 */
        $sheet = \App\Support\Document\PaperSize::isStrip((string) ($values['paper'] ?? ''))
            ? \App\Support\Document\PaperSize::A4
            : (string) $values['paper'];

        $html = DocumentRenderer::saleSheet($bid, $order, $values, [
            'taxInvoice' => true,
            'paper' => $sheet,
            'customerTax' => $order->customer_id ? optional(Customer::find($order->customer_id))->tax_number : null,
            'qr' => EInvoice::forOrder($order, $vat, $business),
            // ورمزُ الورقة أونلاين — كما في فاتورة البيع وإيصالها
            'paperUrl' => PublicDocument::url($order) ?? '',
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);

        Activity::log('report', 'أصدر فاتورة ضريبية للطلب: '.$order->number, ['subject_id' => $order->id]);

        return Pdf::sheet($html, 'tax-invoice-'.$order->number, $sheet, context: self::context($order->number, $bid));
    }

    /**
     * ما يُعرِّف الورقةَ في تذييل كلّ صفحة — رقمُها واسمُ متجرها.
     *
     * ويُقرأ اسمُ المتجر من `Paper::brand` كبقيّة الورق: هي التي تعرف الاسمَ
     * المعروض الذي اختاره صاحبُ المحلّ، لا صفَّ المتجر في لوحة المنصّة.
     */
    private static function context(?string $number, int $businessId): string
    {
        $shop = Paper::brand(Demo::business($businessId))['name'];

        return trim(trim((string) $number).' · '.$shop, ' ·');
    }

    /**
     * فاتورةُ عميل (PDF) — بالبنية القائمة لا بمحرّكٍ ثانٍ.
     *
     * ولا معرّفاتٍ محاسبيّةً على الورق: الزبونُ يقرأ رقمَ الفاتورة لا رقمَ
     * القيد ولا معرّفَ الصفّ.
     */
    public function customerInvoice($id)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $invoice = CustomerInvoice::where('business_id', $bid)->whereKey($id)
            ->with('items')->firstOrFail();

        /*
         * والورقةُ تُبنى حيث تُبنى المعاينة — لا نسخةٌ ثانية هنا.
         *
         * كانت قائمةُ المتغيّرات مكتوبةً في الموضعين، فما يُضاف لأحدهما لا
         * يبلغ الآخر: يُضبط الشعارُ فيُرى في الشاشة ويغيب عن الطابعة.
         * وباللغةِ التي اختارها صاحبُ المحلّ لورقته، لا بلغة من ضغط الزرّ.
         */
        $html = InvoiceBranding::render($bid, null, fn () => CustomerInvoiceController::paper(
            $bid,
            $invoice,
            $invoice->paidTotal(),
            $invoice->outstanding(),
            BankAccount::where('business_id', $bid)->orderBy('id')->first(),
            /*
             * ورمزُ التحقّق يُبنى عند الطباعة لا عند المعاينة.
             *
             * الورقةُ تُرسَل إلى جهةٍ تراجعها، فتحمل طريقَها إلى سجلّ
             * المتجر. والمعاينةُ قد تُرسم لمسودّةٍ لا صفَّ لها — ورمزٌ لها
             * يقود إلى ٤٠٤ في يد التاجر.
             */
            null,
            ['paperUrl' => PublicDocument::url($invoice) ?? ''],
        )->render());

        Activity::log('report', 'صدّر فاتورة عميل: '.$invoice->number, ['subject_id' => $invoice->id]);

        return $this->pdf($html, 'customer-invoice-'.$invoice->number);
    }

    /** كشفُ حساب عميل (PDF) — رصيدٌ افتتاحيٌّ ثمّ حركةٌ برصيدٍ جارٍ */
    public function customerAccountStatement(Request $request, $customer)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $customer = Customer::where('business_id', $bid)->whereKey($customer)->firstOrFail();

        $from = $request->date('from') ?? now()->startOfYear();
        $to = $request->date('to') ?? now()->endOfDay();

        $html = view('pdf.customer-account-statement', [
            'customer' => $customer,
            'business' => Demo::business($bid),
            'statement' => Receivables::statement($bid, (int) $customer->id, $from, $to),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر كشف حساب العميل: '.$customer->name, ['subject_id' => $customer->id]);

        return $this->pdf($html, 'customer-account-'.$customer->id);
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
