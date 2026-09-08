<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * تحصيلاتُ العملاء وتخصيصُها.
 *
 * والدفعةُ مستندٌ قائمٌ بذاته لا تعديلٌ على فاتورة: شركةٌ تسدّد حسابَ الشهر
 * بحوالةٍ واحدة تغطّي ثلاث فواتير — ولو كانت الدفعةُ حقلًا في الفاتورة
 * لاحتاجت الحوالةُ أن تُقسَّم يدويًّا وتُنسى واحدةٌ منها.
 *
 * وقيدُها واحدٌ لا ينقسم بانقسام التخصيص: مدين الصندوق أو البنك / دائن ذمم
 * العملاء. والتخصيصُ توزيعٌ تشغيليٌّ على الأوراق، لا حدثٌ محاسبيّ.
 */
final class CustomerPayments
{
    public const SOURCE = 'تحصيل عميل';

    /** ما يُقبل وسيلةً — والصندوقُ أو البنك يتبعها */
    public const METHODS = ['نقدي', 'بطاقة', 'تحويل', 'شيك'];

    public static function nextNumber(int $businessId): string
    {
        return DB::transaction(function () use ($businessId) {
            Business::whereKey($businessId)->lockForUpdate()->first();

            $last = CustomerPayment::where('business_id', $businessId)
                ->where('number', 'like', 'RCPT-%')
                ->orderByDesc('id')->value('number');

            $n = $last ? ((int) substr($last, 5)) + 1 : 1;

            return 'RCPT-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        });
    }

    /** أيُّ حسابٍ يستقبل المال — النقدُ إلى الصندوق وما عداه إلى البنك */
    public static function sideFor(string $method): string
    {
        return $method === 'نقدي' ? 'cash' : 'bank';
    }

    /**
     * أيُّ حسابٍ بنكيٍّ **بعينه** استقبل المال.
     *
     * ═══ ولماذا يُختار هنا لا في الشاشة ═══
     *
     * `sideFor` تقول «بنك» أو «صندوق»، وهي كافيةٌ للدفتر وحده. ومطابقةُ كشف
     * الحساب تسأل سؤالًا آخر: **أيُّ** بنك. والعمودُ موجودٌ منذ كُتب الجدول،
     * والخادمُ يقبله منذ كُتب الباب — ولم تكن في النظام كلِّه شاشةٌ واحدة
     * ترسله. فكلُّ تحصيلٍ ببطاقةٍ أو تحويلٍ كان يُكتب بحسابٍ فارغ، ولا يجد
     * `bank/rematch` له حسابًا يُسنده إليه.
     *
     * وحين لا يُسمّى يسقط إلى الحساب الرئيسيّ الذي وسمه التاجر بنفسه في
     * المالية — لا إلى أوّل صفٍّ في الجدول. ومتجرٌ بلا حسابٍ مسجَّل يبقى
     * فارغًا: لا يُخترع له حساب.
     *
     * والاختيارُ هنا لا في المتحكّم: بابان يقرّران الشيء نفسه يفترقان يومًا،
     * وبابٌ ثالثٌ يُكتب غدًا لا يعرف بهما.
     */
    public static function accountFor(int $businessId, string $method, int|string|null $given): ?int
    {
        /*
         * والنقدُ لا حسابَ بنكيًّا له مهما أُرسل.
         *
         * الشاشةُ تُخفي القائمة عند «نقدي»، لكنّ الحقلَ يبقى في النموذج —
         * ومن يبدّل الوسيلةَ بعد اختيار الحساب كان يُرسل الاثنين. فيدخل
         * المالُ الصندوقَ في الدفتر ويحمل اسمَ بنكٍ في الصفّ، ولا يُطابقه
         * كشفُ الحساب أبدًا لأنّه لم يمرّ به.
         */
        if (self::sideFor($method) !== 'bank') {
            return null;
        }

        if ($given !== null && $given !== '') {
            $owned = BankAccount::where('business_id', $businessId)->whereKey($given)->exists();

            if (! $owned) {
                throw new RuntimeException(__('هذا الحساب البنكي ليس من حسابات متجرك.'));
            }

            // ومعطَّلٌ سمّاه المستخدم يُقبل: حسابٌ أُغلق بعد تحصيلٍ وقع فيه
            return (int) $given;
        }

        return BankAccount::where('business_id', $businessId)
            ->where('active', true)->where('is_primary', true)->value('id');
    }

    /**
     * تسجيلُ تحصيل.
     *
     * `$allocations` خريطةُ «رقم الفاتورة ⇽ المبلغ». وإن جاءت فارغةً وُزّعت
     * على الأقدم فالأقدم — **وتُعاد النتيجةُ ليراها من سجّلها**، لا تُطبَّق
     * في صمتٍ فيكتشف بعد شهرٍ أنّ دفعتَه ذهبت إلى فاتورةٍ غير التي قصد.
     *
     * وما زاد عن الفواتير يبقى **غيرَ مخصَّص** في رصيد العميل.
     *
     * @param  array<int, float>  $allocations
     */
    public static function record(
        int $businessId,
        Customer $customer,
        float $amount,
        array $data,
        array $allocations = [],
        ?int $userId = null,
    ): CustomerPayment {
        $amount = round($amount, 3);

        if ((int) $customer->business_id !== $businessId) {
            throw new RuntimeException(__('هذا العميل ليس من عملاء متجرك.'));
        }
        if ($amount <= 0) {
            throw new RuntimeException(__('مبلغ التحصيل يجب أن يكون أكبر من صفر.'));
        }

        $method = in_array($data['method'] ?? '', self::METHODS, true) ? $data['method'] : 'نقدي';
        $bankAccountId = self::accountFor($businessId, $method, $data['bank_account_id'] ?? null);

        return DB::transaction(function () use ($businessId, $customer, $amount, $data, $allocations, $method, $bankAccountId, $userId) {
            $payment = CustomerPayment::create([
                'business_id' => $businessId,
                'customer_id' => $customer->id,
                'number' => self::nextNumber($businessId),
                'amount' => $amount,
                'method' => $method,
                'bank_account_id' => $bankAccountId,
                'occurred_at' => isset($data['occurred_at'])
                    ? Carbon::parse($data['occurred_at'])->toDateString()
                    : now()->toDateString(),
                'external_reference' => $data['external_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            self::allocate($payment, $allocations);
            self::post($payment, $userId);

            Activity::log('created', 'سجّل تحصيلًا '.$payment->number.' من '.$customer->name.' بمبلغ '.$amount, [
                'subject_id' => $payment->id, 'subject_type' => 'customer_payment',
            ]);

            return $payment->fresh('allocations');
        });
    }

    /**
     * التخصيص — صريحًا أو على الأقدم فالأقدم.
     *
     * والفواتيرُ تُقفل بقفل الكتابة قبل قراءة باقيها: نافذتان تسجّلان دفعتين
     * في اللحظة نفسها تقرآن الباقي نفسه فتخصّصان أكثر ممّا عليه.
     *
     * @param  array<int, float>  $explicit
     */
    private static function allocate(CustomerPayment $payment, array $explicit): void
    {
        $left = round((float) $payment->amount, 3);

        $ids = $explicit !== []
            ? array_keys($explicit)
            : CustomerInvoice::where('business_id', $payment->business_id)
                ->where('customer_id', $payment->customer_id)
                ->live()->orderBy('due_at')->orderBy('id')->pluck('id')->all();

        foreach ($ids as $invoiceId) {
            if ($left <= 0) {
                break;
            }

            $invoice = CustomerInvoice::where('business_id', $payment->business_id)
                ->whereKey($invoiceId)->lockForUpdate()->first();

            if (! $invoice || $invoice->status !== CustomerInvoice::ISSUED) {
                // معرّفٌ من متجرٍ آخر أو فاتورةٌ غير صادرة: لا تُخصَّص، ولا يضيع المال
                continue;
            }

            $wanted = $explicit !== [] ? round((float) $explicit[$invoiceId], 3) : $invoice->outstanding();
            $share = round(min($wanted, $invoice->outstanding(), $left), 3);

            if ($share <= 0) {
                continue;
            }

            CustomerPaymentAllocation::create([
                'customer_payment_id' => $payment->id,
                'customer_invoice_id' => $invoice->id,
                'amount' => $share,
            ]);

            $left = round($left - $share, 3);
        }
    }

    /**
     * قيدُ التحصيل: مدين الصندوق/البنك — دائن ذمم العملاء.
     *
     * والبنكُ ورقةُ الحساب الذي استقبل المال بعينه، لا ورقة «البنك» العامّة:
     * الصفُّ يقول «بنك ظفار» فيجب أن يقوله الدفتر. وحين لا يُنسب — أو لا
     * ورقةَ للحساب — يسقط المفتاح النظاميّ إلى ورقة الرئيسيّ في `Ledger`.
     */
    private static function post(CustomerPayment $payment, ?int $userId): void
    {
        $side = self::sideFor((string) $payment->method);
        $target = $side === 'bank'
            ? (Bank::leaf((int) $payment->business_id, $payment->bank_account_id) ?? 'bank')
            : $side;

        Ledger::post(
            (int) $payment->business_id,
            __('تحصيل ').$payment->number,
            [
                ['account' => $target, 'debit' => round((float) $payment->amount, 3)],
                ['account' => 'receivable', 'credit' => round((float) $payment->amount, 3)],
            ],
            Carbon::parse($payment->occurred_at),
            self::SOURCE,
            null,
            $userId,
            $payment,
        );
    }

    /**
     * إلغاءُ تحصيل — عكسٌ لا محو، والتخصيصُ يسقط معه.
     *
     * والصفُّ يبقى مقروءًا: من سجّل ومن ألغى ومتى وكم. وتاريخُ المال لا
     * يُمحى، يُعكس أثرُه.
     */
    public static function cancel(CustomerPayment $payment, string $reason, ?int $userId = null): CustomerPayment
    {
        if ($payment->cancelled_at !== null) {
            return $payment;
        }

        return DB::transaction(function () use ($payment, $reason, $userId) {
            $entry = JournalEntry::where('business_id', $payment->business_id)
                ->where('sourceable_type', CustomerPayment::class)
                ->where('sourceable_id', $payment->id)
                ->where('source', self::SOURCE)
                ->latest('id')->first();

            if ($entry) {
                Ledger::reverse($entry, now(), $userId, __('إلغاء تحصيل ').$payment->number);
            }

            /*
             * والتخصيصُ يبقى ولا يُحذف.
             *
             * «أين ذهبت دفعةُ الخميس؟» سؤالٌ يُسأل بعد شهر — وحذفُ صفوفها
             * يمحو الجواب. والاستبعادُ بقاعدةٍ واحدة: `paidTotal` لا تعدّ
             * تخصيصَ دفعةٍ ملغاة. فالتاريخُ مقروءٌ والرقمُ صحيح.
             */
            $payment->update([
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);

            Activity::log('deleted', 'ألغى تحصيل '.$payment->number.' — '.$reason, [
                'subject_id' => $payment->id, 'subject_type' => 'customer_payment',
            ]);

            return $payment->fresh();
        });
    }
}
