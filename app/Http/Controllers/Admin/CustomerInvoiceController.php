<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceAttachment;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Activity;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Customers;
use App\Support\Demo;
use App\Support\Document\Branding;
use App\Support\Document\PaperSize;
use App\Support\Document\Snapshot;
use App\Support\Document\Version;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\InvoiceAttachments;
use App\Support\InvoiceBranding;
use App\Support\Money;
use App\Support\Pagination;
use App\Support\Paper;
use App\Support\Permissions;
use App\Support\Receivables;
use App\Support\Search;
use App\Support\Vat;
use App\Support\WhatsAppPhone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * فواتيرُ العملاء — الشاشةُ وبابُ الكتابة.
 *
 * ولا يُقرأ إجماليٌّ من الواجهة بحال: الخادمُ يحسب من البنود. من يستطيع فتح
 * أدوات المتصفّح يستطيع إرسال إجماليٍّ صفرٍ لفاتورةٍ بمئة.
 */
class CustomerInvoiceController extends Controller
{
    /** ما يُرسل من الكتالوج إلى شاشة الإنشاء — والزائدُ يُعلَن لا يُكتم */
    private const CATALOG_LIMIT = 500;

    /** اسمُ ورقتها في «قوالب الأوراق» — يُكتب مرّةً لا في كلّ نداء */
    public const PAPER_TYPE = 'customer_invoice';

    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /**
     * الحساباتُ البنكيّة التي يجوز أن يُسمّى أحدُها مستقبِلًا للمال.
     *
     * والمعطَّلُ لا يُعرض: حسابٌ أُغلق لا يُوجَّه إليه تحصيلٌ جديد — ويبقى
     * مقبولًا في الخادم لأنّ تحصيلًا قديمًا قد يشير إليه.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bankAccounts(): array
    {
        return BankAccount::where('business_id', $this->bid())
            ->where('active', true)
            // والرئيسيُّ أوّلًا: هو ما تختاره الشاشةُ وحدَها، فليكن أوّلَ ما يُقرأ
            ->orderByDesc('is_primary')->orderBy('id')
            ->get(['id', 'label', 'bank_name', 'account_name', 'iban', 'is_primary'])
            ->map(fn (BankAccount $a) => [
                'id' => $a->id,
                'name' => $a->displayName(),
                'is_primary' => (bool) $a->is_primary,
            ])->all();
    }

    /** الفاتورةُ من متجر الطالب لا من رقمٍ في الرابط */
    private function find(int|string $id): CustomerInvoice
    {
        return CustomerInvoice::where('business_id', $this->bid())
            ->whereKey($id)->with('items', 'customer')->firstOrFail();
    }

    /** كم صفًّا في الصفحة — والتاجر يختار، وما سواها يسقط إلى العشرين */
    private const PER_PAGE = [20, 50, 100];

    /**
     * قائمةُ الفواتير — صفحةً صفحة.
     *
     * ═══ ولماذا لم تعد تُحمَّل كاملة ═══
     *
     * كانت `limit(300)`. ومتجرٌ يكتب عشرين فاتورةً في الشهر يبلغها في سنة،
     * وبعدها **تختفي أقدمُ فواتيره من الشاشة بلا كلمة**: لا رسالة، ولا صفحة
     * ثانية، ولا رقمٌ يقول «من ٣٠٠ من ٤٢٠». والبحثُ يجدها لأنّه يمرّ على
     * القاعدة — فيظنّ التاجر أنّ القائمة كلُّ ما لديه وهي ثلاثةُ أرباعه.
     *
     * ═══ والترشيحُ نزل إلى القاعدة معها ═══
     *
     * «المتأخّرة» كانت تُرشَّح في الذاكرة بعد الجلب. ومع الصفحات يعني ذلك
     * ترشيحَ عشرين صفًّا من أربعمئة — فتقول الشاشة «لا متأخّرات» ولها عشرون
     * في الصفحة الثالثة. انظر `CustomerInvoice::scopePaymentState`.
     */
    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $per = (int) $request->integer('per_page');
        $per = in_array($per, self::PER_PAGE, true) ? $per : self::PER_PAGE[0];

