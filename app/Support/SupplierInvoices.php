<?php

namespace App\Support;

use App\Models\GoodsReceiptNote;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * سنداتُ المورّدين — كتابةً، ومطابقةً، ثمّ اعتمادًا.
 *
 * ═══ مالكُ ذمّة المورّد واحد ═══
 *
 * أمرُ الشراء نيّةُ شراءٍ لا التزام. واستلامُ البضاعة حركةُ مخزون لا مال.
 * والذمّةُ تنشأ **باعتماد السند وحده** — ولو نشأت في موضعٍ ثانٍ لحُمّل
 * المورّد مرّتين عن شحنةٍ واحدة.
 *
 * وكان السند يُرحَّل لحظةَ كتابته: يُدخله المحاسب فيصير على المتجر دَينٌ في
 * الدفتر قبل أن يراه أحد.
 *
 * ═══ والمطابقةُ ثلاثيّة ═══
 *
 * ما طُلب (أمر الشراء) · ما وصل (الاستلامات المعتمَدة) · ما طُولبنا به
 * (السند). وسندٌ بمئةٍ على أمرٍ بثمانين، أو سندٌ بالكامل على شحنةٍ وصل
 * تسعون بالمئة منها — كلاهما يمرّ بلا سؤال ويُدفع.
 *
 * والحصيلةُ ثلاثةُ أحوال: مطابق، تنبيه، ممنوع. والممنوعُ لا يُعتمد إلّا
 * بتجاوزٍ **بسببٍ يُكتب ويُنسب** — ولا يقع صامتًا.
 */
final class SupplierInvoices
{
    public const PENDING = 'بانتظار الاعتماد';

    public const APPROVED = 'معتمد';

    public const REJECTED = 'مرفوض';

    public const CANCELLED = 'ملغاة';

    public const MATCHED = 'مطابق';

    public const WARNING = 'تنبيه';

    public const BLOCKED = 'ممنوع';

    /**
     * حدُّ التسامح: نصفُ بيسة.
     *
     * والفروقُ أدقُّ من ذلك تقريبُ قسمةٍ لا خلاف. ونسبةٌ مئويّة لا تصلح
     * هنا: خمسةٌ بالمئة من عشرة آلاف خمسُمئة ريال — ولا أحد يتسامح فيها.
     */
    private const TOLERANCE = 0.0005;

