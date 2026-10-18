<?php

namespace App\Support;

use App\Http\Controllers\PdfController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Store\CardIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * فاتورةُ العميل تُجهَّز بعد سدادها — من الفاتورة إلى لوحة التجهيز.
 *
 * ═══ ما هو ═══
 *
 * موظّفٌ يكتب فاتورةَ عميل، ويطلب «إرسال إلى لوحة التجهيز بعد السداد» مع
 * الفرع والموعد والمستلِم. يُرسَل للعميل رابطُ Paymob فيدفع. فإذا ثبت
 * الدفعُ **وسُدّدت الفاتورةُ كلُّها** ظهرت مهمّةُ التجهيز في اللوحة مرّةً
 * واحدة، وكُتبت لصندوق الفرع ورقةٌ داخليّةٌ تنتظر الطباعة (`PrintJobs`).
 *
 * ═══ وما ليس هو ═══
 *
 * - **لا بيعٌ ثانٍ.** لا طلبَ يُكتب ولا قيدَ ولا ضريبةَ ولا حركةَ مخزون:
 *   الفاتورةُ قُيّدت حين صدرت (`CustomerInvoices::issue`)، والتحصيلُ حين
 *   دُفع (`CustomerPayments::record`). وهذا الصفُّ عملٌ لا مال.
 * - **ولا تفعيلَ بغير دفعٍ موثَّق.** لا عودةُ المتصفّح من Paymob، ولا رابطٌ
 *   معلَّقٌ أو ملغًى أو منتهٍ، ولا دفعةٌ جزئيّة. البابُ الآليّ الوحيد إشعارُ
 *   Paymob الموقَّع بعد أن يُثبَّت ماله (`afterLinkPaid`). وفاتورةٌ سُدّدت
 *   بغيره (نقدًا مثلًا) تُرسَل بيدِ من يملك التحصيل، بعد أن يُتحقَّق من
 *   سدادها في الخادم (`activateByHand`).
 * - **ولا تفعيلَ مرّتين.** القفلُ على صفّ التجهيز و`activated_at` يقولان
 *   ذلك؛ وإشعارٌ مكرّر أو متزامن يجد الصفَّ مفعَّلًا فيمضي.
 *
 * ═══ والمالُ قبل كلّ شيء ═══
 *
 * التفعيلُ بعد أن تُثبَّت معاملةُ الدفع، لا في داخلها: لو سقط التفعيلُ
 * داخلها لسقط التحصيلُ معه وقيل لـPaymob «تمّ» — مالٌ دُفع ولم يُسجَّل.
 * فيُلتقط خطؤه ويُكتب على الصفّ (`activation_error`)، والإشعارُ التالي
 * وأمرُ المطابقة (`reconcile`) يعيدانه.
 */
final class CustomerInvoicePreparations
{
    /** من أين فُعّلت — إشعارُ Paymob الموقَّع، أو المطابقةُ من روابطه، أو يدٌ مأذونة */
    public const FROM_PAYMOB = 'paymob';

    public const FROM_RECONCILE = 'reconcile';

    public const FROM_HAND = 'manual';

    /** الحالُ الأولى بعد السداد — «بانتظار التجهيز» في اللوحة */
    public const FIRST = OrderStatus::CONFIRMED;

    /** ما يُغلق المهمّة — سُلّمت أو استُلمت */
    public const CLOSED = [OrderStatus::DELIVERED, OrderStatus::PICKED_UP];

    /**
     * ما لا يُبلغ من اللوحة لمهمّة فاتورة.
     *
     * الإلغاءُ ورقةٌ ماليّة (إشعارٌ دائن أو إلغاءُ الفاتورة) لا زرٌّ على الطاولة،
     * و«مكتمل» حالُ بيعٍ لا تسليم، و«جديد» حالُ طلبٍ لم يُؤكَّد — والفاتورةُ
     * المسدَّدة مؤكَّدةٌ بسدادها.
     */
    private const NEVER = [OrderStatus::CANCELLED, OrderStatus::COMPLETED, OrderStatus::PENDING];

    /** ما لا يناسب نوعَ التسليم — كاللوحة للطلبات */
    private const NOT_FOR = [
        FlowerOrder::PICKUP => [OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED, OrderStatus::DELIVERY_FAILED],
        FlowerOrder::DELIVERY => [OrderStatus::PICKED_UP],
    ];

    /** الحقولُ التي تُكتب من النموذج — وما سواها لا يمسّه طلب */
    private const FIELDS = [
        'branch_id', 'fulfillment_type', 'scheduled_for',
        'recipient_name', 'recipient_phone', 'delivery_address', 'delivery_notes', 'internal_notes',
        'is_gift', 'occasion_type', 'occasion_text', 'card_message', 'sender_name', 'hide_sender',
    ];

