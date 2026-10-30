<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\CustomerInvoiceRevision;
use App\Models\CustomerPaymentLink;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * تعديلُ فاتورة العميل وتصحيحُها — المسودّةُ تُعدَّل، والصادرةُ تُصحَّح بمستند.
 *
 * ═══ المسودّة ═══
 *
 * لا ذمّةَ ولا قيدَ ولا رقمَ ضريبيّ بعد. تُكتب ثانيةً كما كُتبت أوّلًا: الأصنافُ
 * والكمّيّاتُ والأسعارُ والخصوماتُ والعميلُ والتواريخ.
 *
 * ═══ والصادرة — مدفوعةً أو لا ═══
 *
 * الفاتورةُ الضريبيّة لا تُعدَّل بعد إصدارها: بنودُها ومجاميعُها ورقمُها
 * وتاريخُها ونسختُها المطبوعة كما صدرت (م١٥٧(١) من اللائحة التنفيذيّة لضريبة
 * القيمة المضافة — قرار ٥٣/٢٠٢١: لا يسمح النظامُ بتغيير الفواتير بعد تسجيلها).
 * والتصحيحُ مستندٌ مستقلّ (م١٥٥): برقمه وتسلسله وتاريخه، وبنوده بتفاصيل م١٤٤،
 * يشير إلى الفاتورة وإلى الضريبة المعدَّلة —
 *
 *  • ما نقص: إشعارٌ دائن (م٤٨ للإرجاع، و١٥٥ لتصحيح الخطأ). يُقيَّد يومَه:
 *    مدين مردودات المبيعات والضريبة / دائن الذمم (`CustomerInvoices::creditNote`).
 *  • ما زاد: إشعارٌ مدين (م٥٠) — يُقيَّد يومَه: مدين الذمم / دائن المبيعات
 *    والضريبة (`CustomerInvoices::debitNote`).
 *  • وما نقص بندٌ منه وزاد آخر: المستندان معًا، كلٌّ بما يخصّه — لا يُقاصّ
 *    نقصٌ بزيادةٍ في مستندٍ واحد.
 *
 * وحالُ الورقة بعد كلّ تصحيح لقطةٌ في سجلّه (`customer_invoice_revisions.after`)،
 * ومنها يبدأ التصحيحُ التالي. فالفاتورةُ + المدينُ − الدائنُ هو ما على العميل،
 * ويقوله `CustomerInvoice::effectiveTotal` والذمّةُ في القاعدة (`outstandingSql`).
 *
 * ═══ والمقبوضُ لا يُمسّ ═══
 *
 * سندُ القبض مستندٌ وقّعه العميل ونتيجةُ Paymob حدثٌ وقع. فإن زاد المطلوبُ
 * صار الفرقُ باقيًا عليه، وإن نقص عمّا قُبض صار الفائضُ رصيدًا دائنًا له
 * (`Receivables::credit` تقرؤه من ذمّةٍ سالبة) — ولا يُردّ مالٌ تلقائيًّا.
 *
 * ═══ وما يُمنع حتى يُحسم ═══
 *
 * ما لم يُعرف حكمُه الضريبيّ لا يُنفَّذ ولا يُفترض فيه جواز: تغييرُ نسبة
 * ضريبة بندٍ صدر، وتغييرُ وصفه أو بياناتٍ مطبوعة على الفاتورة، وتصحيحُ خطأٍ
 * في فترةٍ قُدّم إقرارُها، وإرجاعٌ بعد ثلاثة أشهرٍ من التوريد (م٤٨(٤))،
 * والخصمُ اللاحق (م٤٩ تشترط سياسةَ خصمٍ منشورة). كلُّها نقاطُ قرارٍ للمحاسب.
 *
 * ═══ والفاتورةُ المولودةُ من طلب ═══
 *
 * بنودُها ومبلغُها وقيدُها ومخزونُها من الطلب (`CustomerInvoices::post`). فلا
 * يُصحَّح مالُها هنا: يُصحَّح الطلبُ نفسُه (`OrderCorrection`) فيتبعه قيدُه
 * ومخزونُه. ويُعدَّل منها ما لا يُطبع: الملاحظاتُ الداخليّة.
 *
 * ═══ والمخزون ═══
 *
 * الفاتورةُ اليدويّة لا تُخرج شيئًا من الرفّ ولا تُقيّد تكلفة (انظر رأس
 * `CustomerInvoices`). فتصحيحُ كمّيّتها لا يحرّك المخزون — ويُقال في المعاينة.
 */
final class CustomerInvoiceEdits
{
    /** حقولٌ لا مالَ فيها — تُعدَّل في المسودّة، والمولودةُ من طلبٍ معها */
    public const PLAIN = [
        'due_at', 'payment_terms_days', 'po_number', 'contract_number', 'external_reference',
        'department', 'cost_center', 'attention_to', 'notes', 'internal_notes',
    ];

    /** وما يمسّ الذمّةَ أو الدفترَ أو صاحبَ الدَّين */
    public const BOUND = ['customer_id', 'issued_at', 'branch_id'];

    /** وما يُعدَّل في مكانه بعد الإصدار: ما لا يُطبع على الفاتورة */
    public const AFTER_ISSUE = ['internal_notes'];

    /** أطولُ سبب — يُكتب في وصف القيد وسطرِ النشاط */
    public const REASON_MAX = BooksPeriod::REASON_MAX;

    /** مدّةُ الإرجاع التي تُعدَّل فيها الضريبة — م٤٨(٤): ثلاثةُ أشهرٍ من التوريد */

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

    /** أساسُ التصحيح كما يُقرأ — ومنه المادّةُ التي يقوم عليها المستند */
    public const BASIS_LABELS = [
        CustomerInvoiceRevision::ERROR => 'تصحيح خطأ في الفاتورة',
        CustomerInvoiceRevision::RETURN => 'إرجاع أو إلغاء جزئي',
    ];

    /**
     * ما يُفتح من الأسس في شاشة التصحيح — تصحيحُ الخطأ وحده.
     *
     * الإرجاعُ والإلغاءُ الجزئيّ مسارٌ آخر: المادّة 48 من اللائحة (العدد 1383
     * من الجريدة الرسميّة) تشترط للإلغاء والرفض «إشعار مدين» يشير إلى الفاتورة،
     * واسترجاعَ السلع وإعادةَ المقابل خلال ثلاثة أشهر — لا إشعارًا دائنًا يُختار
     * من اتّجاه الفرق. فيبقى مردودًا هنا حتى يحسم المحاسبُ الضريبيّ مستندَه.
     */
    public const OPEN_BASES = [CustomerInvoiceRevision::ERROR];

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
     * ما يمنع تصحيحَ مال الفاتورة الآن — من حالها هي، قبل أن يُكتب شيء.
     *
     * تعرضه الشاشةُ عند فتحها فلا يكتشفه الموظّفُ بعد أن يصحّح البنود ويعاين،
     * ويقرؤه `planCorrection` نفسُه — فالسببُ في الشاشة هو سببُ الردّ.
     *
     * @return array<string, string> order | link | filed | closed
     */
    public static function standing(CustomerInvoice $invoice): array
    {
        if (self::kind($invoice) === CustomerInvoiceRevision::DRAFT) {
            return [];
        }

        $bid = (int) $invoice->business_id;
        $reasons = [];

        if ($invoice->orders()->exists()) {
            $reasons['order'] = __('هذه الفاتورة مولودة من طلب: بنودها ومبلغها وقيدها ومخزونها من الطلب. صحّح الطلب نفسه فيتبعه قيده ومخزونه — ويُعدَّل هنا ما لا يُطبع.');
        }

        $pendingLink = CustomerPaymentLink::where('business_id', $bid)
            ->where('customer_invoice_id', $invoice->id)
            ->where('status', CustomerPaymentLink::PENDING)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        if ($pendingLink || PaymentLinks::doubt($invoice)) {
            $reasons['link'] = __('على الفاتورة رابطُ دفعٍ لم يُدفع بعد أو محاولةٌ لم تُعرف نتيجتُها — ألغِ الرابط أوّلًا من «المالية ← روابط الدفع»، فلا يُطلب من العميل مبلغٌ غير ما عليه.');
        }

        if (Vat::isFiled($bid, $invoice->issued_at)) {
            $reasons['filed'] = __('الفاتورة في فترة ضريبية قُدّم إقرارها حتى :d — تصحيح خطأ فيها قد يحتاج إقرارًا معدّلًا. القرار للمحاسب الضريبي قبل إصدار مستند التصحيح.', [
                'd' => Vat::filedThrough($bid)?->toDateString(),
            ]);
        }

        // المستندُ يُقيَّد يومَه — والدفاترُ المقفلةُ حتى اليوم لا يُكتب فيها
        $closed = BooksPeriod::closedThrough($bid);
        if ($closed !== null && $closed->gte(now()->startOfDay())) {
            $reasons['closed'] = __('الدفاتر مقفلة حتى :d — لا يُقيَّد مستند تصحيح اليوم.', ['d' => $closed->toDateString()]);
        }

        return $reasons;
    }

