<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * فواتيرُ العملاء: إنشاؤها، وإصدارُها، وترحيلُها، وإلغاؤها.
 *
 * ═══ مالكُ ترحيل الإيراد ═══
 *
 * لكلّ حدثٍ اقتصاديٍّ مالكُ ترحيلٍ **واحد**:
 *
 *  • بيعةُ نقطة البيع → `Books::recordSale($order)`. تكتب قيدَ الإيراد
 *    وقيدَ التكلفة لحظةَ البيع، وتُدين `receivable` من نفسها حين تكون
 *    الفاتورة غير مدفوعة. فالبيعُ الآجل من نقطة البيع **لا يحتاج قيدًا
 *    ثانيًا** — يحتاج أن يُقال للطلب إنّه غير مدفوع.
 *
 *  • فاتورةٌ يدويّةٌ بلا طلب → تُرحَّل من هنا عند الإصدار:
 *    مدين ذمم العملاء / دائن المبيعات + الضريبة المستحقّة.
 *
 *  • فاتورةٌ **مولودةٌ من طلب** → **لا تُرحَّل من هنا**. هي مستندٌ فوق حدثٍ
 *    رُحّل، لا حدثٌ جديد. ولو رحّلت لظهر الإيراد مرّتين وذمّتان لدَينٍ واحد.
 *
 * ولا تكلفةَ مبيعاتٍ من هنا بحال: التكلفةُ تتبع خروجَ البضاعة، والفاتورةُ
 * ليست مستندَ مخزون. فاتورةٌ يدويّةٌ لا تُخرج شيئًا من الرفّ ولا تُقيَّد
 * تكلفتُها؛ وما خرج بطلبٍ قُيّدت تكلفتُه مع الطلب.
 */
final class CustomerInvoices
{
    public const SOURCE = 'فاتورة عميل';

    public const SOURCE_CREDIT = 'إشعار دائن';

    /** إشعارٌ كتبه التاجر — يُقيَّد */
    public const NOTE_MANUAL = 'يدوي';

    /** إشعارٌ وُلد من إلغاء طلبٍ مفوتَر — لا يُقيَّد، فقيدُه سبقه */
    public const NOTE_ORDER_CANCELLED = 'إلغاء طلب';

    /** رقمٌ متسلسلٌ داخل المتجر — لا عشوائيّ، ولا يُعاد استعمالُ رقمٍ أُلغي */
    public static function nextNumber(int $businessId): string
    {
        return self::sequence($businessId, CustomerInvoice::class, 'CINV-');
    }