        $page = CustomerInvoice::where('business_id', $bid)->with('customer')
            // والمعاملُ من `Search::like` لا مكتوبًا بيده: `like` على Postgres
            // حسّاسٌ لحالة الأحرف و`ilike` ليس كذلك — ومن كتبه بيده أصاب في
            // شاشةٍ وأخطأ في أخرى
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('number', Search::like(), "%{$s}%")
                    ->orWhere('po_number', Search::like(), "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', Search::like(), "%{$s}%"))
            ))
            ->when($request->integer('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            // حالُ المستند: مسودة / صادرة / ملغاة
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            // وحالُ السداد غيرُها: مدفوعة / جزئيًا / غير مدفوعة / متأخرة
            ->when($request->string('state')->toString(), fn ($q, $s) => $q->paymentState($s))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->when($request->date('from'), fn ($q, $d) => $q->whereDate('issued_at', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->whereDate('issued_at', '<=', $d))
            ->when($request->date('due_from'), fn ($q, $d) => $q->whereDate('due_at', '>=', $d))
            ->when($request->date('due_to'), fn ($q, $d) => $q->whereDate('due_at', '<=', $d))
            ->orderByDesc('id')
            ->paginate($per)
            ->withQueryString();

        return Inertia::render('Admin/CustomerInvoices/Index', [
            'invoices' => collect($page->items())->map(fn ($i) => $this->row($i))->all(),
            /*
             * وشكلُ الترقيم من `Pagination::meta` لا مكتوبًا هنا بيده.
             *
             * `DataTable` في وضعه الخادميّ يقرأ هذا الشكل بعينه في المنتجات
             * والعملاء والمصروفات والنشاط. وشكلٌ سادسٌ يُكتب هنا يعني شريطَ
             * صفحاتٍ يختلف عن أخواته في شاشةٍ واحدة.
             */
            'pagination' => Pagination::meta($page),
            'filters' => $request->only(
                'q', 'customer_id', 'status', 'state', 'from', 'to', 'due_from', 'due_to', 'overdue', 'per_page',
            ),
            'states' => ['غير مدفوعة', 'مدفوعة جزئيًا', 'مدفوعة', 'متأخرة'],
            'statuses' => [CustomerInvoice::DRAFT, CustomerInvoice::ISSUED, CustomerInvoice::CANCELLED],
            'customers' => Customer::where('business_id', $bid)->orderBy('name')->get(['id', 'name'])->all(),
            'totals' => Receivables::totals($bid),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(CustomerInvoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'customer' => $i->customer?->name ?? $i->customer_name ?? '—',
            'customer_id' => $i->customer_id,
            'status' => $i->status,
            'state' => $i->paymentState(),
            'issued_at' => optional($i->issued_at)->format('Y-m-d'),
            'due_at' => optional($i->due_at)->format('Y-m-d'),
            'total' => (float) $i->total,
            'paid' => $i->paidTotal(),
            'outstanding' => $i->outstanding(),
            'days_overdue' => $i->daysOverdue(),
            'po_number' => $i->po_number,
        ];
    }

    /**
     * ما يملكه من يقرأ — تُرسَل مع الشاشة فلا تُرسم مقابضُ يردّها الخادم.
     *
     * «بابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض»: من يضغط «إلغاء» فيُردّ
     * بـ٤٠٣ لا يعرف أنّه لم يُمنح — يظنّ النظامَ معطوبًا.
     *
     * @return array<string, bool>
     */
    private function may(): array
    {
        $user = auth()->user();

        return [
            'issue' => (bool) $user?->may(Permissions::CUSTOMER_INVOICE_ISSUE),
            'cancel' => (bool) $user?->may(Permissions::CUSTOMER_INVOICE_CANCEL),
            'credit_note' => (bool) $user?->may(Permissions::CUSTOMER_CREDIT_NOTE),
            /*
             * وهويّةُ الورقة إعدادُ متجرٍ لا فعلُ فاتورة — فتُقاس بقسم
             * الإعدادات. ومن لا يملكها لا يُرسَم له زرُّ «تخصيص التصميم»:
             * بابٌ معروضٌ يردّ بـ٤٠٣ يُقرأ عطبًا في النظام لا منعًا.
             */
            'brand' => (bool) $user?->allows('settings'),
            'pay' => (bool) $user?->may(Permissions::CUSTOMER_PAYMENT_CREATE),
        ];
    }

    public function show(int|string $id): Response
    {
        $invoice = $this->find($id);

        return Inertia::render('Admin/CustomerInvoices/Show', [
            'may' => $this->may(),
            // ونافذةُ التحصيل هنا تسأل السؤال نفسه — انظر `create`
            'bank_accounts' => $this->bankAccounts(),
            /*
             * ووسائلُ التحصيل من مالكها — كانت مكتوبةً بيدها في هذه الشاشة.
             *
             * وهي غيرُ قائمة الإنشاء بحرفٍ واحد: لا «آجل» هنا. التحصيلُ مالٌ
             * وقع، و«آجل» تعني أنّه لم يقع — فوسيلةٌ اسمُها «لم يُدفع» في
             * نافذةِ دفعٍ بابٌ يردّه الخادم.
             */
            'methods' => CustomerPayments::METHODS,
            'bank_methods' => CustomerInvoices::bankMethods(),
            'invoice' => $this->row($invoice) + [
                'subtotal' => (float) $invoice->subtotal,
                'discount_total' => (float) $invoice->discount_total,
                'tax_total' => (float) $invoice->tax_total,
                // ملاحظةُ العميل تُطبع، والداخليّةُ لا تخرج من هذه الشاشة
                'notes' => $invoice->notes,
                'internal_notes' => $invoice->internal_notes,
                'contract_number' => $invoice->contract_number,
                'external_reference' => $invoice->external_reference,
                'department' => $invoice->department,
                'cost_center' => $invoice->cost_center,
                'attention_to' => $invoice->attention_to,
                'orders' => $invoice->orders->pluck('number')->all(),
                'cancellation_reason' => $invoice->cancellation_reason,
                'items' => $invoice->items->map(fn ($it) => [
                    'description' => $it->description,
                    'quantity' => (float) $it->quantity,
                    'unit_price' => (float) $it->unit_price,
                    'discount' => (float) $it->discount,
                    'tax_rate' => (float) $it->tax_rate,
                    'line_total' => (float) $it->line_total,
                ])->all(),
                'payments' => $invoice->allocations()->with('payment')->get()
                    ->filter(fn ($a) => $a->payment && $a->payment->cancelled_at === null)
                    ->map(fn ($a) => [
                        'number' => $a->payment->number,
                        'amount' => (float) $a->amount,
                        'method' => $a->payment->method,
                        'at' => optional($a->payment->occurred_at)->format('Y-m-d'),
                    ])->values()->all(),
                /*
                 * والمرفقُ يُقال موجودًا ولو لم يُفتح.
                 *
                 * من لا يملك فتحَ المرفقات لا يُبنى له رابط — ولا يُكتم عنه
                 * وجودُ المستند، فيظنّ الورقةَ بلا سندٍ وهي مسنودة. وهي
                 * الحفرةُ التي وقع فيها إيصالُ أمر الشراء.
                 */
                'may_read_attachments' => (bool) auth()->user()?->may(Permissions::ATTACHMENT_VIEW),
                'attachments' => $invoice->attachments()->orderBy('id')->get()->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'size' => (int) $a->size,
                    'url' => auth()->user()?->may(Permissions::ATTACHMENT_VIEW)
                        ? route('admin.customerInvoices.attachment', [$invoice->id, $a->id])
                        : null,
                ])->all(),
                'credit_notes' => $invoice->creditNotes->map(fn ($n) => [
                    'number' => $n->number,
                    'amount' => (float) $n->amount,
                    'reason' => $n->reason,
                    'at' => optional($n->issued_at)->format('Y-m-d'),
                ])->all(),
            ],
        ]);
    }

    /**
     * شاشةُ الإنشاء — صفحةٌ قائمةٌ بذاتها لا لوحةٌ تنطوي فوق الجدول.
     *
     * وفاتورةُ شركةٍ ليست سطرًا يُملأ في ثانية: بياناتُ الجهة، وأمرُ الشراء،
     * وبنودٌ لكلٍّ ضريبتُه، وشروطُ سدادٍ تُحسب منها مدّةُ الاستحقاق. وحشرُ
     * ذلك في لوحةٍ فوق الجدول كان يجعل نصفَه مخفيًّا خلف زرّ.
     */
    public function create(Request $request): Response
    {
        $bid = $this->bid();

        return Inertia::render('Admin/CustomerInvoices/Create', [
            'customers' => Customer::where('business_id', $bid)->orderBy('name')->get([
                'id', 'name', 'customer_type', 'legal_name', 'tax_number',
                'commercial_registration', 'phone', 'contact_phone', 'email', 'contact_email',
                'address', 'billing_address', 'department', 'customer_reference',
                'contact_person', 'payment_terms_days', 'allow_credit_sales',
            ])->all(),
            /*
             * والكتالوج للاختيار لا للإلزام: بندٌ مخصَّصٌ يُكتب بيده كذلك.
             * أكثرُ فواتير الجهات خدماتٌ لا أصنافَ رفٍّ — «تنسيق قاعة» ليس
             * منتجًا في المخزون، وإلزامُ التاجر باختيار صنفٍ يجعله يخترع صنفًا.
             */
            /*
             * ورمزُ الصنف وباركودُه مع اسمه.
             *
             * البحثُ بالاسم وحده يفترض أنّ من يكتب الفاتورة يحفظ الأسماء —
             * وهو يقرأ الرمزَ من أمر شراء العميل، أو يمسح الباركود بقارئٍ
             * يكتب أرقامًا ثمّ Enter. فبحثٌ لا يقرأ الاثنين يردّ «لا صنف»
             * على صنفٍ في يده.
             */
            'products' => Product::where('business_id', $bid)->orderBy('name')
                ->limit(self::CATALOG_LIMIT)->get(['id', 'name', 'sku', 'barcode', 'price'])->all(),
            /*
             * وهل قُصّ الكتالوج؟ — تقولها الشاشة ولا تكتمها.
             *
             * القائمةُ تُرشَّح في المتصفّح، فما لم يُرسَل لا يُبحث فيه. وقصٌّ
             * صامتٌ يعني صنفًا موجودًا في المخزون يقول عنه البحثُ «لا صنف
             * بهذا الاسم» — وهو أسوأ من قائمةٍ تعتذر.
             */
            'catalog_truncated' => Product::where('business_id', $bid)->count() > self::CATALOG_LIMIT,
            'tax_rate' => Vat::enabled($bid)
                ? (float) (Setting::where('business_id', $bid)->where('key', 'vat_rate')->value('value') ?? 5)
                : 0.0,
            'today' => now()->toDateString(),
            'may' => $this->may(),
            /*
             * ووسائلُ السداد من مالكها لا مكتوبةً هنا.
             *
             * كانت هذه القائمةُ إحدى ثلاث: هذه، وقاعدةُ المصادقة في `store`
             * أسفلُ، وثالثةٌ مكتوبةٌ بيدها في شاشة الفاتورة المفتوحة. وقائمةُ
             * التحصيل الحقيقيّة فيها «شيك» ولم تكن هنا — انظر
             * `CustomerInvoices::methods`.
             */
            'methods' => CustomerInvoices::methods(),
            /*
             * وأيُّها يدخل مالُه بنكًا — فتُسأل الشاشةُ عن الحساب.
             *
             * كان الشرطُ مكتوبًا في الشاشة `بطاقة || تحويل`، و«شيك» تدخل
             * البنكَ مثلَهما. والجوابُ من `CustomerPayments::sideFor` وحدَها.
             */
            'bank_methods' => CustomerInvoices::bankMethods(),
            /*
             * وأيُّ حسابٍ بنكيٍّ استقبل المال — من المالية لا مكتوبًا هنا.
             *
             * كانت الشاشةُ تعرض «بطاقة» و«تحويل» ولا تسأل عن الحساب، والخادمُ
             * يقبل `bank_account_id` ولا ترسله شاشة. فالمالُ يدخل «البنك» في
             * الدفتر بلا أن يُقال أيَّ بنك — ومطابقةُ كشف الحساب تسأل هذا
             * بالضبط.
             */
            'bank_accounts' => $this->bankAccounts(),
            // عميلٌ أُضيف من هذه الشاشة نفسها — يُختار فور العودة إليها
            'new_customer_id' => $request->session()->get('new_customer_id'),
            /*
             * ═══ العميلُ الافتراضيّ ═══
             *
             * أكثرُ المحلّات تفوتر جهةً واحدة أكثرَ ممّا تفوتر غيرَها —
             * عقدٌ شهريّ مع فندق، أو حسابٌ مفتوح لشركة. واختيارُه في كلّ
             * مرّةٍ من قائمةٍ فيها مئتا اسم عملٌ يُعاد بلا سبب.
             *
             * ويبقى قابلًا للتبديل: هو اختيارٌ مبدئيّ لا قفل. وصفُّه يُتحقّق
             * منه عند كلّ قراءة — انظر `InvoiceBranding::defaultCustomerId`.
             */
            'default_customer_id' => InvoiceBranding::defaultCustomerId($bid),
            /*
             * وهويّةُ الورقة: الشعارُ والاسمُ واللغةُ والذيل.
             *
             * تُرسَل لتُعرض في «تخصيص التصميم» — والمعاينةُ لا تقرأ منها
             * حرفًا: هي تُرسَم في الخادم بالقالب نفسه. فلا نسختان للاسم.
             */
            'branding' => InvoiceBranding::settings($bid)
                + DocumentTemplates::settings($bid, self::PAPER_TYPE),
        ]);
    }

    /**
     * الورقةُ كما ستُطبع بما على الشاشة الآن — قبل أن تُحفظ.
     *
     * ═══ ولماذا لا تُرسم في الشاشة ═══
     *
     * القاعدةُ مكتوبةٌ في `DocumentRenderer`: «المعاينةُ تُرسم بالقالب الذي
     * يُطبع لا بنسخةٍ ثانية منه في الشاشة». وصندوقٌ يشبه الفاتورةَ مرسومٌ
     * في JSX يفترق عنها عند أوّل تعديل: يُرفع سطرٌ من الورقة ويبقى في
     * المعاينة، فيعتمد التاجرُ شكلًا لا يخرج من الطابعة — ويرسل إلى عميله
     * ورقةً غيرَ التي رآها.
     *
     * فهنا `pdf.customer-invoice` نفسُه يُرسم بمسودّةٍ **غيرِ محفوظة**.
     *
     * ═══ ولا شيءَ يُكتب ═══
     *
     * لا صفَّ فاتورةٍ، ولا رقمَ يُقطع من التسلسل، ولا قيدَ يقع، ولا سطرَ في
     * سجلّ النشاط. من فتح الشاشةَ وكتب بندًا ثمّ تركها لا يترك خلفه شيئًا.
     *
     * ═══ ولا حقلَ مطلوبًا ═══
     *
     * `store` تشترط عميلًا وبندًا، وهذه لا تشترط: المعاينةُ تُرافق الكتابةَ
     * من أوّل حرف. ومعاينةٌ لا تظهر حتى يكتمل النموذج لا يراها أحدٌ إلّا
     * بعد أن يفرغ من حاجته إليها.
     */
    public function preview(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'po_number' => ['nullable', 'string', 'max:60'],
            'contract_number' => ['nullable', 'string', 'max:60'],
            'external_reference' => ['nullable', 'string', 'max:60'],
            'department' => ['nullable', 'string', 'max:120'],
            'cost_center' => ['nullable', 'string', 'max:60'],
            'attention_to' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric'],
            'items.*.unit_price' => ['nullable', 'numeric'],
            'items.*.discount' => ['nullable', 'numeric'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bank_account_id' => ['nullable', 'integer'],
            /*
             * ولغةُ الورقة تُرسَل لتُعاين — ولا تُحفظ من هنا.
             *
             * صاحبُ المحلّ يقلّب بين العربية والإنجليزية قبل أن يقرّر، وكلُّ
             * تقليبةٍ ليست حفظًا: من نظر إلى الشكل الإنجليزيّ ثمّ عاد لا يجب
             * أن يجد ورقتَه القادمة إنجليزيّة. والحفظُ من «تخصيص التصميم».
             */
            'lang' => ['nullable', 'string', Rule::in(InvoiceBranding::LANGUAGES)],
        ]);

        /*
         * والعميلُ من متجر الطالب أو لا عميل.
         *
         * `first()` لا `firstOrFail()`: رقمٌ من متجرٍ آخر يُهمَل فتُرسم ورقةٌ
         * بلا اسم — ولا يُردّ الطلبُ بـ٤٠٤ في شاشةٍ تكتب. ولا يُقرأ صفُّ
         * عميلٍ ليس من المتجر بحال.
         */
        $customer = ! empty($data['customer_id'])
            ? Customer::where('business_id', $bid)->whereKey($data['customer_id'])->first()
            : null;

        /*
         * ونسبةٌ فارغةٌ تُحذف لا تُقرأ صفرًا — كما في `store` تمامًا.
         *
         * `compute` تقرأ **وجودَ** المفتاح لا قيمتَه، لأنّ الصفر إعفاءٌ
         * مقصود. وبندٌ يصل بـ`tax_rate: null` كان يُعفى في صمت — فتُعاين
         * ورقةً بلا ضريبةٍ وتُحفظ ورقةٌ بها.
         */
        $items = array_map(function (array $line) {
            if (($line['tax_rate'] ?? null) === null) {
                unset($line['tax_rate']);
            }

            return $line;
        }, $data['items'] ?? []);

        $invoice = CustomerInvoices::draft($bid, $customer, $data, $items);

        return response()->json([
            'html' => InvoiceBranding::render(
                $bid,
                $data['lang'] ?? null,
                fn () => self::paper($bid, $invoice, 0.0, (float) $invoice->total,
                    $this->previewBank($bid, $data['bank_account_id'] ?? null))->render(),
            ),
        ]);
    }

    /**
     * الورقةُ مبنيّةً — موضعٌ واحد تقرؤه المعاينةُ والطباعة.
     *
     * ═══ ولمَ خرجت من الاثنين ═══
     *
     * `preview` ترسم في الشاشة، و`PdfController::customerInvoice` ترسم على
     * الورق. وكانتا تبنيان قائمةَ المتغيّرات كلٌّ على حدة — فمتغيّرٌ يُضاف
     * لإحداهما لا يبلغ الأخرى: يُضبط الشعارُ فيظهر في المعاينة ويغيب عن
     * الطبع، أو تُكتب لغةٌ فتُقرأ في موضعٍ دون موضع. وهو الخلافُ الذي لا
     * يُكتشف إلّا بعد أن تصل الورقةُ إلى العميل.
     *
     * وهي `static` كي يناديَها متحكّمُ الطباعة وهو ليس من هذا الصنف.
     */
    /**
     * ═══ ووسيطان لا واحد، ولكلٍّ معناه ═══
     *
     * `$override` قيمُ **قالبٍ** لم تُحفظ بعد — يرسلها محرّرُ القوالب ليرى
     * صاحبُه أثرَ مقبضٍ قبل حفظه، وتمرّ إلى `DocumentTemplates::settings`.
     *
     * و`$options` ما يخصّ **هذه الرسمة** لا القالب: رابطُ التحقّق ونسخةُ
     * القالب. وحشوُها في الأوّل يجعل `settings` تتلقّى مفاتيحَ ليست منها
     * — تتجاهلها اليوم بلا ضرر، وتلتقطها غدًا حين يُضاف حقلٌ باسم أحدها.
     *
     * @param  array<string,mixed>|null  $override  قيمُ قالبٍ لم تُحفظ بعد — لمعاينة المحرّر
     * @param  array<string,mixed>  $options  خيارُ الرسمة: `paperUrl` و`version`
     */
    public static function paper(
        int $bid,
        CustomerInvoice $invoice,
        float $paid,
        float $outstanding,
        ?BankAccount $bank,
        ?array $override = null,
        array $options = [],
    ): View {
        /*
         * وقالبُ الورقة من «قوالب الأوراق» كأخواتها الأربع.
         *
         * ═══ ولمَ لم يبقَ لها مفاتيحُ خاصّة ═══
         *
         * كان لها تذييلٌ في مفتاحٍ من عندها، ولأخواتها تذييلٌ في السجلّ.
         * وهما شيءٌ واحد في حسّ من يضبطه: يكتب تذييلَ فاتورة البيع فيتوقّع
         * أن تتبعه فاتورةُ العميل — ولا تتبعه، ولا موضعَ يقول لماذا. فدخلت
         * السجلَّ نوعًا خامسًا، وصار مفتاحُ التذييل واحدًا لكلّ نوعٍ باسمه.
         *
         * وما بقي في `InvoiceBranding` هو ما لا نظيرَ له في السجلّ: الاسمُ
         * المعروض على الورقة، ولغةُ طبعها، وملفُّ الشعار نفسِه.
         */
        $tpl = DocumentTemplates::settings($bid, self::PAPER_TYPE, $override);

        $brand = InvoiceBranding::paper($bid);

        /*
         * و«إظهار الشعار» يُطفئ الرسمَ لا يمحو الملفّ.
         *
         * من أطفأه يريد ورقةً بلا شعارٍ اليوم، ويبقى شعارُه محفوظًا لغدٍ
         * وللإيصال الحراريّ. وإسقاطُ المفتاح لا تفريغُه: `Paper::brand`
         * تقرأ الوجودَ لا القيمة.
         */
        if (! $tpl['show_logo']) {
            unset($brand['logo']);
        }

        /* ولقطةُ الورقة تسبق حالَ المتجر اليوم — انظر `Document\Snapshot` */
        $snapshot = Snapshot::of($invoice, $bid);

        /*
         * وبياناتُ البائع من اللقطة — **بمفاتيح هذه الورقة وحدها**.
         *
         * ═══ ولمَ `array_intersect_key` لا دمجٌ مطلق ═══
         *
         * لهذه الورقة سياسةٌ صريحةٌ يحرسها اختبار: **لا مفتاحَ عنوانٍ يبلغ
         * قالبَها أصلًا** — لا فارغًا ولا مطفأً. عنوانُ المبنى محفوظٌ في
         * صفّ المتجر ولا يُطبع على فاتورةٍ تمضي إلى جهة.
         *
         * ودمجٌ مطلق للّقطة يُدخل `address` من الباب الخلفيّ، فتخرج
         * الفواتيرُ القديمة بعنوانِ المبنى والجديدةُ بدونه.
         *
         * و`name` يبقى من `InvoiceBranding`: هو **الاسمُ المعروض** الذي
         * اختاره صاحبُ المحلّ لورقته، لا الاسمُ المسجَّل في صفّ المتجر.
         */
        if (($stamped = Snapshot::seller($snapshot)) !== []) {
            $brand = array_intersect_key(
                array_filter(Arr::except($stamped, ['name', 'address'])),
                $brand,
            ) + $brand;
        }

        return view(Version::views($options['version'] ?? null).'.customer-invoice', [
            'invoice' => $invoice,
            /*
             * وعملةُ الورقة من متجرها — لا ثلاثُ منازلَ و«ر.ع» مثبَّتتان.
             *
             * وكان القالبُ يكتبهما بيده، فورقةُ تاجرٍ في دبي تخرج بريالٍ
             * عمانيٍّ ومنزلةٍ زائدة. انظر `Support\Money`.
             */
            'currency' => Snapshot::currency($snapshot) ?: Money::of($bid),
            /*
             * ورموزُ التصميم وغلافُ الورقة — من `Document\Branding`.
             *
             * وبمعامل الخطّ نفسِه الذي يقرؤه `scale` أدناه: قيمتان لمعاملٍ
             * واحد تجعلان الجدولَ يكبر والترويسةَ تبقى.
             */
            'tokens' => Branding::tokens($bid, DocumentRenderer::scale((string) $tpl['font'])),
            'coverImage' => Branding::cover($bid),
            /* وفاتورةُ العميل ورقةُ A4 دائمًا — لا تُطبع على شريطٍ حراريّ */
            'paper' => PaperSize::A4,
            'paperUrl' => $options['paperUrl'] ?? '',
            /*
             * والترويسةُ من `InvoiceBranding` لا من `Demo::business`.
             *
             * الأولى تقرأ الاسمَ المعروض الذي اختاره صاحبُ المحلّ وتضمّن
             * شعارَه في الورقة نفسها، **ولا مفتاحَ عنوانٍ فيها أصلًا**.
             * والثانيةُ صفُّ المتجر كما هو في لوحة المنصّة — وأوّلُ من يضيف
             * إليها `address` يجعل عنوانَ المبنى يُطبع على كلّ فاتورة.
             */
            'business' => $brand,
            'headerNote' => $tpl['header'],
            'footerNote' => $tpl['footer'],
            'showNotes' => (bool) $tpl['show_notes'],
            'scale' => DocumentRenderer::scale((string) $tpl['font']),
            /*
             * والرقمُ الضريبيُّ شرطان: أن يكون المتجر مسجَّلًا، وأن يريده
             * صاحبُه على هذه الورقة. وإطفاؤه لا يُطفئ الضريبةَ نفسَها —
             * المبلغُ يبقى في الجدول، وإنّما يُرفع رقمُ التسجيل من الترويسة.
             */
            /* والرقمُ الضريبيُّ من اللقطة: ورقةُ يناير تحمل رقمَ يناير */
            'vatNumber' => Vat::enabled($bid) && $tpl['show_vat_no']
                ? (Snapshot::stamped($invoice)
                    ? Snapshot::vat($snapshot)
                    : Paper::vatNumber($bid))
                : '',
            'paid' => $paid,
            'outstanding' => $outstanding,
            'bank' => $bank,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);
    }

    /**
     * حسابُ السداد المطبوع في ذيل المعاينة.
     *
     * وهو الحسابُ الذي اختارته الشاشةُ إن اختارت — فمن بدّل الحسابَ يرى
     * الآيبانَ يتبدّل في ورقته. وإلّا فالأوّلُ كما تفعل الطباعة نفسُها،
     * حتى لا تَعِد المعاينةُ بذيلٍ لا يُطبع أو تكتم ذيلًا يُطبع.
     */
    private function previewBank(int $bid, int|string|null $accountId): ?BankAccount
    {
        $q = BankAccount::where('business_id', $bid);

        return $accountId
            ? ($q->clone()->whereKey($accountId)->first() ?? $q->orderBy('id')->first())
            : $q->orderBy('id')->first();
    }

    /**
     * «تخصيص التصميم» — شعارُ الورقة واسمُها ولغتُها وذيلُها.
     *
     * ═══ ولمَ من هذه الشاشة ═══
     *
     * صاحبُ المحلّ يرى ورقتَه أمامه فيقرّر أنّ الشعار ناقصٌ أو أنّ الاسم
     * ليس ما يريد. وإرسالُه إلى شاشة الإعدادات ليعود بعدها يعني أن يترك
     * فاتورةً نصفَ مكتوبة — فالمقبضُ حيث يُرى أثرُه.
     *
     * ═══ ولا مالكَ ثانيًا للشعار ═══
     *
     * العمودُ يُكتب من `InvoiceBranding::storeLogo` وحدها، تناديها هذه
     * و«شعار المتجر» في الإعدادات معًا. وبابان يكتبان عمودًا واحدًا بقاعدتين
     * يفترقان يومًا: يقبل أحدُهما ملفًّا يردّه الآخر.
     *
     * ═══ وهي إعدادُ متجرٍ لا فعلَ فاتورة ═══
     *
     * تُغيّر ما يُطبع على **كلّ** ورقةٍ قادمة — فلا تُمنح لمن مُنح كتابة
     * الفواتير وحدها. وحارسُها `allows('settings')` كحارس شعار المتجر
     * نفسِه، والشاشةُ تقرأ `may.brand` فلا تعرض بابًا يُردّ.
     */
    public function branding(Request $request)
    {
        abort_unless((bool) auth()->user()?->allows('settings'), 403);

        /*
         * وقواعدُ القالب مشتقّةٌ من السجلّ لا مكتوبةٌ هنا.
         *
         * `DocumentTemplates::rules` هي نفسُها التي يصادق بها محرّرُ القوالب.
         * وقاعدةٌ تُكتب في البابين تفترق — فيقبل أحدُهما تذييلًا يردّه الآخر
         * عن الحقل نفسِه.
         */
        $data = $request->validate(DocumentTemplates::rules(self::PAPER_TYPE) + [
            'display_name' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', Rule::in(InvoiceBranding::LANGUAGES)],
            /*
             * و`image` لا امتدادٌ يُقرأ من الاسم: ملفٌّ اسمُه `.png` وفيه
             * سكربتٌ يُخزَّن ثمّ يُقدَّم من القرص العامّ. والقاعدةُ تفتح
             * الصورةَ وتقرأ أبعادها فعلًا.
             */
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ], [
            'logo.image' => __('الشعار صورة — PNG أو JPG أو WEBP'),
            'logo.max' => __('أقصى حجمٍ للشعار ٢ ميغابايت'),
        ], [
            'display_name' => __('اسم المتجر في الفاتورة'),
            'header' => __('سطر تحت اسم المتجر'),
            'footer' => __('سطر أسفل الفاتورة'),
        ]);

        $bid = $this->bid();

        /*
         * والأعلامُ تُقرأ من الطلب لا من المصادَق — كما في `TemplateController`.
         *
         * `false` يصل الحفظَ سلسلةً فارغة فتُقرأ غيابًا لا إطفاءً، فيعود
         * العلمُ إلى افتراضيّه ويُطبع ما أخفاه صاحبُه.
         */
        foreach (array_keys($data) as $field) {
            if (str_starts_with($field, 'show_')) {
                $data[$field] = $request->boolean($field);
            }
        }

        InvoiceBranding::save($bid, $data);
        DocumentTemplates::save($bid, self::PAPER_TYPE, $data);
        InvoiceBranding::storeLogo(
            Business::findOrFail($bid),
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        Activity::log('settings', 'عدّل هويّة فاتورة العميل');

        return back()->with('toast', ['msg' => __('حُفظ تصميم الفاتورة'), 'type' => 'success']);
    }

    /**
     * العميلُ الذي تُفتح عليه الشاشة — يُضبط أو يُرفع.
     *
     * ولا يُحفظ رقمٌ لا صفَّ له: معرّفٌ من متجرٍ آخر يُردّ برسالةٍ على حقله،
     * لا يُكتب صامتًا ثمّ يُهمَل عند القراءة فيقول التنبيهُ «حُفظ» ولا يتغيّر
     * شيءٌ في الشاشة القادمة.
     */
    public function defaultCustomer(Request $request)
    {
        abort_unless((bool) auth()->user()?->allows('settings'), 403);

        $bid = $this->bid();

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('business_id', $bid)],
        ], [
            'customer_id.exists' => __('هذا العميل ليس من عملاء متجرك.'),
        ], ['customer_id' => __('العميل الافتراضي')]);

        Setting::updateOrCreate(
            ['business_id' => $bid, 'key' => InvoiceBranding::DEFAULT_CUSTOMER],
            ['value' => (string) ($data['customer_id'] ?? '')],
        );

        Activity::log('settings', blank($data['customer_id'] ?? null)
            ? 'رفع العميل الافتراضي لفواتير العملاء'
            : 'ضبط العميل الافتراضي لفواتير العملاء');

        return back()->with('toast', [
            'msg' => blank($data['customer_id'] ?? null)
                ? __('رُفع العميل الافتراضي')
                : __('حُفظ العميل الافتراضي'),
            'type' => 'success',
        ]);
    }

    /**
     * عميلٌ جديد من داخل شاشة الفاتورة.
     *
     * ولا يُحال إلى شاشة العملاء: من كتب خمسةَ بنودٍ ثمّ اكتشف أنّ الجهة ليست
     * مسجَّلة كان يفقد ما كتب. فالبابُ هنا، والعودةُ إلى الصفحة نفسها،
     * والعميلُ الجديد يُختار وحده.
     */
    public function storeCustomer(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => Customers::phoneRule($this->bid()),
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'commercial_registration' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'customer_type' => ['nullable', 'string', 'in:فرد,شركة,جهة حكومية'],
        ], [], ['name' => __('اسم العميل')]);

        $data['business_id'] = $this->bid();
        $data['customer_type'] = $data['customer_type'] ?? 'شركة';

        $customer = Customer::create(Customers::localizeName($data));
        Activity::log('created', 'أضاف عميلًا: '.$customer->name);

        return back()
            ->with('new_customer_id', $customer->id)
            ->with('toast', ['msg' => __('أُضيف العميل'), 'type' => 'success']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'po_number' => ['nullable', 'string', 'max:60'],
            'contract_number' => ['nullable', 'string', 'max:60'],
            'external_reference' => ['nullable', 'string', 'max:60'],
            'department' => ['nullable', 'string', 'max:120'],
            'cost_center' => ['nullable', 'string', 'max:60'],
            'attention_to' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // وما لا يُطبع: يُحفظ في عمودٍ آخر ولا يبلغ ورقةَ العميل
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'issue' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            // نسبةُ البند لقطةٌ تُحفظ في السطر — والإعفاءُ يُكتب صفرًا
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            /*
             * ووسيلةُ السداد **مطلوبة** — لا تسقط إلى «آجل» في صمت.
             *
             * كانت `nullable`، فطلبٌ بلا وسيلةٍ يُكتب ذمّةً على العميل ويُقال
             * «أُنشئت الفاتورة». ومن قبض المال نقدًا ثمّ نسي أن يختار خرج من
             * الشاشة بفاتورةٍ آجلة ومالٍ في الدرج لا يعرف به الدفتر — ولا
             * رسالةَ تُنبّهه. و«آجل» اختيارٌ يُقال لا صمتٌ يُفسَّر.
             */
            'payment_method' => ['required', 'string', Rule::in(CustomerInvoices::methods())],
            /*
             * والمقبوضُ قد يكون بعضَ الورقة لا كلَّها.
             *
             * شركةٌ تدفع أربعين من مئة عند التسليم والباقي بعد شهر — وهي
             * أكثرُ ما يقع في فواتير الجهات. وكان الخادمُ يسجّل الإجماليّ
             * دائمًا: فتُقفل ورقةٌ لم يُقبض ثمنُها كلُّه، ويُدين الدفترُ
             * الصندوقَ بستّين لم تدخله.
             *
             * والفراغُ يعني الكلّ — فلا ينكسر بابٌ لا يرسله.
             */
            'paid_amount' => ['nullable', 'numeric', 'gt:0'],
            /* وتاريخُ القبض قد يسبق كتابةَ الورقة — والفراغُ يعني تاريخَها */
            'payment_date' => ['nullable', 'date'],
            /* رقمُ الحوالة أو الشيك — يُطابَق به كشفُ الحساب */
            'payment_reference' => ['nullable', 'string', 'max:60'],
            /* وتاريخُ استحقاق الشيك — يُقبل ولا يُشترط، انظر `pay` */
            'cheque_due_at' => ['nullable', 'date'],
            /*
             * وملكيّةُ الحساب تُسأل هنا كي يقع الخطأ على حقله.
             *
             * `CustomerPayments::accountFor` ترمي على حسابٍ من متجرٍ آخر —
             * و`catch (RuntimeException)` أسفلُ كانت تكتب رسالتَها تحت
             * `items`، فيقرأ التاجرُ عطبًا في البنود وعطبُه في حقلٍ آخر.
             */
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')
                ->where('business_id', $this->bid())],
        ] + InvoiceAttachments::rules('attachments'), [
            'attachments.*.extensions' => __('الصيغ المدعومة: JPG، PNG، PDF، WEBP، HEIC.'),
            'attachments.*.max' => __('أقصى حجم 10 ميجابايت.'),
            'attachments.max' => __('أقصى عدد المرفقات ستة.'),
        ], [
            'customer_id' => __('العميل'),
            'items' => __('بنود الفاتورة'),
            'bank_account_id' => __('الحساب البنكي'),
            'payment_method' => __('طريقة الدفع'),
            'paid_amount' => __('المبلغ المدفوع'),
            'payment_date' => __('تاريخ الدفع'),
        ]);

        $customer = Customer::where('business_id', $this->bid())
            ->whereKey($data['customer_id'])->first();

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => __('هذا العميل ليس من عملاء متجرك.'),
            ]);
        }

        /*
         * واستحقاقٌ قبل تاريخ الورقة يجعلها متأخّرةً لحظةَ إصدارها.
         *
         * والشاشةُ تحسبه، فلا يقع إلّا بكتابةٍ يدويّة أو بطلبٍ مصنوع. ومن
         * كتبه يستحقّ أن يُقال له، لا أن يجد ورقتَه حمراءَ في القائمة ويصلها
         * تذكيرُ السداد المجدول في صباح يومها.
         */
        if (! empty($data['due_at']) && ! empty($data['issued_at'])
            && strtotime($data['due_at']) < strtotime($data['issued_at'])) {
            throw ValidationException::withMessages([
                'due_at' => __('تاريخ الاستحقاق قبل تاريخ الفاتورة.'),
            ]);
        }

        /*
         * ونسبةٌ فارغةٌ تُحذف لا تُقرأ صفرًا.
         *
         * `compute` تقرأ وجودَ المفتاح لا قيمتَه — لأنّ الصفر إعفاءٌ مقصود.
         * فبندٌ يصل بـ`tax_rate: null` كان يُعفى من الضريبة في صمت.
         */
        $data['items'] = array_map(function (array $line) {
            if (($line['tax_rate'] ?? null) === null) {
                unset($line['tax_rate']);
            }

            return $line;
        }, $data['items']);

        $method = $data['payment_method'];

        /*
         * والمقبوضُ لا يُسجَّل على مسودّة.
         *
         * التحصيلُ يُخصَّص على فاتورةٍ **صادرة** — والمسودّةُ ورقةٌ لم تُسلَّم
         * بعد ولا ذمّةَ لها. فلو قُبل هنا لبقي المبلغ معلّقًا بلا ما يقابله،
         * أو ذهب إلى فاتورةٍ أخرى في التوزيع التلقائيّ.
         */
        /*
         * ═══ وهذا بابُ إصدارٍ ثالث — أضيقُ من أن يُرى ═══
         *
         * «احفظ وأصدر» في شاشة الإنشاء تكتب الورقة وتُصدرها في طلبٍ واحد،
         * فلا تمرّ على `issue()` ولا على حارسه. فمن رُدَّ عن زرّ «إصدار» كان
         * يبلغ الفعلَ نفسَه بمربّع اختيارٍ في النموذج — وحارسٌ يُلتفّ حوله
         * ليس حارسًا.
         *
         * ═══ وقبل `try` لا داخله ═══
         *
         * `HttpException` تَرِث `RuntimeException` — و`catch (RuntimeException)`
         * أسفلُ كانت تبتلع الردَّ ٤٠٣ وتحوّله إلى خطأ تحقّقٍ برسالةٍ فارغة.
         * فيُردّ الطلبُ بشيءٍ لا يشبه المنع ولا يقول شيئًا. والإذنُ يُسأل قبل
         * العمل لا في وسطه.
         */
        abort_if(
            $request->boolean('issue') && ! auth()->user()?->may(Permissions::CUSTOMER_INVOICE_ISSUE),
            403,
        );

        if ($method !== CustomerInvoices::CREDIT && ! $request->boolean('issue')) {
            throw ValidationException::withMessages([
                'payment_method' => __('التحصيل يُسجَّل على فاتورةٍ صادرة — أصدر الفاتورة، أو احفظها مسودّةً آجلة.'),
            ]);
        }

        /*
         * ═══ الورقةُ وإيصالُها يقعان معًا أو لا يقع أحدُهما ═══
         *
         * كانت ثلاثَ معاملاتٍ متتابعة: تُكتب الفاتورة، ثمّ تُصدَر، ثمّ
         * يُسجَّل التحصيل. فسقوطُ الثالثة — حسابٌ بنكيٌّ من متجرٍ آخر، أو
         * قفلٌ لم يُظفر به — كان يترك **فاتورةً صادرةً بذمّةٍ في الدفتر
         * ومالًا في يد التاجر لا إيصالَ له**. ويقرأ التاجرُ رسالةَ خطأ
         * فيعيد الضغط، فتُكتب ورقةٌ ثانية.
         *
         * والمرفقاتُ خارج المعاملة لأنّ القرصَ لا يُلغى بالتراجع: تُرفَع
         * أوّلًا، وتُمحى بيدنا إن سقطت المعاملة — انظر `discard`.
         */
        $uploaded = [];

        try {
            $invoice = DB::transaction(function () use ($request, $customer, $data, $method, &$uploaded) {
                $invoice = CustomerInvoices::create($this->bid(), $customer, $data, $data['items'], auth()->id());

                /*
                 * والمرفقاتُ بعد الورقة لا قبلها: مرفقٌ بلا فاتورةٍ يشير إليه
                 * ملفٌّ على القرص لا يقرؤه شيء.
                 */
                foreach ($request->file('attachments') ?? [] as $file) {
                    $uploaded[] = InvoiceAttachments::store($invoice, $file, auth()->id())->path;
                }

                /*
                 * والمُصدَرةُ تُلتقط: `issue` تقرأ الصفَّ تحت قفلٍ وتردّ نسختَه،
                 * فالرقمُ يُكتب هناك. وإهمالُ ما تردّه يترك في اليد نسخةً بلا
                 * رقم — فيقول التنبيهُ «أُنشئت الفاتورة » وينتهي عند الفراغ.
                 */
                if ($request->boolean('issue')) {
                    $invoice = CustomerInvoices::issue($invoice, auth()->id());
                }

                /*
                 * وفاتورةٌ تُسدَّد لحظةَ إصدارها تُسجَّل تحصيلًا كأيّ تحصيل — لا
                 * تُوسَم «مدفوعة» في عمود. مسارٌ ثانٍ للسداد يعني رصيدَ صندوقٍ لا
                 * يعرف به الدفتر، وفاتورةً تقول مدفوعةً بلا إيصالٍ يقابلها.
                 */
                if ($method !== CustomerInvoices::CREDIT) {
                    $this->collect($invoice, $customer, $method, $data);
                }

                return $invoice;
            });
        } catch (RuntimeException $e) {
            InvoiceAttachments::discard($uploaded);

            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        } catch (\Throwable $e) {
            InvoiceAttachments::discard($uploaded);

            throw $e;
        }

        return redirect()->route('admin.customerInvoices.show', $invoice->id)->with('toast', [
            // ومسودّةٌ لا رقمَ لها: لا يُقال «أُنشئت الفاتورة » وينتهي عند فراغ
            'msg' => $invoice->number
                ? __('أُنشئت الفاتورة :n', ['n' => $invoice->number])
                : __('حُفظت مسودّة الفاتورة'),
            'type' => 'success',
        ]);
    }

    /**
     * إيصالُ التحصيل المرافقُ للإصدار.
     *
     * والمبلغُ يُقاس على **إجماليّ الخادم** لا على ما أرسلته الشاشة: من
     * يفتح أدوات المتصفّح يستطيع أن يرسل «دفعتُ ألفًا» على ورقةٍ بعشرة،
     * فيُقيَّد في الصندوق ألفٌ لم يدخله ويبقى للعميل رصيدٌ دائنٌ مخترَع.
     *
     * وما زاد يُردّ برسالةٍ على حقله لا يُقصّ في صمت: من كتب ٤٠٠ وهو يقصد
     * ٤٠ يستحقّ أن يُقال له، لا أن تُقفل ورقتُه بأربعين ويظنّ الباقيَ محصَّلًا.
     *
     * @param  array<string, mixed>  $data
     */
    private function collect(CustomerInvoice $invoice, Customer $customer, string $method, array $data): void
    {
        $total = round((float) $invoice->total, 3);
        $amount = isset($data['paid_amount']) ? round((float) $data['paid_amount'], 3) : $total;

        if ($amount > $total) {
            throw ValidationException::withMessages([
                'paid_amount' => __('المبلغ المدفوع أكبر من إجمالي الفاتورة :n.', ['n' => number_format($total, 3)]),
            ]);
        }

        CustomerPayments::record(
            $this->bid(),
            $customer,
            $amount,
            [
                'method' => $method,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                /* وتاريخُ القبض إن كُتب — وإلّا تاريخُ الورقة كما كان */
                'occurred_at' => $data['payment_date'] ?? $invoice->issued_at,
                'external_reference' => $data['payment_reference'] ?? null,
                /* ويُمرَّر ولو كانت الوسيلةُ غيرَ شيك: `record` تُهمله حينئذٍ */
                'cheque_due_at' => $data['cheque_due_at'] ?? null,
            ],
            /* والتخصيصُ صريحٌ على هذه الورقة: التلقائيُّ قد يذهب إلى أقدمَ منها */
            [$invoice->id => $amount],
            auth()->id(),
        );
    }

    /**
     * إرفاقُ مستندٍ بفاتورةٍ قائمة — أمرُ شراءٍ أو عقدٌ أو طلبٌ موقَّع.
     *
     * ويُقبل على الصادرة كما على المسودّة: أمرُ شراء الوزارة قد يصل بعد
     * إصدار الفاتورة، ومنعُ إرفاقه يجعل المستند يعيش في بريدِ أحدهم.
     */
    public function attach(Request $request, int|string $id)
    {
        $invoice = $this->find($id);

        $request->validate(InvoiceAttachments::rules('attachments') + [
            'attachments' => ['required', 'array', 'min:1', 'max:'.InvoiceAttachments::MAX_FILES],
        ], [
            'attachments.*.extensions' => __('الصيغ المدعومة: JPG، PNG، PDF، WEBP، HEIC.'),
            'attachments.*.max' => __('أقصى حجم 10 ميجابايت.'),
        ], ['attachments' => __('المرفقات')]);

        foreach ($request->file('attachments') as $file) {
            InvoiceAttachments::store($invoice, $file, auth()->id());
        }

        return back()->with('toast', ['msg' => __('أُرفقت المستندات'), 'type' => 'success']);
    }

    /**
     * حذفُ مرفق — الصفُّ وملفُّه معًا.
     *
     * والمرفقُ يُسأل عن ورقته: رقمُ مرفقٍ من فاتورةٍ أخرى لا يُحذف من
     * عنوان هذه.
     */
    public function detach(int|string $id, int|string $attachment)
    {
        $invoice = $this->find($id);

        $row = CustomerInvoiceAttachment::where('business_id', $this->bid())
            ->where('customer_invoice_id', $invoice->id)
            ->findOrFail($attachment);

        InvoiceAttachments::remove($row);

        return back()->with('toast', ['msg' => __('حُذف المرفق'), 'type' => 'warning']);
    }

    public function issue(int|string $id)
    {
        // الإصدارُ يولد الذمّة ويكتب القيد — فعلٌ يُمنح باسمه لا بفتح القسم
        abort_if(! auth()->user()?->may(Permissions::CUSTOMER_INVOICE_ISSUE), 403);

        try {
            $invoice = CustomerInvoices::issue($this->find($id), auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('صدرت الفاتورة :n', ['n' => $invoice->number]), 'type' => 'success',
        ]);
    }

    public function cancel(Request $request, int|string $id)
    {
        // وعكسُ قيدٍ وُقّع ليس تصحيحَ خطأٍ مطبعيّ
        abort_if(! auth()->user()?->may(Permissions::CUSTOMER_INVOICE_CANCEL), 403);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']], [], [
            'reason' => __('سبب الإلغاء'),
        ]);

        try {
            CustomerInvoices::cancel($this->find($id), $data['reason'], auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُلغيت الفاتورة'), 'type' => 'success']);
    }

    public function creditNote(Request $request, int|string $id)
    {
        // والإشعارُ الدائن يُنقص دَينًا قائمًا — فعلٌ يُمنح باسمه
        abort_if(! auth()->user()?->may(Permissions::CUSTOMER_CREDIT_NOTE), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        try {
            $note = CustomerInvoices::creditNote(
                $this->find($id),
                (float) $data['amount'],
                (float) ($data['tax_amount'] ?? 0),
                $data['reason'],
                auth()->id(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('أُصدر إشعار الدائن :n', ['n' => $note->number]), 'type' => 'success',
        ]);
    }

    /** تسجيلُ تحصيل — من الفاتورة أو على حساب العميل */
    public function pay(Request $request)
    {
        // والتحصيلُ مالٌ يدخل الصندوق — غيرُ الإصدار وغيرُ فتح الشاشة
        abort_if(! auth()->user()?->may(Permissions::CUSTOMER_PAYMENT_CREATE), 403);

        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            /*
             * ووسيلةٌ لا يعرفها النظام تُردّ — ولا تُقرأ نقدًا.
             *
             * كانت القاعدة `['required','string']` بلا قائمة، و`record` تسقط
             * إلى «نقدي» عند ما لا تعرفه. فطلبٌ يحمل وسيلةً مكتوبةً بحرفٍ
             * زائد — أو باسمٍ من شاشةٍ قديمة — كان **يدخل المالَ الصندوقَ**
             * ويكتب قيدَه على `cash`، والمالُ في البنك. ولا خطأَ يُقال: يقول
             * التنبيهُ «سُجّل التحصيل» ويقول الإيصالُ «نقدي».
             */
            'method' => ['required', 'string', Rule::in(CustomerPayments::METHODS)],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')
                ->where('business_id', $this->bid())],
            'occurred_at' => ['nullable', 'date'],
            /*
             * وتاريخُ استحقاق الشيك — يُقبل ولا يُشترط.
             *
             * شيكٌ بلا تاريخٍ مكتوبٍ يبقى «تحت التحصيل» بلا موعدٍ يُرتَّب به،
             * وهو أصدقُ من موعدٍ مخترَع. ومن كتبه رأى ورقتَه.
             */
            'cheque_due_at' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
            'customer_invoice_id' => ['nullable', 'integer'],
        ], [], [
            'amount' => __('المبلغ'), 'method' => __('وسيلة الدفع'),
            'bank_account_id' => __('الحساب البنكي'),
        ]);

        $customer = Customer::where('business_id', $this->bid())
            ->whereKey($data['customer_id'])->first();

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => __('هذا العميل ليس من عملاء متجرك.'),
            ]);
        }

        $allocations = [];
        if (! empty($data['customer_invoice_id'])) {
            $allocations = [(int) $data['customer_invoice_id'] => (float) $data['amount']];
        }

        try {
            $payment = CustomerPayments::record(
                $this->bid(), $customer, (float) $data['amount'], $data, $allocations, auth()->id()
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        /*
         * ونتيجةُ التوزيع تُقال، لا تُطبَّق في صمت.
         *
         * دفعةٌ على الحساب تُوزَّع على الأقدم فالأقدم — ومن لا يرى أين ذهبت
         * يكتشف بعد شهرٍ أنّها سدّت غيرَ ما قصد.
         */
        $spread = $payment->allocations->map(fn ($a) => $a->invoice?->number.': '.$a->amount)->join('، ');
        $left = $payment->unallocated();

        return back()->with('toast', [
            'msg' => __('سُجّل التحصيل :n', ['n' => $payment->number])
                .($spread !== '' ? ' — '.$spread : '')
                .($left > 0 ? ' — '.__('وبقي :n رصيدًا للعميل', ['n' => $left]) : ''),
            'type' => 'success',
        ]);
    }

    /**
     * تذكيرٌ بالسداد عبر واتساب.
     *
     * ولا يُبنى موصلٌ ثانٍ للمزوّد: بنيةُ الإرسال التلقائيّ القائمة مبنيّةٌ
     * على الطلبات وأحداثِ حالتها، وحشرُ الفاتورة فيها يعني تعديلَ مسارٍ حيٍّ
     * يرسل إلى زبائن اليوم.
     *
     * فالتذكيرُ يدويٌّ صريح: النصُّ يُكتب في الخادم — الاسمُ والرقمُ والباقي
     * وتاريخُ الاستحقاق — ويُفتح على واتساب التاجر ليرسله بنفسه. وهذا يعمل
     * اليوم بلا وعدٍ بأتمتةٍ غير مبنيّة.
     */
    public function remind(int|string $id)
    {
        $invoice = $this->find($id);

        /*
         * ورسالتا الردّ تُرسمان: `withErrors` تكتب في `errors`، والشاشةُ لا
         * ترسم حقلًا اسمه `remind` — فكان الزرُّ يُضغط ولا يقع شيءٌ ولا يُقال
         * لماذا. بابٌ يُفتح على صمتٍ أسوأ من بابٍ لا يُفتح.
         */
        if ($invoice->outstanding() <= 0) {
            return back()->with('toast', [
                'msg' => __('لا مبلغ مستحقًّا على هذه الفاتورة.'), 'type' => 'danger',
            ]);
        }

        $phone = WhatsAppPhone::normalize(
            $invoice->customer?->contact_phone ?: $invoice->customer?->phone
        );

        if (! $phone) {
            return back()->with('toast', [
                'msg' => __('لا رقم واتساب لهذا العميل — أضِفه في صفحته.'), 'type' => 'danger',
            ]);
        }

        $text = __(':shop — تذكير بفاتورة :number. المبلغ المستحق :amount:due', [
            'shop' => Demo::businessName(),
            'number' => $invoice->number,
            'amount' => number_format($invoice->outstanding(), 3),
            'due' => $invoice->due_at ? '، '.__('تاريخ الاستحقاق ').$invoice->due_at->format('Y-m-d') : '',
        ]);

        Activity::log('updated', 'أعدّ تذكير سداد للفاتورة '.$invoice->number, [
            'subject_id' => $invoice->id, 'subject_type' => 'customer_invoice',
        ]);

        return back()->with('toast', [
            'msg' => __('افتح واتساب وأرسل التذكير'),
            'type' => 'success',
            'link' => ['url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($text), 'label' => __('فتح واتساب')],
        ]);
    }

    public function cancelPayment(Request $request, int|string $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $payment = CustomerPayment::where('business_id', $this->bid())->whereKey($id)->firstOrFail();
        CustomerPayments::cancel($payment, $data['reason'], auth()->id());

        return back()->with('toast', ['msg' => __('أُلغي التحصيل'), 'type' => 'success']);
    }
}
