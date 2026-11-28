<?php

namespace App\Support;

use App\Exceptions\CollectionRefused;
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

    /**
     * دفعٌ عبر رابط Paymob — وسيلةٌ لا يختارها أحدٌ بيده.
     *
     * لا تُعرض في شاشة التحصيل ولا تقبلها مصادقتُها (`METHODS`): لا يكتبها
     * إلّا إشعارُ Paymob الموقَّع (`PaymentLinks::settle`). ومالُها لا يصل
     * البنكَ بالدفع — يجلس في «Paymob تحت التسوية» حتّى تُحوّله Paymob
     * (`PaymobSettlements::record`).
     */
    public const PAYMOB = 'Paymob';

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

    /** أيُّ حسابٍ يستقبل المال — النقدُ إلى الصندوق، وPaymob إلى تسويتها، وما عداهما إلى البنك */
    public static function sideFor(string $method): string
    {
        return match ($method) {
            'نقدي' => 'cash',
            self::PAYMOB => 'paymob_clearing',
            default => 'bank',
        };
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
     * وما زاد عن الفواتير يبقى **غيرَ مخصَّص** في رصيد العميل — في التحصيل على
     * حساب العميل، وفي مالٍ وصل من خارج النظام (رابط Paymob) فلا يُردّ.
     *
     * ═══ ومرّةً واحدة ═══
     *
     * `$requestKey` مفتاحُ محاولة التحصيل من نافذته: إرسالٌ ثانٍ بالمفتاح نفسِه —
     * ضغطةٌ مكرّرة، أو إعادةٌ بعد ردٍّ ضاع — يردّ التحصيلَ الأوّل ولا يكتب شيئًا
     * (`$payment->replayed`). وإرسالان في اللحظة نفسها يصطدم ثانيهما بالفهرس
     * الفريد في القاعدة، فيُقرأ الأوّلُ ويُردّ.
     *
     * و`$exact` تحصيلٌ على فاتورةٍ بعينها: لا يُخصَّص منه إلّا ما عليها، ولا يُقصّ
     * الزائدُ في صمتٍ فيصير رصيدًا للعميل. فاتورةٌ مدفوعةٌ كلُّها، أو مبلغٌ أكبرُ من
     * باقيها، أو فاتورةٌ لم تعد صادرة: يُردّ التحصيلُ بسببه (`CollectionRefused`)
     * ولا يُكتب صفٌّ ولا قيد. والحكمُ تحت قفل صفّ الفاتورة (`allocate`).
     *
     * ═══ والمفتاحُ لعمليّةٍ واحدة (الجولة 16) ═══
     *
     * يُكتب مع المفتاح بصمةُ ما طُلب به (`fingerprint`). إعادةٌ بالمفتاح نفسِه والبصمةِ
     * نفسِها تردّ التحصيلَ الأوّل — لمن سجّله وحده. وبغيرها — مبلغٌ آخر، فاتورةٌ أخرى،
     * وسيلةٌ أو حسابٌ أو شيكٌ أو مرجعٌ آخر، أو مستخدمٌ آخر — يُردّ ولا يُكتب شيء، ولا
     * يُقال رقمُ التحصيل الأوّل: المفتاحُ لا يصير بابًا لقراءة تحصيلِ غيرك ولا لكتابة آخر.
     *
     * ═══ وتركُ الزائد رصيدًا — بطلبٍ صريح ═══
     *
     * `$leaveExcess` مع فاتورةٍ واحدةٍ بعينها: يُخصَّص منها باقيها وحده، ويبقى ما زاد
     * غيرَ مخصَّص — رصيدًا للعميل بالمعالجة القائمة نفسِها (ذممٌ دائنة، لا إيراد، ولا
     * مستحقَّ بوتيك، ولا نقاط). ولا يُقبل على فاتورةٍ لم يبقَ عليها شيء: الدفعةُ المقدّمة
     * بابُها «تحصيل على حساب العميل»، فلا يصير الخيارُ طريقًا لتحصيلٍ ثانٍ على فاتورةٍ مسدَّدة.
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
        ?string $requestKey = null,
        bool $exact = false,
        bool $leaveExcess = false,
    ): CustomerPayment {
        $amount = round($amount, 3);
        $requestKey = filled($requestKey) ? mb_substr(trim((string) $requestKey), 0, 64) : null;

        if ((int) $customer->business_id !== $businessId) {
            throw new RuntimeException(__('هذا العميل ليس من عملاء متجرك.'));
        }
        if ($amount <= 0) {
            throw new RuntimeException(__('مبلغ التحصيل يجب أن يكون أكبر من صفر.'));
        }

        /*
         * و«تحويل بنكي» اسمُ التحويل في نقطة البيع — وهو «تحويل» هنا.
         *
         * دفعةُ البيع الآجل في الصندوق تُسجَّل بوسيلة الطلب نفسها، وكان ما
         * ليس في القائمة يصير «نقدي»: فتحويلٌ دخل البنك يُرحَّل إلى الدرج.
         */
        $asked = ($data['method'] ?? '') === PaymentMethods::TRANSFER ? 'تحويل' : ($data['method'] ?? '');
        $method = in_array($asked, [...self::METHODS, self::PAYMOB], true) ? $asked : 'نقدي';
        $bankAccountId = self::accountFor($businessId, $method, $data['bank_account_id'] ?? null);

        if ($exact && $allocations !== [] && round(array_sum(array_map('floatval', $allocations)), 3) !== $amount) {
            throw new CollectionRefused(__('مبلغ التحصيل لا يطابق ما خُصّص للفواتير.'));
        }

        // وتركُ الزائد رصيدًا لفاتورةٍ واحدةٍ بعينها — لا لتوزيعٍ ولا لتحصيلٍ على الحساب
        if ($leaveExcess && (! $exact || count($allocations) !== 1)) {
            throw new CollectionRefused(__('ترك الزائد رصيدًا يكون لتحصيلٍ على فاتورةٍ واحدة بعينها.'));
        }

        $fingerprint = $requestKey === null ? null
            : self::fingerprint($businessId, (int) $customer->id, $amount, $method, $data, $allocations, $leaveExcess, $userId);

        // والمحاولةُ نفسُها كُتبت من قبل — يُردّ ما كُتب ولا يُكتب غيرُه
        if ($requestKey !== null && ($written = self::written($businessId, $requestKey, $fingerprint, $userId))) {
            return $written;
        }

        /*
         * والكتابةُ في نقطة حفظها (`Contention`): اصطدامٌ بالفهرس الفريد يُميت
         * الكتابةَ وحدَها، ولا يُجهض معاملةً تحيط بها على PostgreSQL.
         */
        $payment = Contention::attempt(fn () => self::write($businessId, $customer, $amount, $data, $allocations, $method, $bankAccountId, $userId, $requestKey, $exact, $fingerprint, $leaveExcess));

        if ($payment !== null) {
            return $payment;
        }

        // سبقه إرسالٌ بالمفتاح نفسِه إلى الفهرس الفريد — فهو هو إن طابقت بصمتُه، لا تحصيلٌ ثانٍ
        if ($requestKey !== null && ($written = self::written($businessId, $requestKey, $fingerprint, $userId))) {
            return $written;
        }

        throw new RuntimeException(__('تعذّر تسجيل التحصيل الآن — أعد المحاولة.'));
    }

    /**
     * بصمةُ محاولة التحصيل — ما يجعلها هي هي.
     *
     * المتجرُ والعميلُ والفواتيرُ بمبالغها والمبلغُ — بالبيسة لا بالكسر العشريّ —
     * والوسيلةُ والحسابُ المسمّى والتاريخُ واستحقاقُ الشيك والمرجعُ والملاحظةُ وتركُ
     * الزائد ومَن سجّل. والقيمُ كما أُرسلت لا كما فُسّرت: تاريخٌ فارغٌ يبقى فارغًا،
     * فإعادةٌ بعد منتصف الليل لِردٍّ ضاع قبله هي المحاولةُ نفسُها لا أخرى.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, float>  $allocations
     */
    public static function fingerprint(
        int $businessId,
        int $customerId,
        float $amount,
        string $method,
        array $data,
        array $allocations,
        bool $leaveExcess,
        ?int $userId,
    ): string {
        $spread = [];
        foreach ($allocations as $invoiceId => $share) {
            $spread[(int) $invoiceId] = Baisa::of((float) $share);
        }
        ksort($spread);
        $text = fn (string $k) => trim((string) ($data[$k] ?? ''));

        return hash('sha256', json_encode([
            'business' => $businessId,
            'customer' => $customerId,
            'amount' => Baisa::of($amount),
            'method' => $method,
            'bank_account' => filled($data['bank_account_id'] ?? null) ? (int) $data['bank_account_id'] : null,
            'occurred_at' => $text('occurred_at'),
            'cheque_due_at' => $text('cheque_due_at'),
            'external_reference' => $text('external_reference'),
            'notes' => $text('notes'),
            'invoices' => $spread,
            'auto' => (bool) ($data['auto_allocate'] ?? true),
            'leave_excess' => $leaveExcess,
            'user' => $userId,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * تحصيلٌ كُتب بهذا المفتاح في هذا المتجر — موسومًا بأنّه أُعيد.
     *
     * ولا يُردّ إلّا لِمَن سجّله وبالبصمة نفسِها: مفتاحٌ يحمله غيرُه، أو مفتاحٌ أُعيد
     * بعمليّةٍ أخرى، يُردّ بسببه — ولا يُقال فيه رقمُ التحصيل الأوّل ولا مبلغُه.
     */
    private static function written(int $businessId, string $requestKey, ?string $fingerprint, ?int $userId): ?CustomerPayment
    {
        $payment = CustomerPayment::where('business_id', $businessId)
            ->where('request_key', $requestKey)->with('allocations')->first();

        if ($payment === null) {
            return null;
        }

        if ($payment->created_by !== null && (int) $payment->created_by !== (int) $userId) {
            throw new CollectionRefused(__('مفتاح هذا التحصيل يخصّ محاولةً سجّلها غيرك — افتح نافذة التحصيل من جديد.'));
        }

        if ($fingerprint !== null && $payment->request_fingerprint !== null && ! hash_equals((string) $payment->request_fingerprint, $fingerprint)) {
            throw new CollectionRefused(__('مفتاح هذا التحصيل استُعمل لتحصيلٍ بتفاصيل أخرى — لم يُكتب شيء. افتح نافذة التحصيل من جديد.'));
        }

        $payment->replayed = true;

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, float>  $allocations
     */
    private static function write(
        int $businessId,
        Customer $customer,
        float $amount,
        array $data,
        array $allocations,
        string $method,
        ?int $bankAccountId,
        ?int $userId,
        ?string $requestKey,
        bool $exact,
        ?string $fingerprint = null,
        bool $leaveExcess = false,
    ): CustomerPayment {
        return DB::transaction(function () use ($businessId, $customer, $amount, $data, $allocations, $method, $bankAccountId, $userId, $requestKey, $exact, $fingerprint, $leaveExcess) {
            $payment = CustomerPayment::create([
                'business_id' => $businessId,
                'customer_id' => $customer->id,
                'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint,
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
                /*
                 * والشيكُ يولد «تحت التحصيل» — لا محصَّلًا.
                 *
                 * هو وعدٌ بمالٍ لا مال: ورقةٌ قد تُصرَف بعد شهرين وقد ترتدّ.
                 * وغيرُ الشيك بلا حال: النقدُ لا يُحصَّل ولا يرتدّ، ووسمُه
                 * بحالِ شيكٍ يجعل كلَّ عدٍّ للشيكات يبتلع تحصيلات المتجر.
                 */
                'cheque_status' => Cheques::isCheque($method) ? Cheques::PENDING : null,
                'cheque_due_at' => Cheques::isCheque($method) && filled($data['cheque_due_at'] ?? null)
                    ? Carbon::parse($data['cheque_due_at'])->toDateString()
                    : null,
            ]);

            self::allocate($payment, $allocations, (bool) ($data['auto_allocate'] ?? true), $exact, $leaveExcess);
            self::post($payment, $userId);

            Activity::log('created', 'سجّل تحصيلًا '.$payment->number.' من '.$customer->name.' بمبلغ '.$amount, [
                'subject_id' => $payment->id, 'subject_type' => 'customer_payment',
                // والمتجرُ من الدفعة لا من الجلسة: إشعارُ Paymob لا جلسةَ له
                'business_id' => $businessId,
            ]);

            /*
             * وفاتورةٌ أتمّها هذا التحصيلُ تظهر في لوحة التجهيز إن طُلب لها —
             * بعد تثبيت المعاملة كلِّها لا فيها: سقوطٌ هناك لا يُسقط المال، ولا
             * يرمي (`CustomerInvoicePreparations::afterPayment`).
             */
            $paymentId = (int) $payment->id;
            DB::afterCommit(fn () => CustomerInvoicePreparations::afterPayment($paymentId));

            return $payment->fresh('allocations');
        });
    }

    /**
     * التخصيص — صريحًا أو على الأقدم فالأقدم.
     *
     * والفواتيرُ تُقفل بقفل الكتابة قبل قراءة باقيها: نافذتان تسجّلان دفعتين
     * في اللحظة نفسها تقرآن الباقي نفسه فتخصّصان أكثر ممّا عليه.
     *
     * و`$auto = false` لا يوزّع ما لم يُسمَّ: رابطُ دفعٍ بلا فاتورةٍ تحصيلٌ
     * للعميل لا سدادٌ لأقدم فواتيره — يبقى رصيدًا غيرَ مخصَّص.
     *
     * و`$exact` يردّ ولا يقصّ: باقي الفاتورة يُقرأ بعد قفلها، فإن طُلب عليها أكثرُ
     * منه — أو لم تعد صادرة — رُدّ التحصيلُ كلُّه، والمعاملةُ تُرجَع بصفّه ورقمه.
     * فنافذتان تحصّلان الفاتورةَ كلَّها في اللحظة نفسها: الثانيةُ تنتظر قفلَ الأولى،
     * ثمّ تقرأ باقيًا صفرًا فتُردّ — لا تُكتب رصيدًا للعميل.
     *
     * @param  array<int, float>  $explicit
     */
    private static function allocate(CustomerPayment $payment, array $explicit, bool $auto = true, bool $exact = false, bool $leaveExcess = false): void
    {
        $exact = $exact && $explicit !== [];
        $leaveExcess = $leaveExcess && $exact;

        $left = round((float) $payment->amount, 3);

        if ($explicit === [] && ! $auto) {
            return;
        }

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
                if ($exact) {
                    throw new CollectionRefused($invoice
                        ? __('الفاتورة :n ليست صادرة — لا يُحصَّل عليها.', ['n' => $invoice->number ?: $invoice->id])
                        : __('هذه الفاتورة ليست من فواتير متجرك.'));
                }

                // معرّفٌ من متجرٍ آخر أو فاتورةٌ غير صادرة: لا تُخصَّص، ولا يضيع المال
                continue;
            }

            /*
             * ومالُ عميلٍ لا يسدّد فاتورةَ عميلٍ آخر.
             *
             * الطلبُ يحمل العميلَ والفاتورةَ كليهما، وكانت الفاتورةُ تُقفل
             * بمتجرها وحدَه: «من الوزارة، على فاتورة الشركة» كان يمرّ — فتُوسَم
             * فاتورةُ الشركة مسدَّدةً بمالٍ لم تدفعه، ويُكتب للوزارة رصيدٌ لم
             * تقصده. ويُردّ بالاسم لا يُتخطّى في صمت: تخطّيه يترك المالَ رصيدًا
             * «للعميل» ويقول التنبيهُ إنّه بقي — وهو مالٌ سُجّل على غير صاحبه.
             */
            if ((int) $invoice->customer_id !== (int) $payment->customer_id) {
                throw new RuntimeException(__('الفاتورة :n ليست على هذا العميل.', ['n' => $invoice->number]));
            }

            $outstanding = $invoice->outstanding();
            $wanted = $explicit !== [] ? round((float) $explicit[$invoiceId], 3) : $outstanding;

            /*
             * وتركُ الزائد رصيدًا يُخصّص باقيها وحده ويُبقي ما فوقه غيرَ مخصَّص — على
             * فاتورةٍ عليها باقٍ. فإن لم يبقَ عليها شيء رُدّ: لا يصير الخيارُ تحصيلًا
             * ثانيًا على فاتورةٍ مسدَّدة، والدفعةُ المقدّمة لها بابُها.
             */
            if ($leaveExcess && $outstanding <= 0.0005) {
                throw new CollectionRefused(__('الفاتورة :n مدفوعة بالكامل — لا يُترك عليها زائدٌ رصيدًا. لدفعةٍ مقدّمة سجّل «تحصيلًا على حساب العميل».', ['n' => $invoice->number]));
            }

            if ($exact && ! $leaveExcess && $wanted > $outstanding + 0.0005) {
                throw new CollectionRefused($outstanding <= 0.0005
                    ? __('الفاتورة :n مدفوعة بالكامل — لا يُحصَّل عليها شيء.', ['n' => $invoice->number])
                    : __('المتبقي على الفاتورة :n هو :left — لا يُحصَّل عليها أكثر منه.', ['n' => $invoice->number, 'left' => number_format($outstanding, 3)]));
            }

            $share = round(min($wanted, $outstanding, $left), 3);

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
        /*
         * والشيكُ لا يُرحَّل إلى البنك — بل إلى «شيكات تحت التحصيل».
         *
         * الذمّةُ انتقلت من العميل إلى الورقة، والورقةُ لم تصل البنك بعد.
         * وترحيلُه بنكًا يجعل الدفتر يشهد بمالٍ لم يصل، فيقرأ التاجر رصيدًا
         * مخترَعًا ولا يكتشفه حتّى يطابق كشف حسابه — إن طابقه.
         *
         * ويمرّ بـ`Cheques::clear` إلى البنك حين يُصرَف فعلًا.
         */
        if (Cheques::isCheque((string) $payment->method)) {
            $target = Cheques::account((int) $payment->business_id);
        } else {
            $side = self::sideFor((string) $payment->method);
            $target = $side === 'bank'
                ? (Bank::leaf((int) $payment->business_id, $payment->bank_account_id) ?? 'bank')
                : $side;
        }

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
            /*
             * والإلغاءُ مرّةً واحدة — يُقرأ تحت قفل صفّه.
             *
             * نافذتان تُلغيان التحصيلَ نفسَه، أو صفحةٌ قديمةٌ قرأته قبل إلغائه: كانت
             * الثانيةُ تمرّ من فحص النسخة التي في يدها، فتكتب إلغاءً فوق إلغاء
             * وتعكس نقاطَه مرّةً ثانية. فيُقرأ الصفُّ مقفلًا، والملغى يُردّ كما هو.
             */
            $payment = CustomerPayment::whereKey($payment->id)->lockForUpdate()->first() ?? $payment;

            if ($payment->cancelled_at !== null) {
                return $payment;
            }

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

            // ونقاطُ سداد فاتورةٍ برابطٍ تُعكس معه — بحركةٍ لا بمحو، وفي معاملته
            InvoiceLoyalty::reversePayment($payment, $userId);

            Activity::log('deleted', 'ألغى تحصيل '.$payment->number.' — '.$reason, [
                'subject_id' => $payment->id, 'subject_type' => 'customer_payment',
            ]);

            return $payment->fresh();
        });
    }
}
