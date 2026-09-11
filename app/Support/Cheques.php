<?php

namespace App\Support;

use App\Models\CustomerPayment;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * عمرُ الشيك — الموضعُ الوحيد الذي يعرفه.
 *
 * ═══ لماذا لا يكفي أن يكون «وسيلةَ دفع» ═══
 *
 * كلُّ وسائل التحصيل الأخرى تنتهي لحظةَ وقوعها: النقدُ في الدرج، والبطاقةُ
 * في البنك، والحوالةُ وصلت. والشيكُ **وعدٌ بمال**: ورقةٌ قد تُصرَف بعد
 * شهرين، وقد ترتدّ لعدم الرصيد أو لتوقيعٍ لا يطابق.
 *
 * فترحيلُه فورًا إلى البنك يجعل الدفتر يشهد بمالٍ لم يصل. ويقرأ التاجر
 * رصيدًا بنكيًّا مخترَعًا، وعميلًا سدّد ولم يسدّد — **ولا يكتشف حتّى يطابق
 * كشف الحساب، إن طابقه**.
 *
 * ═══ فالمسار ثلاثُ محطّات ═══
 *
 *   قبضٌ   → مدين «شيكات تحت التحصيل» / دائن «ذمم العملاء»
 *   صرفٌ   → مدين «البنك بعينه»        / دائن «شيكات تحت التحصيل»
 *   ارتدادٌ → عكسُ قيد القبض           (فتعود الذمّةُ على العميل)
 *
 * والذمّةُ في الحالين تقول الصدق: تحت التحصيل ليست على العميل — هي على
 * الورقة. وحين ترتدّ تعود إليه.
 *
 * ═══ وما لا تفعله هذه الطبقة ═══
 *
 * لا تُحصّل شيكًا من نفسها ولا تعتمد على تاريخ الاستحقاق. الاستحقاقُ **موعدٌ
 * متوقَّع** لا حدثٌ واقع: بنكٌ يتأخّر يومين، وشيكٌ يُقدَّم متأخّرًا. ومن
 * يُحصّل بالتاريخ وحده يكتب في الدفتر مالًا لم يصل — وهو العطبُ نفسه الذي
 * جاءت هذه الطبقة لتزيله، منقولًا إلى موضعٍ آخر.
 *
 * فالصرفُ والارتدادُ يُسجّلهما إنسانٌ رأى الحركة في كشف حسابه.
 */
final class Cheques
{
    public const METHOD = 'شيك';

    public const PENDING = 'تحت التحصيل';

    public const CLEARED = 'محصَّل';

    public const BOUNCED = 'مرتجع';

    public const STATUSES = [self::PENDING, self::CLEARED, self::BOUNCED];

    /** مفتاحُ الحساب الوسيط في الشجرة */
    public const ACCOUNT = 'cheques_receivable';

    public const SOURCE_CLEAR = 'تحصيل شيك';

    public const SOURCE_BOUNCE = 'ارتداد شيك';

    /** كم يومًا قبل الاستحقاق يُعدّ الشيك «قريبًا» */
    public const SOON_DAYS = 7;

    public static function isCheque(?string $method): bool
    {
        return trim((string) $method) === self::METHOD;
    }

    /**
     * حسابُ الشيكات في الشجرة.
     *
     * ولا يُستدرك هنا: `Ledger::account` تبني الناقصَ عند الغياب — وهو
     * الموضع الصحيح، فسبعةُ متحكّماتٍ تُرحّل ولا تسأل عن الشجرة، والإصلاحُ
     * في السطر الذي يقرأ الحساب لا في كلّ من يكتب.
     *
     * وكان هنا استدراكٌ ثانٍ، فلم تقتله طفرة: حذفتُه فبقي كلُّ شيءٍ أخضر —
     * لأنّ ما يحميه محميٌّ في مكانٍ آخر. وشيفرةٌ لا يُثبتها حارسٌ تُحذف،
     * ويحرس الحالَ `test_an_old_chart_gains_the_account_when_needed`.
     */
    public static function account(int $businessId): string
    {
        return self::ACCOUNT;
    }

    /* ═══════════════════ القراءة ═══════════════════ */

    /** استعلامُ شيكات المتجر — بحالٍ إن طُلبت */
    public static function query(int $businessId, ?string $status = null)
    {
        $q = CustomerPayment::where('business_id', $businessId)
            ->where('method', self::METHOD)
            ->whereNull('cancelled_at');

        return $status === null ? $q : $q->where('cheque_status', $status);
    }

    /**
     * خلاصةُ الشيكات — للشاشة وللتنبيه.
     *
     * و«متأخّر» يُقاس بنهاية يوم الاستحقاق لا ببدايته: من يستحقّ اليوم لا
     * يتأخّر اليوم. وهي القاعدةُ نفسها في `CustomerInvoice::scopeOverdue`.
     */
    public static function summary(int $businessId): array
    {
        $pending = self::query($businessId, self::PENDING)->get(['amount', 'cheque_due_at']);
        $today = now()->startOfDay();

        $overdue = $pending->filter(
            fn ($p) => $p->cheque_due_at !== null && Carbon::parse($p->cheque_due_at)->lt($today)
        );

        $soon = $pending->filter(
            fn ($p) => $p->cheque_due_at !== null
                && Carbon::parse($p->cheque_due_at)->gte($today)
                && Carbon::parse($p->cheque_due_at)->lte($today->copy()->addDays(self::SOON_DAYS))
        );

        return [
            'pending_count' => $pending->count(),
            'pending_total' => round((float) $pending->sum('amount'), 3),
            'overdue_count' => $overdue->count(),
            'overdue_total' => round((float) $overdue->sum('amount'), 3),
            'soon_count' => $soon->count(),
            'bounced_count' => self::query($businessId, self::BOUNCED)->count(),
        ];
    }