    /**
     * قواعدُ النموذج — وبنودُه: القائمُ بمفتاحه (`key`) أو معرّفه، والجديدُ كبند الإنشاء.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:0'],
            'edit_token' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:'.self::REASON_MAX],
            'basis' => ['nullable', 'string', 'in:'.implode(',', CustomerInvoiceRevision::BASES)],
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
            'items.*.key' => ['nullable', 'string', 'max:40'],
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
            'basis' => __('أساس التصحيح'),
            'branch_id' => __('الفرع'),
            'edit_token' => __('رمز النموذج'),
            'expected_revision' => __('نسخة الفاتورة'),
        ];
    }

    /**
     * الورقةُ كما هي — رأسًا وبنودًا ومالًا. تُكتب في سجلّ تعديل المسودّة.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(CustomerInvoice $invoice): array
    {
        $invoice->loadMissing('items');

        return self::head($invoice) + [
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
     * حالُ الفاتورة الصادرة بعد مستندات تصحيحها — بنودًا ومجاميع.
     *
     * قبل أوّل تصحيح: بنودُها كما صدرت، كلٌّ بمفتاحه `i{id}`. وبعده: لقطةُ آخر
     * تصحيح (`after.lines`) — والبندُ الذي أضافه تصحيحٌ مفتاحُه من رقم ذلك
     * التصحيح. والفاتورةُ نفسُها لا تتغيّر في الحالين.
     *
     * والمجموعُ هنا مجموعُ البنود: ما يُطالَب به بعد الإشعارات اليدويّة التي
     * لا بنود لها `effectiveTotal()` لا هذا.
     *
     * @return array{lines: array<int, array<string, mixed>>, subtotal: float, discount_total: float, tax_total: float, total: float}
     */
    public static function state(CustomerInvoice $invoice): array
    {
        $last = CustomerInvoiceRevision::where('customer_invoice_id', $invoice->id)
            ->where('kind', '!=', CustomerInvoiceRevision::DRAFT)
            ->orderByDesc('revision')->first();

        if ($last && isset($last->after['lines'])) {
            return [
                'lines' => array_values($last->after['lines']),
                'subtotal' => round((float) ($last->after['subtotal'] ?? 0), 3),
                'discount_total' => round((float) ($last->after['discount_total'] ?? 0), 3),
                'tax_total' => round((float) ($last->after['tax_total'] ?? 0), 3),
                'total' => round((float) ($last->after['total'] ?? 0), 3),
            ];
        }

        $invoice->loadMissing('items');

        return [
            'lines' => $invoice->items->map(fn (CustomerInvoiceItem $i) => [
                'key' => 'i'.$i->id,
                'parent_key' => $i->parent_item_id !== null ? 'i'.$i->parent_item_id : null,
                'item_id' => (int) $i->id,
                'product_id' => $i->product_id,
                'addon_id' => $i->addon_id,
                'order_item_id' => $i->order_item_id,
                'description' => (string) $i->description,
                'quantity' => round((float) $i->quantity, 3),
                'unit_price' => round((float) $i->unit_price, 3),
                'discount' => round((float) $i->discount, 3),
                'tax_rate' => round((float) $i->tax_rate, 3),
                'tax_amount' => round((float) $i->tax_amount, 3),
                'line_total' => round((float) $i->line_total, 3),
                'details' => $i->details,
            ])->values()->all(),
            'subtotal' => round((float) $invoice->subtotal, 3),
            'discount_total' => round((float) $invoice->discount_total, 3),
            'tax_total' => round((float) $invoice->tax_total, 3),
            'total' => round((float) $invoice->total, 3),
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
    public static function plan(CustomerInvoice $invoice, array $input, ?User $user): array
    {
        if ($invoice->status === CustomerInvoice::CANCELLED) {
            throw new RuntimeException(__('الفاتورة ملغاة — لا تُعدَّل.'));
        }

        $invoice->loadMissing('items', 'orders', 'preparation');

        return $invoice->status === CustomerInvoice::DRAFT
            ? self::planDraft($invoice, $input, $user)
            : self::planCorrection($invoice, $input, $user);
    }

    /**
     * الحفظ — تحت قفل الورقة، مرّةً واحدةً للنموذج الواحد.
     *
     * @param  array<string, mixed>  $input  ما مرّ من `rules()`
     *
     * @throws AuthorizationException ما لا يملكه
     * @throws ValidationException سببٌ أو أساسٌ ناقص
     * @throws RuntimeException ما يمنع التعديل، بسببه
     */
    public static function apply(CustomerInvoice $invoice, array $input, User $user): CustomerInvoiceRevision
    {
        $bid = (int) $invoice->business_id;
        $token = (string) $input['edit_token'];

        return DB::transaction(function () use ($invoice, $input, $user, $bid, $token) {
            /*
             * والقفلُ قبل كلّ قراءة: موظّفان يحفظان الورقةَ نفسَها في اللحظة
             * نفسِها — الثاني ينتظر، ثمّ يجد العدّادَ قد تقدّم فيُردّ ولا يُصدر
             * مستندًا ثانيًا عن الفرق نفسِه.
             */
            $locked = CustomerInvoice::where('business_id', $bid)->lockForUpdate()->findOrFail($invoice->id);

            // ونموذجٌ أُرسل من قبل: تعديلُه هو، لا تعديلٌ ثانٍ ولا مستندٌ ثانٍ
            $twin = CustomerInvoiceRevision::where('business_id', $bid)->where('edit_token', $token)->first();
            if ($twin) {
                if ((int) $twin->customer_invoice_id !== (int) $locked->id) {
                    throw new RuntimeException(__('رمز النموذج هذا استُعمل لفاتورة أخرى — افتح شاشة التعديل من جديد.'));
                }

                return $twin;
            }

            if ((int) $locked->revision !== (int) $input['expected_revision']) {
                throw new RuntimeException(__('عُدّلت هذه الفاتورة بعد أن فتحتها (التعديل :n) — افتحها من جديد لترى ما تغيّر، ثمّ أعد تعديلك.', ['n' => (int) $locked->revision]));
            }

            $plan = self::plan($locked, $input, $user);

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
                throw ValidationException::withMessages(['reason' => __('اكتب سبب تصحيح الفاتورة — يُطبع على مستند التصحيح ويُحفظ في السجل.')]);
            }
            if ($plan['needs_basis'] && $plan['basis'] === null) {
                throw ValidationException::withMessages(['basis' => __('اختر أساس التصحيح.')]);
            }

            $number = (int) $locked->revision + 1;

            return $locked->status === CustomerInvoice::DRAFT
                ? self::applyDraft($locked, $plan, $reason, $number, $token, $user)
                : self::applyCorrection($locked, $plan, $reason, $number, $token, $user);
        });
    }

    /**
     * عميلٌ آخر لفاتورةٍ صدرت — تُلغى هذه وتُكتب بديلتُها مسودّةً للعميل الصحيح.
     *
     * ═══ ولمَ لا تُعدَّل ═══
     *
     * اسمُ العميل ورقمُه الضريبيّ من بيانات الفاتورة الضريبيّة (م١٤٤(٥))، فلا
     * يتغيّر على ورقةٍ صدرت. والطريقُ الآمن: تُلغى (عكسُ قيدها في يومه —
     * `CustomerInvoices::cancel`) وتبقى برقمها مقروءةً موسومةً بالإلغاء، وتُكتب
     * بديلتُها مسودّةً تُراجَع ثمّ تُصدَر برقمٍ جديد، وتحمل رقمَ ما حلّت محلَّه.
     *
     * ولا يكون إلّا لورقةٍ لم يُقبض منها شيءٌ ولا عليها إشعارٌ ولا رابطٌ ولا
     * نقاطٌ ولا تجهيزٌ قائم: مالٌ باسم عميلٍ لا ينتقل إلى غيره في صمت.
     *
     * ولا في فترةٍ يُمنع فيها تصحيحُ مالها (`periodRefusal`): الإلغاءُ يعكس
     * ضريبتَها كلَّها اليوم بلا مستند تصحيح. وفاتورةُ فترةٍ سابقة تُستبدل
     * بصلاحية تصحيحها، كما يُصحَّح مالُها.
     *
     * @throws AuthorizationException ما لا يملكه
     * @throws RuntimeException ما يمنعه، بسببه
     */
    public static function replace(CustomerInvoice $invoice, Customer $customer, string $reason, User $user): CustomerInvoice
    {
        $bid = (int) $invoice->business_id;

        $actions = [Permissions::CUSTOMER_INVOICE_CANCEL, Permissions::CUSTOMER_INVOICE_EDIT_ISSUED];
        if ($invoice->status === CustomerInvoice::ISSUED && self::prior($invoice)) {
            $actions[] = Permissions::CUSTOMER_INVOICE_CORRECT_PERIOD;
        }

        foreach ($actions as $action) {
            if (! $user->may($action)) {
                throw new AuthorizationException(__('لا تملك صلاحية هذا التعديل: :a', ['a' => Permissions::actionLabels()[$action] ?? $action]));
            }
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('اكتب سبب استبدال الفاتورة — يُكتب على إلغائها.')]);
        }

