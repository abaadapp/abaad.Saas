<?php

namespace App\Support;

use App\Exceptions\SettlementRefused;
use App\Models\Boutique;
use App\Models\BoutiqueInvoiceLine;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueSettlement;
use App\Models\BoutiqueSheetHold;
use App\Models\Business;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تحصيلٌ أُلغي بعد صدور ورقة البوتيك: يوقَف سدادُ ما تأثّر وحده حتّى يُراجَع.
 *
 * ═══ ما لا يُمسّ ═══
 *
 * الورقةُ ومبلغُها ومصروفُها وقيدُ صدورها كما هي. ولا تُستحقّ الحصّةُ ثانيةً: بندُها محمولٌ
 * بورقته. وما هنا سجلٌّ بجانبها: صفٌّ لكلّ بندٍ متأثّر، يُضاف ويُغلق بسببه ولا يُحذف.
 *
 * ═══ ورقةٌ لم تُسدَّد ═══
 *
 * يُسدَّد صافيها ناقصًا المعلَّق (`payNow`)، والمعلَّقُ يبقى دَينًا في 2160. فإن سُدّدت دونه
 * (`withhold`) صار مستقلًّا: يُسدَّد من بابه (`pay`) بعد أن يُغلق تعليقُه.
 *
 * ═══ ورقةٌ سُدّدت قبل الإلغاء ═══
 *
 * لا قيدَ ولا سداد: يُعرض «سُدّد قبل تحصيله» حتّى يُراجَع (`review`) أو يُعاد تحصيلُه. وردُّ
 * القطعة بإشعارٍ مطالبةٌ على البوتيك كما كان (`BoutiqueReturns`).
 *
 * ═══ كيف يُغلق ═══
 *
 *   أُعيد التحصيل (تلقائيًّا)، أو حُسم ردُّ القطعة كلِّها (تلقائيًّا)، أو أُفرج عنه بقرارٍ
 *   مكتوب ممّن يملك السداد. وبعد القرار لا يُعلَّق ثانيةً حتّى يُحصَّل ثمّ يُلغى من جديد.
 */
final class BoutiqueHolds
{
    /** مصدرُ قيد سداد ما عُلّق بعد الإفراج عنه */
    public const SOURCE = 'سداد معلّق بوتيك';

    /** حُسم من إشعارات المرتجع: على البوتيك أو على المتجر */
    private const DECIDED = [BoutiqueReturn::REDUCED, BoutiqueReturn::CLAIM, BoutiqueReturn::RECOVERED, BoutiqueReturn::SHOP];

    /* ═══════════ ١. التحصيلُ تغيّر ═══════════ */

    /**
     * بنودُ فاتورةٍ حملتها أوراق: يُفتح تعليقُ ما أُلغي تحصيلُه ويُغلق ما أُعيد.
     *
     * يُنادى من `BoutiqueInvoiceDues::sync` داخل معاملته وتحت قفل المتجر — وسدادُ الورقة
     * يمسك القفلَ نفسَه أوّلًا (`ExpenseController::markPaid`)، فلا يقع بينهما شيء.
     */
    public static function sync(int $invoiceId, bool $collected, ?CustomerPayment $payment = null): void
    {
        $lines = BoutiqueInvoiceLine::where('customer_invoice_id', $invoiceId)
            ->whereNotNull('boutique_settlement_id')->orderBy('id')->get();

        foreach ($lines as $line) {
            $live = BoutiqueSheetHold::where('live_key', self::key($line))->lockForUpdate()->first();

            if ($live !== null) {
                self::settle($live, $collected);

                continue;
            }

            $last = BoutiqueSheetHold::where('boutique_invoice_line_id', $line->id)->latest('id')->first();

            if ($collected) {
                // حُسم بقرارٍ ثمّ قُبض: إلغاءٌ بعدها تعليقٌ جديد
                if ($last !== null && $last->recollected_at === null) {
                    $last->update(['recollected_at' => now()]);
                }

                continue;
            }

            if ($last === null || $last->recollected_at !== null) {
                self::open($line, $payment);
            }
        }
    }

    private static function key(BoutiqueInvoiceLine $line): string
    {
        return 'line:'.$line->id;
    }

