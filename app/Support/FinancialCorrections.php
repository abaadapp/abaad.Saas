<?php

namespace App\Support;

use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceRevision;
use App\Models\FinancialCorrection;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * الاعتمادُ الثنائيّ للتصحيحات الماليّة — من يطلب لا يعتمد.
 *
 * ═══ متى يقع ═══
 *
 * حين يُشعل صاحبُ النشاط «الاعتماد الثنائي» من الإعدادات (`SETTING`)، وحين
 * يمسّ التعديلُ المالَ: فاتورةٌ صادرةٌ أو مدفوعة يتغيّر مبلغُها أو عميلُها أو
 * يُعكس قيدُها (`touchesMoney`). والمسودّةُ والمراجعُ والملاحظاتُ تُحفظ كما
 * كانت: لا دفترَ يتحرّك فيها.
 *
 * ومتجرٌ لم يُشعله يعمل كما كان تمامًا — المفتاحُ مطفأٌ لكلّ نشاطٍ قائمٍ وجديد.
 *
 * ═══ ما يقع قبل الاعتماد ═══
 *
 * لا شيء في الفاتورة ولا في الدفتر ولا في المدفوعات ولا في المخزون: يُكتب
 * صفٌّ في `financial_corrections` يحمل التعديلَ كما طُلب وبصمةَ الفاتورة.
 *
 * ═══ وما يقع عنده ═══
 *
 * تحت قفل الطلب ثمّ قفل الفاتورة:
 *   ١. الطلبُ ما زال معلّقًا — فلا يُعتمد مرّتين.
 *   ٢. المعتمدُ غيرُ مقدّمه، ويملك `financialCorrection.approve`، ومن متجره.
 *   ٣. الفاتورةُ كما كانت يوم الطلب (`fingerprint`) — وإلّا صار الطلبُ
 *      «تغيّرت الفاتورة» ولم يُنفَّذ: لا يُعتمد تصحيحٌ على ورقةٍ لم يرها المعتمد.
 *   ٤. ثمّ `CustomerInvoiceEdits::apply` نفسُها باسم مقدّمه: تعيد السؤال عن
 *      صلاحياته اليوم، والفترة اليوم، وكلِّ مانع. فإن ردّت لم يُكتب شيء.
 */
final class FinancialCorrections
{
    /** مفتاحُ الإعداد — `SettingController::KEYS` */
    public const SETTING = 'dual_approval_corrections';

    /** هل أشعل هذا النشاطُ الاعتمادَ الثنائيّ؟ */
    public static function required(int $businessId): bool
    {
        return Setting::where('business_id', $businessId)->where('key', self::SETTING)->value('value') === '1';
    }