    /**
     * التسلسل — تحت قفلٍ على صفّ المتجر.
     *
     * وبلا قفل: نافذتان تُصدران في اللحظة نفسها تقرآن آخر رقمٍ فتكتبانه
     * كلتاهما، فيسقط أحدهما على فهرس التفرّد أو — وهو أسوأ — يمرّان على
     * قاعدةٍ بلا فهرس فيصير رقمان لورقتين.
     */
    private static function sequence(int $businessId, string $model, string $prefix): string
    {
        return DB::transaction(function () use ($businessId, $model, $prefix) {
            Business::whereKey($businessId)->lockForUpdate()->first();

            /*
             * والأكبرُ عددًا لا الأحدثُ صفًّا.
             *
             * كان يقرأ آخرَ معرّفٍ لأنّ الرقم كان يُقطع لحظةَ الإنشاء، فترتيبُ
             * المعرّفات هو ترتيبُ الأرقام. ولم يعد: الرقمُ يُقطع عند الإصدار،
             * ومسودّةٌ كُتبت أمس تُصدَر اليوم بعد واحدةٍ كُتبت صباحًا — فآخرُ
             * صفٍّ ليس أكبرَ رقم، وقراءتُه تُعيد رقمًا مستعمَلًا يردّه فهرسُ
             * التفرّد في وجه من ضغط «إصدار».
             *
             * والقراءةُ في PHP لا `CAST` في SQL: نصٌّ غير رقميّ يتساهل معه
             * SQLite ويرفضه PostgreSQL، فرقمٌ شاذٌّ واحد من نسخةٍ مستعادة
             * يُعطّل الإصدار كلَّه بعد النقل.
             */
            $highest = (int) $model::where('business_id', $businessId)
                ->where('number', 'like', $prefix.'%')
                ->get(['number'])
                ->map(fn ($r) => (int) substr((string) $r->number, strlen($prefix)))
                ->max();

            return $prefix.str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT);
        });
    }

    /**
     * حسابُ البنود — في الخادم دائمًا.
     *
     * ولا يُقرأ إجماليٌّ من الواجهة: من يستطيع فتح أدوات المتصفّح يستطيع
     * إرسال إجماليٍّ صفرٍ لفاتورةٍ بمئة، فتُرحَّل ذمّةٌ لا تساوي ورقتَها.
     *
     * والضريبةُ لقطة: النسبةُ تُقرأ من إعدادات المتجر لحظةَ الكتابة وتُحفظ
     * في السطر. وتغييرُها الشهرَ القادم لا يمسّ ورقةً صدرت.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, discount: float, tax: float, total: float}
     */
    public static function compute(int $businessId, array $lines, ?float $taxRate = null): array
    {
        /*
         * ونسبةُ الضريبة تُقرأ لمتجر الفاتورة لا لمتجر القارئ.
         *
         * `Demo::vatSettings()` تقرأ متجرَ الجلسة — فلو أُنشئت فاتورةٌ لمتجرٍ
         * آخر (أمرُ صيانة، أو لوحةُ المنصّة) لحُسبت بنسبة متجرٍ غيره.
         */
        $rate = $taxRate ?? (Vat::enabled($businessId)
            ? (float) (Setting::where('business_id', $businessId)->where('key', 'vat_rate')->value('value') ?? 5)
            : 0.0);

        $items = [];
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        $sort = 0;

        foreach ($lines as $line) {
            $qty = round((float) ($line['quantity'] ?? 1), 3);
            $price = round((float) ($line['unit_price'] ?? 0), 3);
            $lineDiscount = round((float) ($line['discount'] ?? 0), 3);

            if ($qty <= 0) {
                continue;
            }

            $gross = round($qty * $price, 3);
            $net = round(max(0, $gross - $lineDiscount), 3);
            // بندٌ معفًى يُكتب بنسبة صفر — والإعفاء لقطةٌ في السطر لا قاعدةٌ عامّة
            $lineRate = array_key_exists('tax_rate', $line) ? (float) $line['tax_rate'] : $rate;
            $lineTax = round($net * $lineRate / 100, 3);

            $subtotal = round($subtotal + $gross, 3);
            $discount = round($discount + $lineDiscount, 3);
            $tax = round($tax + $lineTax, 3);

            $items[] = [
                'product_id' => $line['product_id'] ?? null,
                'order_item_id' => $line['order_item_id'] ?? null,
                'description' => (string) ($line['description'] ?? ''),
                'quantity' => $qty,
                'unit_price' => $price,
                'discount' => $lineDiscount,
                'tax_rate' => $lineRate,
                'tax_amount' => $lineTax,
                'line_total' => round($net + $lineTax, 3),
                'sort_order' => $sort++,
            ];
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => round($subtotal - $discount + $tax, 3),
        ];
    }

    /**
     * إنشاءُ فاتورةٍ — مسودّةً أو صادرة.
     *
     * والمنتجُ يُقرأ من متجر الفاتورة لا من الطلب: معرّفٌ من متجرٍ آخر يُرفض
     * هنا لا في الشاشة.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function create(int $businessId, Customer $customer, array $data, array $lines, ?int $userId = null): CustomerInvoice
    {
        if ((int) $customer->business_id !== $businessId) {
            throw new RuntimeException(__('هذا العميل ليس من عملاء متجرك.'));
        }

        foreach ($lines as $line) {
            if (! empty($line['product_id'])) {
                $ok = Product::where('business_id', $businessId)->whereKey($line['product_id'])->exists();
                if (! $ok) {
                    throw new RuntimeException(__('هذا الصنف ليس من أصناف متجرك.'));
                }
            }
        }

        $totals = self::compute($businessId, $lines);
        $terms = $data['payment_terms_days'] ?? $customer->payment_terms_days;
        $issuedAt = isset($data['issued_at']) ? Carbon::parse($data['issued_at']) : now();

        return DB::transaction(function () use ($businessId, $customer, $data, $totals, $terms, $issuedAt, $userId) {
            $invoice = CustomerInvoice::create([
                'business_id' => $businessId,
                'customer_id' => $customer->id,
                'branch_id' => $data['branch_id'] ?? null,
                /*
                 * ولا رقمَ للمسودّة.
                 *
                 * كان يُقطع هنا، فمسودّةٌ تُهجر تأخذ رقمَها معها ويقفز التسلسل
                 * في دفترٍ يُقرأ عند الضريبة. والرقمُ يُقطع عند الإصدار — حيث
                 * يقع القيد. انظر `issue`.
                 */
                'number' => null,
                'status' => CustomerInvoice::DRAFT,
                'issued_at' => $issuedAt->toDateString(),
                'due_at' => $data['due_at']
                    ?? ($terms !== null ? $issuedAt->copy()->addDays((int) $terms)->toDateString() : null),
                'payment_terms_days' => $terms,
                /*
                 * وعملةُ الورقة عملةُ المتجر لا عملةُ عرض القارئ.
                 *
                 * `displayCurrency` تفضيلُ مشاهدةٍ يُبدَّل من الترويسة — وورقةٌ
                 * صدرت لا يغيّر عملتَها من فتحها. ولا صرفَ أجنبيًّا هنا: النظام
                 * يمسك عملةً تشغيليّةً واحدة، والباقي عرضٌ.
                 */
                'currency' => Currency::where('business_id', $businessId)
                    ->where('is_base', true)->value('code'),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount'],
                'tax_total' => $totals['tax'],
                'total' => $totals['total'],
                'customer_name' => $customer->legal_name ?: $customer->name,
                'customer_tax_number' => $customer->tax_number,
                'customer_cr' => $customer->commercial_registration,
                'customer_address' => $customer->billing_address ?: $customer->address,
                'po_number' => $data['po_number'] ?? null,
                'contract_number' => $data['contract_number'] ?? null,
                'external_reference' => $data['external_reference'] ?? null,
                'department' => $data['department'] ?? $customer->department,
                'cost_center' => $data['cost_center'] ?? null,
                'attention_to' => $data['attention_to'] ?? $customer->contact_person,
                'notes' => $data['notes'] ?? null,
                // وما لا يُطبع: عمودٌ آخر لا يبلغ ورقةَ العميل
                'internal_notes' => $data['internal_notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($totals['items'] as $item) {
                CustomerInvoiceItem::create($item + ['customer_invoice_id' => $invoice->id]);
            }

            Activity::log('created', 'أنشأ مسودّة فاتورة عميل — '.$customer->name, [
                'subject_id' => $invoice->id, 'subject_type' => 'customer_invoice',
            ]);

            return $invoice->fresh('items');
        });
    }

    /**
     * إصدارُ الفاتورة — وهنا يقع القيد، إن كانت هي مالكةَ الحدث.
     *
     * ولا يُصدَر ما صدر: نداءان لا يكتبان قيدين.
     */
    public static function issue(CustomerInvoice $invoice, ?int $userId = null): CustomerInvoice
    {
        return DB::transaction(function () use ($invoice, $userId) {
            /*
             * والحالةُ تُقرأ تحت قفلٍ داخل المعاملة لا قبلها.
             *
             * ضغطتان على «إصدار» — أو ردٌّ يبطؤ فيُعاد الطلب — كانتا تقرآن
             * «مسودة» كلتاهما خارج المعاملة فتمضيان: قيدان لفاتورةٍ واحدة،
             * وإيرادٌ مضاعف، وذمّتان لدَينٍ واحد. والقفلُ يجعل الثانيةَ تنتظر
             * ثمّ تجدها صادرةً فتخرج بها.
             */
            $locked = CustomerInvoice::where('business_id', $invoice->business_id)
                ->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === CustomerInvoice::CANCELLED) {
                throw new RuntimeException(__('لا تُصدَر فاتورةٌ ملغاة.'));
            }
            if ($locked->status === CustomerInvoice::ISSUED) {
                return $locked;
            }
            if ((float) $locked->total <= 0) {
                throw new RuntimeException(__('لا تُصدَر فاتورةٌ بلا مبلغ.'));
            }

            /*
             * وهنا يُقطع الرقم — لا قبلُ.
             *
             * `?:` لا إسنادٌ مطلق: مسودّةٌ كُتبت تحت القاعدة القديمة تحمل
             * رقمًا حجزته، فتُصدَر به ولا تُرقَّم ثانيةً. وورقةٌ لا تُرقَّم
             * مرّتين ولو أُعيد نداءُ الإصدار عليها.
             */
            $locked->update([
                'number' => $locked->number ?: self::nextNumber((int) $locked->business_id),
                'status' => CustomerInvoice::ISSUED,
                'issued_by_at' => now(),
            ]);

            self::post($locked, $userId);

            Activity::log('updated', 'أصدر فاتورة عميل '.$locked->number.' بمبلغ '.$locked->total, [
                'subject_id' => $locked->id, 'subject_type' => 'customer_invoice',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * القيد — ولا يُكتب لفاتورةٍ وُلدت من طلب.
     *
     * `Books::recordSale` رحّلت ذلك الطلب لحظةَ وقوعه: إيرادَه وضريبتَه
     * وتكلفتَه، وأدانت `receivable` إن كان آجلًا. فقيدٌ ثانٍ هنا يعني إيرادًا
     * مضاعفًا وذمّتين لدَينٍ واحد.
     */
    private static function post(CustomerInvoice $invoice, ?int $userId): void
    {
        if ($invoice->coversOrders()) {
            return;
        }

        $net = round((float) $invoice->subtotal - (float) $invoice->discount_total, 3);
        $tax = round((float) $invoice->tax_total, 3);

        $lines = [['account' => 'receivable', 'debit' => round((float) $invoice->total, 3)]];

        if ($net > 0) {
            $lines[] = ['account' => 'sales', 'credit' => $net];
        }
        if ($tax > 0) {
            $lines[] = ['account' => 'tax_payable', 'credit' => $tax];
        }

        Ledger::post(
            (int) $invoice->business_id,
            __('فاتورة عميل ').$invoice->number,
            $lines,
            Carbon::parse($invoice->issued_at),
            self::SOURCE,
            $invoice->branch_id,
            $userId,
            $invoice,
        );
    }

    /**
     * الإلغاء — عكسٌ لا محو.
     *
     * ومستندٌ ماليٌّ صدر لا يُحذف: يبقى مقروءًا موسومًا بالإلغاء، ويُكتب
     * مقابله عكسُه في الدفتر. ومن ألغى ومتى ولمَ — كلُّه في السجلّ.
     *
     * ولا تُلغى فاتورةٌ سُدِّد منها شيء: ذاك إرجاعٌ يحتاج إشعارَ دائنٍ وردَّ
     * مال، لا شطبًا صامتًا يترك مالًا مقبوضًا بلا مقابل.
     */
    public static function cancel(CustomerInvoice $invoice, string $reason, ?int $userId = null): CustomerInvoice
    {
        if ($invoice->status === CustomerInvoice::CANCELLED) {
            return $invoice;
        }
        if ($invoice->paidTotal() > 0) {
            throw new RuntimeException(__('سُدِّد من هذه الفاتورة :n — ألغِ التحصيل أو أصدر إشعار دائن قبل الإلغاء.', ['n' => $invoice->paidTotal()]));
        }

        return DB::transaction(function () use ($invoice, $reason, $userId) {
            if ($invoice->status === CustomerInvoice::ISSUED && ! $invoice->coversOrders()) {
                $entry = JournalEntry::where('business_id', $invoice->business_id)
                    ->where('sourceable_type', CustomerInvoice::class)
                    ->where('sourceable_id', $invoice->id)
                    ->where('source', self::SOURCE)
                    ->latest('id')->first();

                if ($entry) {
                    Ledger::reverse($entry, now(), $userId, __('إلغاء فاتورة عميل ').$invoice->number);
                }
            }

            $invoice->update([
                'status' => CustomerInvoice::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);

            Activity::log('deleted', 'ألغى فاتورة عميل '.$invoice->number.' — '.$reason, [
                'subject_id' => $invoice->id, 'subject_type' => 'customer_invoice',
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * إشعارُ دائن — يُنقص الذمّة ولا يُعيد كتابة الفاتورة.
     *
     * فاتورةٌ بمئة سُدِّد منها ثلاثون ورُدّ بعشرين: الباقي خمسون. ولو خُفّض
     * الإجماليُّ إلى ثمانين لقالت الورقةُ في يد الزبون غيرَ ما يقوله النظام.
     */
    public static function creditNote(
        CustomerInvoice $invoice,
        float $amount,
        float $taxAmount,
        string $reason,
        ?int $userId = null,
        string $source = self::NOTE_MANUAL,
        ?int $orderId = null,
    ): CustomerCreditNote {
        $amount = round($amount, 3);

        if ($amount <= 0) {
            throw new RuntimeException(__('مبلغ إشعار الدائن يجب أن يكون أكبر من صفر.'));
        }
        if ($amount > $invoice->outstanding() + $invoice->paidTotal()) {
            throw new RuntimeException(__('لا يتجاوز إشعارُ الدائن قيمةَ الفاتورة.'));
        }

        return DB::transaction(function () use ($invoice, $amount, $taxAmount, $reason, $userId, $source, $orderId) {
            $note = CustomerCreditNote::create([
                'business_id' => $invoice->business_id,
                'customer_invoice_id' => $invoice->id,
                'number' => self::sequence((int) $invoice->business_id, CustomerCreditNote::class, 'CN-'),
                'amount' => $amount,
                'tax_amount' => round($taxAmount, 3),
                'issued_at' => now()->toDateString(),
                'reason' => $reason,
                'source' => $source,
                'order_id' => $orderId,
                'created_by' => $userId,
            ]);

            /*
             * والقيدُ يتبع المصدر.
             *
             * إشعارٌ يكتبه التاجر بيده حدثٌ ماليٌّ جديد فيُقيَّد. وإشعارٌ يولد
             * من إلغاء طلبٍ لا يُقيَّد: `Books::unpostSale` عكست قيدَ ذلك الطلب
             * لحظةَ إلغائه — إيرادَه وضريبتَه وذمّتَه — فقيدٌ ثانٍ هنا يُنقص
             * الذمّة مرّتين ويجعل الدفتر يقول غيرَ ما تقوله الفواتير.
             */
            if ($source !== self::NOTE_MANUAL) {
                Activity::log('created', 'أُصدر إشعار دائن '.$note->number.' بإلغاء طلب — '.$reason, [
                    'subject_id' => $note->id, 'subject_type' => 'customer_credit_note',
                ]);

                return $note;
            }

            $net = round($amount - $taxAmount, 3);
            $lines = [];
            if ($net > 0) {
                $lines[] = ['account' => 'sales_returns', 'debit' => $net];
            }
            if ($taxAmount > 0) {
                $lines[] = ['account' => 'tax_payable', 'debit' => round($taxAmount, 3)];
            }
            $lines[] = ['account' => 'receivable', 'credit' => $amount];

            Ledger::post(
                (int) $invoice->business_id,
                __('إشعار دائن ').$note->number.' — '.$invoice->number,
                $lines,
                now(),
                self::SOURCE_CREDIT,
                $invoice->branch_id,
                $userId,
                $note,
            );

            Activity::log('created', 'أصدر إشعار دائن '.$note->number.' بمبلغ '.$amount.' على '.$invoice->number, [
                'subject_id' => $note->id, 'subject_type' => 'customer_credit_note',
            ]);

            return $note;
        });
    }

    /**
     * فوترةُ الشهر — ورقةٌ واحدة تغطّي عدّة طلبات.
     *
     * شركةٌ تشتري ثلاث مرّاتٍ في الشهر لا تريد ثلاثَ فواتير: تريد ورقةً
     * واحدة بمجموعها آخرَ الشهر.
     *
     * ولا تُفوتَر إلّا البيعاتُ الآجلة: بيعةٌ نقديّةٌ قُبض ثمنُها ولا ذمّةَ
     * لها، ووضعُها في فاتورةٍ يُنشئ التزامًا لا وجود له في الدفتر — فتفترق
     * الذمّةُ التشغيليّة عن رصيد `receivable`.
     *
     * ولا طلبَ في ورقتين: الطلبُ يُقفل بقفل الكتابة ويُفحص، فلا تُطالَب
     * شركةٌ بمبلغٍ مرّتين.
     *
     * ولا قيدَ لهذه الورقة: كلُّ طلبٍ فيها رُحّل لحظةَ وقوعه.
     *
     * @param  array<int, int>  $orderIds
     */
    public static function consolidate(Customer $customer, array $orderIds, array $data = [], ?int $userId = null): CustomerInvoice
    {
        $businessId = (int) $customer->business_id;

        return DB::transaction(function () use ($businessId, $customer, $orderIds, $data, $userId) {
            $orders = Order::where('business_id', $businessId)
                ->where('customer_id', $customer->id)
                ->whereIn('id', $orderIds)
                ->lockForUpdate()->with('items')->get();

            if ($orders->isEmpty()) {
                throw new RuntimeException(__('لا طلبات لفوترتها.'));
            }

            foreach ($orders as $order) {
                if ((string) $order->payment_status !== 'غير مدفوع') {
                    throw new RuntimeException(__('الطلب :n مدفوعٌ — ولا يُفوتَر إلّا البيعُ الآجل.', ['n' => $order->number]));
                }

                $taken = CustomerInvoice::whereHas('orders', fn ($q) => $q->whereKey($order->id))
                    ->where('status', '!=', CustomerInvoice::CANCELLED)->exists();

                if ($taken) {
                    throw new RuntimeException(__('الطلب :n مفوتَرٌ سلفًا.', ['n' => $order->number]));
                }
            }

            $lines = [];
            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    $lines[] = [
                        'product_id' => $item->product_id,
                        'order_item_id' => $item->id,
                        // ورقمُ الطلب في البيان: الشركةُ تُطابق ورقتَها بطلباتها
                        'description' => ($item->name ?: __('بند')).' — '.$order->number,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->price,
                        'discount' => 0.0,
                    ];
                }
            }

            $invoice = self::create($businessId, $customer, $data, $lines, $userId);
            $invoice->orders()->attach($orders->pluck('id')->all());

            /*
             * والمجاميعُ تتبع الطلبات لا حسابَ البنود.
             *
             * الطلبُ يحمل خصمًا على مستواه وأجرةَ توصيلٍ وضريبةً حُسبت بقواعده.
             * وإعادةُ الحساب هنا تُنتج ورقةً بمبلغٍ غير الذي رُحّل في الدفتر.
             */
            $invoice->update([
                'subtotal' => round($orders->sum(fn ($o) => (float) $o->subtotal + (float) $o->delivery_fee), 3),
                'discount_total' => round($orders->sum(fn ($o) => (float) $o->discount), 3),
                'tax_total' => round($orders->sum(fn ($o) => (float) $o->tax), 3),
                'total' => round($orders->sum(fn ($o) => (float) $o->total), 3),
            ]);

            return self::issue($invoice->fresh('items'), $userId);
        });
    }

    /**
     * إلغاءُ طلبٍ مفوتَر — الورقةُ تعرف بذلك.
     *
     * وكان الإلغاء يُنقص الدفتر ولا يُنقص الفاتورة: يُلغى طلبٌ في فاتورةِ
     * شهرٍ فيبقى مبلغُه مطلوبًا من الشركة، ويُرسَل لها تذكيرٌ بدَينٍ لم يعد
     * عليها. والفارقُ يظهر في شريط المطابقة ولا يُعرف سببُه.
     *
     * ولا تُعاد كتابة الورقة: يُصدَر إشعارُ دائنٍ بمبلغ الطلب — والورقةُ في
     * يد الشركة تبقى كما استلمتها.
     *
     * ولا قيدَ له: `Books::unpostSale` عكست قيدَ الطلب لحظةَ إلغائه.
     */
    public static function onOrderCancelled(Order $order, ?int $userId = null, ?string $reason = null): ?CustomerCreditNote
    {
        $invoice = CustomerInvoice::whereHas('orders', fn ($q) => $q->whereKey($order->id))
            ->where('status', CustomerInvoice::ISSUED)->first();

        if (! $invoice) {
            return null;
        }

        // ولا يُعكس ما عُكس: إلغاءان لا يكتبان إشعارين
        $already = CustomerCreditNote::where('customer_invoice_id', $invoice->id)
            ->where('order_id', $order->id)->exists();

        if ($already) {
            return null;
        }

        $amount = round(min((float) $order->total, $invoice->outstanding() + $invoice->paidTotal()), 3);

        if ($amount <= 0) {
            return null;
        }

        return self::creditNote(
            $invoice,
            $amount,
            round((float) $order->tax, 3),
            $reason ?: __('إلغاء الطلب ').$order->number,
            $userId,
            self::NOTE_ORDER_CANCELLED,
            (int) $order->id,
        );
    }

    /**
     * فاتورةٌ من طلبٍ قائم — لقطةُ بنوده، بلا مخزونٍ وبلا إيرادٍ ثانٍ.
     *
     * ولا تُنشأ مرّتين للطلب نفسه: ورقتان لدَينٍ واحد تُحصَّل إحداهما ويبقى
     * الأخرى تُطالب.
     */
    public static function fromOrder(Order $order, array $data = [], ?int $userId = null): CustomerInvoice
    {
        $existing = CustomerInvoice::whereHas('orders', fn ($q) => $q->whereKey($order->id))
            ->where('status', '!=', CustomerInvoice::CANCELLED)->first();

        if ($existing) {
            return $existing;
        }

        $customer = Customer::where('business_id', $order->business_id)
            ->whereKey($order->customer_id)->first();

        if (! $customer) {
            throw new RuntimeException(__('لا فاتورةَ عميلٍ لطلبٍ بلا عميل مسجَّل.'));
        }

        $lines = $order->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'description' => $item->name ?: __('بند'),
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->price,
            'discount' => 0.0,
        ])->all();

        /*
         * والإجماليُّ يتبع الطلبَ لا حسابَ البنود.
         *
         * الطلبُ يحمل خصمًا على مستواه وأجرةَ توصيلٍ وضريبةً حُسبت بقواعده.
         * وإعادةُ الحساب هنا تُنتج ورقةً بمبلغٍ غير الذي رُحّل في الدفتر —
         * فتفترق الذمّةُ التشغيليّة عن رصيد `receivable`.
         */
        $invoice = self::create((int) $order->business_id, $customer, $data + [
            'branch_id' => $order->branch_id,
            'issued_at' => $order->ordered_at ?? $order->created_at,
        ], $lines, $userId);

        $invoice->orders()->attach($order->id);

        $invoice->update([
            'subtotal' => round((float) $order->subtotal + (float) $order->delivery_fee, 3),
            'discount_total' => round((float) $order->discount, 3),
            'tax_total' => round((float) $order->tax, 3),
            'total' => round((float) $order->total, 3),
        ]);

        return $invoice->fresh('items');
    }
}