    /**
     * كتابةُ السند — بلا قيد.
     *
     * @param  array<string, mixed>  $data
     */
    public static function create(int $businessId, array $data, ?User $by = null): SupplierInvoice
    {
        $subtotal = round((float) ($data['subtotal'] ?? 0), 3);
        $tax = round((float) ($data['tax'] ?? 0), 3);
        $total = round($subtotal + $tax, 3);

        if ($total <= 0) {
            throw new RuntimeException(__('السند بلا مبلغ لا يُسجَّل'));
        }

        return DB::transaction(function () use ($businessId, $data, $subtotal, $tax, $total, $by) {
            $invoice = SupplierInvoice::create([
                'business_id' => $businessId,
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'supplier_ref' => $data['supplier_ref'],
                'issued_at' => $data['issued_at'],
                'due_at' => $data['due_at'] ?? null,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'attachment' => $data['attachment'] ?? null,
                'attachment_name' => $data['attachment_name'] ?? null,
                'approval_status' => self::PENDING,
                'submitted_by' => $by?->id,
            ]);

            // والحصيلةُ تُكتب عند الكتابة لتُقرأ في الطابور — وتُعاد عند الاعتماد
            $match = self::match($invoice);
            $invoice->update(['match_status' => $match['status'], 'match_notes' => implode("\n", $match['notes'])]);

            Activity::log('created', 'سجّل سند مورّد '.$invoice->supplier_ref.' بقيمة '.$total.' — بانتظار الاعتماد', [
                'subject_id' => $invoice->id, 'subject_type' => 'supplier_invoice',
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * المطابقةُ الثلاثيّة — على مستوى القيمة لا السطر.
     *
     * وسندُ المورّد في هذا النظام إجماليٌّ بلا بنود: يُدخله المحاسب رقمين —
     * مبلغًا وضريبة — كما هو في ورقة المورّد. فالمطابقةُ بالقيمة والكميّة
     * الإجماليّة لا بالسطر، ولا يُخترع لها جدولُ بنودٍ لا يملأه أحد.
     *
     * @return array{status: string, notes: list<string>}
     */
    public static function match(SupplierInvoice $invoice): array
    {
        $notes = [];
        $blocked = false;
        $po = $invoice->purchase_order_id
            ? PurchaseOrder::where('business_id', $invoice->business_id)->find($invoice->purchase_order_id)
            : null;

        if (! $po) {
            // سندٌ بلا أمر شراء ليس عطبًا: خدمةٌ أو شراءٌ عاجل — ولا يُطابَق
            return ['status' => self::MATCHED, 'notes' => []];
        }

        /*
         * والمورّدُ أوّلًا: سندٌ من مورّدٍ على أمرِ مورّدٍ آخر يخلط الحسابين،
         * فيُدفع لمن لا يستحقّ ويبقى المستحقُّ مطلوبًا.
         */
        if ((int) $po->supplier_id !== (int) $invoice->supplier_id) {
            $notes[] = __('السند لمورّدٍ غير مورّد أمر الشراء');
            $blocked = true;
        }

        $poTotal = round((float) $po->total, 3);
        $invTotal = round((float) $invoice->total, 3);
        $diff = round($invTotal - $poTotal, 3);

        if ($diff > self::TOLERANCE) {
            $notes[] = __('فاتورة المورد أعلى من أمر الشراء بـ :v', ['v' => number_format($diff, 3)]);
            $blocked = true;
        } elseif ($diff < -self::TOLERANCE) {
            // الأقلُّ تنبيهٌ لا منع: شحنةٌ ناقصة تُفوتَر بما وصل، وهذا صحيح
            $notes[] = __('فاتورة المورد أقلّ من أمر الشراء بـ :v', ['v' => number_format(abs($diff), 3)]);
        }

        /*
         * وما وصل فعلًا: الاستلاماتُ **المعتمَدة** وحدها.
         *
         * والمعلَّقُ لا يُحسب واصلًا — وإلّا لصار كتابةُ استلامٍ بلا اعتماد
         * طريقًا إلى تمرير سند. وهو ما فُتح البابُ لإغلاقه.
         */
        $ordered = (float) $po->items()->sum('quantity');
        $received = (float) $po->items()->sum('received_quantity');

        if ($ordered > 0 && $received + self::TOLERANCE < $ordered) {
            $notes[] = __('تم استلام :got من :want وحدة فقط', [
                'got' => rtrim(rtrim(number_format($received, 3), '0'), '.'),
                'want' => rtrim(rtrim(number_format($ordered, 3), '0'), '.'),
            ]);

            /*
             * والسندُ الكاملُ على استلامٍ ناقص يُمنع: هو أشيعُ ما يُدفع بلا
             * حقّ — بضاعةٌ لم تصل يُدفع ثمنُها ثمّ يُطالَب المورّد بها بعد
             * شهرين وقد نُسي.
             */
            if ($diff >= -self::TOLERANCE) {
                $notes[] = __('والسند بقيمة أمر الشراء كاملًا');
                $blocked = true;
            }
        }

        if ($ordered > 0 && $received <= 0) {
            $notes[] = __('لم يُعتمد أيُّ استلامٍ على هذا الأمر بعد');
            $blocked = true;
        }

        return [
            'status' => $blocked ? self::BLOCKED : ($notes === [] ? self::MATCHED : self::WARNING),
            'notes' => $notes,
        ];
    }

    /**
     * الاعتماد — وهنا وحدَه تنشأ الذمّة.
     *
     * والوسمُ لا يقع إن سقط القيد: المعاملةُ تلفّ الاثنين، فسندٌ يقول
     * «معتمد» بلا قيدٍ في الدفتر لا يُكتب أصلًا.
     *
     * @throws RuntimeException
     */
    public static function approve(SupplierInvoice $invoice, ?User $by = null, ?string $overrideReason = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $by, $overrideReason) {
            $locked = SupplierInvoice::where('business_id', $invoice->business_id)
                ->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->approval_status === self::APPROVED) {
                throw new RuntimeException(__('هذا السند معتمدٌ مسبقًا'));
            }
            if (in_array($locked->approval_status, [self::REJECTED, self::CANCELLED], true)) {
                throw new RuntimeException(__('هذا السند مرفوضٌ أو ملغًى — لا يُعتمد'));
            }

            // والمبلغُ يُحسب من طرفيه ثانيةً: عمودٌ عُبث به لا يُرحَّل
            $total = round((float) $locked->subtotal + (float) $locked->tax, 3);

            if (abs($total - (float) $locked->total) > self::TOLERANCE) {
                throw new RuntimeException(__('إجمالي السند لا يساوي مجموعه — لا يُعتمد'));
            }
            if ($total <= 0) {
                throw new RuntimeException(__('السند بلا مبلغ لا يُعتمد'));
            }

            /*
             * والمطابقةُ تُعاد عند الاعتماد لا تُقرأ من عمودٍ كُتب أمس:
             * استلامٌ اعتُمد بين الأمرين يغيّر الحصيلة، ومن يوقّع اليوم
             * يوقّع على حال اليوم.
             */
            $match = self::match($locked);

            if ($match['status'] === self::BLOCKED) {
                $reason = trim((string) $overrideReason);

                if ($reason === '') {
                    throw new RuntimeException(
                        __('السند لا يطابق أمره: :why — اكتب سبب التجاوز إن أردت اعتماده.', [
                            'why' => implode('، ', $match['notes']),
                        ])
                    );
                }

                if (! $by?->may(Permissions::INVOICE_OVERRIDE)) {
                    throw new RuntimeException(__('تجاوزُ عدم المطابقة ليس من صلاحياتك — اطلبه ممّن يملكه.'));
                }

                $locked->update([
                    'override_by' => $by->id,
                    'override_at' => now(),
                    'override_reason' => $reason,
                ]);

                Activity::log('updated', 'تجاوز عدم مطابقة السند '.$locked->supplier_ref.' — '.$reason, [
                    'subject_id' => $locked->id, 'subject_type' => 'supplier_invoice',
                ]);
            }

            /*
             * والضريبةُ تتبع تسجيلَ المتجر، لا قاعدةً واحدةً للاثنين.
             *
             * ═══ ما كان ═══
             *
             * كانت تدخل في تكلفة المخزون دائمًا. وهذا صوابٌ لمن **لم** يُسجَّل
             * في الضريبة: هي عنده جزءٌ من ثمن البضاعة لا شيءٌ يُستردّ.
             *
             * أمّا المسجَّل فيستردّها — وتقريرُ الإقرار يخصمها من ضريبة
             * مخرجاته. فكانت الخمسةُ ريالاتٍ الواحدة تُعدّ مرّتين: أصلًا في
             * المخزون **و** خصمًا في الإقرار. يقرأ التاجر مخزونًا أغلى ممّا
             * كلّفه، وميزانًا لا يعرف الضريبةَ التي سيستردّها.
             *
             * ═══ وإلى أين تذهب ═══
             *
             * إلى «الضريبة المستحقّة» (2300) مدينةً — وهو الحساب الذي تُقيَّد
             * فيه ضريبةُ المبيعات دائنةً. فيصير رصيدُه صافي ما يُدفع للجهة:
             * الرقمُ نفسه الذي يقوله تقريرُ الإقرار. حسابٌ واحد لا يفترق عن
             * تقريره.
             */
            $tax = round((float) $locked->tax, 3);
            $registered = Vat::enabled($locked->business_id);
            $cost = $registered ? round($total - $tax, 3) : $total;

            $lines = [['account' => 'inventory', 'debit' => $cost, 'memo' => $locked->supplier?->name]];

            if ($registered && $tax > 0) {
                $lines[] = ['account' => 'tax_payable', 'debit' => $tax];
            }

            $lines[] = ['account' => 'payable', 'credit' => $total];

            Ledger::post(
                $locked->business_id,
                __('سند مورّد: ').$locked->supplier_ref,
                $lines,
                Carbon::parse($locked->issued_at),
                'سند مورّد',
                null,
                $by?->id,
                $locked,
            );

            $locked->update([
                'approval_status' => self::APPROVED,
                'approved_at' => now(),
                'approved_by' => $by?->id,
                'match_status' => $match['status'],
                'match_notes' => implode("\n", $match['notes']),
            ]);

            $locked->syncStatus();

            Activity::log('updated', 'اعتمد سند المورّد '.$locked->supplier_ref.' بقيمة '.$total, [
                'subject_id' => $locked->id, 'subject_type' => 'supplier_invoice',
            ]);

            return $locked->fresh();
        });
    }

    /** الرفض — بسببٍ مكتوب، ولا قيدَ له، ولا يُمحى */
    public static function reject(SupplierInvoice $invoice, string $reason, ?User $by = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $by) {
            $locked = SupplierInvoice::where('business_id', $invoice->business_id)
                ->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->approval_status === self::APPROVED) {
                throw new RuntimeException(__('هذا السند معتمَد — قيدُه في الدفتر، ولا يُرفض بعده'));
            }
            if ($locked->approval_status === self::REJECTED) {
                throw new RuntimeException(__('هذا السند مرفوضٌ مسبقًا'));
            }

            $locked->update([
                'approval_status' => self::REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $by?->id,
                'rejection_reason' => $reason,
            ]);

            Activity::log('updated', 'رفض سند المورّد '.$locked->supplier_ref.' — '.$reason, [
                'subject_id' => $locked->id, 'subject_type' => 'supplier_invoice',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * إلغاءُ سندٍ معتمَد — عكسًا لا محوًا.
     *
     * ═══ المعتمَدُ لا يُحذف ═══
     *
     * قيدُه في الدفتر. ومحوُ الصفّ يمحو الذمّة ويترك تكلفةَ المخزون مرحَّلةً
     * بلا ما يقابلها — فيتضخّم المخزون في الميزانية بمبلغٍ لا يقابله دَينٌ
     * ولا نقدٌ خرج، ولا يتوازن شيء.
     *
     * والعكسُ يُبقي الأثر: القيدُ الأصلُ يبقى مقروءًا بتاريخه، وقيدٌ ثانٍ
     * يُلغيه بتاريخ اليوم. فمن يقرأ الدفتر بعد سنةٍ يرى ما وقع ومتى صُحّح —
     * لا فراغًا لا يُشرح.
     *
     * @throws RuntimeException
     */
    public static function cancel(SupplierInvoice $invoice, string $reason, ?User $by = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $by) {
            $locked = SupplierInvoice::where('business_id', $invoice->business_id)
                ->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->approval_status !== self::APPROVED) {
                throw new RuntimeException(__('لا يُلغى إلّا سندٌ معتمَد — وغيرُ المعتمَد يُرفض أو يُحذف'));
            }

            /*
             * وسندٌ خرج مقابله مال لا يُلغى من هنا.
             *
             * عكسُ الذمّة وحدَها يترك قيدَ السداد يتيمًا: نقدٌ خرج مقابل دَينٍ
             * لا وجود له. وطريقُه أن يُسترَدّ المال أو يُقيَّد إشعارٌ دائن.
             */
            if ((float) $locked->paid > 0) {
                throw new RuntimeException(__('سُدّد من هذا السند — لا يُلغى بعد أن خرج مقابله مال'));
            }

            foreach (JournalEntry::where('business_id', $locked->business_id)
                ->where('sourceable_type', SupplierInvoice::class)
                ->where('sourceable_id', $locked->id)
                ->where('source', 'سند مورّد')
                ->whereNull('reversed_at')->get() as $entry) {
                Ledger::reverse($entry, null, $by?->id, __('إلغاء سند مورّد: ').$reason);
            }

            $locked->update([
                'approval_status' => self::CANCELLED,
                'rejection_reason' => $reason,
                'rejected_at' => now(),
                'rejected_by' => $by?->id,
            ]);

            Activity::log('updated', 'ألغى سند المورّد '.$locked->supplier_ref.' — '.$reason, [
                'subject_id' => $locked->id, 'subject_type' => 'supplier_invoice',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * قيمةُ ما وصل فعلًا على أمرٍ — من الاستلامات المعتمَدة وحدها.
     *
     * تُقرأ في الشاشة بجوار قيمة السند: من يعتمد يريد الرقمين معًا.
     */
    public static function receivedValue(int $purchaseOrderId): float
    {
        return round((float) GoodsReceiptNote::where('purchase_order_id', $purchaseOrderId)
            ->where('status', GoodsReceipts::APPROVED)
            ->join('goods_receipt_note_items as i', 'i.goods_receipt_note_id', '=', 'goods_receipt_notes.id')
            ->sum(DB::raw('i.quantity * i.cost')), 3);
    }
}