    /**
     * هل يمسّ هذا التعديلُ المال؟ — ما يحتاج عينًا ثانية حين يُشعل المفتاح.
     *
     * @param  array<string, mixed>  $plan  `CustomerInvoiceEdits::plan`
     */
    public static function touchesMoney(array $plan): bool
    {
        return $plan['kind'] !== CustomerInvoiceRevision::DRAFT
            && ($plan['money_changed'] || $plan['customer_changed'] || $plan['ledger']['repost']);
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
     * بصمةُ الفاتورة كما يراها الطلب: ورقتُها، وحالُها، ومقبوضُها، وإشعاراتُها، وقيدُها.
     *
     * تحصيلٌ يُسجَّل، أو إشعارٌ دائن، أو تعديلٌ آخر، أو إلغاء — كلٌّ منها يغيّرها.
     */
    public static function fingerprint(CustomerInvoice $invoice): string
    {
        $invoice->loadMissing('items');

        return hash('sha256', (string) json_encode([
            'sheet' => CustomerInvoiceEdits::snapshot($invoice),
            'status' => $invoice->status,
            'revision' => (int) $invoice->revision,
            'paid' => round($invoice->paidTotal(), 3),
            'credited' => round($invoice->creditedTotal(), 3),
            'entry' => CustomerInvoices::liveEntry($invoice)?->id,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * طلبُ التصحيح — يُفحص كما يُفحص الحفظ، ولا يكتب في الفاتورة شيئًا.
     *
     * @param  array<string, mixed>  $input  ما مرّ من `CustomerInvoiceEdits::rules()`
     *
     * @throws AuthorizationException ما لا يملكه
     * @throws ValidationException سببٌ أو موضعُ تصحيحٍ ناقص
     * @throws RuntimeException ما يمنع التعديل، بسببه
     */
    public static function request(CustomerInvoice $invoice, array $input, User $user): FinancialCorrection
    {
        $bid = (int) $invoice->business_id;
        $token = (string) $input['edit_token'];

        return DB::transaction(function () use ($invoice, $input, $user, $bid, $token) {
            $locked = CustomerInvoice::where('business_id', $bid)->lockForUpdate()->findOrFail($invoice->id);

            // النموذجُ نفسُه أُرسل مرّتين: الطلبُ الأوّل هو الجواب
            $twin = FinancialCorrection::where('business_id', $bid)->where('edit_token', $token)->first();
            if ($twin) {
                if ((int) $twin->customer_invoice_id !== (int) $locked->id) {
                    throw new RuntimeException(__('رمز النموذج هذا استُعمل لفاتورة أخرى — افتح شاشة التعديل من جديد.'));
                }

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
                throw ValidationException::withMessages(['reason' => __('اكتب سبب تعديل الفاتورة — يُحفظ في سجل التعديلات وعلى قيد التصحيح.')]);
            }

            CustomerInvoiceEdits::assertPeriodChosen($plan, $reason);

            $correction = FinancialCorrection::create([
                'business_id' => $bid,
                'kind' => FinancialCorrection::INVOICE_EDIT,
                'customer_invoice_id' => $locked->id,
                'status' => FinancialCorrection::PENDING,
                'expected_revision' => (int) $locked->revision,
                'fingerprint' => self::fingerprint($locked),
                'payload' => $input,
                'summary' => self::summary($plan, $locked),
                'reason' => mb_substr($reason, 0, 255),
                'edit_token' => $token,
                'requested_by' => $user->id,
            ]);

            Activity::log('created', BooksPeriod::fit(
                'طلب تصحيح فاتورة عميل '.$locked->number.' بانتظار الاعتماد: '
                    .$plan['money']['total_before'].' ← '.$plan['money']['total_after'],
                $reason,
            ), ['subject_id' => $locked->id, 'subject_type' => 'customer_invoice', 'business_id' => $bid]);

            return $correction;
        });
    }

    /**
     * الاعتماد — يُنفَّذ التصحيحُ كلُّه أو لا شيء منه.
     *
     * @throws AuthorizationException معتمدٌ لا يملكه، أو هو مقدّمه
     * @throws ValidationException ما صار التصحيحُ يحتاجه اليوم (موضعُ الفترة)
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

            try {
                $revision = CustomerInvoiceEdits::apply($invoice, $row->payload, $requester, $row);
            } catch (AuthorizationException $e) {
                throw new AuthorizationException(__('مقدّم الطلب لم يعد يملك هذا التعديل — :why', ['why' => $e->getMessage()]));
            }

            $row->forceFill([
                'status' => FinancialCorrection::APPROVED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_note' => self::note($note),
                'customer_invoice_revision_id' => $revision->id,
            ])->save();

            Activity::log('updated', 'اعتمد تصحيح فاتورة عميل '.$invoice->number.' (التعديل '.$revision->revision.') طلبه '.$requester->name, [
                'subject_id' => $invoice->id, 'subject_type' => 'customer_invoice', 'business_id' => $bid,
            ]);

            return $row;
        });

        if ($stale) {
            throw new RuntimeException(__('تغيّرت الفاتورة بعد طلب التصحيح (تحصيل أو إشعار دائن أو تعديل آخر) — لم يُنفَّذ، وأُغلق الطلب. يُطلب التصحيح من جديد على الفاتورة كما هي الآن.'));
        }

        return $row;
    }

    /** الرفض — بسببٍ يُكتب، ولا يمسّ الفاتورة */
    public static function reject(FinancialCorrection $correction, User $approver, string $reason): FinancialCorrection
    {
        $bid = (int) $correction->business_id;

        return DB::transaction(function () use ($correction, $approver, $reason, $bid) {
            $row = self::lockPending($correction, $bid);
            self::assertDecider($row, $approver, $bid);

            $row->forceFill([
                'status' => FinancialCorrection::REJECTED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_note' => self::note($reason),
            ])->save();

            Activity::log('updated', BooksPeriod::fit('رفض طلب تصحيح فاتورة عميل', $reason), [
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

            return $row;
        });
    }

    /**
     * كم موظّفًا نشطًا يعتمد غيرَ فلان — يملك `financialCorrection.approve` في متجره.
     *
     * مفتاحٌ يُشعَل ولا معتمدَ غيرُ صاحب التصحيح يُعلّق طلبَه بلا من يبتّ فيه —
     * وصاحبُ النشاط وحده في متجرٍ صغير أوّلُ من يقع فيه. فيسأله الإشعالُ
     * (`SettingController`) والطلبُ (`CustomerInvoiceEdits::plan`) معًا، ويُقال
     * السببُ وطريقُه بدل أن يقف العملُ صامتًا.
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
            'may_decide' => self::mayDecide($viewer, $row),
            'may_withdraw' => $viewer !== null && $row->isPending() && (int) $viewer->id === (int) $row->requested_by,
        ];
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
     * ما رآه مقدّمُ الطلب — يُحفظ كما كان، ويُعرض للمعتمد مع ما يقوله الخادمُ اليوم.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function summary(array $plan, CustomerInvoice $invoice): array
    {
        $day = fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : $d;

        return [
            'kind' => $plan['kind'],
            'number' => $invoice->number,
            'money' => $plan['money'],
            'changes' => $plan['changes'],
            'lines' => $plan['lines'],
            'customer_changed' => $plan['customer_changed'],
            'period' => [
                'mode' => $plan['period']['mode'] ?? null,
                'reversal_date' => $day($plan['period']['reversal_date'] ?? null),
                'entry_date' => $day($plan['period']['entry_date'] ?? null),
            ],
        ];
    }
}
