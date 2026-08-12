<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * وردية الصندوق: فتحٌ برصيد ابتدائي، وبيعٌ منسوب إليها، وإقفالٌ بعدٍّ فعليّ.
 *
 * الغرض واحد: أن يُطابَق ما في الدرج بما يجب أن يكون فيه، اليوم لا بعد شهر.
 * والمبيعات وحدها لا تفعل ذلك — بيعةٌ بالبطاقة لا تضع في الدرج ريالًا، فلا
 * يُحسب من النقد إلا ما قُبض نقدًا.
 *
 * الوردية للفرع لا للموظف: الدرج واحد في المحل يتناوب عليه من يقف على
 * الصندوق، فربطُها بالحساب المسجَّل كان سيفتح ورديةً لكل من يسجّل دخوله على
 * الدرج نفسه.
 */
class Shifts
{
    /** ما يُعدّ نقدًا في الدرج. البطاقة والتحويل لا يمرّان بالصندوق */
    public const CASH = 'نقدي';

    /** أطول ما تبقى وردية مفتوحة قبل أن تُعدّ منسيّة — ساعةً لا يومًا تقويميًّا */
    public const DEFAULT_MAX_HOURS = 18;

    public static function maxHours(?int $businessId = null): int
    {
        $value = \App\Models\Setting::where('business_id', $businessId ?? Demo::bid())
            ->where('key', 'shift_max_hours')->value('value');

        // صفرٌ أو سالبٌ يعني إقفال كل وردية لحظة فتحها — يُردّ إلى الافتراضي
        $hours = (int) $value;

        return $hours > 0 ? $hours : self::DEFAULT_MAX_HOURS;
    }

    /**
     * هل يُمنع البيع بلا وردية مفتوحة؟ مطفأٌ افتراضيًّا.
     *
     * منعُ البيع أخطر تصرّف في نقطة بيع: خللٌ واحد — كاشير لم يفتح، أو
     * وردية على فرعٍ آخر — يوقف المحل عن العمل ولا يجد صاحبه ما يفعله.
     * فالوردية تعمل وتُحسب وتُقفل من أوّل يوم، ولا تمنع شيئًا حتى يقرّر
     * صاحب النشاط ذلك بنفسه.
     */
    public static function blocksSelling(): bool
    {
        $value = \App\Models\Setting::where('business_id', Demo::bid())
            ->where('key', 'require_open_shift')->value('value');

        return (string) $value === '1';
    }

    /** الوردية المفتوحة لفرع المستخدم الحالي، إن وُجدت */
    public static function current(): ?Shift
    {
        return Shift::where('business_id', Demo::bid())
            ->where('branch_id', Demo::activeBranchId())
            ->where('status', Shift::OPEN)
            ->latest('opened_at')
            ->first();
    }

    public static function isOpen(): bool
    {
        return self::current() !== null;
    }

    /**
     * فتح وردية.
     *
     * الحارس داخل معاملة مع قفلٍ على الصفوف: جهازان يفتحان في اللحظة نفسها
     * كانا سيُنشئان ورديتين للدرج الواحد، فتنقسم مبيعات اليوم بينهما ولا
     * يطابق أيٌّ منهما ما في الدرج.
     */
    public static function open(float $float, ?int $userId = null): Shift
    {
        $bid = Demo::bid();
        // activeBranchId لا currentBranchId: «كل الفروع» عرضٌ لا موضع بيع،
        // ووردية بلا فرع لا تُطابَق بدرجٍ بعينه
        $branch = Demo::activeBranchId();

        return DB::transaction(function () use ($bid, $branch, $float, $userId) {
            $existing = Shift::where('business_id', $bid)
                ->where('branch_id', $branch)
                ->where('status', Shift::OPEN)
                ->lockForUpdate()
                ->first();

            /*
             * الوردية المنسيّة لا تُورَّث.
             *
             * كان الفتح يُرجع القائمة أيًّا كان عمرها، فكاشير الصباح يبيع في
             * درج الأمس ولا يعلم: مبيعات يومين في وردية واحدة، ودرجٌ لا
             * يطابق شيئًا. فتُقفل بلا عدّ — وفرقُها مجهول يُنسب إلى ليلة
             * نسيانها — ثمّ تُفتح واحدةٌ نظيفة لهذا اليوم.
             */
            if ($existing && $existing->isStale(self::maxHours($bid))) {
                self::closeWithoutCount($existing, Shift::BY_SYSTEM);
                $existing = null;
            }

            if ($existing) {
                return $existing;
            }

            $user = $userId ? \App\Models\User::find($userId) : auth()->user();

            return Shift::create([
                'business_id' => $bid,
                'branch_id' => $branch,
                'user_id' => $user?->id,
                'employee_name' => PosCashier::name(),
                // الدرج الذي فُتحت عليه — من الخادم لا من الطلب
                'pos_device_id' => \App\Support\PosTerminal::current()?->id,
                'opened_at' => now(),
                'opening_balance' => max(0, $float),
                'status' => Shift::OPEN,
            ]);
        });
    }