    /* ═══════════════════ الحركة ═══════════════════ */

    /**
     * صُرِف الشيك — فينتقل المال من الورقة إلى البنك.
     *
     * ولا يُقبل إلّا على شيكٍ تحت التحصيل: صرفُ ما صُرف يُرحّل القيد مرّتين
     * فيُضاعف رصيدَ البنك، وصرفُ ما ارتدّ يُدخل مالًا رجع.
     */
    public static function clear(
        CustomerPayment $payment,
        ?string $on = null,
        ?int $bankAccountId = null,
        ?int $userId = null,
    ): CustomerPayment {
        self::guard($payment, self::PENDING, __('لا يُحصَّل إلّا شيكٌ تحت التحصيل.'));

        $date = $on !== null && $on !== '' ? Carbon::parse($on) : now();

        return DB::transaction(function () use ($payment, $date, $bankAccountId, $userId) {
            /*
             * والحسابُ البنكيّ يُعاد اختيارُه هنا لا يُؤخذ من القبض.
             *
             * التاجر قد يودع الشيك في حسابٍ غير الذي كتبه يوم استلمه.
             * و`accountFor` تفحص أنّ الحساب من حسابات متجره — فلا يُسند
             * قيدٌ إلى ورقة متجرٍ آخر.
             */
            $account = $bankAccountId !== null
                ? CustomerPayments::accountFor((int) $payment->business_id, self::METHOD, $bankAccountId)
                : $payment->bank_account_id;

            $leaf = Bank::leaf((int) $payment->business_id, $account) ?? 'bank';

            Ledger::post(
                (int) $payment->business_id,
                __('تحصيل شيك ').$payment->number,
                [
                    ['account' => $leaf, 'debit' => round((float) $payment->amount, 3)],
                    ['account' => self::account((int) $payment->business_id), 'credit' => round((float) $payment->amount, 3)],
                ],
                $date,
                self::SOURCE_CLEAR,
                null,
                $userId,
                $payment,
            );

            $payment->forceFill([
                'cheque_status' => self::CLEARED,
                'cheque_settled_at' => $date,
                'bank_account_id' => $account,
            ])->save();

            Activity::log('updated', 'حصّل شيك '.$payment->number.' بمبلغ '.$payment->amount, [
                'subject_id' => $payment->id, 'subject_type' => 'customer_payment',
            ]);

            return $payment->fresh();
        });
    }

    /**
     * ارتدّ الشيك — فتعود الذمّةُ على العميل.
     *
     * والعكسُ بـ`Ledger::reverse` على قيد القبض نفسه، لا بقيدٍ مكتوبٍ بيد:
     * قيدٌ يُكتب هنا قد يخالف قيدَ القبض في الحساب أو المبلغ إن تغيّر أحدُهما
     * يومًا، فيبقى فرقٌ في الدفتر لا يُفسَّر.
     *
     * والسببُ يُطلب ولا يُترك فارغًا: «لماذا رجع؟» سؤالٌ يُسأل بعد شهرٍ حين
     * يُقرَّر أيُقبل من هذا العميل شيكٌ مرّةً أخرى.
     */
    public static function bounce(CustomerPayment $payment, string $reason, ?int $userId = null): CustomerPayment
    {
        self::guard($payment, self::PENDING, __('لا يرتدّ إلّا شيكٌ تحت التحصيل.'));

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException(__('اكتب سبب الارتداد.'));
        }

        return DB::transaction(function () use ($payment, $reason, $userId) {
            $entry = JournalEntry::where('business_id', $payment->business_id)
                ->where('sourceable_type', CustomerPayment::class)
                ->where('sourceable_id', $payment->id)
                ->where('source', CustomerPayments::SOURCE)
                ->latest('id')->first();

            if ($entry) {
                Ledger::reverse($entry, now(), $userId, __('ارتداد شيك ').$payment->number);
            }

            $payment->forceFill([
                'cheque_status' => self::BOUNCED,
                'cheque_settled_at' => now(),
                'cheque_note' => mb_substr($reason, 0, 200),
            ])->save();

            Activity::log('updated', 'شيك مرتجع '.$payment->number.' — '.$reason, [
                'subject_id' => $payment->id, 'subject_type' => 'customer_payment',
            ]);

            return $payment->fresh();
        });
    }

    /** لا يُحرَّك شيكٌ ملغًى ولا غيرُ شيك ولا حالٌ غيرُ المطلوبة */
    private static function guard(CustomerPayment $payment, string $expected, string $message): void
    {
        if (! self::isCheque($payment->method)) {
            throw new RuntimeException(__('هذا التحصيل ليس شيكًا.'));
        }

        if ($payment->cancelled_at !== null) {
            throw new RuntimeException(__('هذا التحصيل ملغًى.'));
        }

        if ((string) $payment->cheque_status !== $expected) {
            throw new RuntimeException($message);
        }
    }
}