    private static function open(BoutiqueInvoiceLine $line, ?CustomerPayment $payment): void
    {
        // ما بقي من البند في ورقته: ما لم يُردّ، وما رُدّ ولم يُحسم بعد (ما زال في صافيها)
        $amount = round((float) $line->net - (float) $line->returned_net + self::pending($line->id), 3);
        $sheet = BoutiqueSettlement::find($line->boutique_settlement_id);

        if ($amount <= 0 || $sheet === null) {
            return;
        }

        BoutiqueSheetHold::create([
            'business_id' => $line->business_id,
            'boutique_id' => $line->boutique_id,
            'boutique_settlement_id' => $sheet->id,
            'boutique_invoice_line_id' => $line->id,
            'customer_invoice_id' => $line->customer_invoice_id,
            'customer_payment_id' => $payment?->id,
            'amount' => $amount,
            'decided_base' => self::sumOf((int) $line->id, self::DECIDED),
            'why' => self::why($payment),
            'paid_before' => $sheet->expense_id !== null && Expense::withTrashed()->find($sheet->expense_id)?->isPaid() === true,
            'opened_at' => now(),
            'live_key' => self::key($line),
        ]);

        Activity::log('updated', 'علّق سداد جزءٍ من ورقة البوتيك '.$sheet->number.' — أُلغي تحصيل الفاتورة '.(string) CustomerInvoice::whereKey($line->customer_invoice_id)->value('number'), [
            'business_id' => $line->business_id, 'subject_id' => $sheet->id, 'subject_type' => 'boutique_settlement',
        ]);
    }

    private static function why(?CustomerPayment $payment): string
    {
        // والشيكُ لا يرتدّ بعد صرفه (`Cheques::bounce`)، وقبله لا تكتمل به فاتورة: الإلغاءُ وحده يفتح تعليقًا
        return mb_substr($payment?->cancelled_at !== null
            ? __('أُلغي سند القبض :number', ['number' => $payment->number])
            : __('لم يعد تحصيل الفاتورة مكتملًا'), 0, 255);
    }

    /* ═══════════ ٢. كم هو معلَّق الآن؟ ═══════════ */

    /** @param  list<string>  $statuses */
    private static function sumOf(int $lineId, array $statuses): float
    {
        return round((float) BoutiqueReturn::where('boutique_invoice_line_id', $lineId)->whereIn('status', $statuses)->sum('amount'), 3);
    }

    /** إشعاراتُ بندٍ تنتظر القطعةَ أو المحاسب — حصّتُها ما زالت في صافي ورقتها */
    private static function pending(int $lineId): float
    {
        return self::sumOf($lineId, [BoutiqueReturn::AWAITING, BoutiqueReturn::REVIEW]);
    }

    /** ما لم يُحسم من المتأثّر: مبلغُه ناقصًا ما حُسم من مرتجعه منذ فُتح */
    private static function left(BoutiqueSheetHold $hold): float
    {
        $decided = self::sumOf((int) $hold->boutique_invoice_line_id, self::DECIDED) - (float) $hold->decided_base;

        return max(0.0, round((float) $hold->amount - $decided, 3));
    }

    /** الموقوفُ سدادُه الآن من هذا التعليق — وفاتورةٌ أُعيد تحصيلُها لا يوقَف منها إلّا مرتجعٌ لم يُحسم */
    private static function held(BoutiqueSheetHold $hold): float
    {
        if ($hold->closed_at !== null || $hold->paid_before) {
            return 0.0;
        }

        $left = self::left($hold);

        return BoutiqueInvoiceDues::collected((int) $hold->customer_invoice_id) ? min($left, self::pending((int) $hold->boutique_invoice_line_id)) : $left;
    }

    /** يُغلق ما لم يبق فيه شيءٌ يُراجَع */
    private static function settle(BoutiqueSheetHold $hold, bool $collected): void
    {
        $as = match (true) {
            self::left($hold) <= 0.0005 => BoutiqueSheetHold::RETURNED,
            $collected && self::pending((int) $hold->boutique_invoice_line_id) <= 0.0005 => BoutiqueSheetHold::RECOLLECTED,
            default => null,
        };

        if ($as !== null) {
            self::close($hold, $as, null, $collected);
        }
    }