    /** أفُتح التجهيزُ بعد السداد لهذا النشاط؟ — يفتحه مديرُ المنصّة وحده */
    public static function on(int $businessId): bool
    {
        return (bool) Business::whereKey($businessId)->value('customer_invoice_preparation_enabled');
    }

    /* ═══════════════════════════ النموذج ═══════════════════════════ */

    /**
     * قواعدُ قسم «التجهيز والتسليم» — بمفتاح `preparation.*` في طلب الفاتورة.
     *
     * والتحقّقُ في الخادم كلُّه: الشاشةُ تُخفي ما لا يلزم، ولا تقرّر ما يلزم.
     *
     * @return array<string, mixed>
     */
    public static function rules(int $businessId, string $prefix = 'preparation.'): array
    {
        $p = $prefix;
        $wanted = 'required_if_accepted:'.$p.'enabled';
        $delivery = fn (string $rule) => $rule.':'.$p.'fulfillment_type,'.FlowerOrder::DELIVERY;

        return [
            $p.'enabled' => ['nullable', 'boolean'],
            $p.'fulfillment_type' => ['nullable', $wanted, Rule::in(FlowerOrder::FULFILLMENT)],
            $p.'branch_id' => ['nullable', $wanted, 'integer', Rule::exists('branches', 'id')->where('business_id', $businessId)],
            $p.'scheduled_for' => ['nullable', $wanted, 'date'],
            // من يستلم — المستلِمُ في التوصيل، ومن يأتي للاستلام من المحلّ
            $p.'recipient_name' => ['nullable', $wanted, 'string', 'max:120'],
            $p.'recipient_phone' => array_merge(['nullable', $wanted], array_values(array_diff(FlowerOrder::PHONE_RULE, ['nullable']))),
            $p.'delivery_address' => ['nullable', $delivery('required_if'), 'string', 'max:500'],
            $p.'delivery_notes' => ['nullable', 'string', 'max:1000'],
            $p.'internal_notes' => ['nullable', 'string', 'max:2000'],
            $p.'is_gift' => ['nullable', 'boolean'],
            $p.'occasion_type' => ['nullable', Rule::in(FlowerOrder::allOccasions($businessId))],
            $p.'occasion_text' => ['nullable', 'string', 'max:40'],
            $p.'card_message' => ['nullable', 'string', 'max:'.FlowerOrder::CARD_MAX],
            $p.'sender_name' => ['nullable', 'string', 'max:120'],
            $p.'hide_sender' => ['nullable', 'boolean'],
            // رسالةٌ تكشف اسمَ من أخفى نفسَه تُطبع كما هي بتأكيدٍ صريح — انظر `CardIdentity`
            $p.'card_identity_confirmed' => ['nullable', 'boolean'],
        ];
    }

    /** أسماءُ الحقول في رسائل الخطأ — بالعربيّة لا بمفاتيحها */
    public static function attributes(string $prefix = 'preparation.'): array
    {
        return [
            $prefix.'fulfillment_type' => __('نوع التسليم'),
            $prefix.'branch_id' => __('فرع التجهيز'),
            $prefix.'scheduled_for' => __('موعد التسليم'),
            $prefix.'recipient_name' => __('اسم المستلم'),
            $prefix.'recipient_phone' => __('هاتف المستلم'),
            $prefix.'delivery_address' => __('عنوان التوصيل'),
            $prefix.'delivery_notes' => __('تعليمات التوصيل'),
            $prefix.'internal_notes' => __('ملاحظات التجهيز'),
            $prefix.'occasion_type' => __('المناسبة'),
            $prefix.'occasion_text' => __('المناسبة'),
            $prefix.'card_message' => __('رسالة البطاقة'),
            $prefix.'sender_name' => __('اسم المرسل'),
        ];
    }

