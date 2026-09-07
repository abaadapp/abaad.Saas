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

            $last = $model::where('business_id', $businessId)
                ->where('number', 'like', $prefix.'%')
                ->orderByDesc('id')->value('number');

            $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

            return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
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
                'order_id' => $data['order_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'number' => self::nextNumber($businessId),
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
                'created_by' => $userId,
            ]);

            foreach ($totals['items'] as $item) {
                CustomerInvoiceItem::create($item + ['customer_invoice_id' => $invoice->id]);
            }

            Activity::log('created', 'أنشأ فاتورة عميل '.$invoice->number.' — '.$customer->name, [
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
        if ($invoice->status === CustomerInvoice::CANCELLED) {
            throw new RuntimeException(__('لا تُصدَر فاتورةٌ ملغاة.'));
        }
        if ($invoice->status === CustomerInvoice::ISSUED) {
            return $invoice;
        }
        if ((float) $invoice->total <= 0) {
            throw new RuntimeException(__('لا تُصدَر فاتورةٌ بلا مبلغ.'));
        }

        return DB::transaction(function () use ($invoice, $userId) {
            $invoice->update([
                'status' => CustomerInvoice::ISSUED,
                'issued_by_at' => now(),
            ]);

            self::post($invoice, $userId);

            Activity::log('updated', 'أصدر فاتورة عميل '.$invoice->number.' بمبلغ '.$invoice->total, [
                'subject_id' => $invoice->id, 'subject_type' => 'customer_invoice',
            ]);

            return $invoice->fresh();
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
        if ($invoice->order_id !== null) {
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
            if ($invoice->status === CustomerInvoice::ISSUED && $invoice->order_id === null) {
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
    public static function creditNote(CustomerInvoice $invoice, float $amount, float $taxAmount, string $reason, ?int $userId = null): CustomerCreditNote
    {
        $amount = round($amount, 3);

        if ($amount <= 0) {
            throw new RuntimeException(__('مبلغ إشعار الدائن يجب أن يكون أكبر من صفر.'));
        }
        if ($amount > $invoice->outstanding() + $invoice->paidTotal()) {
            throw new RuntimeException(__('لا يتجاوز إشعارُ الدائن قيمةَ الفاتورة.'));
        }

        return DB::transaction(function () use ($invoice, $amount, $taxAmount, $reason, $userId) {
            $note = CustomerCreditNote::create([
                'business_id' => $invoice->business_id,
                'customer_invoice_id' => $invoice->id,
                'number' => self::sequence((int) $invoice->business_id, CustomerCreditNote::class, 'CN-'),
                'amount' => $amount,
                'tax_amount' => round($taxAmount, 3),
                'issued_at' => now()->toDateString(),
                'reason' => $reason,
                'created_by' => $userId,
            ]);

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
     * فاتورةٌ من طلبٍ قائم — لقطةُ بنوده، بلا مخزونٍ وبلا إيرادٍ ثانٍ.
     *
     * ولا تُنشأ مرّتين للطلب نفسه: ورقتان لدَينٍ واحد تُحصَّل إحداهما ويبقى
     * الأخرى تُطالب.
     */
    public static function fromOrder(Order $order, array $data = [], ?int $userId = null): CustomerInvoice
    {
        $existing = CustomerInvoice::where('order_id', $order->id)
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
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'issued_at' => $order->ordered_at ?? $order->created_at,
        ], $lines, $userId);

        $invoice->update([
            'subtotal' => round((float) $order->subtotal + (float) $order->delivery_fee, 3),
            'discount_total' => round((float) $order->discount, 3),
            'tax_total' => round((float) $order->tax, 3),
            'total' => round((float) $order->total, 3),
        ]);

        return $invoice->fresh('items');
    }
}