    private static function close(BoutiqueSheetHold $hold, string $as, ?string $note, bool $collected): void
    {
        $hold->update([
            'closed_at' => now(), 'closed_as' => $as, 'closed_by' => auth()->id(), 'note' => $note,
            'recollected_at' => $collected ? now() : null, 'live_key' => null,
        ]);
    }

    /** حُسم مرتجعُ بند: ما اكتمل حسمُه يُغلق تعليقُه — من `BoutiqueReturns` */
    public static function decided(?int $lineId): void
    {
        $hold = $lineId === null ? null : BoutiqueSheetHold::where('live_key', 'line:'.$lineId)->lockForUpdate()->first();

        if ($hold !== null) {
            self::settle($hold, BoutiqueInvoiceDues::collected((int) $hold->customer_invoice_id));
        }
    }

    /* ═══════════ ٣. سدادُ الورقة ═══════════ */

    /** الموقوفُ من ورقةٍ لم تُسدَّد */
    public static function heldOn(?int $sheetId): float
    {
        return $sheetId === null ? 0.0 : round(BoutiqueSheetHold::where('boutique_settlement_id', $sheetId)
            ->whereNotNull('live_key')->where('paid_before', false)->whereNull('withheld_at')->get()
            ->sum(fn (BoutiqueSheetHold $h) => self::held($h)), 3);
    }

    /** ما يُسدَّد من الورقة الآن: صافيها بعد ما خُفّض، ناقصًا الموقوف */
    public static function payNow(Expense $expense): float
    {
        return max(0.0, round(BoutiqueReturns::payable($expense) - self::heldOn(BoutiqueReturns::sheetOf($expense)?->id), 3));
    }

    /** يُردّ سدادُ ورقةٍ كلُّ ما بقي منها معلَّق — بسببه، ولا تُوسم مسدَّدةً بلا مال */
    public static function assertSomethingToPay(Expense $expense): void
    {
        $sheet = BoutiqueReturns::sheetOf($expense);

        if ($sheet !== null && self::heldOn($sheet->id) > 0 && self::payNow($expense) <= 0) {
            throw new SettlementRefused(__('كل ما بقي في الورقة :number معلّق: أُلغي تحصيل فاتورته. حصّله من جديد، أو أفرج عنه من شاشة البوتيك بسببٍ مكتوب.', ['number' => $sheet->number]));
        }
    }

    /** سُدّدت الورقةُ دون المعلَّق: يبقى دَينًا في 2160 مستقلًّا بصفّه */
    public static function withhold(Expense $expense): void
    {
        $sheet = BoutiqueReturns::sheetOf($expense);

        if ($sheet === null) {
            return;
        }

        BoutiqueSheetHold::where('boutique_settlement_id', $sheet->id)->whereNotNull('live_key')
            ->where('paid_before', false)->whereNull('withheld_at')->lockForUpdate()->get()
            ->each(function (BoutiqueSheetHold $h) {
                if (($held = self::held($h)) > 0) {
                    $h->update(['withheld_at' => now(), 'withheld_amount' => $held, 'reduced_base' => self::sumOf((int) $h->boutique_invoice_line_id, [BoutiqueReturn::REDUCED])]);
                }
            });
    }

    /** ما بقي دَينًا ممّا سُدّدت الورقةُ دونه: ناقصًا ما أسقطه إشعارٌ بعدها وما سُدّد منه */
    public static function owed(BoutiqueSheetHold $hold): float
    {
        if ($hold->withheld_at === null || $hold->paid_at !== null) {
            return 0.0;
        }

        $reduced = self::sumOf((int) $hold->boutique_invoice_line_id, [BoutiqueReturn::REDUCED]) - (float) $hold->reduced_base;

        return max(0.0, round((float) $hold->withheld_amount - $reduced, 3));
    }

    /** ما سُدّدت أوراقٌ دونه ولم يُسدَّد بعد — `[رقمُ الورقة => المبلغ]` */
    public static function owedOn(Collection|array $sheetIds): array
    {
        return BoutiqueSheetHold::whereIn('boutique_settlement_id', $sheetIds)->whereNotNull('withheld_at')->whereNull('paid_at')->get()
            ->groupBy('boutique_settlement_id')->map(fn ($hs) => round($hs->sum(fn ($h) => self::owed($h)), 3))->filter(fn ($v) => $v > 0)->all();
    }