    /** أطُلب التجهيزُ في هذا الطلب؟ */
    public static function wanted(array $data): bool
    {
        return filter_var($data['preparation']['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * يكتب تجهيزَ الفاتورة أو يعدّله — ولا يمسّ بندًا ولا مبلغًا.
     *
     * - المفتاحُ مغلقٌ ← لا شيء يُكتب، ويُقال.
     * - فاتورةٌ ملغاة أو تغطّي طلبات ← لا: طلباتُها في اللوحة أصلًا، ومهمّةٌ
     *   ثانيةٌ لها تجهيزٌ مكرَّر للبضاعة نفسِها.
     * - مهمّةٌ أُغلقت (سُلّمت) ← لا تُعدَّل.
     *
     * @param  array<string, mixed>  $input  ما تحت `preparation.` بعد التحقّق
     */
    public static function save(CustomerInvoice $invoice, array $input, ?int $userId = null): CustomerInvoicePreparation
    {
        $bid = (int) $invoice->business_id;

        if (! self::on($bid)) {
            throw ValidationException::withMessages(['preparation' => __('التجهيز بعد السداد غير مفعّل لهذا النشاط.')]);
        }
        if ($invoice->status === CustomerInvoice::CANCELLED) {
            throw ValidationException::withMessages(['preparation' => __('الفاتورة ملغاة — لا تُرسل إلى التجهيز.')]);
        }
        if ($invoice->coversOrders()) {
            throw ValidationException::withMessages(['preparation' => __('هذه الفاتورة تغطّي طلبات تُجهَّز من لوحة التجهيز أصلًا.')]);
        }

        $data = self::normalize($input, $invoice);

        return DB::transaction(function () use ($invoice, $data, $userId, $bid) {
            $current = CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->lockForUpdate()->first();

            if ($current && in_array($current->status, self::CLOSED, true)) {
                throw ValidationException::withMessages(['preparation' => __('سُلّمت هذه المهمّة — لا تُعدَّل بعد التسليم.')]);
            }

            if ($current) {
                $current->forceFill($data)->save();
                Activity::log('updated', 'عدّل تجهيز الفاتورة '.($invoice->number ?: '#'.$invoice->id), [
                    'subject_id' => $current->id, 'subject_type' => 'customer_invoice_preparation', 'business_id' => $bid,
                ]);

                return $current;
            }

            $prep = CustomerInvoicePreparation::create($data + [
                'business_id' => $bid,
                'customer_invoice_id' => $invoice->id,
                'created_by' => $userId,
            ]);

            Activity::log('created', 'طلب تجهيز الفاتورة '.($invoice->number ?: '#'.$invoice->id).' بعد سدادها', [
                'subject_id' => $prep->id, 'subject_type' => 'customer_invoice_preparation', 'business_id' => $bid,
            ]);

            return $prep;
        });
    }

    /** يُزال طلبُ التجهيز ما لم يُفعَّل — بعد التفعيل عملٌ بدأ لا يُمحى */
    public static function remove(CustomerInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $prep = CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->lockForUpdate()->first();

            if (! $prep) {
                return;
            }
            if ($prep->isActive()) {
                throw ValidationException::withMessages(['preparation' => __('ظهرت المهمّة في لوحة التجهيز — لا تُزال بعد الآن.')]);
            }

            $prep->delete();
            Activity::log('deleted', 'ألغى طلب تجهيز الفاتورة '.($invoice->number ?: '#'.$invoice->id), [
                'subject_id' => $prep->id, 'subject_type' => 'customer_invoice_preparation', 'business_id' => (int) $invoice->business_id,
            ]);
        });
    }

    /**
     * ما يُحفظ ممّا أُرسل — مع شروطٍ لا تقولها القواعدُ وحدها.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function normalize(array $input, CustomerInvoice $invoice): array
    {
        $text = fn (string $k) => filled($input[$k] ?? null) ? trim((string) $input[$k]) : null;
        $type = (string) ($input['fulfillment_type'] ?? '');
        $hide = filter_var($input['hide_sender'] ?? false, FILTER_VALIDATE_BOOL);

        $data = [
            'branch_id' => (int) $input['branch_id'],
            'fulfillment_type' => $type,
            'scheduled_for' => Carbon::parse((string) $input['scheduled_for']),
            'recipient_name' => $text('recipient_name'),
            'recipient_phone' => $text('recipient_phone'),
            // والعنوانُ للتوصيل وحده — استلامٌ من المحلّ لا يحمل عنوانًا يُطبع
            'delivery_address' => $type === FlowerOrder::DELIVERY ? $text('delivery_address') : null,
            'delivery_notes' => $text('delivery_notes'),
            'internal_notes' => $text('internal_notes'),
            'is_gift' => filter_var($input['is_gift'] ?? false, FILTER_VALIDATE_BOOL),
            'occasion_type' => $text('occasion_type'),
            'occasion_text' => ($input['occasion_type'] ?? null) === 'other' ? $text('occasion_text') : null,
            'card_message' => $text('card_message'),
            'sender_name' => $text('sender_name'),
            'hide_sender' => $hide,
        ];

        /*
         * ═══ رسالةٌ تكشف من أخفى اسمَه ═══
         *
         * القاعدةُ نفسُها التي يسألها المتجرُ الإلكترونيّ (`CardIdentity::reveals`):
         * مُهدٍ طلب إخفاءَ اسمه، ورسالتُه تحمل اسمَه أو رقمَه — فإمّا أن تُعدَّل،
         * أو يُؤكَّد صراحةً أنّها تُطبع كما هي. ولا تُطبع كاشفةً في صمت.
         */
        $confirmed = null;
        if ($hide && filled($data['card_message'])) {
            $customer = Customer::withTrashed()->find($invoice->customer_id);
            $names = array_filter([$data['sender_name'], $invoice->customer_name, $customer?->name]);
            $reveals = false;
            foreach ($names as $name) {
                $reveals = $reveals || CardIdentity::reveals((string) $data['card_message'], (string) $name, (string) ($customer?->phone ?? ''), (string) $data['recipient_name']);
            }
            $reveals = $reveals || CardIdentity::reveals((string) $data['card_message'], '', (string) ($customer?->phone ?? ''));

            if ($reveals) {
                if (! filter_var($input['card_identity_confirmed'] ?? false, FILTER_VALIDATE_BOOL)) {
                    throw ValidationException::withMessages([
                        'preparation.card_message' => __('رسالة البطاقة قد تحتوي على اسم المرسل أو رقمه مع أنه اختار إخفاء اسمه — عدّلها أو أكّد طباعتها كما هي.'),
                    ]);
                }
                $confirmed = now();
            }
        }
        $data['card_identity_confirmed_at'] = $confirmed;

        return $data;
    }

