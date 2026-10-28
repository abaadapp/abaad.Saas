<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\CustomerInvoiceRevision;
use App\Models\CustomerPaymentLink;
use App\Models\FinancialCorrection;
use App\Models\JournalEntry;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * تعديلُ فاتورة العميل بعد كتابتها — مسودّةً كانت أو صادرةً أو مدفوعة.
 *
 * ═══ حالُ الورقة لا يمنع التعديل — يحدّد ثمنَه ═══
 *
 *  • المسودّة: لا ذمّةَ ولا قيد. تُكتب ثانيةً كما كُتبت أوّلًا.
 *  • الصادرة: قيدُها يُعكس (`Ledger::reverse`) ويُرحَّل قيدٌ بالمبلغ الجديد —
 *    لا يُحذف قيدٌ ولا يُعاد تأريخه. والذمّةُ تتبع.
 *  • المدفوعة جزئيًّا أو كلًّا: كالصادرة، والمقبوضُ **لا يُمسّ**. سندُ القبض
 *    مستندٌ مستقلٌّ وقّعه العميل؛ فإن زاد الإجماليُّ صار الفرقُ باقيًا عليه،
 *    وإن نقص عمّا قُبض صار الفائضُ رصيدًا دائنًا له (`Receivables::credit`
 *    تقرؤه من باقٍ سالب) — ولا يُردّ مالٌ تلقائيًّا ولا يُعدَّل سند.
 *
 * ═══ وما لا يتغيّر بحال ═══
 *
 * رقمُ الورقة الصادرة، وسنداتُ القبض، ونتيجةُ Paymob، والإشعاراتُ الدائنة،
 * ولقطةُ هويّة المتجر يومَ الإصدار. وما يمسّ أحدَها يُردّ بسببه ويُدلّ على
 * طريقه (إلغاءُ التحصيل، إلغاءُ الرابط، إشعارُ دائن).
 *
 * ═══ والفاتورةُ المولودةُ من طلب ═══
 *
 * بنودُها ومبلغُها وتاريخُها وعميلُها من الطلب، وقيدُها قيدُ الطلب ومخزونُها
 * مخزونُه (`CustomerInvoices::post`). فتعديلُها هنا يُفرّق الورقةَ عن الطلب
 * والدفتر — فلا يُعدَّل منها إلّا ما لا مالَ فيه: المراجعُ والملاحظاتُ
 * والاستحقاق. ومالُها يُصحَّح بإشعار دائن كما يقول `OrderCorrection`.
 *
 * ═══ والمخزون ═══
 *
 * الفاتورةُ اليدويّةُ لا تُخرج شيئًا من الرفّ ولا تُقيّد تكلفة (انظر رأس
 * `CustomerInvoices`). فتعديلُ كمّيّتها لا يُرجع ما لم يُخصم ولا يخصم ما لم
 * يخرج. والمولودةُ من طلبٍ خرج مخزونُها مع الطلب ولا تُعدَّل كمّيّاتُها هنا.
 * فلا أثرَ مخزنيَّ لأيّ تعديلٍ من هذا الباب — ويُقال ذلك في المعاينة.
 *
 * ═══ والفترات ═══
 *
 * قاعدةُ `BooksPeriod` نفسُها: قيدٌ في الشهر الجاري يُصحَّح بيومه، وما عبر حدَّ
 * شهرٍ أو وقع في فترةٍ مقفلة لا يُصحَّح إلّا باختيارٍ صريحٍ وسببٍ مكتوب —
 * «في الفترة الحاليّة»، أو «بتاريخ القيد» لصاحب النشاط في فترةٍ مفتوحةٍ
 * معلومة. والمقفلُ لا يُكتب فيه شيء: `Ledger::post` يسأل في كلّ حال.
 */
final class CustomerInvoiceEdits
{
    /** حقولٌ لا مالَ فيها — تُعدَّل في كلّ ورقةٍ غير ملغاة، والمولودةُ من طلبٍ معها */
    public const PLAIN = [
        'due_at', 'payment_terms_days', 'po_number', 'contract_number', 'external_reference',
        'department', 'cost_center', 'attention_to', 'notes', 'internal_notes',
    ];

    /** وما يمسّ الذمّةَ أو الدفترَ أو صاحبَ الدَّين */
    public const BOUND = ['customer_id', 'issued_at', 'branch_id'];

    /** موضعُ التصحيح في الدفتر حين لا يعبر حدًّا */
    public const SAME = 'same';

    /** أطولُ سبب — يُكتب في وصف القيد وسطرِ النشاط، وكلاهما ٢٥٥ */
    public const REASON_MAX = BooksPeriod::REASON_MAX;

    /** ما يُسمّى في الشاشة والسجلّ لكلّ حقل */
    public const LABELS = [
        'customer_id' => 'العميل',
        'issued_at' => 'تاريخ الفاتورة',
        'due_at' => 'تاريخ الاستحقاق',
        'payment_terms_days' => 'شروط الدفع (أيام)',
        'branch_id' => 'الفرع',
        'po_number' => 'مرجع العميل (أمر الشراء)',
        'contract_number' => 'رقم العقد',
        'external_reference' => 'رقم المرجع',
        'department' => 'القسم / الإدارة',
        'cost_center' => 'مركز التكلفة',
        'attention_to' => 'موجه إلى / عناية',
        'notes' => 'ملاحظات للعميل',
        'internal_notes' => 'ملاحظات داخلية',
    ];

    /** حالُ الورقة ساعةَ تُعدَّل — ومنه الفعلُ الذي يُسأل */
    public static function kind(CustomerInvoice $invoice): string
    {
        if ($invoice->status === CustomerInvoice::DRAFT) {
            return CustomerInvoiceRevision::DRAFT;
        }

        return $invoice->paidTotal() > 0 ? CustomerInvoiceRevision::PAID : CustomerInvoiceRevision::ISSUED;
    }

    public static function actionFor(string $kind): string
    {
        return match ($kind) {
            CustomerInvoiceRevision::DRAFT => Permissions::CUSTOMER_INVOICE_EDIT_DRAFT,
            CustomerInvoiceRevision::PAID => Permissions::CUSTOMER_INVOICE_EDIT_PAID,
            default => Permissions::CUSTOMER_INVOICE_EDIT_ISSUED,
        };
    }

