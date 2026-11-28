<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceRevision;
use App\Models\CustomerPaymentLink;
use App\Models\FinancialCorrection;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * الاعتمادُ الثنائيّ لتصحيح الفاتورة ماليًّا — من يطلب لا يعتمد.
 *
 * ═══ متى يقع ═══
 *
 * حين يُشعل صاحبُ النشاط «الاعتماد الثنائي» من الإعدادات (`SETTING`)، وحين
 * يمسّ التصحيحُ المال (`touchesMoney`):
 *   - تصحيحُ فاتورةٍ صادرةٍ أو مدفوعة يصدر به إشعارٌ دائنٌ أو مدين (#195).
 *   - «إلغاء وإصدار بديلة» لعميلٍ آخر: يلغي الفاتورةَ ويعكس قيدَها.
 *   - إشعارٌ دائنٌ يدويّ من صفحة الفاتورة (`CREDIT_NOTE`).
 *   - إلغاءُ فاتورةٍ صادرة (`INVOICE_CANCEL`).
 *
 * والحارسُ في الخدمة نفسِها لا في الشاشة (`guard`): `CustomerInvoices::creditNote`
 * و`::cancel` و`CustomerInvoiceEdits::apply` و`::replace` تردّ ما لم يأتِ من اعتماد
 * طلبه — فلا يتجاوزها مسارٌ مباشر ولا استدعاءٌ داخليّ. وطلبٌ معلّقٌ على الورقة يمنع
 * ما سواه عليها ولو أُطفئ المفتاح: الإطفاءُ لا يُنفّذ معلّقًا ولا يتجاوز اعتمادَه.
 *
 * والمسودّةُ والملاحظاتُ الداخليّة تُحفظ كما كانت: لا دفترَ يتحرّك فيها.
 * ومتجرٌ لم يُشعله يعمل كما كان تمامًا — المفتاحُ مطفأٌ لكلّ نشاطٍ قائمٍ وجديد،
 * ولا هجرةَ تُشعله.
 *
 * ═══ ما يقع قبل الاعتماد ═══
 *
 * لا شيء في الفاتورة ولا في الدفتر ولا في التحصيلات ولا في النقاط: يُكتب صفٌّ
 * في `financial_corrections` يحمل التصحيحَ كما طُلب وبصمةَ الفاتورة يومَها.
 *
 * ═══ وما يقع عنده ═══
 *
 * تحت قفل الطلب ثمّ قفل الفاتورة:
 *   ١. الطلبُ ما زال معلّقًا — فلا يُعتمد مرّتين.
 *   ٢. المعتمدُ غيرُ مقدّمه، ويملك `financialCorrection.approve`، ومن متجره.
 *   ٣. الفاتورةُ كما كانت يوم الطلب (`fingerprint`) — وإلّا أُغلق الطلبُ
 *      «تغيّرت الفاتورة» ولم يُنفَّذ: لا يُعتمد تصحيحٌ على ورقةٍ لم يرها المعتمد.
 *   ٤. ثمّ يُنفَّذ بالباب نفسِه (`CustomerInvoiceEdits::apply` أو `replace`)
 *      باسم مقدّمه: يُعاد السؤالُ عن صلاحياته اليوم، والفترة اليوم، وكلِّ مانع.
 *      فإن ردّ لم يُكتب شيء.
 */
final class FinancialCorrections
{
    /** مفتاحُ الإعداد — `SettingController::KEYS` */
    public const SETTING = 'dual_approval_corrections';

    /**
     * الطلبُ الذي يُنفَّذ الآن باعتماده — يعبر به الحارسُ (`guard`) في الخدمات التي
     * يناديها التنفيذ: الإشعارُ والإلغاءُ، والإلغاءُ داخل الاستبدال. يُضبط في `approve`
     * وحدها ويُرفع في `finally`، فلا يبقى معلّقًا بعد طلبٍ واحد.
     */
    private static ?FinancialCorrection $executing = null;

    /**
     * حارسُ الخدمة: إشعارٌ يدويّ أو إلغاءُ صادرة لا يقع إلّا باعتماد طلبه حين يُشعَل
     * الاعتماد، ولا فوق طلبٍ معلّقٍ على الورقة أبدًا.
     *
     * @throws NeedsApproval يحتاج اعتمادًا — يُكتب طلبُه
     * @throws RuntimeException على الورقة طلبٌ معلّق
     */
    public static function guard(CustomerInvoice $invoice, string $kind): void
    {
        $executing = self::$executing;
        if ($executing !== null && (int) $executing->customer_invoice_id === (int) $invoice->id) {
            return;
        }

        if (self::pendingFor($invoice)) {
            throw new RuntimeException(__('على هذه الفاتورة طلب تصحيح مالي بانتظار الاعتماد — يُعتمد أو يُرفض أو يُسحب أولًا.'));
        }

        if (self::required((int) $invoice->business_id)) {
            throw new NeedsApproval($kind === FinancialCorrection::INVOICE_CANCEL
                ? __('إلغاء هذه الفاتورة يحتاج اعتماد شخص ثانٍ — أُرسل للاعتماد ولم تُلغَ بعد.')
                : __('إشعار الدائن هذا يحتاج اعتماد شخص ثانٍ — أُرسل للاعتماد ولم يصدر بعد.'));
        }
    }

    /** هل أشعل هذا النشاطُ الاعتمادَ الثنائيّ؟ — لا صفَّ يعني مطفأ */
    public static function required(int $businessId): bool
    {
        return Setting::where('business_id', $businessId)->where('key', self::SETTING)->value('value') === '1';
    }

    /**
     * هل يمسّ هذا التصحيحُ المال؟ — يصدر به إشعارٌ دائنٌ أو مدين.
     *
     * @param  array<string, mixed>  $plan  `CustomerInvoiceEdits::plan`
     */
    public static function touchesMoney(array $plan): bool
    {
        return $plan['kind'] !== CustomerInvoiceRevision::DRAFT
            && (($plan['documents']['credit'] ?? null) !== null || ($plan['documents']['debit'] ?? null) !== null);
    }

    /**
     * ما يقوله الاعتمادُ عن هذا التصحيح — يُضاف إلى الخطّة، فتقوله المعاينةُ قبل الحفظ.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function gate(CustomerInvoice $invoice, array $plan, ?User $user, ?FinancialCorrection $approving): array
    {
        $bid = (int) $invoice->business_id;

        /*
         * وطلبٌ ينتظر الاعتماد يُبقي الورقةَ كما هي حتى يُبتّ فيه.
         *
         * تعديلٌ يقع فوقه يغيّر بصمتها فيُغلق الطلب «تغيّرت الفاتورة» — فيُقال
         * قبل الحفظ لا بعده. والطلبُ الذي يُعتمد الآن لا يمنع نفسه.
         */
        if (self::pendingFor($invoice, $approving)) {
            $plan['blockers'][] = __('على هذه الفاتورة طلب تصحيح مالي بانتظار الاعتماد — يُعتمد أو يُرفض أو يُسحب أولًا.');
        }

        $required = self::required($bid) && self::touchesMoney($plan);
        $plan['approval'] = ['required' => $required];

        if ($required && $approving === null && $user !== null && ($why = self::nobodyToApprove($bid, $user)) !== null) {
            $plan['blockers'][] = $why;
        }

        return $plan;
    }

    /**
     * ولا يُرسل طلبٌ لا يعتمده أحد: المعتمدُ غيرُ صاحبه. معتمدٌ عُطّل بعد
     * الإشعال يترك صاحبَ الطلب وحده — فيُقال له لماذا وكيف يمضي، لا أن يُعلَّق
     * تصحيحُه بلا من يبتّ فيه.
     */
    public static function nobodyToApprove(int $businessId, User $requester): ?string
    {
        return self::approvers($businessId, (int) $requester->id) === 0
            ? __('الاعتماد الثنائي مفعّل، ولا يملك «اعتماد تصحيح مالي» موظفٌ نشطٌ غيرك — فلا يُرسل طلبٌ لا يعتمده أحد. امنح الصلاحية لموظف آخر من ملفه، أو يطفئ صاحب النشاط الاعتماد الثنائي من الإعدادات.')
            : null;
    }

    /** الطلبُ المعلّق على هذه الفاتورة — إلّا الذي يُعتمد الآن */
    public static function pendingFor(CustomerInvoice $invoice, ?FinancialCorrection $except = null): ?FinancialCorrection
    {
        return FinancialCorrection::where('business_id', $invoice->business_id)
            ->where('customer_invoice_id', $invoice->id)
            ->where('status', FinancialCorrection::PENDING)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->first();
    }

    /**
     * بصمةُ الفاتورة كما يراها الطلب: ورقتُها وحالُها ومقبوضُها وإشعاراتُها وقيدُها.
     *
     * تحصيلٌ يُسجَّل، أو إشعارٌ دائنٌ أو مدين، أو تصحيحٌ آخر، أو رابطُ دفع، أو
     * إلغاء — كلٌّ منها يغيّرها.
     */
    public static function fingerprint(CustomerInvoice $invoice): string
    {
        $invoice->loadMissing('items');

        return hash('sha256', (string) json_encode([
            'sheet' => CustomerInvoiceEdits::snapshot($invoice),
            'status' => $invoice->status,
            'revision' => (int) $invoice->revision,
            'customer' => (int) $invoice->customer_id,
            'paid' => round($invoice->paidTotal(), 3),
            'credited' => round($invoice->creditedTotal(), 3),
            'debited' => round($invoice->debitedTotal(), 3),
            'links' => CustomerPaymentLink::where('business_id', $invoice->business_id)
                ->where('customer_invoice_id', $invoice->id)->orderBy('id')->pluck('status', 'id')->all(),
            'entry' => CustomerInvoices::liveEntry($invoice)?->id,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * طلبُ تصحيح الفاتورة — يُفحص كما يُفحص الحفظ، ولا يكتب في الفاتورة شيئًا.
     *
     * @param  array<string, mixed>  $input  ما مرّ من `CustomerInvoiceEdits::rules()`
     *
     * @throws AuthorizationException ما لا يملكه
     * @throws ValidationException سببٌ أو أساسٌ ناقص
     * @throws RuntimeException ما يمنع التصحيح، بسببه
     */
    public static function request(CustomerInvoice $invoice, array $input, User $user): FinancialCorrection
    {
        $bid = (int) $invoice->business_id;
        $token = (string) $input['edit_token'];

        return DB::transaction(function () use ($invoice, $input, $user, $bid, $token) {
            $locked = CustomerInvoice::where('business_id', $bid)->lockForUpdate()->findOrFail($invoice->id);

            if ($twin = self::twin($bid, $token, (int) $locked->id)) {
                return $twin;
            }

            if ((int) $locked->revision !== (int) $input['expected_revision']) {
                throw new RuntimeException(__('عُدّلت هذه الفاتورة بعد أن فتحتها (التعديل :n) — افتحها من جديد لترى ما تغيّر، ثمّ أعد تعديلك.', ['n' => (int) $locked->revision]));
            }

            $plan = CustomerInvoiceEdits::plan($locked, $input, $user);

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

            if (! $plan['approval']['required']) {
                throw new RuntimeException(__('هذا التعديل لا يحتاج اعتمادًا — احفظه مباشرة.'));
            }

            $reason = trim((string) ($input['reason'] ?? ''));
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => __('اكتب سبب تصحيح الفاتورة — يُطبع على مستند التصحيح ويُحفظ في السجل.')]);
            }
            if ($plan['needs_basis'] && $plan['basis'] === null) {
                throw ValidationException::withMessages(['basis' => __('اختر أساس التصحيح.')]);
            }

            return self::open($locked, FinancialCorrection::INVOICE_EDIT, $input, self::summary($plan, $locked), $reason, $token, $user,
                'طلب تصحيح فاتورة عميل '.$locked->number.' بانتظار الاعتماد: '.$plan['money']['total_before'].' ← '.$plan['money']['total_after']);
        });
    }

    /**
     * طلبُ «إلغاء وإصدار بديلة» لعميلٍ آخر — يُفحص كما يُفحص الاستبدال، ولا يكتب شيئًا.
     *
     * @throws AuthorizationException ما لا يملكه
     * @throws ValidationException سببٌ أو عميلٌ غيرُ صالح
     * @throws RuntimeException ما يمنع الاستبدال، بسببه
     */
    public static function requestReplace(CustomerInvoice $invoice, Customer $customer, string $reason, string $token, User $user): FinancialCorrection
    {
        $bid = (int) $invoice->business_id;
        CustomerInvoiceEdits::assertMayReplace($invoice, $user);

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('اكتب سبب استبدال الفاتورة — يُكتب على إلغائها.')]);
        }

        return DB::transaction(function () use ($invoice, $customer, $reason, $token, $user, $bid) {
            $locked = CustomerInvoice::where('business_id', $bid)->lockForUpdate()->findOrFail($invoice->id);
            $locked->loadMissing('items', 'orders', 'preparation');

            if ($twin = self::twin($bid, $token, (int) $locked->id)) {
                return $twin;
            }

            if (self::pendingFor($locked)) {
                throw new RuntimeException(__('على هذه الفاتورة طلب تصحيح مالي بانتظار الاعتماد — يُعتمد أو يُرفض أو يُسحب أولًا.'));
            }

            if (($why = CustomerInvoiceEdits::replaceRefusal($locked, $customer)) !== null) {
                throw new RuntimeException($why);
            }

            if (($why = self::nobodyToApprove($bid, $user)) !== null) {
                throw new RuntimeException($why);
            }

            return self::open($locked, FinancialCorrection::INVOICE_REPLACE, ['customer_id' => (int) $customer->id, 'reason' => $reason], [
                'kind' => FinancialCorrection::INVOICE_REPLACE,
                'number' => $locked->number,
                'customer_before' => $locked->customer_name,
                'customer_after' => $customer->name,
                'total' => round((float) $locked->total, 3),
            ], $reason, $token, $user, 'طلب إلغاء فاتورة عميل '.$locked->number.' وإصدار بديلة للعميل '.$customer->name.' بانتظار الاعتماد');
        });
    }

    /**
     * طلبُ إشعارٍ دائنٍ يدويّ — يُفحص كما يُفحص الإصدار (`ManualInvoiceAdjustments::assertCreditNote`)
     * ولا يكتب شيئًا. والرمزُ رمزُ نموذج الإشعار: يُرسل مرّتين فيكون طلبًا واحدًا.
     *
     * @param  array{amount: float|string, tax_amount?: float|string|null, reason: string, basis: string, token: string}  $data
     *
     * @throws ValidationException مبلغٌ أو ضريبةٌ أو سببٌ لا يصلح
     * @throws RuntimeException ما يمنعه، بسببه
     */
    public static function requestCreditNote(CustomerInvoice $invoice, array $data, User $user): FinancialCorrection
    {
        $bid = (int) $invoice->business_id;
        $token = 'credit-note:'.(string) $data['token'];
        $amount = round((float) $data['amount'], 3);
        $tax = round((float) ($data['tax_amount'] ?? 0), 3);
        $reason = trim((string) $data['reason']);

        return DB::transaction(function () use ($invoice, $data, $user, $bid, $token, $amount, $tax, $reason) {
            $locked = CustomerInvoice::where('business_id', $bid)->lockForUpdate()->findOrFail($invoice->id);

            if ($twin = self::twin($bid, $token, (int) $locked->id)) {
                return $twin;
            }
            if (self::pendingFor($locked)) {
                throw new RuntimeException(__('على هذه الفاتورة طلب تصحيح مالي بانتظار الاعتماد — يُعتمد أو يُرفض أو يُسحب أولًا.'));
            }
            if ($locked->status !== CustomerInvoice::ISSUED) {
                throw new RuntimeException(__('لا يتجاوز إشعارُ الدائن قيمةَ الفاتورة.'));
            }

            ManualInvoiceAdjustments::assertCreditNote($locked, $amount, $tax, (string) $data['basis']);

            // والطلبُ الذي سُمّي عنه الإرجاعُ (#285) يُسأل الآن كما يُسأل عند الإصدار — ويُسأل ثانيةً عند الاعتماد
            $forOrder = isset($data['order_id']) ? (int) $data['order_id'] : null;
            if ($forOrder !== null) {
                CreditNoteOrders::assertFits($locked, $forOrder, $amount, $tax);
            }

            if (($why = self::nobodyToApprove($bid, $user)) !== null) {
                throw new RuntimeException($why);
            }

            return self::open($locked, FinancialCorrection::CREDIT_NOTE,
                ['amount' => $amount, 'tax_amount' => $tax, 'reason' => $reason, 'basis' => (string) $data['basis'], 'order_id' => $forOrder], [
                    'kind' => FinancialCorrection::CREDIT_NOTE,
                    'number' => $locked->number,
                    'basis' => (string) $data['basis'],
                    'documents' => ['credit' => ['amount' => $amount, 'tax' => $tax], 'debit' => null],
                    'total' => round((float) $locked->total, 3),
                ], $reason, $token, $user, 'طلب إشعار دائن بمبلغ '.$amount.' على فاتورة عميل '.$locked->number.' بانتظار الاعتماد');
        });
    }

    /**
     * طلبُ إلغاء فاتورةٍ صادرة — يُفحص كما يُفحص الإلغاء ولا يكتب شيئًا.
     *
     * ونموذجُ الإلغاء بلا رمز: الرمزُ رقمُ الورقة وعددُ ما بُتّ من طلباتها — فضغطتان
     * تعطيان طلبًا واحدًا، وطلبٌ جديدٌ بعد رفضٍ يُقبل.
     *
     * @throws RuntimeException ما يمنعه، بسببه
     */
    public static function requestCancel(CustomerInvoice $invoice, string $reason, User $user): FinancialCorrection
    {
        $bid = (int) $invoice->business_id;
        $reason = trim($reason);

        return DB::transaction(function () use ($invoice, $reason, $user, $bid) {
            $locked = CustomerInvoice::where('business_id', $bid)->lockForUpdate()->findOrFail($invoice->id);
            $token = 'cancel:'.$locked->id.':'.FinancialCorrection::where('business_id', $bid)->where('customer_invoice_id', $locked->id)
                ->where('status', '!=', FinancialCorrection::PENDING)->count();

            if ($twin = self::twin($bid, $token, (int) $locked->id)) {
                return $twin;
            }
            if (self::pendingFor($locked)) {
                throw new RuntimeException(__('على هذه الفاتورة طلب تصحيح مالي بانتظار الاعتماد — يُعتمد أو يُرفض أو يُسحب أولًا.'));
            }
            if ($locked->status !== CustomerInvoice::ISSUED) {
                throw new RuntimeException(__('الإلغاء بالاعتماد لفاتورةٍ صادرة.'));
            }
            if ($locked->paidTotal() > 0) {
                throw new RuntimeException(__('سُدِّد من هذه الفاتورة :n — ألغِ التحصيل أو أصدر إشعار دائن قبل الإلغاء.', ['n' => $locked->paidTotal()]));
            }
            if ($why = ManualInvoiceAdjustments::cancelRefusal($locked)) {
                throw new RuntimeException($why);
            }
            if (($why = self::nobodyToApprove($bid, $user)) !== null) {
                throw new RuntimeException($why);
            }

            return self::open($locked, FinancialCorrection::INVOICE_CANCEL, ['reason' => $reason], [
                'kind' => FinancialCorrection::INVOICE_CANCEL,
                'number' => $locked->number,
                'customer_before' => $locked->customer_name,
                'total' => round((float) $locked->total, 3),
            ], $reason, $token, $user, 'طلب إلغاء فاتورة عميل '.$locked->number.' بانتظار الاعتماد');
        });
    }

    /**
     * الاعتماد — يُنفَّذ التصحيحُ كلُّه أو لا شيء منه.
     *
     * @throws AuthorizationException معتمدٌ لا يملكه، أو هو مقدّمه
     * @throws ValidationException ما صار التصحيحُ يحتاجه اليوم
     * @throws RuntimeException طلبٌ بُتّ فيه، أو فاتورةٌ تغيّرت، أو مانعٌ جدّ
     */
    public static function approve(FinancialCorrection $correction, User $approver, ?string $note = null): FinancialCorrection
    {
        $bid = (int) $correction->business_id;
        $stale = false;

        $row = DB::transaction(function () use ($correction, $approver, $note, $bid, &$stale) {
            $row = self::lockPending($correction, $bid);
            self::assertDecider($row, $approver, $bid);

            $invoice = CustomerInvoice::where('business_id', $bid)->lockForUpdate()->findOrFail($row->customer_invoice_id);

            /*
             * تغيّرت الفاتورة بعد الطلب — يُكتب ذلك ولا يُنفَّذ.
             *
             * ويُحفظ في الصفّ لا في رسالةٍ تزول: الطلبُ لا يصلح بعدها أبدًا،
             * فلا يبقى «معلّقًا» يعتمده آخرُ على ورقةٍ غير التي طُلب لها.
             */
            if (self::fingerprint($invoice) !== $row->fingerprint) {
                $row->forceFill([
                    'status' => FinancialCorrection::STALE,
                    'decided_by' => $approver->id,
                    'decided_at' => now(),
                    'decision_note' => __('تغيّرت الفاتورة بعد الطلب — لم يُنفَّذ.'),
                ])->save();
                $stale = true;

                return $row;
            }

            $requester = User::where('business_id', $bid)->find($row->requested_by);

            if (! $requester || $requester->status === 'معطل') {
                throw new RuntimeException(__('مقدّم الطلب لم يعد موظفًا نشطًا في النشاط — ارفض الطلب، ويُطلب التصحيح من جديد.'));
            }

            self::$executing = $row;
            try {
                $done = match ($row->kind) {
                    FinancialCorrection::INVOICE_REPLACE => CustomerInvoiceEdits::replace(
                        $invoice,
                        Customer::where('business_id', $bid)->findOrFail((int) $row->payload['customer_id']),
                        (string) $row->payload['reason'],
                        $requester,
                        $row,
                    ),
                    FinancialCorrection::CREDIT_NOTE => self::issueApprovedCreditNote($invoice, $row, $requester),
                    FinancialCorrection::INVOICE_CANCEL => self::cancelApproved($invoice, $row, $requester),
                    default => CustomerInvoiceEdits::apply($invoice, $row->payload, $requester, $row),
                };
            } catch (AuthorizationException $e) {
                throw new AuthorizationException(__('مقدّم الطلب لم يعد يملك هذا التعديل — :why', ['why' => $e->getMessage()]));
            } finally {
                self::$executing = null;
            }

            $row->forceFill([
                'status' => FinancialCorrection::APPROVED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_note' => self::note($note),
                'customer_invoice_revision_id' => $done instanceof CustomerInvoiceRevision ? $done->id : null,
                'replacement_invoice_id' => $done instanceof CustomerInvoice && $row->kind === FinancialCorrection::INVOICE_REPLACE ? $done->id : null,
                'customer_credit_note_id' => $done instanceof CustomerCreditNote ? $done->id : null,
            ])->save();

            $what = match ($row->kind) {
                FinancialCorrection::INVOICE_REPLACE => 'إلغاء فاتورة عميل '.$invoice->number.' وإصدار بديلتها',
                FinancialCorrection::CREDIT_NOTE => 'إشعار دائن '.$done->number.' على فاتورة عميل '.$invoice->number,
                FinancialCorrection::INVOICE_CANCEL => 'إلغاء فاتورة عميل '.$invoice->number,
                default => 'تصحيح فاتورة عميل '.$invoice->number.' (التعديل '.$done->revision.')',
            };

            Activity::log('updated', BooksPeriod::fit('اعتمد '.$what.' طلبه '.$requester->name, (string) $note), [
                'subject_id' => $invoice->id, 'subject_type' => 'customer_invoice', 'business_id' => $bid,
            ]);

            return $row;
        });

        if ($stale) {
            throw new RuntimeException(__('تغيّرت الفاتورة بعد طلب التصحيح (تحصيل أو إشعار أو رابط دفع أو تعديل آخر) — لم يُنفَّذ، وأُغلق الطلب. يُطلب التصحيح من جديد على الفاتورة كما هي الآن.'));
        }

        return $row;
    }

    /** الإشعارُ الدائنُ المعتمَد — باسم مقدّمه، وبصلاحيته اليوم */
    private static function issueApprovedCreditNote(CustomerInvoice $invoice, FinancialCorrection $row, User $requester): CustomerCreditNote
    {
        if (! $requester->may(Permissions::CUSTOMER_CREDIT_NOTE)) {
            throw new AuthorizationException(Permissions::actionLabels()[Permissions::CUSTOMER_CREDIT_NOTE] ?? Permissions::CUSTOMER_CREDIT_NOTE);
        }

        return CustomerInvoices::creditNote(
            $invoice,
            (float) $row->payload['amount'],
            (float) ($row->payload['tax_amount'] ?? 0),
            (string) $row->payload['reason'],
            (int) $requester->id,
            basis: (string) $row->payload['basis'],
            // والطلبُ المسمّى في الطلب يُسمّى عند الإصدار، ويُسأل عن سعته من جديد (`CreditNoteOrders::assertFits`)
            forOrderId: isset($row->payload['order_id']) ? (int) $row->payload['order_id'] : null,
        );
    }

    /** الإلغاءُ المعتمَد — باسم مقدّمه، وبصلاحيته اليوم، وكلُّ موانع الإلغاء تُسأل من جديد */
    private static function cancelApproved(CustomerInvoice $invoice, FinancialCorrection $row, User $requester): CustomerInvoice
    {
        if (! $requester->may(Permissions::CUSTOMER_INVOICE_CANCEL)) {
            throw new AuthorizationException(Permissions::actionLabels()[Permissions::CUSTOMER_INVOICE_CANCEL] ?? Permissions::CUSTOMER_INVOICE_CANCEL);
        }

        return CustomerInvoices::cancel($invoice, (string) $row->payload['reason'], (int) $requester->id);
    }

    /** الرفض — بسببٍ يُكتب، ولا يمسّ الفاتورة */
    public static function reject(FinancialCorrection $correction, User $approver, string $reason): FinancialCorrection
    {
        $bid = (int) $correction->business_id;
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => __('اكتب سبب رفض التصحيح — يراه مقدّم الطلب.')]);
        }

        return DB::transaction(function () use ($correction, $approver, $reason, $bid) {
            $row = self::lockPending($correction, $bid);
            self::assertDecider($row, $approver, $bid);

            $row->forceFill([
                'status' => FinancialCorrection::REJECTED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_note' => self::note($reason),
            ])->save();

            Activity::log('updated', BooksPeriod::fit('رفض طلب تصحيح فاتورة عميل '.($row->summary['number'] ?? ''), $reason), [
                'subject_id' => $row->customer_invoice_id, 'subject_type' => 'customer_invoice', 'business_id' => $bid,
            ]);

            return $row;
        });
    }

    /** مقدّمُ الطلب يسحبه قبل أن يُبتّ فيه */
    public static function withdraw(FinancialCorrection $correction, User $user): FinancialCorrection
    {
        $bid = (int) $correction->business_id;

        return DB::transaction(function () use ($correction, $user, $bid) {
            $row = self::lockPending($correction, $bid);

            if ((int) $row->requested_by !== (int) $user->id) {
                throw new AuthorizationException(__('يسحب الطلبَ مقدّمُه وحده — والمعتمدُ يرفضه بسبب.'));
            }

            $row->forceFill([
                'status' => FinancialCorrection::WITHDRAWN,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ])->save();

            Activity::log('updated', 'سحب طلب تصحيح فاتورة عميل '.($row->summary['number'] ?? ''), [
                'subject_id' => $row->customer_invoice_id, 'subject_type' => 'customer_invoice', 'business_id' => $bid,
            ]);

            return $row;
        });
    }

    /**
     * كم موظّفًا نشطًا يعتمد غيرَ فلان — يملك `financialCorrection.approve` في متجره.
     *
     * مفتاحٌ يُشعَل ولا معتمدَ غيرُ صاحب التصحيح يُعلّق طلبَه بلا من يبتّ فيه —
     * وصاحبُ النشاط وحده في متجرٍ صغير أوّلُ من يقع فيه. فيسأله الإشعالُ
     * (`SettingController`) والطلبُ (`gate`) معًا.
     */
    public static function approvers(int $businessId, ?int $except = null): int
    {
        return User::where('business_id', $businessId)->where('role', '!=', 'super_admin')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'معطل'))
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->get()->filter(fn (User $u) => $u->may(Permissions::FINANCIAL_CORRECTION_APPROVE))->count();
    }

    /** أيبتّ هذا المستخدمُ في هذا الطلب؟ — للشاشة؛ والحارسُ `assertDecider` */
    public static function mayDecide(?User $user, FinancialCorrection $row): bool
    {
        return $user !== null
            && $row->isPending()
            && (int) $user->business_id === (int) $row->business_id
            && (int) $user->id !== (int) $row->requested_by
            && $user->may(Permissions::FINANCIAL_CORRECTION_APPROVE);
    }

    /**
     * ما يُعرض من الطلب — في بطاقة الفاتورة وفي قائمة الطلبات.
     *
     * @return array<string, mixed>
     */
    public static function present(FinancialCorrection $row, ?User $viewer): array
    {
        return [
            'id' => $row->id,
            'kind' => $row->kind,
            'status' => $row->status,
            'reason' => $row->reason,
            'summary' => $row->summary,
            'invoice' => $row->relationLoaded('invoice') && $row->invoice
                ? ['id' => $row->invoice->id, 'number' => $row->invoice->number]
                : ['id' => $row->customer_invoice_id, 'number' => $row->summary['number'] ?? null],
            'requested_by' => $row->requester?->name,
            'requested_at' => optional($row->created_at)->format('Y-m-d H:i'),
            'decided_by' => $row->decider?->name,
            'decided_at' => optional($row->decided_at)->format('Y-m-d H:i'),
            'decision_note' => $row->decision_note,
            'revision' => $row->revision?->revision,
            'replacement' => $row->replacement_invoice_id ? ['id' => (int) $row->replacement_invoice_id] : null,
            'credit_note' => $row->customer_credit_note_id
                ? ['id' => (int) $row->customer_credit_note_id, 'number' => $row->creditNote?->number]
                : null,
            'may_decide' => self::mayDecide($viewer, $row),
            'may_withdraw' => $viewer !== null && $row->isPending() && (int) $viewer->id === (int) $row->requested_by,
        ];
    }

    /** النموذجُ نفسُه أُرسل مرّتين: الطلبُ الأوّل هو الجواب */
    private static function twin(int $bid, string $token, int $invoiceId): ?FinancialCorrection
    {
        $twin = FinancialCorrection::where('business_id', $bid)->where('edit_token', $token)->first();

        if ($twin && (int) $twin->customer_invoice_id !== $invoiceId) {
            throw new RuntimeException(__('رمز النموذج هذا استُعمل لفاتورة أخرى — افتح شاشة التعديل من جديد.'));
        }

        return $twin;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $summary
     */
    private static function open(CustomerInvoice $locked, string $kind, array $payload, array $summary, string $reason, string $token, User $user, string $log): FinancialCorrection
    {
        $row = FinancialCorrection::create([
            'business_id' => $locked->business_id,
            'kind' => $kind,
            'customer_invoice_id' => $locked->id,
            'status' => FinancialCorrection::PENDING,
            'expected_revision' => (int) $locked->revision,
            'fingerprint' => self::fingerprint($locked),
            'payload' => $payload,
            'summary' => $summary,
            'reason' => mb_substr($reason, 0, 255),
            'edit_token' => mb_substr($token, 0, 64),
            'requested_by' => $user->id,
        ]);

        Activity::log('created', BooksPeriod::fit($log, $reason), [
            'subject_id' => $locked->id, 'subject_type' => 'customer_invoice', 'business_id' => $locked->business_id,
        ]);

        return $row;
    }

    private static function lockPending(FinancialCorrection $correction, int $bid): FinancialCorrection
    {
        $row = FinancialCorrection::where('business_id', $bid)->lockForUpdate()->findOrFail($correction->id);

        if (! $row->isPending()) {
            throw new RuntimeException(__('بُتّ في هذا الطلب من قبل — لا يُعتمد ولا يُرفض مرّتين.'));
        }

        return $row;
    }

    private static function assertDecider(FinancialCorrection $row, User $approver, int $bid): void
    {
        if ((int) $approver->business_id !== $bid) {
            throw new AuthorizationException(__('هذا الطلب ليس من نشاطك.'));
        }

        // ومعتمدٌ عُطّل حسابُه لا يبتّ — ولو بقيت جلستُه مفتوحة
        if ($approver->status === 'معطل') {
            throw new AuthorizationException(__('حسابك معطّل — لا تبتّ في طلبات التصحيح.'));
        }

        if ((int) $approver->id === (int) $row->requested_by) {
            throw new AuthorizationException(__('لا تعتمد طلبًا قدّمته أنت ولا ترفضه — يبتّ فيه موظفٌ آخر يملك الاعتماد، أو اسحبه.'));
        }

        if (! $approver->may(Permissions::FINANCIAL_CORRECTION_APPROVE)) {
            throw new AuthorizationException(__('ليس لديك صلاحية اعتماد التصحيحات المالية.'));
        }
    }

    private static function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 255);
    }

    /**
     * ما رآه مقدّمُ الطلب — يُحفظ كما كان، ويُعرض للمعتمد.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function summary(array $plan, CustomerInvoice $invoice): array
    {
        $doc = fn (?array $d) => $d === null ? null : ['amount' => $d['amount'], 'tax' => $d['tax']];

        return [
            'kind' => $plan['kind'],
            'number' => $invoice->number,
            'basis' => $plan['basis'] ?? null,
            'money' => $plan['money'],
            'changes' => $plan['changes'],
            'lines' => $plan['lines'],
            'documents' => ['credit' => $doc($plan['documents']['credit']), 'debit' => $doc($plan['documents']['debit'])],
            'note_date' => $plan['period']['note_date'] ?? null,
        ];
    }
}