    /** أسُدّدت ورقةُ هذا البند دونه، وفيه ما يكفي هذا الإشعار؟ — فيُنقص من 2160 لا مطالبةً على البوتيك */
    public static function covers(?int $lineId, float $amount): bool
    {
        $hold = $lineId === null ? null : BoutiqueSheetHold::where('boutique_invoice_line_id', $lineId)
            ->whereNotNull('withheld_at')->whereNull('paid_at')->latest('id')->first();

        return $hold !== null && self::owed($hold) + 0.0005 >= $amount;
    }

    /* ═══════════ ٤. القرار ═══════════ */

    /** يُفرج عن معلَّقٍ بقرارٍ مكتوب: يُسدَّد ولو لم يُقبض ثمنُه */
    public static function release(BoutiqueSheetHold $hold, string $why): void
    {
        self::decide($hold, fn (BoutiqueSheetHold $h) => ! $h->paid_before, BoutiqueSheetHold::RELEASED, $why, 'أفرج عن معلّق في ورقة البوتيك ');
    }

    /** «سُدّد قبل تحصيله» رُوجع — لا قيدَ ولا سداد، وما يلزم من تسويةٍ ماليّةٍ في بابه */
    public static function review(BoutiqueSheetHold $hold, string $why): void
    {
        self::decide($hold, fn (BoutiqueSheetHold $h) => $h->paid_before, BoutiqueSheetHold::REVIEWED, $why, 'راجع حصةً سُدّدت قبل تحصيلها في ورقة البوتيك ');
    }

    private static function decide(BoutiqueSheetHold $hold, \Closure $fits, string $as, string $why, string $log): void
    {
        DB::transaction(function () use ($hold, $fits, $as, $why, $log) {
            Business::whereKey($hold->business_id)->lockForUpdate()->first();
            $h = BoutiqueSheetHold::whereKey($hold->id)->lockForUpdate()->firstOrFail();

            if ($h->closed_at !== null || ! $fits($h)) {
                throw new SettlementRefused(__('حُسم هذا المعلّق قبل لحظة — افتح الشاشة من جديد.'));
            }

            self::close($h, $as, mb_substr($why, 0, 255), BoutiqueInvoiceDues::collected((int) $h->customer_invoice_id));

            Activity::log('updated', $log.(string) $h->settlement?->number.' — '.$why, [
                'business_id' => $h->business_id, 'subject_id' => $h->boutique_settlement_id, 'subject_type' => 'boutique_settlement',
            ]);
        });
    }

    /**
     * يُسدَّد ما سُدّدت الورقةُ دونه بعد أن أُغلق تعليقُه: مدين 2160 / دائن الصندوق أو البنك.
     *
     * ولا مصروفَ ثانٍ: كُتب مع الورقة يومَ صدورها. ومرّةً واحدة تحت قفل صفّه.
     */
    public static function pay(BoutiqueSheetHold $hold, string $method): float
    {
        return DB::transaction(function () use ($hold, $method) {
            Business::whereKey($hold->business_id)->lockForUpdate()->first();
            $h = BoutiqueSheetHold::whereKey($hold->id)->lockForUpdate()->firstOrFail();
            $owed = self::owed($h);

            if ($h->closed_at === null) {
                throw new SettlementRefused(__('ما زال معلّقًا: حصّل فاتورته، أو أفرج عنه بسببٍ مكتوب أوّلًا.'));
            }
            if ($owed <= 0) {
                throw new SettlementRefused(__('لا شيء يُسدَّد من هذا المعلّق.'));
            }

            $sheet = BoutiqueSettlement::findOrFail($h->boutique_settlement_id);
            $expense = Expense::withTrashed()->findOrFail($sheet->expense_id);
            $at = now();

            $transaction = Transaction::create([
                'business_id' => $h->business_id,
                'branch_id' => ExpenseScope::journalBranch($expense),
                'reference' => Transaction::nextReference((int) $h->business_id),
                'description' => Boutiques::EXPENSE_TYPE.' — '.__('معلّق من الورقة :number', ['number' => $sheet->number]),
                'method' => $method,
                'type' => 'مصروف',
                'amount' => $owed,
                'employee_name' => auth()->user()?->name,
                'occurred_at' => $at,
            ]);

            Ledger::post(
                (int) $h->business_id,
                BooksPeriod::fit(__('سداد معلّق من تسوية بوتيك: ').$sheet->number, (string) $h->line?->name),
                [
                    ['account' => 'boutique_payable', 'debit' => $owed, 'memo' => $h->line?->name],
                    ['account' => Books::payingAccount($method), 'credit' => $owed],
                ],
                $at,
                self::SOURCE,
                ExpenseScope::journalBranch($expense),
                auth()->id(),
                $h,
            );

            $h->update(['paid_at' => $at, 'paid_amount' => $owed, 'paid_method' => $method, 'transaction_id' => $transaction->id]);

            Activity::log('updated', 'سدّد معلّقًا من ورقة البوتيك '.$sheet->number, [
                'business_id' => $h->business_id, 'subject_id' => $sheet->id, 'subject_type' => 'boutique_settlement',
            ]);

            return $owed;
        });
    }