    /* ═══════════════════════════ التفعيل ═══════════════════════════ */

    /**
     * أتظهر المهمّة الآن؟ — `null` إن نعم، وإلّا سببُ الرفض.
     *
     * والسدادُ الكامل: فاتورةٌ **صادرة** باقيها صفر. و`outstanding()` وحدها
     * تردّ صفرًا للمسودّة والملغاة — فالحالُ يُسأل معها.
     */
    public static function blocker(CustomerInvoicePreparation $prep, CustomerInvoice $invoice): ?string
    {
        return match (true) {
            (int) $invoice->business_id !== (int) $prep->business_id => __('الفاتورة لا تخصّ هذا النشاط.'),
            ! self::on((int) $prep->business_id) => __('التجهيز بعد السداد غير مفعّل لهذا النشاط.'),
            $invoice->status !== CustomerInvoice::ISSUED => __('الفاتورة غير صادرة.'),
            $invoice->outstanding() > 0.0005 => __('الفاتورة لم تُسدَّد بالكامل بعد.'),
            $invoice->coversOrders() => __('هذه الفاتورة تغطّي طلبات تُجهَّز من لوحة التجهيز أصلًا.'),
            default => null,
        };
    }

    /**
     * يُظهر المهمّة في لوحة التجهيز — مرّةً واحدة، وبسدادٍ كاملٍ يُقرأ الآن.
     *
     * في معاملةٍ واحدة: الصفُّ تحت قفل، والفاتورةُ تُقرأ تحت قفلٍ من جديد —
     * فإشعاران متزامنان لا يُفعّلان مرّتين، ودفعةٌ أُلغيت قبل لحظة لا تُحسب.
     * وتُكتب ورقةُ الصندوق الداخليّة في المعاملة نفسِها (`PrintJobs`): لا
     * مهمّةٌ بلا ورقة، ولا ورقةٌ بلا مهمّة.
     *
     * @return array{0: ?CustomerInvoicePreparation, 1: ?string} المهمّةُ إن فُعّلت الآن، وسببُ الرفض إن رُفضت
     */
    public static function activate(int $invoiceId, string $source, ?int $paymentId = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($invoiceId, $source, $paymentId, $userId) {
            $prep = CustomerInvoicePreparation::where('customer_invoice_id', $invoiceId)->lockForUpdate()->first();

            if (! $prep) {
                return [null, __('لم يُطلب تجهيزٌ لهذه الفاتورة.')];
            }
            if ($prep->isActive()) {
                return [null, null];
            }

            $invoice = CustomerInvoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            if ($why = self::blocker($prep, $invoice)) {
                return [null, $why];
            }

            $prep->forceFill([
                'activated_at' => now(),
                'activation_source' => $source,
                'activation_payment_id' => $paymentId,
                'activated_by' => $userId,
                'activation_error' => null,
                'status' => self::FIRST,
                'status_changed_at' => now(),
            ])->save();

            PrintJobs::queueInvoiceInternal($prep);

            Activity::log('created', 'وصلت الفاتورة '.$invoice->number.' إلى لوحة التجهيز بعد سدادها', [
                'subject_id' => $prep->id, 'subject_type' => 'customer_invoice_preparation', 'business_id' => (int) $prep->business_id,
            ]);

            return [$prep, null];
        });
    }

    /**
     * بعد أن يُثبَّت دفعُ رابطٍ موثَّق — البابُ الآليّ الوحيد.
     *
     * يُنادى من `PaymentLinks::callback` بعد `settle` وخارج معاملتها، ولو كان
     * الإشعارُ مكرّرًا: فيصير كلُّ إعادةٍ من Paymob محاولةً ثانيةً للتفعيل.
     *
     * ولا يُفعَّل إلّا برابطٍ:
     *   - مدفوعٍ فعلًا، وتحصيلُه قائمٌ غيرُ ملغًى؛
     *   - على فاتورةٍ وفي متجرِها؛
     *   - كان مفتوحًا حين دُفع: لم يُلغَ، ولم تنقضِ مهلتُه قبل الدفع.
     *     والمالُ من رابطٍ ملغًى أو منتهٍ يُسجَّل كما يُسجَّل (`settle`)،
     *     والتجهيزُ له بيدٍ بعد مراجعة — لا وحده.
     *
     * ولا يرمي أبدًا: سقوطُه يُكتب على الصفّ ويُعاد، والمالُ ثابتٌ قبله.
     */
    public static function afterLinkPaid(CustomerPaymentLink $link): void
    {
        $invoiceId = $link->customer_invoice_id ? (int) $link->customer_invoice_id : null;

        try {
            $link = CustomerPaymentLink::find($link->id);

            if (! $link || ! $invoiceId || ! self::linkCounts($link)) {
                return;
            }

            // وما يمنعه حالٌ مشروع (دفعةٌ جزئيّة، مفتاحٌ مغلق) ليس خطأً يُكتب — يُرى في الفاتورة بحالها
            self::activate($invoiceId, self::FROM_PAYMOB, (int) $link->customer_payment_id);
        } catch (\Throwable $e) {
            report($e);
            self::remember($invoiceId, $e);
        }
    }

    /**
     * أيُحتسب هذا الرابطُ بابًا للتفعيل؟
     *
     * مدفوعٌ بتحصيلٍ قائم، على فاتورةٍ من متجره، ولم يكن ملغًى ولا منتهيًا
     * حين دُفع. والحكمُ بما على الصفّ لا بحالٍ عابرة: يصدق في الإعادة كما
     * صدق أوّلَ مرّة.
     */
    public static function linkCounts(CustomerPaymentLink $link): bool
    {
        if ($link->status !== CustomerPaymentLink::PAID || ! $link->customer_payment_id || ! $link->customer_invoice_id || ! $link->paid_at) {
            return false;
        }
        if ($link->cancelled_at !== null) {
            return false;
        }
        if ($link->expires_at !== null && $link->paid_at->greaterThan($link->expires_at)) {
            return false;
        }

        /*
         * ودُفع والتجهيزُ مفتوح — لا قبل آخر فتحٍ له.
         *
         * رابطٌ سُدّد والمفتاحُ مغلق لم يظهر يومَها، وجُهّز بيدٍ أو لم يُجهَّز.
         * وفتحُ المفتاح بعد أسبوع لا يُعيده إلى اللوحة ويطبع ورقتَه وحده —
         * المطابقةُ والإشعارُ المكرَّر كلاهما يمرّان من هنا. ويُرسَل بيدٍ من
         * صفحة الفاتورة إن لم يُجهَّز بعد.
         */
        $since = Business::whereKey($link->business_id)->value('customer_invoice_preparation_enabled_at');
        if ($since !== null && $link->paid_at->lessThan(Carbon::parse($since))) {
            return false;
        }

        $payment = CustomerPayment::find($link->customer_payment_id);
        if (! $payment || $payment->cancelled_at !== null || (int) $payment->business_id !== (int) $link->business_id) {
            return false;
        }

        return CustomerInvoice::whereKey($link->customer_invoice_id)->where('business_id', $link->business_id)->exists();
    }

    /**
     * فاتورةٌ سُدّدت بغير رابط (نقدًا، أو تحويلًا) — تُرسَل بيدٍ مأذونة.
     *
     * والسدادُ يُقرأ في الخادم كما يُقرأ للرابط (`blocker`): لا يُرسل الزرُّ
     * فاتورةً لم تُسدَّد، ولا يُرسلها مرّتين.
     */
    public static function activateByHand(CustomerInvoice $invoice, int $userId): ?string
    {
        [, $why] = self::activate((int) $invoice->id, self::FROM_HAND, null, $userId);

        return $why;
    }

    /**
     * المطابقة: روابطُ دُفعت ومهامُّها لم تظهر — تُعاد من جديد.
     *
     * لسقوطٍ بعد ثبات المال (قاعدةٌ مشغولة، خطأٌ عابر). ولا يُفعّل إلّا ما
     * يُفعّله الإشعارُ نفسُه: رابطٌ يُحتسب (`linkCounts`) وفاتورةٌ سُدّدت كلُّها.
     */
    public static function reconcile(): int
    {
        $done = 0;

        CustomerInvoicePreparation::whereNull('activated_at')
            ->whereIn('business_id', Business::where('customer_invoice_preparation_enabled', true)->select('id'))
            ->orderBy('id')
            ->chunkById(100, function ($preps) use (&$done) {
                foreach ($preps as $prep) {
                    $links = CustomerPaymentLink::where('business_id', $prep->business_id)
                        ->where('customer_invoice_id', $prep->customer_invoice_id)
                        ->where('status', CustomerPaymentLink::PAID)
                        ->orderByDesc('paid_at')
                        ->get();

                    $link = $links->first(fn (CustomerPaymentLink $l) => self::linkCounts($l));
                    if (! $link) {
                        continue;
                    }

                    try {
                        [$activated] = self::activate((int) $prep->customer_invoice_id, self::FROM_RECONCILE, (int) $link->customer_payment_id);
                        $done += $activated ? 1 : 0;
                    } catch (\Throwable $e) {
                        report($e);
                        self::remember((int) $prep->customer_invoice_id, $e);
                    }
                }
            });

        return $done;
    }

    /** سببُ سقوط التفعيل على الصفّ — يُرى في الفاتورة، ولا يُسقط شيئًا إن سقط هو */
    private static function remember(?int $invoiceId, \Throwable $e): void
    {
        if (! $invoiceId) {
            return;
        }

        try {
            CustomerInvoicePreparation::where('customer_invoice_id', $invoiceId)
                ->whereNull('activated_at')
                ->update(['activation_error' => mb_substr(__('تعذّر إرسال المهمّة إلى لوحة التجهيز — ستُعاد المحاولة.'), 0, 500), 'updated_at' => now()]);
        } catch (\Throwable $inner) {
            Log::error('customer invoice preparation: لم يُكتب سببُ السقوط', ['invoice' => $invoiceId, 'error' => $inner->getMessage()]);
        }
    }

    /* ═══════════════════════════ اللوحة ═══════════════════════════ */

    /** مهامُّ اللوحة: ظهرت ولم تُغلق — في متجرها، وفي الفرع الحاليّ إن كان */
    public static function board(int $businessId, ?int $branchId)
    {
        return CustomerInvoicePreparation::where('customer_invoice_preparations.business_id', $businessId)
            ->whereNotNull('activated_at')
            ->whereNotIn('status', self::CLOSED)
            ->when($branchId, fn ($w) => $w->where('branch_id', $branchId));
    }

    /** ما يجوز الانتقالُ إليه من الحال الحاليّة — أزرارُ البطاقة منها */
    public static function next(CustomerInvoicePreparation $prep): array
    {
        if (! $prep->isActive() || $prep->status === null) {
            return [];
        }

        $hidden = array_merge(self::NEVER, self::NOT_FOR[$prep->fulfillment_type] ?? []);

        return array_values(array_diff(OrderStatus::nextFrom((string) $prep->status), $hidden));
    }

    /**
     * ينقل المهمّة في اللوحة — تحت قفل، وبالانتقالات المسموحة وحدها.
     *
     * ولا يمسّ الفاتورةَ ولا تحصيلَها ولا دفترًا: التسليمُ حدثٌ على الطاولة،
     * والمالُ قُيّد حين صدرت الورقةُ ودُفعت.
     *
     * @return string|null سببُ الرفض، أو `null` إن نُقلت
     */
    public static function move(int $businessId, int $prepId, ?int $branchId, string $to, ?string &$from = null): ?string
    {
        return DB::transaction(function () use ($businessId, $prepId, $branchId, $to, &$from) {
            $prep = self::board($businessId, $branchId)->whereKey($prepId)->lockForUpdate()->first();

            if (! $prep) {
                return __('المهمّة غير موجودة في لوحة التجهيز.');
            }

            $from = (string) $prep->status;

            if (! in_array($to, self::next($prep), true)) {
                return __('لا يمكن نقل المهمّة من «:from» إلى «:to».', ['from' => $from, 'to' => $to]);
            }

            $prep->forceFill(['status' => $to, 'status_changed_at' => now()])->save();

            return null;
        });
    }

    /* ═══════════════════════════ سندُ التسليم ═══════════════════════════ */

    /**
     * ما يُعطى لبانِي سند التسليم — موضوعٌ في الذاكرة بشكل الطلب، لا يُحفظ.
     *
     * لا بيعٌ يُكتب: الصفُّ لا يُحفظ أبدًا (`save` ترمي)، ولا يعرفه دفترٌ ولا
     * مخزون. وإنّما لأنّ سند التسليم الآمنَ للمستلِم يُبنى في مكانٍ واحد
     * (`DeliveryPaper::forRecipient`): المستلِمُ والعنوانُ والبطاقةُ وإخفاءُ
     * المُهدي ومسحُ اسمه ورقمه من كلّ نصّ. فيُعطى الموضوعَ نفسَه بدل أن
     * تُنسخ قواعدُه — ونسخةٌ ثانيةٌ للخصوصيّة تفترق يومًا عن الأولى.
     *
     * والبنودُ من الفاتورة: وصفُها وكميّتُها، ولا سعرَ — والقالبُ لا يقرأ سعرًا.
     */
    public static function deliverySubject(CustomerInvoicePreparation $prep, CustomerInvoice $invoice): Order
    {
        $subject = new class extends Order
        {
            public function save(array $options = []): bool
            {
                throw new LogicException('موضوعُ سند التسليم في الذاكرة لا يُحفظ — ليس بيعًا.');
            }
        };

        $customer = Customer::withTrashed()->find($invoice->customer_id);
        $branch = $prep->branch_id ? Branch::find($prep->branch_id) : null;

        $subject->forceFill([
            'business_id' => (int) $invoice->business_id,
            'number' => (string) $invoice->number,
            // ساعةُ الإصدار إن كُتبت — `issued_at` تاريخٌ بلا ساعة يُطبع «٠٠:٠٠»
            'ordered_at' => $invoice->issued_by_at ?? $invoice->issued_at,
            'scheduled_for' => $prep->scheduled_for,
            'fulfillment_type' => $prep->fulfillment_type,
            'branch' => $branch?->name,
            'customer_id' => $invoice->customer_id,
            'customer_name' => $invoice->customer_name ?: $customer?->name,
            'recipient_name' => $prep->recipient_name,
            'recipient_phone' => $prep->recipient_phone,
            'delivery_address' => $prep->delivery_address,
            'delivery_notes' => $prep->delivery_notes,
            'is_gift' => (bool) $prep->is_gift,
            'occasion_type' => $prep->occasion_type,
            'occasion_text' => $prep->occasion_text,
            'card_message' => $prep->card_message,
            'sender_name' => $prep->sender_name,
            'hide_sender' => (bool) $prep->hide_sender,
            'document_snapshot' => $invoice->getRawOriginal('document_snapshot'),
        ]);

        $subject->setRelation('customer', $customer);
        $subject->setRelation('items', $invoice->items->map(function ($line) {
            $item = new OrderItem;
            $item->forceFill([
                'name' => (string) $line->description,
                // والكميّةُ كما في الفاتورة: «١.٥ متر» لا «٢»
                'quantity' => (float) $line->quantity,
                'note' => null,
                'custom_details' => null,
                'product_id' => null,
            ]);
            $item->setRelation('product', null);
            $item->setRelation('addons', collect());
            $item->setRelation('components', collect());

            return $item;
        })->values());

        return $subject;
    }

    /** الفاتورةُ مسدَّدةٌ الآن؟ — تُقرأ للبطاقة والورقة الداخليّة، ولا تُفعّل شيئًا */
    public static function paid(CustomerInvoice $invoice): bool
    {
        return $invoice->status === CustomerInvoice::ISSUED && $invoice->outstanding() <= 0.0005;
    }

    /** تسميتا سند التسليم لفاتورة — لا «رقم الطلب» ولا «تاريخ الطلب» */
    public static function deliveryLabels(): array
    {
        return ['number_label' => __('رقم الفاتورة'), 'date_label' => __('تاريخ الفاتورة')];
    }

    /** نوعُ التسليم كما يُقرأ — «توصيل» أو «استلام من المحل» */
    public static function fulfilmentLabel(?string $type): ?string
    {
        return match ((string) $type) {
            FlowerOrder::DELIVERY => __('توصيل'),
            FlowerOrder::PICKUP => __('استلام من المحل'),
            default => null,
        };
    }

    /* ═══════════════════════════ الورقةُ الداخليّة ═══════════════════════════ */

    /**
     * «فاتورة عميل — نسخة داخلية» — ما يُطبع، مبنيًّا في الخادم.
     *
     * ورقةُ الطاولة لا ورقةُ العميل: فيها المالُ كلُّه (السعرُ والخصمُ والضريبةُ
     * والإجماليُّ وحالُ السداد) وملاحظاتُ التجهيز واسمُ المُهدي ولو أخفاه عن
     * المستلِم — فمن يجهّز يكتب البطاقة. ولا تُقيَّد ولا تُرقَّم: رقمُها رقمُ
     * الفاتورة الأصليّة، والفاتورةُ A4 هي الورقةُ الماليّة كما كانت.
     *
     * @return array<string, mixed>
     */
    public static function internalPaper(CustomerInvoicePreparation $prep, CustomerInvoice $invoice): array
    {
        $bid = (int) $invoice->business_id;
        $snapshot = Document\Snapshot::of($invoice, $bid);
        $currency = Document\Snapshot::currency($snapshot) ?: Money::of($bid);
        $money = fn ($v) => Money::format((float) $v, $currency);
        $customer = Customer::withTrashed()->find($invoice->customer_id);
        $text = fn ($v) => filled($v) ? trim((string) $v) : null;

        $payments = $invoice->allocations()->with('payment')->get()
            ->filter(fn ($a) => $a->payment && $a->payment->cancelled_at === null);

        return [
            'number' => (string) $invoice->number,
            'date' => optional($invoice->issued_at)->format('Y-m-d'),
            'branch' => $prep->branch_id ? Branch::whereKey($prep->branch_id)->value('name') : null,
            'customer' => ['name' => $text($invoice->customer_name ?: $customer?->name), 'phone' => $text($customer?->phone)],
            'fulfilment' => self::fulfilmentLabel($prep->fulfillment_type),
            'scheduled_for' => optional($prep->scheduled_for)->format('Y-m-d H:i'),
            'recipient' => [
                'name' => $text($prep->recipient_name),
                'phone' => $text($prep->recipient_phone),
                'address' => $text($prep->delivery_address),
                'notes' => $text($prep->delivery_notes),
            ],
            'items' => $invoice->items->map(fn ($line) => [
                'name' => (string) $line->description,
                'qty' => DocumentPaper::qty($line->quantity),
                'price' => $money($line->unit_price),
                'discount' => (float) $line->discount > 0 ? $money($line->discount) : null,
                'total' => $money($line->line_total),
            ])->values()->all(),
            'pieces' => DocumentPaper::qty($invoice->items->sum('quantity')),
            'totals' => [
                'subtotal' => $money($invoice->subtotal),
                'discount' => (float) $invoice->discount_total > 0 ? $money($invoice->discount_total) : null,
                'tax' => (float) $invoice->tax_total > 0 ? $money($invoice->tax_total) : null,
                'total' => $money($invoice->total),
            ],
            'payment' => [
                'state' => __($invoice->paymentState()),
                'paid' => $money($invoice->paidTotal()),
                'outstanding' => $invoice->outstanding() > 0.0005 ? $money($invoice->outstanding()) : null,
                // الوسيلةُ لا رقمُ العمليّة: ورقةُ الطاولة لا تحمل معرّفات Paymob
                'methods' => $payments->map(fn ($a) => (string) $a->payment->method)->unique()->values()->all(),
            ],
            'notes' => $text($invoice->notes),
            'invoice_notes' => $text($invoice->internal_notes),
            'prep_notes' => $text($prep->internal_notes),
            'gift' => ($prep->is_gift || filled($prep->occasion_type) || filled($prep->card_message)) ? [
                'occasion' => $prep->occasion_type === 'other' && filled($prep->occasion_text)
                    ? __('أخرى').': '.$prep->occasion_text
                    : FlowerOrder::occasionLabel($prep->occasion_type),
                'message' => $text($prep->card_message),
                'sender' => $text($prep->sender_name),
                'hidden' => (bool) $prep->hide_sender,
            ] : null,
        ];
    }

    /** الورقةُ الداخليّة شريطًا حراريًّا — بعرض طابعة الصندوق (`PdfController::receiptWidth`) */
    public static function internalPdf(CustomerInvoicePreparation $prep, CustomerInvoice $invoice)
    {
        $bid = (int) $invoice->business_id;
        $width = PdfController::receiptWidth($bid);

        Activity::log('report', 'طبع «فاتورة عميل — نسخة داخلية» للفاتورة '.$invoice->number, [
            'subject_id' => $prep->id, 'subject_type' => 'customer_invoice_preparation', 'business_id' => $bid,
        ]);

        return Pdf::strip(
            DocumentRenderer::customerInvoiceStrip($bid, self::internalPaper($prep, $invoice), $width),
            'customer-invoice-internal-'.$invoice->number,
            $width,
        );
    }

    /**
     * سندُ التسليم شريطًا حراريًّا — يدويًّا وحده، ولا يُطبع بعد السداد أبدًا.
     *
     * حمولتُه من `DeliveryPaper::forRecipient` على موضوعٍ في الذاكرة
     * (`deliverySubject`): لا سعرَ ولا دفعَ ولا ملاحظاتٍ داخليّة ولا هويّةَ
     * من أخفى اسمه. والقالبُ قالبُ سند الموقع نفسُه (#127).
     */
    public static function deliveryPdf(CustomerInvoicePreparation $prep, CustomerInvoice $invoice)
    {
        $bid = (int) $invoice->business_id;
        $width = PdfController::receiptWidth($bid);

        Activity::log('report', 'طبع سند تسليم الفاتورة '.$invoice->number, [
            'subject_id' => $prep->id, 'subject_type' => 'customer_invoice_preparation', 'business_id' => $bid,
        ]);

        return Pdf::strip(
            DocumentRenderer::deliveryStrip($bid, self::deliverySubject($prep, $invoice), $width, self::deliveryLabels()),
            'delivery-'.$invoice->number,
            $width,
        );
    }

    /** سندُ التسليم نصًّا — للاختبار وللمعاينة */
    public static function deliveryHtml(CustomerInvoicePreparation $prep, CustomerInvoice $invoice, int $width): string
    {
        return DocumentRenderer::deliveryStrip((int) $invoice->business_id, self::deliverySubject($prep, $invoice), $width, self::deliveryLabels());
    }

    /** الورقةُ الداخليّة نصًّا — للاختبار */
    public static function internalHtml(CustomerInvoicePreparation $prep, CustomerInvoice $invoice, int $width): string
    {
        return DocumentRenderer::customerInvoiceStrip((int) $invoice->business_id, self::internalPaper($prep, $invoice), $width);
    }
}