    /** أيفتح هذا المستخدمُ شاشةَ التعديل لهذه الورقة؟ — تسأله الشاشةُ والمسار معًا */
    public static function mayOpen(?User $user, CustomerInvoice $invoice): bool
    {
        return $user !== null
            && $invoice->status !== CustomerInvoice::CANCELLED
            && $user->may(self::actionFor(self::kind($invoice)));
    }

    /**
     * قواعدُ النموذج — وبنودُه: القائمُ بمعرّفه، والجديدُ كبند الإنشاء.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:0'],
            'edit_token' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:'.self::REASON_MAX],
            'period_mode' => ['nullable', 'string', 'in:'.implode(',', BooksPeriod::MODES)],
            'customer_id' => ['required', 'integer'],
            'issued_at' => ['required', 'date'],
            'due_at' => ['nullable', 'date'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'branch_id' => ['nullable', 'integer'],
            'po_number' => ['nullable', 'string', 'max:60'],
            'contract_number' => ['nullable', 'string', 'max:60'],
            'external_reference' => ['nullable', 'string', 'max:60'],
            'department' => ['nullable', 'string', 'max:120'],
            'cost_center' => ['nullable', 'string', 'max:60'],
            'attention_to' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            // والبنودُ قد لا تُرسَل: الغيابُ «كما هي» — وهو حالُ الورقة المولودة من طلب
            'items' => ['nullable', 'array', 'min:1', 'max:1000'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.addon_id' => ['nullable', 'integer', 'prohibits:items.*.product_id'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ...InvoiceLineDetails::rules(),
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'customer_id' => __('العميل'),
            'issued_at' => __('تاريخ الفاتورة'),
            'due_at' => __('تاريخ الاستحقاق'),
            'items' => __('بنود الفاتورة'),
            'reason' => __('سبب التعديل'),
            'period_mode' => __('موضع التصحيح'),
            'branch_id' => __('الفرع'),
            'edit_token' => __('رمز النموذج'),
            'expected_revision' => __('نسخة الفاتورة'),
        ];
    }

    /**
     * الورقةُ كما هي — رأسًا وبنودًا ومالًا. تُكتب في السجلّ قبل التعديل وبعده.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(CustomerInvoice $invoice): array
    {
        $invoice->loadMissing('items');

        return [
            'number' => $invoice->number,
            'status' => $invoice->status,
            'customer_id' => $invoice->customer_id,
            'customer_name' => $invoice->customer_name,
            'issued_at' => optional($invoice->issued_at)->toDateString(),
            'due_at' => optional($invoice->due_at)->toDateString(),
            'payment_terms_days' => $invoice->payment_terms_days,
            'branch_id' => $invoice->branch_id,
            'po_number' => $invoice->po_number,
            'contract_number' => $invoice->contract_number,
            'external_reference' => $invoice->external_reference,
            'department' => $invoice->department,
            'cost_center' => $invoice->cost_center,
            'attention_to' => $invoice->attention_to,
            'notes' => $invoice->notes,
            'internal_notes' => $invoice->internal_notes,
            'subtotal' => round((float) $invoice->subtotal, 3),
            'discount_total' => round((float) $invoice->discount_total, 3),
            'tax_total' => round((float) $invoice->tax_total, 3),
            'total' => round((float) $invoice->total, 3),
            'items' => $invoice->items->map(fn (CustomerInvoiceItem $i) => [
                'id' => $i->id,
                'parent_item_id' => $i->parent_item_id,
                'product_id' => $i->product_id,
                'addon_id' => $i->addon_id,
                'order_item_id' => $i->order_item_id,
                'description' => $i->description,
                'quantity' => round((float) $i->quantity, 3),
                'unit_price' => round((float) $i->unit_price, 3),
                'discount' => round((float) $i->discount, 3),
                'tax_rate' => round((float) $i->tax_rate, 3),
                'tax_amount' => round((float) $i->tax_amount, 3),
                'line_total' => round((float) $i->line_total, 3),
                'details' => $i->details,
            ])->values()->all(),
        ];
    }

    /**
     * قاعدةُ ضريبة الورقة يومَ صدرت: شاملةٌ أم مضافة — تُقرأ من بنودها.
     *
     * لا عمودَ يحفظها، والإعدادُ يتبدّل. فتُعاد البنودُ المحفوظةُ بالقاعدتين،
     * وأيّتُهما أعادت مجاميعَها المحفوظة فهي قاعدتُها. وحين تتّفقان (لا ضريبة)
     * فلا فرق. وحين لا تُعيدها أيٌّ منهما — ورقةٌ قديمةٌ أو مولودةٌ من طلبٍ
     * بأجرة توصيل — فلا تُعرف قاعدتُها: `null`، ولا يُعاد حسابُ مالها هنا.
     */
    public static function inclusiveOf(CustomerInvoice $invoice): ?bool
    {
        $invoice->loadMissing('items');

        $lines = $invoice->items->map(fn (CustomerInvoiceItem $i) => [
            'quantity' => (float) $i->quantity,
            'unit_price' => (float) $i->unit_price,
            'discount' => (float) $i->discount,
            'tax_rate' => (float) $i->tax_rate,
        ])->all();

        $matches = fn (bool $inclusive) => (function () use ($invoice, $lines, $inclusive) {
            $t = CustomerInvoices::compute((int) $invoice->business_id, $lines, null, $inclusive);

            return abs($t['total'] - (float) $invoice->total) < 0.0005
                && abs($t['tax'] - (float) $invoice->tax_total) < 0.0005;
        })();

        $current = Vat::inclusive((int) $invoice->business_id);

        if ($matches($current)) {
            return $current;
        }

        return $matches(! $current) ? ! $current : null;
    }