        return DB::transaction(function () use ($invoice, $customer, $reason, $user, $bid) {
            $locked = CustomerInvoice::where('business_id', $bid)->lockForUpdate()->findOrFail($invoice->id);
            $locked->loadMissing('items', 'orders', 'preparation');

            if ($locked->status !== CustomerInvoice::ISSUED) {
                throw new RuntimeException(__('الاستبدال لفاتورةٍ صادرة — والمسودّةُ يُغيَّر عميلُها من «تعديل الفاتورة».'));
            }
            if ((int) $customer->business_id !== $bid) {
                throw ValidationException::withMessages(['customer_id' => __('هذا العميل ليس من عملاء متجرك.')]);
            }
            if ((int) $customer->id === (int) $locked->customer_id) {
                throw ValidationException::withMessages(['customer_id' => __('هذا عميل الفاتورة نفسه — اختر العميل الصحيح.')]);
            }

            $why = self::periodRefusal($locked) ?? match (true) {
                $locked->orders->isNotEmpty() => __('هذه الفاتورة مولودة من طلب — يُصحَّح الطلب نفسه.'),
                $locked->paidTotal() > 0 || $locked->allocations()->exists() => __('قُبض من هذه الفاتورة مال باسم عميلها — لا تُستبدل. أصدر إشعارًا دائنًا لعميلها، ثم فاتورة جديدة للعميل الصحيح.'),
                $locked->creditNotes()->exists() || $locked->debitNotes()->exists() => __('على الفاتورة إشعارات تصحيح — لا تُستبدل. أصدر إشعارًا دائنًا بالباقي، ثم فاتورة جديدة للعميل الصحيح.'),
                CustomerPaymentLink::where('business_id', $bid)->where('customer_invoice_id', $locked->id)->exists() => __('للفاتورة روابط دفع باسم عميلها — ألغها أوّلًا، أو أصدر إشعارًا دائنًا ثم فاتورة جديدة.'),
                PointTransaction::where('customer_invoice_id', $locked->id)->exists() => __('للفاتورة نقاط ولاء باسم عميلها — لا تُستبدل.'),
                $locked->preparation !== null => __('على الفاتورة طلب تجهيز — ألغه أوّلًا من صفحتها، فلا يُجهَّز لعميلٍ غير عميلها.'),
                default => null,
            };

            if ($why !== null) {
                throw new RuntimeException($why);
            }

            CustomerFlags::assertSellable($customer, CustomerFlags::settings($bid), null, null, 'customer_id');

            $lines = $locked->items->map(fn (CustomerInvoiceItem $i) => [
                'product_id' => $i->product_id,
                'addon_id' => $i->addon_id,
                'description' => (string) $i->description,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'discount' => (float) $i->discount,
                'tax_rate' => (float) $i->tax_rate,
                'details' => $i->details,
                'group' => 'g'.$i->id,
                'parent' => $i->parent_item_id !== null ? 'g'.$i->parent_item_id : null,
            ])->values()->all();

            CustomerInvoices::cancel($locked, BooksPeriod::fit(__('استُبدلت لعميل آخر'), $reason), $user->id);

            $draft = CustomerInvoices::create($bid, $customer, [
                'issued_at' => now()->toDateString(),
                'payment_terms_days' => $locked->payment_terms_days,
                'branch_id' => $locked->branch_id,
                'po_number' => $locked->po_number,
                'contract_number' => $locked->contract_number,
                'external_reference' => $locked->external_reference,
                'department' => $locked->department,
                'cost_center' => $locked->cost_center,
                'attention_to' => $locked->attention_to,
                'notes' => $locked->notes,
                'internal_notes' => $locked->internal_notes,
            ], $lines, $user->id);

            $draft->forceFill(['replaces_invoice_id' => $locked->id])->save();

            Activity::log('created', BooksPeriod::fit('استبدل فاتورة عميل '.$locked->number.' بمسودة لعميل آخر', $reason), [
                'subject_id' => $draft->id, 'subject_type' => 'customer_invoice', 'business_id' => $bid,
            ]);

            return $draft;
        });
    }

    /** فاتورةُ فترةٍ سابقة: قبل هذا الشهر، أو في دفاترَ أُقفلت — يُصحَّح مالُها ويُستبدل بصلاحيته */
    private static function prior(CustomerInvoice $invoice): bool
    {
        $supplied = Carbon::parse($invoice->issued_at)->startOfDay();
        $closed = BooksPeriod::closedThrough((int) $invoice->business_id);

        return $supplied->lt(now()->startOfMonth()) || ($closed !== null && $supplied->lte($closed));
    }

    /**
     * ما يمنع استبدالَ الفاتورة من فترتها — يُسأل تحت القفل قبل الإلغاء وعكسِ القيد والبديلة.
     *
     * الاستبدالُ يُلغي الفاتورةَ ويعكس قيدَها كلَّه اليوم، ولا يصدر له مستندُ
     * تصحيح: فيُمنع بما يُمنع به تصحيحُ مالها (`standing`)، ولا تتجاوزه صلاحية.
     *   - الفترةُ الضريبيّةُ المُقرّة: لمتجرٍ مسجَّلٍ في الضريبة، أو لفاتورةٍ حملت
     *     ضريبةً ولو أُطفئت بعدها. وغيرُ المسجَّل لا إقرارَ له يُعكس فيه شيء.
     *   - الدفاترُ المقفلةُ حتى اليوم: لا يُكتب فيها عكسُ القيد — لأيّ متجر.
     */
    private static function periodRefusal(CustomerInvoice $invoice): ?string
    {
        $bid = (int) $invoice->business_id;
        $taxed = Vat::enabled($bid) || (float) $invoice->tax_total > 0;

        if ($taxed && Vat::isFiled($bid, $invoice->issued_at)) {
            return __('الفاتورة في فترة ضريبية قُدّم إقرارها حتى :d — استبدالها يلغيها ويعكس ضريبتها بلا مستند تصحيح، وقد يحتاج ذلك إقرارًا معدّلًا. القرار للمحاسب الضريبي، وتبقى الفاتورة كما صدرت.', [
                'd' => Vat::filedThrough($bid)?->toDateString(),
            ]);
        }

        $closed = BooksPeriod::closedThrough($bid);
        if ($closed !== null && $closed->gte(now()->startOfDay())) {
            return __('الدفاتر مقفلة حتى :d — لا يُعكس قيد الفاتورة اليوم، فلا تُلغى ولا تُستبدل.', ['d' => $closed->toDateString()]);
        }

        return null;
    }

    /* ═══════════════════════════ المسودّة ═══════════════════════════ */

    /** @return array<string, mixed> */
    private static function planDraft(CustomerInvoice $invoice, array $input, ?User $user): array
    {
        $bid = (int) $invoice->business_id;
        $blockers = [];
        $fromOrder = $invoice->orders->isNotEmpty();

        $customer = self::customer($bid, $input);
        $customerChanged = (int) $customer->id !== (int) $invoice->customer_id;
        [$branchId, $branchChanged] = self::branch($bid, $invoice, $input);

        $issued = Carbon::parse($input['issued_at'])->startOfDay();
        $oldIssued = $invoice->issued_at ? Carbon::parse($invoice->issued_at)->startOfDay() : $issued->copy();
        $dateChanged = $issued->toDateString() !== $oldIssued->toDateString();
        $due = self::due($input, $issued);

        $lines = self::draftLines($invoice, $input['items'] ?? null);
        $itemsChanged = $lines['changed'];

        $totals = $itemsChanged ? CustomerInvoices::compute($bid, $lines['compute'], null, Vat::inclusive($bid)) : null;
        $newTotal = $totals ? $totals['total'] : round((float) $invoice->total, 3);
        $newTax = $totals ? $totals['tax'] : round((float) $invoice->tax_total, 3);
        $moneyChanged = $totals !== null && (
            abs($totals['total'] - (float) $invoice->total) >= 0.0005
            || abs($totals['tax'] - (float) $invoice->tax_total) >= 0.0005
            || abs($totals['subtotal'] - (float) $invoice->subtotal) >= 0.0005
            || abs($totals['discount'] - (float) $invoice->discount_total) >= 0.0005
        );

        $header = self::header($input, $issued, $due, $branchId, $customer);
        $changes = self::headerChanges($invoice, $header, $oldIssued, $bid);

        if ($fromOrder && ($itemsChanged || $customerChanged || $dateChanged || $branchChanged)) {
            $blockers[] = __('هذه الفاتورة مولودة من طلب: بنودها ومبلغها وتاريخها وعميلها وفرعها من الطلب وقيده ومخزونه. صحّح الطلب نفسه — ويبقى تعديل المراجع والملاحظات والاستحقاق هنا.');
        }

        if ($customerChanged) {
            CustomerFlags::assertSellable($customer, CustomerFlags::settings($bid), null, null, 'customer_id');
        }

        if (self::branchRefused($user, $invoice, $branchId, $branchChanged)) {
            $blockers[] = __('لست مُسنَدًا إلى هذا الفرع — لا تُنقل الفاتورة إليه ولا منه إلّا ممّن يعمل فيه.');
        }

        $actions = [Permissions::CUSTOMER_INVOICE_EDIT_DRAFT];

        return [
            'kind' => CustomerInvoiceRevision::DRAFT,
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
            'money' => [
                'total_before' => round((float) $invoice->total, 3),
                'total_after' => $newTotal,
                'tax_before' => round((float) $invoice->tax_total, 3),
                'tax_after' => $newTax,
                'difference' => round($newTotal - (float) $invoice->total, 3),
                'tax_difference' => round($newTax - (float) $invoice->tax_total, 3),
                'paid' => 0.0,
                'credited' => 0.0,
                'debited' => 0.0,
                'outstanding_before' => 0.0,
                'outstanding_after' => 0.0,
                'customer_credit' => 0.0,
            ],
            'documents' => ['credit' => null, 'debit' => null],
            'period' => ['note_date' => null, 'prior' => false, 'closed_through' => BooksPeriod::closedThrough($bid)?->toDateString(), 'filed_through' => Vat::filedThrough($bid)?->toDateString()],
            'stock' => self::stock($fromOrder),
            'needs_reason' => false,
            'needs_basis' => false,
            'basis' => null,
            'actions' => $actions,
            'missing_actions' => self::missing($user, $actions),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /** @param  array<string, mixed>  $plan */
    private static function applyDraft(CustomerInvoice $locked, array $plan, string $reason, int $number, string $token, User $user): CustomerInvoiceRevision
    {
        $bid = (int) $locked->business_id;
        $before = self::snapshot($locked);

        self::write($locked, $plan);

        $locked->forceFill(['revision' => $number])->save();
        $locked = $locked->fresh(['items']);

        $revision = CustomerInvoiceRevision::create([
            'business_id' => $bid,
            'customer_invoice_id' => $locked->id,
            'revision' => $number,
            'kind' => CustomerInvoiceRevision::DRAFT,
            'reason' => $reason !== '' ? $reason : null,
            'before' => $before,
            'after' => self::snapshot($locked),
            'changes' => ['fields' => $plan['changes'], 'lines' => $plan['lines'], 'money' => $plan['money'], 'stock' => $plan['stock']],
            'total_before' => $plan['money']['total_before'],
            'total_after' => round((float) $locked->total, 3),
            'tax_before' => $plan['money']['tax_before'],
            'tax_after' => round((float) $locked->tax_total, 3),
            'edit_token' => $token,
            'created_by' => $user->id,
        ]);

        Activity::log('updated', BooksPeriod::fit(
            'عدّل مسودة فاتورة عميل (التعديل '.$number.'): '.$plan['money']['total_before'].' ← '.round((float) $locked->total, 3),
            $reason !== '' ? $reason : null,
        ), [
            'subject_id' => $locked->id, 'subject_type' => 'customer_invoice', 'business_id' => $bid,
        ]);

        return $revision;
    }

    /* ═══════════════════════════ التصحيح ═══════════════════════════ */

    /** @return array<string, mixed> */
    private static function planCorrection(CustomerInvoice $invoice, array $input, ?User $user): array
    {
        $bid = (int) $invoice->business_id;
        $kind = self::kind($invoice);
        $blockers = [];
        $fromOrder = $invoice->orders->isNotEmpty();
        $state = self::state($invoice);

        /* ═══ رأسُ الفاتورة — مطبوعٌ عليها، فلا يتغيّر ═══ */
        $customer = self::customer($bid, $input);
        [$branchId] = self::branch($bid, $invoice, $input);
        $issued = Carbon::parse($input['issued_at'])->startOfDay();
        $oldIssued = $invoice->issued_at ? Carbon::parse($invoice->issued_at)->startOfDay() : $issued->copy();
        $due = self::due($input, $issued);
        $header = self::header($input, $issued, $due, $branchId, $customer);
        $all = self::headerChanges($invoice, $header, $oldIssued, $bid);

        $changes = array_values(array_filter($all, fn ($c) => in_array($c['field'], self::AFTER_ISSUE, true)));
        $printed = array_values(array_filter($all, fn ($c) => ! in_array($c['field'], self::AFTER_ISSUE, true)));

        if (collect($printed)->contains('field', 'customer_id')) {
            $blockers[] = __('اسم العميل ورقمه الضريبي من بيانات الفاتورة الصادرة — لا يتغيّران عليها. استعمل «إلغاء وإصدار بديلة» لفاتورة لم يُقبض منها شيء، أو أصدر إشعارًا دائنًا ثم فاتورة جديدة للعميل الصحيح.');
        } elseif ($printed !== []) {
            $blockers[] = __('لا تتغيّر بيانات الفاتورة الصادرة المطبوعة عليها (:f) — التصحيح بعد الإصدار مستندٌ مستقل لا إعادة كتابة للفاتورة. يُعدَّل هنا البنود والملاحظات الداخلية.', [
                'f' => implode('، ', array_column($printed, 'label')),
            ]);
        }

        /* ═══ البنود ═══ */
        $lines = self::correctionLines($invoice, $state, $input['items'] ?? null, (int) $invoice->revision + 1);
        $blockers = [...$blockers, ...$lines['blockers']];
        $itemsChanged = $lines['changed'];
        $inclusive = $itemsChanged ? self::inclusiveOf($invoice) : null;

        if ($itemsChanged && $inclusive === null) {
            $blockers[] = __('تعذّر معرفة قاعدة ضريبة هذه الفاتورة من بنودها (شاملة أم مضافة) — لا يُحسب مستند تصحيحها بقاعدةٍ غير قاعدتها. أصدر إشعارًا دائنًا بمبلغه من صفحة الفاتورة.');
        }

        $calc = $itemsChanged && $inclusive !== null
            ? self::deltas($bid, $state, $lines['proposed'], $inclusive)
            : ['after' => null, 'credit' => [], 'debit' => [], 'silent' => []];

        foreach ($calc['silent'] as $name) {
            $blockers[] = __('تغيّرت كمية «:d» أو سعرها ولم يتغيّر مبلغها — لا يصدر مستند تصحيح بلا مبلغ. اترك البند كما هو.', ['d' => $name]);
        }

        $credit = self::document($calc['credit']);
        $debit = self::document($calc['debit']);
        $moneyChanged = $credit !== null || $debit !== null;

        /* ═══ المال ═══ */
        $paid = $invoice->paidTotal();
        $credited = $invoice->creditedTotal();
        $debited = $invoice->debitedTotal();
        $before = $invoice->effectiveTotal();
        $after = round($before - ($credit['amount'] ?? 0) + ($debit['amount'] ?? 0), 3);
        $taxBefore = round((float) $invoice->tax_total + (float) $invoice->debitNotes()->sum('tax_amount') - (float) $invoice->creditNotes()->sum('tax_amount'), 3);
        $taxAfter = round($taxBefore - ($credit['tax'] ?? 0) + ($debit['tax'] ?? 0), 3);
        $owed = round($after - $paid, 3);

        // والإشعاراتُ اليدويّة بلا بنود تبقى منقوصةً من البنود — فلا تنزل البنودُ تحتها
        $unlinked = round((float) $invoice->creditNotes()->whereNull('revision_id')->sum('amount'), 3);
        if ($calc['after'] !== null && $calc['after']['total'] + 0.0005 < $unlinked) {
            $blockers[] = __('على الفاتورة إشعارات دائنة يدوية بـ :n — لا تنزل بنودها تحتها.', ['n' => number_format($unlinked, 3)]);
        }

        /* ═══ ما يمنع ═══ */
        $standing = self::standing($invoice);

        if ($fromOrder && $itemsChanged && isset($standing['order'])) {
            $blockers[] = $standing['order'];
        }

        if ($moneyChanged) {
            foreach (['link', 'filed', 'closed'] as $why) {
                if (isset($standing[$why])) {
                    $blockers[] = $standing[$why];
                }
            }
        }

        $prep = $invoice->preparation;
        if ($lines['quantities'] && $prep !== null && ! in_array($prep->status, CustomerInvoicePreparations::CLOSED, true)) {
            $blockers[] = __('على الفاتورة طلب تجهيز لم يُسلَّم — لا تتغيّر أصنافها ولا كمياتها تحت من يجهّزها. يُصحَّح السعر والخصم هنا، والكمية بعد التسليم.');
        }

        $basis = in_array($input['basis'] ?? null, CustomerInvoiceRevision::BASES, true) ? $input['basis'] : null;

        if ($basis !== null && ! in_array($basis, self::OPEN_BASES, true)) {
            $blockers[] = __('الإرجاع والإلغاء الجزئي ليسا تصحيحًا لخطأ، ولم يُفتح مسارهما بعد: المادة 48 من اللائحة تشترط للإلغاء إشعارًا مدينًا يشير إلى الفاتورة، واسترجاع السلع وإعادة المقابل خلال ثلاثة أشهر. يُفتح بعد قرار المحاسب الضريبي.');
        }

        /* ═══ الصلاحيات ═══ */
        $closed = BooksPeriod::closedThrough($bid);
        $prior = self::prior($invoice);
        $actions = [self::actionFor($kind)];
        if ($moneyChanged && $prior) {
            $actions[] = Permissions::CUSTOMER_INVOICE_CORRECT_PERIOD;
        }

        return [
            'kind' => $kind,
            'from_order' => $fromOrder,
            'changed' => $changes !== [] || $moneyChanged,
            'money_changed' => $moneyChanged,
            'items_changed' => $itemsChanged,
            'changes' => $changes,
            'lines' => $lines['diff'],
            'header' => $header,
            'state_before' => $state,
            'state_after' => $calc['after'],
            'money' => [
                'total_before' => $before,
                'total_after' => $after,
                'tax_before' => $taxBefore,
                'tax_after' => $taxAfter,
                'difference' => round($after - $before, 3),
                'tax_difference' => round($taxAfter - $taxBefore, 3),
                'paid' => $paid,
                'credited' => $credited,
                'debited' => $debited,
                'outstanding_before' => max(0.0, round($before - $paid, 3)),
                'outstanding_after' => max(0.0, $owed),
                // ما قُبض فوق المطلوب الجديد — رصيدٌ دائنٌ للعميل، لا مالٌ يُردّ
                'customer_credit' => $owed < -0.0005 ? round(-$owed, 3) : 0.0,
            ],
            'documents' => ['credit' => $credit, 'debit' => $debit],
            'period' => [
                'note_date' => now()->toDateString(),
                'prior' => $prior,
                'closed_through' => $closed?->toDateString(),
                'filed_through' => Vat::filedThrough($bid)?->toDateString(),
            ],
            'stock' => self::stock($fromOrder),
            'needs_reason' => $moneyChanged,
            'needs_basis' => $moneyChanged,
            'basis' => $basis,
            'vat' => Vat::enabled($bid),
            'actions' => $actions,
            'missing_actions' => self::missing($user, $actions),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /** @param  array<string, mixed>  $plan */
    private static function applyCorrection(CustomerInvoice $locked, array $plan, string $reason, int $number, string $token, User $user): CustomerInvoiceRevision
    {
        $bid = (int) $locked->business_id;
        $head = self::head($locked);
        $after = $plan['state_after'] ?? $plan['state_before'];

        $revision = CustomerInvoiceRevision::create([
            'business_id' => $bid,
            'customer_invoice_id' => $locked->id,
            'revision' => $number,
            'kind' => $plan['kind'],
            'basis' => $plan['money_changed'] ? $plan['basis'] : null,
            'reason' => $reason !== '' ? $reason : null,
            'before' => $head + $plan['state_before'],
            'after' => array_merge($head, $after, array_intersect_key($plan['header'], array_flip(self::AFTER_ISSUE))),
            'changes' => [
                'fields' => $plan['changes'],
                'lines' => $plan['lines'],
                'money' => $plan['money'],
                'documents' => $plan['documents'],
                'stock' => $plan['stock'],
                'vat' => $plan['vat'],
            ],
            'total_before' => $plan['money']['total_before'],
            'total_after' => $plan['money']['total_after'],
            'tax_before' => $plan['money']['tax_before'],
            'tax_after' => $plan['money']['tax_after'],
            'paid_at_edit' => $plan['money']['paid'],
            'credited_at_edit' => $plan['money']['credited'],
            'edit_token' => $token,
            'created_by' => $user->id,
        ]);

        /*
         * والمدينُ قبل الدائن: نقصُ بندٍ وزيادةُ آخر قد يجعلان الدائنَ أكبرَ ممّا
         * على الورقة قبل الزيادة — و`creditNote` تردّ ما يتجاوز المطلوب.
         */
        if ($plan['documents']['debit'] !== null) {
            $d = $plan['documents']['debit'];
            CustomerInvoices::debitNote($locked, $d['amount'], $d['tax'], $reason, $d['lines'], $plan['basis'], $revision->id, $user->id);
        }

        if ($plan['documents']['credit'] !== null) {
            $c = $plan['documents']['credit'];
            CustomerInvoices::creditNote($locked, $c['amount'], $c['tax'], $reason, $user->id, CustomerInvoices::NOTE_MANUAL, null, $c['lines'], $plan['basis'], $revision->id);
        }

        $update = ['revision' => $number];
        foreach (self::AFTER_ISSUE as $field) {
            $update[$field] = $plan['header'][$field];
        }
        $locked->forceFill($update)->save();

        Activity::log('updated', BooksPeriod::fit(
            'صحّح فاتورة عميل '.$locked->number.' (التعديل '.$number.'): '.$plan['money']['total_before'].' ← '.$plan['money']['total_after'],
            $reason !== '' ? $reason : null,
        ), [
            'subject_id' => $locked->id, 'subject_type' => 'customer_invoice', 'business_id' => $bid,
        ]);

        return $revision;
    }

    /**
     * البنودُ المقترحة على حال الورقة — القائمُ بمفتاحه، والجديدُ من الكتالوج.
     *
     * ═══ وما لا يتغيّر في بندٍ صدر ═══
     *
     * وصفُه ونسبةُ ضريبته: الأوّلُ من بيانات الفاتورة المطبوعة (م١٤٤(٦))،
     * والثاني حكمٌ ضريبيٌّ لا تصحيحُ مبلغ. وتغييرُ أيٍّ منهما يُردّ بسببه حتى
     * يقرّره المحاسب. ويتغيّر الكمّيّةُ والسعرُ والخصم — وإضافةُ الكتالوج
     * ثمنُها ثمنُ بيعها لا ثمنُ اليوم.
     *
     * والبندُ الجديد بنسبةٍ من نسب الفاتورة نفسِها: ورقةٌ صدرت بخمسةٍ لا يُضاف
     * إليها بندٌ بنسبةٍ لم تُعرف عليها.
     *
     * @param  array<int, array<string, mixed>>|null  $input
     * @return array{changed: bool, quantities: bool, proposed: array<int, array<string, mixed>>, diff: array<int, array<string, mixed>>, blockers: array<int, string>}
     */
    private static function correctionLines(CustomerInvoice $invoice, array $state, ?array $input, int $revision): array
    {
        $none = ['changed' => false, 'quantities' => false, 'proposed' => [], 'diff' => [], 'blockers' => []];

        if ($input === null) {
            return $none;
        }

        $bid = (int) $invoice->business_id;
        $current = collect($state['lines'])->keyBy('key');
        $rates = $current->pluck('tax_rate')->map(fn ($r) => round((float) $r, 3))->unique()->values();
        $proposed = [];
        $blockers = [];
        $seen = [];
        $quantities = false;

        foreach (array_values($input) as $pos => $line) {
            // والمسودّةُ تُرسل المعرّف — وبندٌ صدر مفتاحُه من الحال (`i{id}`)
            $key = filled($line['key'] ?? null) ? (string) $line['key']
                : (filled($line['item_id'] ?? null) ? 'i'.(int) $line['item_id'] : null);

            if ($key !== null) {
                $was = $current->get($key);

                if (! $was || isset($seen[$key])) {
                    throw ValidationException::withMessages(['items' => __('بندٌ ليس من هذه الفاتورة.')]);
                }
                $seen[$key] = true;

                $next = $was;
                $next['quantity'] = round((float) $line['quantity'], 3);
                $next['discount'] = round((float) ($line['discount'] ?? 0), 3);
                if ($was['addon_id'] === null) {
                    $next['unit_price'] = round((float) $line['unit_price'], 3);
                }

                if (filled($line['description'] ?? null) && trim((string) $line['description']) !== (string) $was['description']) {
                    $blockers[] = __('وصف البند «:d» من بيانات الفاتورة المطبوعة — لا يتغيّر بعد الإصدار. احذف البند وأضف الصحيح، فيصدر بهما مستندا التصحيح.', ['d' => $was['description']]);
                }
                if (array_key_exists('tax_rate', $line) && $line['tax_rate'] !== null && $line['tax_rate'] !== ''
                    && abs((float) $line['tax_rate'] - (float) $was['tax_rate']) >= 0.0005) {
                    $blockers[] = __('نسبة ضريبة البند «:d» لا تتغيّر على فاتورة صادرة — تغيير المعاملة الضريبية قرار للمحاسب الضريبي.', ['d' => $was['description']]);
                }
                if (abs($next['quantity'] - (float) $was['quantity']) >= 0.0005) {
                    $quantities = true;
                }

                $proposed[] = $next;

                continue;
            }

            // بندٌ جديد — كبند الإنشاء: وصفٌ مطلوب، ومن الكتالوج بثمنه
            if (trim((string) ($line['description'] ?? '')) === '' && empty($line['product_id']) && empty($line['addon_id'])) {
                throw ValidationException::withMessages(['items' => __('اكتب وصف البند الجديد.')]);
            }

            $rate = array_key_exists('tax_rate', $line) && $line['tax_rate'] !== null && $line['tax_rate'] !== ''
                ? round((float) $line['tax_rate'], 3) : null;
            if ($rate === null && $rates->count() === 1) {
                $rate = (float) $rates->first();
            }
            if ($rate === null || ! $rates->contains(fn ($r) => abs($r - $rate) < 0.0005)) {
                $blockers[] = __('البند الجديد بنسبة ضريبة من نسب الفاتورة نفسها (:r) — لا تُضاف إليها معاملة ضريبية أخرى.', [
                    'r' => $rates->map(fn ($r) => rtrim(rtrim(number_format($r, 3, '.', ''), '0'), '.').'%')->implode('، '),
                ]);
                $rate = (float) $rates->first();
            }

            $line = array_diff_key($line, ['item_id' => true, 'key' => true]);
            $line['tax_rate'] = $rate;

            $expanded = InvoiceLineDetails::expand($bid, [$line]);
            CustomerInvoices::assertLinesBelong($bid, $expanded);
            $quantities = true;

            $groups = [];
            foreach ($expanded as $k => $new) {
                $newKey = 'r'.$revision.'-'.$pos.'-'.$k;
                if (($new['group'] ?? null) !== null) {
                    $groups[$new['group']] = $newKey;
                }

                $proposed[] = [
                    'key' => $newKey,
                    'parent_key' => ($new['parent'] ?? null) !== null ? ($groups[$new['parent']] ?? null) : null,
                    'item_id' => null,
                    'product_id' => $new['product_id'] ?? null,
                    'addon_id' => ! empty($new['addon_id']) ? (int) $new['addon_id'] : null,
                    'order_item_id' => null,
                    'description' => (string) ($new['description'] ?? ''),
                    'quantity' => round((float) ($new['quantity'] ?? 1), 3),
                    'unit_price' => round((float) ($new['unit_price'] ?? 0), 3),
                    'discount' => round((float) ($new['discount'] ?? 0), 3),
                    'tax_rate' => round((float) ($new['tax_rate'] ?? $rate), 3),
                    'details' => $new['details'] ?? null,
                ];
            }
        }

        $removed = $current->keys()->reject(fn ($k) => isset($seen[$k]))->values()->all();
        if ($removed !== []) {
            $quantities = true;
        }

        // إضافةٌ مدفوعةٌ بلا صنفها — أو صنفٌ حُذف وبقيت إضافتُه
        foreach ($current as $line) {
            if ($line['parent_key'] !== null && isset($seen[$line['key']]) && in_array($line['parent_key'], $removed, true)) {
                throw ValidationException::withMessages(['items' => __('حُذف صنفٌ وبقيت إضافته «:a» — احذف إضافاته معه.', ['a' => $line['description']])]);
            }
        }

        if ($proposed === []) {
            throw ValidationException::withMessages(['items' => __('الفاتورة تحتاج بندًا واحدًا على الأقل.')]);
        }

        $diff = [];
        foreach ($proposed as $line) {
            $was = $current->get($line['key']);
            if ($was === null) {
                $diff[] = ['change' => 'added', 'key' => $line['key'], 'before' => null, 'after' => self::lineShown($line)];
            } elseif (self::lineDiffers($was, $line)) {
                $diff[] = ['change' => 'changed', 'key' => $line['key'], 'before' => self::lineShown($was), 'after' => self::lineShown($line)];
            }
        }
        foreach ($removed as $key) {
            $diff[] = ['change' => 'removed', 'key' => $key, 'before' => self::lineShown($current->get($key)), 'after' => null];
        }

        return [
            'changed' => $diff !== [],
            'quantities' => $quantities,
            'proposed' => $proposed,
            'diff' => $diff,
            'blockers' => $blockers,
        ];
    }

    /**
     * فرقُ كلّ بندٍ بين حال الورقة والمقترح — ومنه مستندا التصحيح.
     *
     * يُحسب المقترحُ كلُّه بقاعدة الفاتورة (`compute` بقاعدتها الشاملة أو
     * المضافة)، ثمّ يُطرح كلُّ بندٍ من سابقه: ما نقص مبلغُه إلى الإشعار الدائن،
     * وما زاد إلى المدين. والضريبةُ تتبع المبلغ في البند الواحد: نسبتُه لا
     * تتغيّر (`correctionLines`)، فلا ينقص صافيه وتزيد ضريبتُه.
     *
     * @return array{after: array<string, mixed>, credit: array<int, array<string, mixed>>, debit: array<int, array<string, mixed>>, silent: array<int, string>}
     */
    private static function deltas(int $bid, array $state, array $proposed, bool $inclusive): array
    {
        $totals = CustomerInvoices::compute($bid, $proposed, null, $inclusive);

        if (count($totals['items']) !== count($proposed)) {
            throw new RuntimeException(__('تعذّر حساب بنود الفاتورة — أعد المحاولة.'));
        }

        $after = [];
        foreach ($proposed as $n => $line) {
            $row = $totals['items'][$n];
            $after[] = array_merge($line, [
                'quantity' => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'discount' => $row['discount'],
                'tax_rate' => $row['tax_rate'],
                'tax_amount' => $row['tax_amount'],
                'line_total' => $row['line_total'],
            ]);
        }

        $before = collect($state['lines'])->keyBy('key');
        $now = collect($after)->keyBy('key');
        $credit = [];
        $debit = [];
        $silent = [];

        foreach ($before->keys()->merge($now->keys())->unique() as $key) {
            $was = $before->get($key);
            $is = $now->get($key);
            $total = round((float) ($is['line_total'] ?? 0) - (float) ($was['line_total'] ?? 0), 3);
            $tax = round((float) ($is['tax_amount'] ?? 0) - (float) ($was['tax_amount'] ?? 0), 3);

            if (abs($total) < 0.0005 && abs($tax) < 0.0005) {
                if ($was !== null && $is !== null && self::lineDiffers($was, $is)) {
                    $silent[] = (string) $was['description'];
                }

                continue;
            }

            $row = self::noteLine($was, $is, $total, $tax);

            if ($total < 0 || ($total === 0.0 && $tax < 0)) {
                $credit[] = $row;
            } else {
                $debit[] = $row;
            }
        }

        $sum = round(array_sum(array_column($after, 'line_total')), 3);
        $expected = round((float) $state['total'] - array_sum(array_column($credit, 'total')) + array_sum(array_column($debit, 'total')), 3);

        // البنودُ والمستنداتُ تقول المبلغَ نفسَه — وإلّا فلا يُكتب شيء
        if (abs($sum - $expected) >= 0.0015) {
            throw new RuntimeException(__('تعذّر مطابقة مستندات التصحيح ببنود الفاتورة — لم يُكتب شيء.'));
        }

        return [
            'after' => [
                'lines' => $after,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount'],
                'tax_total' => $totals['tax'],
                'total' => $totals['total'],
            ],
            'credit' => $credit,
            'debit' => $debit,
            'silent' => $silent,
        ];
    }

    /**
     * سطرُ مستند التصحيح — البندُ وما تغيّر فيه، ومبلغُ الفرق وضريبتُه موجبين.
     *
     * والإشارةُ من نوع المستند لا من السطر: الدائنُ ينقص والمدينُ يزيد.
     *
     * @return array<string, mixed>
     */
    private static function noteLine(?array $was, ?array $is, float $total, float $tax): array
    {
        $line = $is ?? $was;

        return [
            'key' => $line['key'],
            'change' => $was === null ? 'added' : ($is === null ? 'removed' : 'changed'),
            'description' => (string) $line['description'],
            'quantity_before' => $was !== null ? round((float) $was['quantity'], 3) : null,
            'quantity_after' => $is !== null ? round((float) $is['quantity'], 3) : null,
            'unit_price_before' => $was !== null ? round((float) $was['unit_price'], 3) : null,
            'unit_price_after' => $is !== null ? round((float) $is['unit_price'], 3) : null,
            'discount_before' => $was !== null ? round((float) $was['discount'], 3) : null,
            'discount_after' => $is !== null ? round((float) $is['discount'], 3) : null,
            'tax_rate' => round((float) $line['tax_rate'], 3),
            'taxable' => round(abs($total - $tax), 3),
            'tax' => round(abs($tax), 3),
            'total' => round(abs($total), 3),
        ];
    }

    /**
     * مستندُ تصحيحٍ من سطوره — مبلغُه وضريبتُه وصافيه، أو لا مستند.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{amount: float, tax: float, net: float, lines: array<int, array<string, mixed>>}|null
     */
    private static function document(array $lines): ?array
    {
        if ($lines === []) {
            return null;
        }

        $amount = round(array_sum(array_column($lines, 'total')), 3);
        $tax = round(array_sum(array_column($lines, 'tax')), 3);

        if ($amount < 0.0005 && $tax < 0.0005) {
            return null;
        }

        return ['amount' => $amount, 'tax' => $tax, 'net' => round($amount - $tax, 3), 'lines' => $lines];
    }

    /* ═══════════════════════════ ما يشترك فيه الطريقان ═══════════════════════════ */

    /** @return array<string, mixed> */
    private static function head(CustomerInvoice $invoice): array
    {
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
        ];
    }

    private static function customer(int $bid, array $input): Customer
    {
        $customer = Customer::withTrashed()->where('business_id', $bid)->whereKey($input['customer_id'] ?? 0)->first();
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => __('هذا العميل ليس من عملاء متجرك.')]);
        }

        return $customer;
    }

    /** @return array{0: ?int, 1: bool} */
    private static function branch(int $bid, CustomerInvoice $invoice, array $input): array
    {
        $branchId = filled($input['branch_id'] ?? null) ? (int) $input['branch_id'] : null;
        if ($branchId !== null && ! Branch::where('business_id', $bid)->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => __('هذا الفرع ليس من فروع متجرك.')]);
        }

        return [$branchId, $branchId !== ($invoice->branch_id !== null ? (int) $invoice->branch_id : null)];
    }

    private static function due(array $input, Carbon $issued): ?string
    {
        $due = filled($input['due_at'] ?? null) ? Carbon::parse($input['due_at'])->toDateString() : null;
        if ($due !== null && $due < $issued->toDateString()) {
            throw ValidationException::withMessages(['due_at' => __('تاريخ الاستحقاق قبل تاريخ الفاتورة.')]);
        }

        return $due;
    }

    private static function branchRefused(?User $user, CustomerInvoice $invoice, ?int $branchId, bool $changed): bool
    {
        return $changed && $user !== null
            && (($branchId !== null && ! $user->worksAt($branchId))
                || ($invoice->branch_id !== null && ! $user->worksAt((int) $invoice->branch_id)));
    }

    /** @return array<int, array<string, mixed>> */
    private static function headerChanges(CustomerInvoice $invoice, array $header, Carbon $oldIssued, int $bid): array
    {
        $changes = [];
        foreach ([...self::BOUND, ...self::PLAIN] as $field) {
            $before = self::scalar($field === 'issued_at' ? $oldIssued->toDateString() : ($field === 'due_at' ? optional($invoice->due_at)->toDateString() : $invoice->{$field}));
            $after = self::scalar($header[$field]);

            if ($before !== $after) {
                $changes[] = ['field' => $field, 'label' => __(self::LABELS[$field]), 'before' => self::shown($field, $before, $bid), 'after' => self::shown($field, $after, $bid)];
            }
        }

        return $changes;
    }

    /** @return array{moves: bool, why: string} */
    private static function stock(bool $fromOrder): array
    {
        return [
            'moves' => false,
            'why' => $fromOrder
                ? __('مخزون هذه الفاتورة خرج مع طلبها، ولا تُعدَّل كمياتها من هنا — فلا حركة مخزون.')
                : __('الفاتورة اليدوية لا تُخرج بضاعة من المخزون ولا تُقيّد تكلفة — فتعديل كمياتها لا يحرّك المخزون.'),
        ];
    }

    /** @return array<int, string> */
    private static function missing(?User $user, array $actions): array
    {
        return $user ? array_values(array_filter($actions, fn ($a) => ! $user->may($a))) : $actions;
    }

    /**
     * بنودُ المسودّة كما ستُكتب — القائمُ بمعرّفه، والجديدُ من الكتالوج كبند الإنشاء.
     *
     * ═══ والقائمُ لا يمرّ من الكتالوج ثانيةً ═══
     *
     * بندٌ يحمل معرّفَه يبقى صفَّه: لقطتُه (الصنفُ والإضافةُ والتفاصيل) وأبوه
     * كما كانا، ويتغيّر منه الكمّيّةُ والسعرُ والخصمُ والضريبة. وإضافةُ الكتالوج
     * سعرُها سعرُ بيعها لا سعرُ اليوم. والجديدُ يمرّ من `InvoiceLineDetails::expand`
     * كما في الإنشاء: من متجر الورقة، بسعر الكتالوج، وإضافاتُه تحته.
     *
     * @param  array<int, array<string, mixed>>|null  $input
     * @return array{changed: bool, compute: array<int, array<string, mixed>>, kept: array<int, array<string, mixed>>, removed: array<int, int>, diff: array<int, array<string, mixed>>}
     */
    private static function draftLines(CustomerInvoice $invoice, ?array $input): array
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
            $id = filled($line['item_id'] ?? null) ? (int) $line['item_id']
                : (preg_match('/^i(\d+)$/', (string) ($line['key'] ?? ''), $m) ? (int) $m[1] : null);

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
                    $diff[] = ['change' => 'changed', 'key' => 'i'.$id, 'before' => self::lineShown($was), 'after' => self::lineShown($next)];
                }

                continue;
            }

            if (trim((string) ($line['description'] ?? '')) === '' && empty($line['product_id']) && empty($line['addon_id'])) {
                throw ValidationException::withMessages(['items' => __('اكتب وصف البند الجديد.')]);
            }

            // ونسبةٌ فارغةٌ تُحذف لا تُقرأ صفرًا — كما في الإنشاء (`compute` تقرأ وجودَ المفتاح)
            $line = array_diff_key($line, ['item_id' => true, 'key' => true]);
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
                $diff[] = ['change' => 'added', 'key' => null, 'before' => null, 'after' => self::lineShown($new)];
            }
        }

        $removed = $existing->keys()->reject(fn ($id) => isset($seen[(int) $id]))->map(fn ($id) => (int) $id)->values()->all();

        foreach ($existing as $item) {
            if ($item->parent_item_id !== null && isset($seen[(int) $item->id]) && in_array((int) $item->parent_item_id, $removed, true)) {
                throw ValidationException::withMessages(['items' => __('حُذف صنفٌ وبقيت إضافته «:a» — احذف إضافاته معه.', ['a' => $item->description])]);
            }
        }

        foreach ($removed as $id) {
            $diff[] = ['change' => 'removed', 'key' => 'i'.$id, 'before' => self::lineShown(self::itemLine($existing->get($id))), 'after' => null];
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

        // ولقطةُ الجهة من العميل الجديد — والقديمُ تبقى لقطتُه كما كُتبت
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