    /** مبيعات الوردية مجمَّعةً حسب وسيلة الدفع */
    public static function totals(Shift $shift): array
    {
        $rows = Order::where('shift_id', $shift->id)
            ->where('is_held', false)
            ->selectRaw('payment_method, COUNT(*) as n, SUM(total) as sum')
            ->groupBy('payment_method')
            ->get();

        $byMethod = $rows->mapWithKeys(fn ($r) => [
            $r->payment_method ?: self::CASH => (float) $r->sum,
        ])->all();

        return [
            'byMethod' => $byMethod,
            'count' => (int) $rows->sum('n'),
            'cash' => (float) ($byMethod[self::CASH] ?? 0),
            'sales' => (float) $rows->sum('sum'),
        ];
    }

    /** مجموع السحب والإيداع في الوردية */
    public static function movements(Shift $shift): array
    {
        $rows = \App\Models\ShiftMovement::where('shift_id', $shift->id)
            ->selectRaw('type, SUM(amount) as sum')->groupBy('type')->pluck('sum', 'type');

        $in = (float) ($rows[\App\Models\ShiftMovement::IN] ?? 0);
        $out = (float) ($rows[\App\Models\ShiftMovement::OUT] ?? 0);

        return ['in' => $in, 'out' => $out, 'net' => $in - $out];
    }

    /** تسجيل سحبٍ من الدرج أو إيداعٍ فيه */
    public static function move(Shift $shift, string $type, float $amount, string $reason): \App\Models\ShiftMovement
    {
        $movement = \App\Models\ShiftMovement::create([
            'business_id' => $shift->business_id,
            'shift_id' => $shift->id,
            'user_id' => auth()->id(),
            'employee_name' => PosCashier::name(),
            'type' => $type,
            'amount' => round(abs($amount), 3),
            'reason' => $reason,
        ]);

        Activity::log('shift', ($type === \App\Models\ShiftMovement::OUT ? 'سحب من الدرج: ' : 'إيداع في الدرج: ')
            . number_format($movement->amount, 3) . ' — ' . $reason, ['subject_id' => $shift->id]);

        return $movement;
    }

    /**
     * ما يجب أن يكون في الدرج نقدًا الآن.
     *
     * يُحسب حيًّا ما دامت الوردية مفتوحة، ويُجمَّد في الجدول لحظة الإقفال:
     * بيعةٌ تُعدَّل بعد شهر كانت ستغيّر «الفرق» بأثر رجعي، فيصير سجلّ
     * الوردية يكذب على قارئه بعد أن أُغلق الباب عليه.
     */
    public static function expectedCash(Shift $shift): float
    {
        if (! $shift->isOpen()) {
            return (float) $shift->expected_balance;
        }

        return round((float) $shift->opening_balance + self::totals($shift)['cash'] + self::movements($shift)['net'], 3);
    }

    /**
     * إقفالٌ بلا عدّ — تلقائيّ أو بيد صاحب النشاط.
     *
     * يُثبَّت المتوقّع كما يُثبَّت في الإقفال العاديّ، **ويبقى المعدود والفرق
     * فارغَين**: لا أحد فتح الدرج فعدّه، وكتابة صفرٍ في خانة الفرق تعني
     * «طابق» — فتُبرّئ نقصًا لم يُعلم به أحد، وتفسد أيّ متوسّطٍ يُحسب لاحقًا.
     */
    public static function closeWithoutCount(Shift $shift, string $kind, ?string $note = null): Shift
    {
        $totals = self::totals($shift);
        $moves = self::movements($shift);

        $shift->update([
            'closed_by' => auth()->id(),
            'closed_at' => now(),
            'cash_sales' => $totals['cash'],
            'card_sales' => $totals['sales'] - $totals['cash'],
            'expenses' => $moves['out'],
            'returns' => $moves['in'],
            'expected_balance' => round((float) $shift->opening_balance + $totals['cash'] + $moves['net'], 3),
            'actual_balance' => null,
            'difference' => null,
            'note' => $note,
            'status' => Shift::CLOSED,
            'closed_kind' => $kind,
        ]);

        Activity::log('shift', $kind === Shift::BY_SYSTEM
            ? 'أُقفلت وردية منسيّة تلقائيًّا بلا عدّ'
            : 'أقفل وردية إداريًّا بلا عدّ', ['subject_id' => $shift->id]);

        return $shift->fresh();
    }

    /** إقفال: يُثبَّت المحسوب، ويُسجَّل المعدود، والفرق بينهما */
    public static function close(Shift $shift, float $counted, ?string $note = null): Shift
    {
        $totals = self::totals($shift);
        $moves = self::movements($shift);
        $expected = round((float) $shift->opening_balance + $totals['cash'] + $moves['net'], 3);

        $shift->update([
            'closed_by' => auth()->id(),
            'closed_at' => now(),
            'cash_sales' => $totals['cash'],
            'card_sales' => $totals['sales'] - $totals['cash'],
            // العمودان موجودان في الجدول منذ أوّل هجرة ولم يُكتب فيهما شيء
            'expenses' => $moves['out'],
            'returns' => $moves['in'],
            'expected_balance' => $expected,
            'actual_balance' => $counted,
            'difference' => round($counted - $expected, 3),
            'note' => $note,
            'status' => Shift::CLOSED,
            'closed_kind' => Shift::BY_COUNT,
        ]);

        Activity::log('shift', 'أقفل الوردية — فرق: ' . number_format($shift->difference, 3), ['subject_id' => $shift->id]);

        return $shift->fresh();
    }
}