    /**
     * ما سيقع لو حُفظ هذا النموذج — بلا أن يُكتب شيء.
     *
     * تقرؤه المعاينةُ قبل الحفظ، ويقرؤه الحفظُ نفسُه تحت القفل: فما يُرى هو ما
     * يقع، لا حسابان يفترقان.
     *
     * @param  array<string, mixed>  $input  ما مرّ من `rules()`
     * @return array<string, mixed>
     */
    public static function plan(CustomerInvoice $invoice, array $input, ?User $user, ?FinancialCorrection $approving = null): array
    {
        $bid = (int) $invoice->business_id;
        $invoice->loadMissing('items', 'orders');
        $kind = self::kind($invoice);
        $blockers = [];

        if ($invoice->status === CustomerInvoice::CANCELLED) {
            throw new RuntimeException(__('الفاتورة ملغاة — لا تُعدَّل.'));
        }

        $fromOrder = $invoice->orders->isNotEmpty();

        /* ═══ العميل ═══ */
        $customer = Customer::withTrashed()->where('business_id', $bid)->whereKey($input['customer_id'] ?? 0)->first();
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => __('هذا العميل ليس من عملاء متجرك.')]);
        }
        $customerChanged = (int) $customer->id !== (int) $invoice->customer_id;

        /* ═══ الفرع ═══ */
        $branchId = filled($input['branch_id'] ?? null) ? (int) $input['branch_id'] : null;
        if ($branchId !== null && ! Branch::where('business_id', $bid)->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => __('هذا الفرع ليس من فروع متجرك.')]);
        }
        $branchChanged = $branchId !== ($invoice->branch_id !== null ? (int) $invoice->branch_id : null);

        /* ═══ التواريخ ═══ */
        $issued = Carbon::parse($input['issued_at'])->startOfDay();
        $oldIssued = $invoice->issued_at ? Carbon::parse($invoice->issued_at)->startOfDay() : $issued->copy();
        $dateChanged = $issued->toDateString() !== $oldIssued->toDateString();

        $due = filled($input['due_at'] ?? null) ? Carbon::parse($input['due_at'])->toDateString() : null;
        if ($due !== null && $due < $issued->toDateString()) {
            throw ValidationException::withMessages(['due_at' => __('تاريخ الاستحقاق قبل تاريخ الفاتورة.')]);
        }

        /* ═══ البنود ═══ */
        $lines = self::lines($invoice, $input['items'] ?? null);
        $itemsChanged = $lines['changed'];

        // والمسودّةُ بقاعدة اليوم كالإنشاء — والصادرةُ بقاعدة يوم صدورها
        $inclusive = ! $itemsChanged ? null
            : ($kind === CustomerInvoiceRevision::DRAFT ? Vat::inclusive($bid) : self::inclusiveOf($invoice));

        $totals = $itemsChanged
            ? CustomerInvoices::compute($bid, $lines['compute'], null, $inclusive ?? Vat::inclusive($bid))
            : null;

        $newTotal = $totals ? $totals['total'] : round((float) $invoice->total, 3);
        $newTax = $totals ? $totals['tax'] : round((float) $invoice->tax_total, 3);
        $moneyChanged = $totals !== null && (
            abs($totals['total'] - (float) $invoice->total) >= 0.0005
            || abs($totals['tax'] - (float) $invoice->tax_total) >= 0.0005
            || abs($totals['subtotal'] - (float) $invoice->subtotal) >= 0.0005
            || abs($totals['discount'] - (float) $invoice->discount_total) >= 0.0005
        );

        /* ═══ ما تغيّر في الرأس ═══ */
        $header = self::header($input, $issued, $due, $branchId, $customer);
        $changes = [];
        foreach ([...self::BOUND, ...self::PLAIN] as $field) {
            $before = self::scalar($field === 'issued_at' ? $oldIssued->toDateString() : ($field === 'due_at' ? optional($invoice->due_at)->toDateString() : $invoice->{$field}));
            $after = self::scalar($header[$field]);

            if ($before !== $after) {
                $changes[] = ['field' => $field, 'label' => __(self::LABELS[$field]), 'before' => self::shown($field, $before, $bid), 'after' => self::shown($field, $after, $bid)];
            }
        }

        $paid = $invoice->status === CustomerInvoice::DRAFT ? 0.0 : $invoice->paidTotal();
        $credited = $invoice->status === CustomerInvoice::DRAFT ? 0.0 : $invoice->creditedTotal();

        /* ═══ ما يمنع ═══ */
        if ($fromOrder && ($itemsChanged || $customerChanged || $dateChanged || $branchChanged)) {
            $blockers[] = __('هذه الفاتورة مولودة من طلب: بنودها ومبلغها وتاريخها وعميلها وفرعها من الطلب وقيده ومخزونه. صحّح مالها بإشعار دائن أو بتصحيح الطلب نفسه — ويبقى تعديل المراجع والملاحظات والاستحقاق هنا.');
        }

        if ($itemsChanged && $kind !== CustomerInvoiceRevision::DRAFT && $inclusive === null) {
            $blockers[] = __('تعذّر معرفة قاعدة ضريبة هذه الفاتورة من بنودها (شاملة أم مضافة) — لا يُعاد حساب مالها بقاعدةٍ غير قاعدتها. صحّحها بإشعار دائن.');
        }

        if ($kind !== CustomerInvoiceRevision::DRAFT && $newTotal <= 0) {
            $blockers[] = __('لا تبقى فاتورةٌ صادرةٌ بلا مبلغ — ألغها بدل تصفيرها.');
        }

        if ($moneyChanged && $newTotal + 0.0005 < $credited) {
            $blockers[] = __('على الفاتورة إشعارات دائنة بـ :n — لا يقلّ إجماليها عنها.', ['n' => number_format($credited, 3)]);
        }

        $pendingLink = CustomerPaymentLink::where('business_id', $bid)
            ->where('customer_invoice_id', $invoice->id)
            ->where('status', CustomerPaymentLink::PENDING)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        if ($moneyChanged && ($pendingLink || PaymentLinks::doubt($invoice))) {
            $blockers[] = __('على الفاتورة رابطُ دفعٍ لم يُدفع بعد أو محاولةٌ لم تُعرف نتيجتُها — ألغِ الرابط أوّلًا من «المالية ← روابط الدفع»، فلا يُطلب من العميل مبلغٌ غير مبلغ الفاتورة.');
        }

        if ($customerChanged && $invoice->status !== CustomerInvoice::DRAFT) {
            $linked = $invoice->allocations()->exists()
                || $credited > 0
                || CustomerPaymentLink::where('business_id', $bid)->where('customer_invoice_id', $invoice->id)->exists()
                || PointTransaction::where('customer_invoice_id', $invoice->id)->exists();

            if ($linked) {
                $blockers[] = __('للفاتورة تحصيلاتٌ أو إشعاراتٌ دائنة أو روابطُ دفع أو نقاطُ ولاء باسم عميلها — لا تُنقل إلى عميلٍ آخر فينتقل معها مالُه في صمت. ألغِ التحصيل أو الرابط أوّلًا، أو أصدر الفاتورة الصحيحة للعميل الآخر وألغِ هذه.');
            }
        }

        /*
         * وطلبُ تصحيحٍ ينتظر الاعتماد يُبقي الورقةَ كما هي حتى يُبتّ فيه.
         *
         * تعديلٌ يقع فوقه يغيّر بصمتها فيُغلق الطلب «تغيّرت الفاتورة» — فيُقال
         * قبل الحفظ لا بعده. والطلبُ الذي يُعتمد الآن لا يمنع نفسه.
         */
        if (FinancialCorrections::pendingFor($invoice, $approving)) {
            $blockers[] = __('على هذه الفاتورة طلب تصحيح مالي بانتظار الاعتماد — يُعتمد أو يُرفض أو يُسحب أولًا.');
        }

        if ($itemsChanged && $invoice->preparation && $invoice->preparation->activated_at !== null) {
            $blockers[] = __('بدأ تجهيز هذه الفاتورة على لوحة التجهيز — لا تتغيّر بنودها تحت من يجهّزها. صحّح مالها بإشعار دائن.');
        }

        if ($customerChanged) {
            CustomerFlags::assertSellable($customer, CustomerFlags::settings($bid), null, null, 'customer_id');
        }

        /* ═══ الدفتر ═══ */
        $repost = $invoice->status === CustomerInvoice::ISSUED && ! $fromOrder
            && ($moneyChanged || $dateChanged || $branchChanged);
        $live = $repost ? CustomerInvoices::liveEntry($invoice) : null;

        if ($repost && ! $live) {
            $blockers[] = __('لا قيد قائم لهذه الفاتورة في الدفتر — لا يُصحَّح ما لم يُرحَّل. راجع الدفتر قبل تعديل مالها.');
        }

        $period = ['needs_choice' => false, 'mode' => null, 'reversal_date' => null, 'entry_date' => null, 'closed_through' => BooksPeriod::closedThrough($bid)?->toDateString(), 'original_allowed' => false];
        $closedDate = false;

        if ($repost && $live) {
            $period = self::period($bid, Carbon::parse($live->entry_date)->startOfDay(), $issued, $user, $input['period_mode'] ?? null);
            $closedDate = $dateChanged && $period['date_in_closed'];

            if ($closedDate) {
                $blockers[] = __('تاريخ الفاتورة القديم أو الجديد في فترة مقفلة حتى :d — لا تُنقل ورقةٌ من فترةٍ مقفلة ولا إليها. صحّح مالها في الفترة الحاليّة واترك تاريخها.', ['d' => $period['closed_through']]);
            }
        }

        /* ═══ الصلاحيات ═══ */
        $actions = [self::actionFor($kind)];
        if ($period['needs_choice']) {
            $actions[] = Permissions::CUSTOMER_INVOICE_CORRECT_PERIOD;
        }
        $missing = $user ? array_values(array_filter($actions, fn ($a) => ! $user->may($a))) : $actions;

        $branchRefused = $branchChanged && $user !== null
            && (($branchId !== null && ! $user->worksAt($branchId))
                || ($invoice->branch_id !== null && ! $user->worksAt((int) $invoice->branch_id)));

        if ($branchRefused) {
            $blockers[] = __('لست مُسنَدًا إلى هذا الفرع — لا تُنقل الفاتورة إليه ولا منه إلّا ممّن يعمل فيه.');
        }

        $outstanding = round($newTotal - $paid - $credited, 3);

        $plan = [
            'kind' => $kind,
            'from_order' => $fromOrder,
            'changed' => $changes !== [] || $itemsChanged,
            'money_changed' => $moneyChanged,
            'items_changed' => $itemsChanged,
            'changes' => $changes,
            'lines' => $lines['diff'],
            'header' => $header,
            'customer' => $customer,
            'customer_changed' => $customerChanged,
            'totals' => $totals,
            'compute_lines' => $lines['compute'],
            'kept' => $lines['kept'],
            'removed' => $lines['removed'],
            'inclusive' => $inclusive,
            'money' => [
                'total_before' => round((float) $invoice->total, 3),
                'total_after' => $newTotal,
                'tax_before' => round((float) $invoice->tax_total, 3),
                'tax_after' => $newTax,
                'difference' => round($newTotal - (float) $invoice->total, 3),
                'tax_difference' => round($newTax - (float) $invoice->tax_total, 3),
                'paid' => $paid,
                'credited' => $credited,
                'outstanding_before' => $invoice->outstanding(),
                'outstanding_after' => max(0.0, $outstanding),
                // ما قُبض فوق الإجماليّ الجديد — رصيدٌ دائنٌ للعميل، لا مالٌ يُردّ
                'customer_credit' => $outstanding < -0.0005 ? round(-$outstanding, 3) : 0.0,
            ],
            'ledger' => [
                'repost' => $repost && $live !== null,
                'live_entry_id' => $live?->id,
                'live_entry_date' => $live ? Carbon::parse($live->entry_date)->toDateString() : null,
            ],
            'period' => $period,
            'stock' => [
                'moves' => false,
                'why' => $fromOrder
                    ? __('مخزون هذه الفاتورة خرج مع طلبها، ولا تُعدَّل كمياتها من هنا — فلا حركة مخزون.')
                    : __('الفاتورة اليدوية لا تُخرج بضاعة من المخزون ولا تُقيّد تكلفة — فتعديل كمياتها لا يحرّك المخزون.'),
            ],
            'needs_reason' => $kind !== CustomerInvoiceRevision::DRAFT,
            'actions' => $actions,
            'missing_actions' => $missing,
            'blockers' => array_values(array_unique($blockers)),
        ];

        // والاعتمادُ الثنائيّ: حين يُشعله صاحب النشاط ويمسّ التعديلُ المال — `FinancialCorrections`
        $plan['approval'] = [
            'required' => FinancialCorrections::required($bid) && FinancialCorrections::touchesMoney($plan),
        ];

        /*
         * ولا يُرسل طلبٌ لا يعتمده أحد: المعتمدُ غيرُ صاحبه (`mayDecide`). معتمدٌ
         * عُطّل بعد الإشعال يترك صاحبَ النشاط وحده — فيُقال له قبل الحفظ لماذا
         * وكيف يمضي، لا أن يُعلَّق تصحيحُه. والطلبُ الذي يُعتمد الآن له معتمدُه.
         */
        if ($plan['approval']['required'] && $approving === null && $user !== null
            && FinancialCorrections::approvers($bid, (int) $user->id) === 0) {
            $plan['blockers'][] = __('الاعتماد الثنائي مفعّل، ولا يملك «اعتماد تصحيح مالي» موظفٌ نشطٌ غيرك — فلا يُرسل طلبٌ لا يعتمده أحد. امنح الصلاحية لموظف آخر من ملفه، أو يطفئ صاحب النشاط الاعتماد الثنائي من الإعدادات.');
        }

        return $plan;
    }

    /**
     * الحفظ — تحت قفل الورقة، مرّةً واحدةً للنموذج الواحد.
     *
     * @param  array<string, mixed>  $input  ما مرّ من `rules()`
     *
     * @throws AuthorizationException ما لا يملكه
     * @throws ValidationException سببٌ أو موضعُ تصحيحٍ ناقص
     * @throws RuntimeException ما يمنع التعديل، بسببه
     */
    public static function apply(CustomerInvoice $invoice, array $input, User $user, ?FinancialCorrection $approving = null): CustomerInvoiceRevision
    {
        $bid = (int) $invoice->business_id;
        $token = (string) $input['edit_token'];

        return DB::transaction(function () use ($invoice, $input, $user, $bid, $token, $approving) {
            /*
             * والقفلُ قبل كلّ قراءة: موظّفان يحفظان الورقةَ نفسَها في اللحظة
             * نفسِها — الثاني ينتظر، ثمّ يجد العدّادَ قد تقدّم فيُردّ ولا يمحو
             * ما حفظه الأوّل.
             */
            $locked = CustomerInvoice::where('business_id', $bid)->lockForUpdate()->findOrFail($invoice->id);

            // ونموذجٌ أُرسل من قبل: تعديلُه هو، لا تعديلٌ ثانٍ ولا قيدٌ ثانٍ
            $twin = CustomerInvoiceRevision::where('business_id', $bid)->where('edit_token', $token)->first();
            if ($twin) {
                if ((int) $twin->customer_invoice_id !== (int) $locked->id) {
                    throw new RuntimeException(__('رمز النموذج هذا استُعمل لفاتورة أخرى — افتح شاشة التعديل من جديد.'));
                }

                return $twin;
            }

            // والنموذجُ نفسُه أُرسل للاعتماد من قبل: الطلبُ الأوّل هو الجواب (`FinancialCorrections::request`)
            if ($approving === null && FinancialCorrection::where('business_id', $bid)->where('edit_token', $token)->exists()) {
                throw new NeedsApproval(__('هذا التصحيح يحتاج اعتماد شخص ثانٍ — أُرسل للاعتماد ولم يُنفَّذ بعد.'));
            }

            if ((int) $locked->revision !== (int) $input['expected_revision']) {
                throw new RuntimeException(__('عُدّلت هذه الفاتورة بعد أن فتحتها (التعديل :n) — افتحها من جديد لترى ما تغيّر، ثمّ أعد تعديلك.', ['n' => (int) $locked->revision]));
            }

            $plan = self::plan($locked, $input, $user, $approving);

            if ($plan['missing_actions'] !== []) {
                throw new AuthorizationException(__('لا تملك صلاحية هذا التعديل: :a', [
                    'a' => implode('، ', array_map(fn ($a) => Permissions::actionLabels()[$a] ?? $a, $plan['missing_actions'])),
                ]));
            }

            if ($plan['blockers'] !== []) {
                throw new RuntimeException($plan['blockers'][0]);
            }

            if (! $plan['changed']) {
                throw new RuntimeException(__('لم يتغيّر شيء في الفاتورة.'));
            }

            $reason = trim((string) ($input['reason'] ?? ''));
            if ($plan['needs_reason'] && $reason === '') {
                throw ValidationException::withMessages(['reason' => __('اكتب سبب تعديل الفاتورة — يُحفظ في سجل التعديلات وعلى قيد التصحيح.')]);
            }

            self::assertPeriodChosen($plan, $reason);

            /*
             * والاعتمادُ الثنائيّ مُشعل والتعديلُ يمسّ المال: لا يُكتب شيءٌ هنا.
             *
             * يُردّ قبل أوّل كتابة — والمتحكّمُ يحوّله طلبًا ينتظر غيرَ صاحبه
             * (`FinancialCorrections::request`). ولا يُنفَّذ إلّا من `approve`،
             * وهي تمرّر طلبَها المقفل هنا.
             */
            if ($plan['approval']['required'] && ($approving === null || (int) $approving->customer_invoice_id !== (int) $locked->id)) {
                throw new NeedsApproval(__('هذا التصحيح يحتاج اعتماد شخص ثانٍ — أُرسل للاعتماد ولم يُنفَّذ بعد.'));
            }

            $before = self::snapshot($locked);
            $number = (int) $locked->revision + 1;

            /* ═══ الدفتر أوّلًا: يسأل عن الفترة، فإن ردّ لم يُكتب شيءٌ بعده ═══ */
            $reversal = null;
            $entry = null;

            if ($plan['ledger']['repost']) {
                $live = JournalEntry::where('business_id', $bid)->whereKey($plan['ledger']['live_entry_id'])->firstOrFail();
                $why = __('تعديل الفاتورة :n (التعديل :r): :why', ['n' => $locked->number, 'r' => $number, 'why' => $reason]);

                $reversal = Ledger::reverse($live, $plan['period']['reversal_date'], $user->id, mb_substr($why, 0, 150));

                if (! $reversal) {
                    throw new RuntimeException(__('عُكس قيد هذه الفاتورة من مكانٍ آخر — افتحها من جديد.'));
                }
            }

            /* ═══ ثمّ الورقة ═══ */
            self::write($locked, $plan);

            $locked->forceFill(['revision' => $number])->save();
            $locked = $locked->fresh(['items']);

            if ($plan['ledger']['repost']) {
                $entry = CustomerInvoices::postEntry(
                    $locked,
                    $user->id,
                    $plan['period']['entry_date'],
                    BooksPeriod::fit(
                        __('فاتورة عميل ').$locked->number.' — '.__('تعديل :r', ['r' => $number]),
                        $plan['period']['mode'] !== self::SAME ? $reason : null,
                    ),
                );
            }

            $revision = CustomerInvoiceRevision::create([
                'business_id' => $bid,
                'customer_invoice_id' => $locked->id,
                'revision' => $number,
                'kind' => $plan['kind'],
                'reason' => $reason !== '' ? $reason : null,
                'before' => $before,
                'after' => self::snapshot($locked),
                'changes' => ['fields' => $plan['changes'], 'lines' => $plan['lines'], 'money' => $plan['money'], 'stock' => $plan['stock']],
                'total_before' => $plan['money']['total_before'],
                'total_after' => round((float) $locked->total, 3),
                'tax_before' => $plan['money']['tax_before'],
                'tax_after' => round((float) $locked->tax_total, 3),
                'paid_at_edit' => $plan['money']['paid'],
                'credited_at_edit' => $plan['money']['credited'],
                'period_mode' => $plan['ledger']['repost'] ? $plan['period']['mode'] : null,
                'reversal_date' => $reversal?->entry_date,
                'entry_date' => $entry?->entry_date,
                'reversal_entry_id' => $reversal?->id,
                'entry_id' => $entry?->id,
                'edit_token' => $token,
                'created_by' => $user->id,
            ]);

            Activity::log('updated', BooksPeriod::fit(
                'عدّل فاتورة عميل '.($locked->number ?: 'مسودة').' (التعديل '.$number.'): '
                    .$plan['money']['total_before'].' ← '.round((float) $locked->total, 3),
                $reason !== '' ? $reason : null,
            ), [
                'subject_id' => $locked->id, 'subject_type' => 'customer_invoice', 'business_id' => $bid,
            ]);

            return $revision;
        });
    }

    /**
     * أين يقع التصحيح في الدفتر — بقاعدة `BooksPeriod`.
     *
     * قيدٌ قائمٌ في الشهر الجاري وتاريخٌ جديدٌ فيه، ولا إقفالَ يغطّيهما: يُعكس
     * بيومه ويُرحَّل بتاريخ الورقة — لا سؤال. وما سوى ذلك يحتاج اختيارًا:
     * «الفترة الحاليّة» (العكسُ والترحيلُ اليوم، إلّا تاريخًا جديدًا في الشهر
     * الجاري فبيومه)، أو «تاريخ القيد» لصاحب النشاط في فترةٍ مفتوحةٍ معلومة.
     *
     * @return array<string, mixed>
     */
    private static function period(int $bid, Carbon $old, Carbon $new, ?User $user, ?string $mode): array
    {
        $closed = BooksPeriod::closedThrough($bid);
        $isClosed = fn (Carbon $d) => $closed !== null && $d->toDateString() <= $closed->toDateString();
        $prior = fn (Carbon $d) => $d->lt(now()->startOfMonth());
        $context = BooksPeriod::context($bid, $user);

        $needs = $prior($old) || $prior($new) || $isClosed($old) || $isClosed($new);
        $originalAllowed = $context['owner']
            && BooksPeriod::status($context, $old) === BooksPeriod::OPEN
            && BooksPeriod::status($context, $new) === BooksPeriod::OPEN;

        $base = [
            'needs_choice' => $needs,
            'closed_through' => $closed?->toDateString(),
            'old_status' => BooksPeriod::status($context, $old),
            'new_status' => BooksPeriod::status($context, $new),
            'original_allowed' => $needs && $originalAllowed,
            'date_in_closed' => $isClosed($old) || $isClosed($new),
        ];

        if (! $needs) {
            return $base + ['mode' => self::SAME, 'reversal_date' => $old, 'entry_date' => $new];
        }

        if ($mode === BooksPeriod::CURRENT) {
            $today = now()->startOfDay();
            $entry = ! $prior($new) && ! $isClosed($new) ? $new : $today;

            return $base + ['mode' => BooksPeriod::CURRENT, 'reversal_date' => $today, 'entry_date' => $entry];
        }

        if ($mode === BooksPeriod::ORIGINAL) {
            return $base + ['mode' => BooksPeriod::ORIGINAL, 'reversal_date' => $old, 'entry_date' => $new];
        }

        return $base + ['mode' => null, 'reversal_date' => null, 'entry_date' => null];
    }

    /**
     * موضعُ التصحيح كما اختير — يُسأل قبل الحفظ بعد أن تُعرف الحاجة إليه.
     *
     * @param  array<string, mixed>  $plan
     *
     * @throws ValidationException
     */
    public static function assertPeriodChosen(array $plan, ?string $reason): void
    {
        $period = $plan['period'];

        if (! $plan['ledger']['repost'] || ! $period['needs_choice']) {
            return;
        }

        if ($period['mode'] === null) {
            throw ValidationException::withMessages(['period_mode' => __(
                'قيد هذه الفاتورة بتاريخ :date، في شهرٍ مضى أو فترةٍ مقفلة. اختر أين يُسجَّل التصحيح، واكتب سببه.',
                ['date' => $plan['ledger']['live_entry_date']],
            )]);
        }

        if (trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => __('اكتب سبب تصحيح الفترة السابقة — يُحفظ مع القيد.')]);
        }

        if ($period['mode'] !== BooksPeriod::ORIGINAL) {
            return;
        }

        if (! ($period['original_allowed'] ?? false)) {
            throw ValidationException::withMessages(['period_mode' => match (true) {
                $period['old_status'] === BooksPeriod::CLOSED || $period['new_status'] === BooksPeriod::CLOSED => __(
                    'الدفاتر مقفلة حتى :closed، فلا يُكتب في تلك الفترة شيء. سجّل التصحيح في الفترة الحاليّة.',
                    ['closed' => $period['closed_through']],
                ),
                $period['old_status'] === BooksPeriod::UNKNOWN || $period['new_status'] === BooksPeriod::UNKNOWN => __(
                    'لم يُحدَّد تاريخ إقفال الدفاتر في الإعدادات، فلا يُفترض أنّ شهرًا مضى مفتوح. سجّل التصحيح في الفترة الحاليّة، أو حدّد تاريخ الإقفال أوّلًا.',
                ),
                default => __('التصحيح بتاريخ فترةٍ سابقة لصاحب النشاط وحده.'),
            }]);
        }
    }

    /**
     * البنودُ كما ستُكتب — القائمُ بمعرّفه، والجديدُ من الكتالوج كبند الإنشاء.
     *
     * ═══ والقائمُ لا يمرّ من الكتالوج ثانيةً ═══
     *
     * بندٌ يحمل معرّفَه يبقى صفَّه: لقطتُه (الصنفُ والإضافةُ والتفاصيل) وأبوه
     * كما كانا، ويتغيّر منه الكمّيّةُ والسعرُ والخصمُ والضريبة. وإضافةُ الكتالوج
     * سعرُها سعرُ بيعها لا سعرُ اليوم: إضافةٌ غُيّر ثمنُها أو أُوقفت بعد الإصدار
     * لا يتغيّر بها بندٌ لم يُلمس. والجديدُ يمرّ من `InvoiceLineDetails::expand`
     * كما في الإنشاء: من متجر الورقة، بسعر الكتالوج، وإضافاتُه تحته.
     *
     * @param  array<int, array<string, mixed>>|null  $input
     * @return array{changed: bool, compute: array<int, array<string, mixed>>, kept: array<int, array<string, mixed>>, removed: array<int, int>, diff: array<int, array<string, mixed>>}
     */
    private static function lines(CustomerInvoice $invoice, ?array $input): array
    {
        $existing = $invoice->items->keyBy('id');
        $asIs = $existing->map(fn (CustomerInvoiceItem $i) => self::itemLine($i))->values()->all();

        if ($input === null) {
            return ['changed' => false, 'compute' => $asIs, 'kept' => [], 'removed' => [], 'diff' => []];
        }

        $bid = (int) $invoice->business_id;
        $compute = [];
        $kept = [];
        $diff = [];
        $seen = [];

        foreach (array_values($input) as $pos => $line) {
            $id = filled($line['item_id'] ?? null) ? (int) $line['item_id'] : null;

            if ($id !== null) {
                $item = $existing->get($id);

                if (! $item || isset($seen[$id])) {
                    throw ValidationException::withMessages(['items' => __('بندٌ ليس من هذه الفاتورة.')]);
                }
                $seen[$id] = true;

                $next = self::itemLine($item);
                $next['quantity'] = round((float) $line['quantity'], 3);
                $next['discount'] = round((float) ($line['discount'] ?? 0), 3);
                if (array_key_exists('tax_rate', $line) && $line['tax_rate'] !== null && $line['tax_rate'] !== '') {
                    $next['tax_rate'] = round((float) $line['tax_rate'], 3);
                }
                // إضافةُ الكتالوج اسمُها وثمنُها لقطتُها — والباقي يُكتب بيد
                if ($item->addon_id === null) {
                    $next['unit_price'] = round((float) $line['unit_price'], 3);
                    if (filled($line['description'] ?? null)) {
                        $next['description'] = trim((string) $line['description']);
                    }
                }

                $compute[] = $next;
                $kept[] = ['id' => $id, 'position' => $pos];

                $was = self::itemLine($item);
                if (self::lineDiffers($was, $next)) {
                    $diff[] = ['change' => 'changed', 'id' => $id, 'before' => self::lineShown($was), 'after' => self::lineShown($next)];
                }

                continue;
            }

            // بندٌ جديد — كبند الإنشاء: وصفٌ مطلوب، ومن الكتالوج بثمنه
            if (trim((string) ($line['description'] ?? '')) === '' && empty($line['product_id']) && empty($line['addon_id'])) {
                throw ValidationException::withMessages(['items' => __('اكتب وصف البند الجديد.')]);
            }

            // ونسبةٌ فارغةٌ تُحذف لا تُقرأ صفرًا — كما في الإنشاء (`compute` تقرأ وجودَ المفتاح)
            $line = array_diff_key($line, ['item_id' => true]);
            if (($line['tax_rate'] ?? null) === null || $line['tax_rate'] === '') {
                unset($line['tax_rate']);
            }

            $expanded = InvoiceLineDetails::expand($bid, [$line]);
            CustomerInvoices::assertLinesBelong($bid, $expanded);

            foreach ($expanded as $new) {
                if (($new['group'] ?? null) !== null) {
                    $new['group'] = 'n'.$pos.'-'.$new['group'];
                }
                if (($new['parent'] ?? null) !== null) {
                    $new['parent'] = 'n'.$pos.'-'.$new['parent'];
                }
                $compute[] = $new;
                $diff[] = ['change' => 'added', 'id' => null, 'before' => null, 'after' => self::lineShown($new)];
            }
        }

        $removed = $existing->keys()->reject(fn ($id) => isset($seen[(int) $id]))->map(fn ($id) => (int) $id)->values()->all();

        // إضافةٌ مدفوعةٌ بلا صنفها — أو صنفٌ حُذف وبقيت إضافتُه
        foreach ($existing as $item) {
            if ($item->parent_item_id !== null && isset($seen[(int) $item->id]) && in_array((int) $item->parent_item_id, $removed, true)) {
                throw ValidationException::withMessages(['items' => __('حُذف صنفٌ وبقيت إضافته «:a» — احذف إضافاته معه.', ['a' => $item->description])]);
            }
        }

        foreach ($removed as $id) {
            $diff[] = ['change' => 'removed', 'id' => $id, 'before' => self::lineShown(self::itemLine($existing->get($id))), 'after' => null];
        }

        if ($compute === []) {
            throw ValidationException::withMessages(['items' => __('الفاتورة تحتاج بندًا واحدًا على الأقل.')]);
        }

        // والترتيبُ تغييرٌ أيضًا: الورقةُ تُطبع بترتيب بنودها
        $order = collect($kept)->pluck('id')->all();
        $reordered = $order !== $existing->keys()->map(fn ($id) => (int) $id)->reject(fn ($id) => in_array($id, $removed, true))->values()->all();

        return [
            'changed' => $diff !== [] || $reordered,
            'compute' => $compute,
            'kept' => $kept,
            'removed' => $removed,
            'diff' => $diff,
        ];
    }

    /** @return array<string, mixed> */
    private static function itemLine(CustomerInvoiceItem $item): array
    {
        return [
            'item_id' => (int) $item->id,
            'product_id' => $item->product_id,
            'addon_id' => $item->addon_id,
            'order_item_id' => $item->order_item_id,
            'description' => (string) $item->description,
            'quantity' => round((float) $item->quantity, 3),
            'unit_price' => round((float) $item->unit_price, 3),
            'discount' => round((float) $item->discount, 3),
            'tax_rate' => round((float) $item->tax_rate, 3),
            'details' => $item->details,
        ];
    }

    /** @param  array<string, mixed>  $a @param  array<string, mixed>  $b */
    private static function lineDiffers(array $a, array $b): bool
    {
        foreach (['quantity', 'unit_price', 'discount', 'tax_rate'] as $k) {
            if (abs((float) $a[$k] - (float) $b[$k]) >= 0.0005) {
                return true;
            }
        }

        return (string) $a['description'] !== (string) $b['description'];
    }

    /** @return array<string, mixed> */
    private static function lineShown(array $line): array
    {
        return [
            'description' => (string) ($line['description'] ?? ''),
            'quantity' => round((float) ($line['quantity'] ?? 0), 3),
            'unit_price' => round((float) ($line['unit_price'] ?? 0), 3),
            'discount' => round((float) ($line['discount'] ?? 0), 3),
            'tax_rate' => array_key_exists('tax_rate', $line) && $line['tax_rate'] !== null ? round((float) $line['tax_rate'], 3) : null,
        ];
    }

    /**
     * رأسُ الورقة بعد التعديل — ولقطةُ العميل تُعاد حين يتبدّل وحده.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function header(array $input, Carbon $issued, ?string $due, ?int $branchId, Customer $customer): array
    {
        $out = [
            'customer_id' => (int) $customer->id,
            'issued_at' => $issued->toDateString(),
            'due_at' => $due,
            'payment_terms_days' => filled($input['payment_terms_days'] ?? null) ? (int) $input['payment_terms_days'] : null,
            'branch_id' => $branchId,
        ];

        foreach (['po_number', 'contract_number', 'external_reference', 'department', 'cost_center', 'attention_to', 'notes', 'internal_notes'] as $field) {
            $value = $input[$field] ?? null;
            $out[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $plan */
    private static function write(CustomerInvoice $invoice, array $plan): void
    {
        $header = $plan['header'];
        $update = $header;

        // ولقطةُ الجهة من العميل الجديد — والقديمُ تبقى لقطتُه كما صدرت
        if ($plan['customer_changed']) {
            $c = $plan['customer'];
            $update += [
                'customer_name' => $c->legal_name ?: $c->name,
                'customer_tax_number' => $c->tax_number,
                'customer_cr' => $c->commercial_registration,
                'customer_address' => $c->billing_address ?: $c->address,
            ];
        }

        if ($plan['items_changed'] && $plan['totals'] !== null) {
            $totals = $plan['totals'];
            $computed = $totals['items'];

            if (count($computed) !== count($plan['compute_lines'])) {
                throw new RuntimeException(__('تعذّر حساب بنود الفاتورة — أعد المحاولة.'));
            }

            // المحذوفُ أوّلًا، والإضافاتُ قبل أصنافها: لا يبقى صفٌّ يشير إلى غائب
            CustomerInvoiceItem::where('customer_invoice_id', $invoice->id)
                ->whereIn('id', $plan['removed'])->whereNotNull('parent_item_id')->delete();
            CustomerInvoiceItem::where('customer_invoice_id', $invoice->id)
                ->whereIn('id', $plan['removed'])->delete();

            $fresh = [];
            foreach ($plan['compute_lines'] as $n => $line) {
                $row = $computed[$n];

                if (isset($line['item_id'])) {
                    CustomerInvoiceItem::where('customer_invoice_id', $invoice->id)->whereKey($line['item_id'])->update([
                        'description' => $row['description'],
                        'quantity' => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'discount' => $row['discount'],
                        'tax_rate' => $row['tax_rate'],
                        'tax_amount' => $row['tax_amount'],
                        'line_total' => $row['line_total'],
                        'sort_order' => $row['sort_order'],
                    ]);

                    continue;
                }

                $fresh[] = $row;
            }

            if ($fresh !== []) {
                CustomerInvoices::writeItems($invoice, $fresh);
            }

            $update += [
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount'],
                'tax_total' => $totals['tax'],
                'total' => $totals['total'],
            ];
        }

        $invoice->forceFill($update)->save();
    }

    /** قيمةٌ تُقارن — الفراغُ فراغٌ واحد */
    private static function scalar(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** القيمةُ كما تُقرأ في السجلّ — العميلُ والفرعُ باسميهما */
    private static function shown(string $field, ?string $value, int $bid): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'customer_id' => (string) (Customer::withTrashed()->where('business_id', $bid)->whereKey($value)->value('name') ?? $value),
            'branch_id' => (string) (Branch::withTrashed()->where('business_id', $bid)->whereKey($value)->value('name') ?? $value),
            default => $value,
        };
    }
}