    /* ═══════════ للقراءة ═══════════ */

    /**
     * ما بقي دَينًا من أوراقٍ سُدّدت دونه — لشاشة «المبالغ المستحقة» ومجموعها.
     *
     * @return list<array{id: int, sheet: ?string, boutique: ?string, name: ?string, amount: float, held: bool}>
     */
    public static function dues(int $businessId): array
    {
        return BoutiqueSheetHold::where('business_id', $businessId)->whereNotNull('withheld_at')->whereNull('paid_at')
            ->with(['line:id,name', 'settlement:id,number'])->orderBy('id')->get()
            ->map(fn (BoutiqueSheetHold $h) => [
                'id' => $h->id, 'sheet' => $h->settlement?->number, 'boutique' => Boutique::find($h->boutique_id)?->label(),
                'name' => $h->line?->name, 'amount' => self::owed($h), 'held' => $h->closed_at === null,
            ])->filter(fn (array $r) => $r['amount'] > 0)->values()->all();
    }

    /**
     * ما عُلّق أو سُدّد قبل تحصيله لبوتيك — المفتوحُ وما يُسدَّد أوّلًا، ثمّ الأحدث.
     *
     * @return list<array<string, mixed>>
     */
    public static function of(int $businessId, int $boutiqueId): array
    {
        return BoutiqueSheetHold::where('business_id', $businessId)->where('boutique_id', $boutiqueId)
            ->with(['line:id,name', 'settlement:id,number', 'invoice:id,number'])
            ->orderByRaw('CASE WHEN live_key IS NULL THEN 1 ELSE 0 END')->latest('id')->limit(100)->get()
            ->map(function (BoutiqueSheetHold $h) {
                $owed = self::owed($h);

                return [
                    'id' => $h->id,
                    'sheet' => $h->settlement?->number,
                    'invoice_id' => (int) $h->customer_invoice_id,
                    'invoice' => $h->invoice?->number,
                    'name' => $h->line?->name,
                    'amount' => round((float) $h->amount, 3),
                    'held' => $h->withheld_at !== null ? ($h->closed_at === null ? $owed : 0.0) : self::held($h),
                    'why' => $h->why,
                    'at' => $h->opened_at?->format('Y-m-d H:i'),
                    'state' => match (true) {
                        $h->paid_at !== null => 'paid',
                        $h->closed_at === null && $h->paid_before => 'paid_before',
                        $h->closed_at === null && $h->withheld_at !== null => 'withheld',
                        $h->closed_at === null => 'held',
                        $owed > 0 => 'payable',
                        default => (string) $h->closed_as,
                    },
                    'owed' => $owed,
                    'closed_as' => $h->closed_as,
                    'closed_at' => $h->closed_at?->format('Y-m-d H:i'),
                    'note' => $h->note,
                    'paid_amount' => $h->paid_amount === null ? null : round((float) $h->paid_amount, 3),
                    'paid_at' => $h->paid_at?->format('Y-m-d'),
                ];
            })->all();
    }
}
