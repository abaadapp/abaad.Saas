<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Activity;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Customers;
use App\Support\Demo;
use App\Support\Receivables;
use App\Support\Search;
use App\Support\Vat;
use App\Support\WhatsAppPhone;
use Illuminate\Http\Request;
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

    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /** الفاتورةُ من متجر الطالب لا من رقمٍ في الرابط */
    private function find(int|string $id): CustomerInvoice
    {
        return CustomerInvoice::where('business_id', $this->bid())
            ->whereKey($id)->with('items', 'customer')->firstOrFail();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $rows = CustomerInvoice::where('business_id', $bid)->with('customer')
            // والمعاملُ من `Search::like` لا مكتوبًا بيده: `like` على Postgres
            // حسّاسٌ لحالة الأحرف و`ilike` ليس كذلك — ومن كتبه بيده أصاب في
            // شاشةٍ وأخطأ في أخرى
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('number', Search::like(), "%{$s}%")
                    ->orWhere('po_number', Search::like(), "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', Search::like(), "%{$s}%"))
            ))
            ->when($request->integer('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->date('from'), fn ($q, $d) => $q->whereDate('issued_at', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->whereDate('issued_at', '<=', $d))
            ->orderByDesc('id')->limit(300)->get();

        // و«المتأخّرة» تُرشَّح بعد الاشتقاق لا بعمودٍ مخزَّن يحتاج مهمّةً ليليّة
        if ($request->boolean('overdue')) {
            $rows = $rows->filter(fn ($i) => $i->daysOverdue() > 0)->values();
        }

        return Inertia::render('Admin/CustomerInvoices/Index', [
            'invoices' => $rows->map(fn ($i) => $this->row($i))->all(),
            'filters' => $request->only('q', 'customer_id', 'status', 'from', 'to', 'overdue'),
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

    public function show(int|string $id): Response
    {
        $invoice = $this->find($id);

        return Inertia::render('Admin/CustomerInvoices/Show', [
            'invoice' => $this->row($invoice) + [
                'subtotal' => (float) $invoice->subtotal,
                'discount_total' => (float) $invoice->discount_total,
                'tax_total' => (float) $invoice->tax_total,
                'notes' => $invoice->notes,
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
            /*
             * ورقمُ الفاتورة معاينةٌ لا حجز: التسلسل يُقطع لحظةَ الحفظ تحت
             * قفل. وعرضُه هنا يُطمئن من يكتب الرقم على أمر شراء، ولا يُرسَل
             * من الواجهة بحال.
             */
            'next_number' => CustomerInvoices::nextNumber($bid),
            'tax_rate' => Vat::enabled($bid)
                ? (float) (Setting::where('business_id', $bid)->where('key', 'vat_rate')->value('value') ?? 5)
                : 0.0,
            'today' => now()->toDateString(),
            'methods' => ['آجل', 'نقدي', 'بطاقة', 'تحويل'],
            // عميلٌ أُضيف من هذه الشاشة نفسها — يُختار فور العودة إليها
            'new_customer_id' => $request->session()->get('new_customer_id'),
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
            'issue' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            // نسبةُ البند لقطةٌ تُحفظ في السطر — والإعفاءُ يُكتب صفرًا
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['nullable', 'string', 'in:آجل,نقدي,بطاقة,تحويل'],
            'bank_account_id' => ['nullable', 'integer'],
        ], [], [
            'customer_id' => __('العميل'),
            'items' => __('بنود الفاتورة'),
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

        $method = $data['payment_method'] ?? 'آجل';

        /*
         * والمقبوضُ لا يُسجَّل على مسودّة.
         *
         * التحصيلُ يُخصَّص على فاتورةٍ **صادرة** — والمسودّةُ ورقةٌ لم تُسلَّم
         * بعد ولا ذمّةَ لها. فلو قُبل هنا لبقي المبلغ معلّقًا بلا ما يقابله،
         * أو ذهب إلى فاتورةٍ أخرى في التوزيع التلقائيّ.
         */
        if ($method !== 'آجل' && ! $request->boolean('issue')) {
            throw ValidationException::withMessages([
                'payment_method' => __('التحصيل يُسجَّل على فاتورةٍ صادرة — أصدر الفاتورة، أو احفظها مسودّةً آجلة.'),
            ]);
        }

        try {
            $invoice = CustomerInvoices::create($this->bid(), $customer, $data, $data['items'], auth()->id());

            if ($request->boolean('issue')) {
                CustomerInvoices::issue($invoice, auth()->id());
            }

            /*
             * وفاتورةٌ تُسدَّد لحظةَ إصدارها تُسجَّل تحصيلًا كأيّ تحصيل — لا
             * تُوسَم «مدفوعة» في عمود. مسارٌ ثانٍ للسداد يعني رصيدَ صندوقٍ لا
             * يعرف به الدفتر، وفاتورةً تقول مدفوعةً بلا إيصالٍ يقابلها.
             */
            if ($method !== 'آجل') {
                CustomerPayments::record(
                    $this->bid(), $customer, (float) $invoice->total,
                    ['method' => $method, 'bank_account_id' => $data['bank_account_id'] ?? null,
                        'occurred_at' => $invoice->issued_at],
                    [$invoice->id => (float) $invoice->total], auth()->id(),
                );
            }
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return redirect()->route('admin.customerInvoices.show', $invoice->id)->with('toast', [
            'msg' => __('أُنشئت الفاتورة :n', ['n' => $invoice->number]), 'type' => 'success',
        ]);
    }

    public function issue(int|string $id)
    {
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
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string'],
            'bank_account_id' => ['nullable', 'integer'],
            'occurred_at' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
            'customer_invoice_id' => ['nullable', 'integer'],
        ], [], ['amount' => __('المبلغ'), 'method' => __('وسيلة الدفع')]);

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
