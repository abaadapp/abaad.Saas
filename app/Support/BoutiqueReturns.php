<?php

namespace App\Support;

use App\Exceptions\SettlementRefused;
use App\Models\Account;
use App\Models\Boutique;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueReturnRecovery;
use App\Models\BoutiqueSettlement;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * مرتجعُ قطعة بوتيكٍ بعد أن حملتها ورقةُ تسوية — إشعارٌ لا تعديل.
 *
 * ═══ لماذا ═══
 *
 * الورقةُ حصّةُ البوتيك ممّا بِيع، مجمَّدةً يومَ صدورها (`Boutiques::write`).
 * فإن أُلغيت بيعتُها بعد ذلك بقي للبوتيك مستحقٌّ عن بيعٍ لم يقع. ولا تُعدَّل
 * الورقةُ ولا قيدُها ولا يُكتب في فترةٍ مقفلة — فيُكتب إشعارٌ بحصّة القطعة
 * كما حملتها الورقة، مرتبطٌ بها وبالبند وبيوم البيع.
 *
 * ═══ ثلاثةُ أحوالٍ لا يُغني أحدُها عن غيره ═══
 *
 *   - **الزبون:** يُردّ له بالإلغاء أو بتصحيح الكمّيّة نفسِه (`order_edit_id`).
 *   - **القطعة:** عادت سليمةً فإلى الرفّ، أو تالفةً أو لم تعد فخارجَه، أو لم
 *     تُستلم بعد فخارجَه حتّى تُستلم (`receive`).
 *   - **حصّةُ البوتيك:** تُحسم بشرط اتّفاقيته الموثَّق (`Boutique::returns_terms`
 *     بمرجعه)، وبلا شرطٍ يحسمها تنتظر قرارًا محاسبيًّا (`review`) — لا يُفترض
 *     أنّ البوتيك يتحمّل.
 *
 * ═══ وأثرُ تحمُّل البوتيك ═══
 *
 *   - ورقةٌ لم تُسدَّد وفي مستحقّها متّسع: مدين 2160 / دائن حساب حصّتها —
 *     يُنقص الدَّينَ نفسَه، ويُسدَّد الصافي (`payable`). وورقةٌ قديمةٌ لا قيدَ
 *     لها في 2160 (تُقيَّد عند سدادها): يُسدَّد صافيها ويدخل الأثرُ يومَ السداد.
 *   - ورقةٌ سُدِّدت (أو استُنفد مستحقُّها بخصمٍ سابق): مدين 1370 / دائن حساب
 *     حصّتها — مطالبةٌ حقيقيّة تُستردّ بمستند (`receipt`) أو تُخصم من ورقةٍ
 *     لاحقة لم تُسدَّد (`offset`)، ولا تُخصم مرّتين.
 *
 * وكلُّ قيدٍ بيوم وقوعه في الفترة المفتوحة (`BooksPeriod::openOr`).
 */
final class BoutiqueReturns
{
    public const SOURCE = 'مرتجع بوتيك';

    /** ما تقوله الاتّفاقية: السليمُ على البوتيك */
    public const TERMS_SELLABLE = 'sellable';

    /** وكلُّ مرتجعٍ عليه — سليمًا أو تالفًا */
    public const TERMS_ALL = 'all';

    public const TERMS = [self::TERMS_SELLABLE, self::TERMS_ALL];

    /* ═══════════ من جهة الطلب ═══════════ */

    /** بنودُ البوتيك المنفردة في الطلب التي حملتها ورقة */
    public static function settledLines(Order $order): Collection
    {
        return OrderItem::where('order_id', $order->id)
            ->whereNotNull('boutique_id')->whereNotNull('boutique_settlement_id')
            ->orderBy('id')->get();
    }

    /**
     * إلغاءُ طلبٍ فيه بنودٌ حملتها ورقة — إشعارٌ لكلّ حالٍ من أحوال قطعها.
     *
     * يُنادى داخل معاملة الإلغاء بعد أن ردّ الإلغاءُ القطعَ إلى الرفّ: فما لم
     * يعد سليمًا يُرفع عنه ثانيةً، كما تفعل المكوّنات. و`$answers`:
     * `[رقمُ البند => ['sellable' => n, 'damaged' => n]]`، والباقي لم يُستلم.
     *
     * @param  array<int, array{sellable?: int, damaged?: int}>|null  $answers
     */
    public static function onCancel(Order $order, ?array $answers, string $reason, ?int $editId): void
    {
        $items = self::settledLines($order);
        if ($items->isEmpty()) {
            return;
        }

        self::lockSheets($items->pluck('boutique_settlement_id')->all());

        foreach ($items as $item) {
            $qty = (int) $item->quantity;
            $sellable = (int) ($answers[$item->id]['sellable'] ?? 0);
            $damaged = (int) ($answers[$item->id]['damaged'] ?? 0);

            if ($sellable < 0 || $damaged < 0 || $sellable + $damaged > $qty) {
                throw ValidationException::withMessages([
                    'lines' => __('«:name»: السليمُ والتالفُ أكثرُ من :qty قطعة في الطلب.', ['name' => $item->name, 'qty' => $qty]),
                ]);
            }

            $parts = array_filter([
                BoutiqueReturn::SELLABLE => $sellable,
                BoutiqueReturn::DAMAGED => $damaged,
                BoutiqueReturn::PENDING => $qty - $sellable - $damaged,
            ]);

            // ما لم يعد سليمًا يخرج من الرفّ الذي ردّه إليه الإلغاء
            self::offShelf($order, (int) $item->product_id, $qty - $sellable, __('مرتجع بوتيك لم يعد سليمًا'));

            $grossLeft = round((float) $item->total, 3);
            $left = count($parts);
            foreach ($parts as $condition => $n) {
                // والأخيرُ يأخذ ما بقي من إجماليّ البند — فلا يضيع فلسٌ بين الأجزاء
                $gross = --$left === 0 ? $grossLeft : round((float) $item->price * $n, 3);
                $grossLeft = round($grossLeft - $gross, 3);

                $note = self::write($order, self::snapshot($item), $n, $gross, BoutiqueReturn::CANCEL, $condition, $reason, $editId);
                self::settle($note);
            }
        }
    }

    /**
     * إنقاصُ كمّيّة بندٍ حملته ورقة في يوم بيعه — إشعارٌ بما نقص، والقطعةُ لم تُستلم بعد.
     *
     * `$line` لقطةُ البند قبل التصحيح (`snapshot`)، و`$gross` ما نقص من إجماليّه.
     * والتصحيحُ ردّ القطعَ إلى الرفّ: تُرفع حتّى يُقال ما حالُها.
     *
     * @param  array<string, mixed>  $line
     */
    public static function onDecrease(Order $order, array $line, int $qty, float $gross, string $reason, ?int $editId): void
    {
        self::lockSheets([$line['settlement_id']]);
        self::offShelf($order, (int) $line['product_id'], $qty, __('مرتجع بوتيك لم يُستلم بعد'));

        $note = self::write($order, $line, $qty, $gross, BoutiqueReturn::QUANTITY, BoutiqueReturn::PENDING, $reason, $editId);
        self::settle($note);
    }

    /** لقطةُ بندٍ يُكتب عنه إشعار — قبل أن يتغيّر أو يُحذف */
    public static function snapshot(OrderItem $item): array
    {
        return [
            'item_id' => (int) $item->id,
            'settlement_id' => (int) $item->boutique_settlement_id,
            'boutique_id' => (int) $item->boutique_id,
            'product_id' => $item->product_id ? (int) $item->product_id : null,
            'name' => (string) $item->name,
            'variant_name' => $item->variant_name,
            'rate' => round((float) ($item->boutique_rate ?? 0), 2),
            'unit_price' => round((float) $item->price, 3),
        ];
    }

    /**
     * قطعٌ لم تُستلم استُلمت: كم منها سليمٌ وكم تالفٌ أو لن يعود.
     *
     * السليمُ يعود إلى الرفّ الآن، ثمّ تُحسم حصّةُ كلّ جزءٍ بشرط الاتّفاقية.
     * وما لم يُذكر يبقى منتظرًا في الإشعار نفسِه.
     */
    public static function receive(BoutiqueReturn $note, int $sellable, int $damaged, string $reason): void
    {
        DB::transaction(function () use ($note, $sellable, $damaged, $reason) {
            $locked = BoutiqueReturn::whereKey($note->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BoutiqueReturn::AWAITING) {
                throw new SettlementRefused(__('حالُ هذه القطع سُجّل قبل لحظة — افتح الطلب من جديد.'));
            }

            $qty = (int) $locked->quantity;
            if ($sellable < 0 || $damaged < 0 || $sellable + $damaged === 0 || $sellable + $damaged > $qty) {
                throw ValidationException::withMessages([
                    'pieces' => __('«:name»: السليمُ والتالفُ بين قطعةٍ و:qty.', ['name' => $locked->name, 'qty' => $qty]),
                ]);
            }

            $order = Order::find($locked->order_id);
            $parts = self::split($locked, array_filter([
                BoutiqueReturn::SELLABLE => $sellable,
                BoutiqueReturn::DAMAGED => $damaged,
                BoutiqueReturn::PENDING => $qty - $sellable - $damaged,
            ]), $reason);

            foreach ($parts as $part) {
                if ($part->condition === BoutiqueReturn::SELLABLE && $order) {
                    StockLedger::move((int) $order->business_id, $order->branch_id,
                        [(int) $part->product_id => (int) $part->quantity], StockLedger::CORRECTION,
                        PosCashier::name() ?? auth()->user()?->name, $order->number);
                }
                self::settle($part);
            }
        });
    }

    /* ═══════════ حصّةُ البوتيك ═══════════ */

    /**
     * قرارُ المحاسب في إشعارٍ لا يحسمه شرط: على البوتيك أو على المتجر — بسببه.
     */
    public static function review(BoutiqueReturn $note, string $bearer, string $why): void
    {
        DB::transaction(function () use ($note, $bearer, $why) {
            $locked = BoutiqueReturn::whereKey($note->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BoutiqueReturn::REVIEW) {
                throw new SettlementRefused(__('حُسم هذا الإشعار قبل لحظة — افتح الشاشة من جديد.'));
            }

            $bearer === 'boutique'
                ? self::bear($locked, 'accountant', $why)
                : self::shop($locked, 'accountant', $why);
        });
    }

    /**
     * يُحسم بشرط الاتّفاقية إن حسمه — وإلّا ينتظر المحاسب.
     *
     * والشرطُ يُقرأ ساعةَ الحسم ويُحفظ مرجعُه في الإشعار: اتّفاقيةٌ تتغيّر
     * بعده لا تُعيد كتابة ما حُسم بها.
     */
    private static function settle(BoutiqueReturn $note): void
    {
        if ($note->condition === BoutiqueReturn::PENDING) {
            return;
        }

        $boutique = Boutique::find($note->boutique_id);
        $terms = $boutique?->returns_terms;
        $agreed = filled($boutique?->returns_terms_ref) && (
            $terms === self::TERMS_ALL
            || ($terms === self::TERMS_SELLABLE && $note->condition === BoutiqueReturn::SELLABLE)
        );

        if ($agreed) {
            self::bear($note, 'agreement', null);

            return;
        }

        $note->update(['status' => BoutiqueReturn::REVIEW]);
    }

    /**
     * البوتيكُ يتحمّل: يُنقص مستحقَّ ورقةٍ لم تُسدَّد، أو يصير مطالبةً عليه.
     *
     * ويُقرأ «أسُدِّدت؟» تحت قفل مصروف الورقة — السدادُ يقفله نفسَه
     * (`ExpenseController::markPaid`)، فلا يُسدَّد كاملًا ما خُفّض للتوّ.
     */
    private static function bear(BoutiqueReturn $note, string $basis, ?string $why): void
    {
        $sheet = BoutiqueSettlement::find($note->boutique_settlement_id);
        $expense = $sheet?->expense_id
            ? Expense::withTrashed()->whereKey($sheet->expense_id)->lockForUpdate()->first()
            : null;
        $amount = round((float) $note->amount, 3);

        $decided = [
            'bearer' => 'boutique',
            'basis' => $basis,
            'terms_ref' => Boutique::whereKey($note->boutique_id)->value('returns_terms_ref'),
            'decision_note' => $why,
            'decided_by' => auth()->id(),
            'decided_at' => now(),
            'branch_id' => self::branchOf($note, $expense),
        ];

        // حصّةٌ بلا مال (نسبةُ المتجر مئة) لا قيدَ لها — والإشعارُ يُحفظ بحاله
        if ($expense === null || $amount <= 0) {
            $note->update($decided + ['status' => BoutiqueReturn::REDUCED, 'booked_at' => now()->toDateString()]);

            return;
        }

        $accrued = Boutiques::accrual($expense) !== null;

        if (! $expense->isPaid() && (! $accrued || $amount <= self::payable($expense) + 0.0005)) {
            // ورقةٌ قديمةٌ لم تُسدَّد لا دَينَ لها في 2160: يُسدَّد صافيها ويدخل الأثرُ يومَ السداد
            $at = $accrued ? self::post($note, $expense, 'boutique_payable') : null;
            $note->update($decided + ['status' => BoutiqueReturn::REDUCED, 'booked_at' => $at?->toDateString()]);

            return;
        }

        $at = self::post($note, $expense, 'boutique_receivable');
        $note->update($decided + ['status' => BoutiqueReturn::CLAIM, 'booked_at' => $at->toDateString()]);
    }

    /**
     * المتجرُ يتحمّل: تبقى حصّةُ البوتيك كما على الورقة.
     *
     * والقطعةُ السليمةُ التي عادت إلى رفّ البوتيك تُرفع عنه: دُفع للبوتيك
     * ثمنُها، فبيعُها ثانيةً من رفّه يعطيه حصّتَها مرّةً أخرى.
     */
    private static function shop(BoutiqueReturn $note, string $basis, ?string $why): void
    {
        $note->update([
            'status' => BoutiqueReturn::SHOP,
            'bearer' => 'shop',
            'basis' => $basis,
            'decision_note' => $why,
            'decided_by' => auth()->id(),
            'decided_at' => now(),
        ]);

        if ($note->condition === BoutiqueReturn::SELLABLE && ($order = Order::find($note->order_id))) {
            self::offShelf($order, (int) $note->product_id, (int) $note->quantity, __('مرتجعٌ تحمّله المتجر — خرج من رفّ البوتيك'));
        }
    }

    /** قيدُ الإشعار: مدين `$side` / دائن حساب حصّة الورقة — بيوم اليوم في الفترة المفتوحة */
    private static function post(BoutiqueReturn $note, Expense $expense, string $side): Carbon
    {
        $at = BooksPeriod::openOr((int) $note->business_id, now());
        $number = BoutiqueSettlement::whereKey($note->boutique_settlement_id)->value('number');

        Ledger::post(
            (int) $note->business_id,
            BooksPeriod::fit(__('مرتجع بوتيك — ورقة ').$number, $note->name.' × '.$note->quantity),
            [
                ['account' => $side, 'debit' => round((float) $note->amount, 3)],
                ['account' => self::shareAccount($expense), 'credit' => round((float) $note->amount, 3), 'memo' => $note->name],
            ],
            $at,
            self::SOURCE,
            ExpenseScope::journalBranch($expense),
            auth()->id(),
            $note,
        );

        return $at;
    }

    /**
     * حسابُ حصّة الورقة كما قُيّدت — لا كما يقول نوعُ المصروف اليوم.
     *
     * نوعٌ غُيّر حسابُه بعد الورقة يجعل الإشعارَ يُنقص حسابًا لم يُدَن بها.
     */
    private static function shareAccount(Expense $expense): Account|string
    {
        $entry = Boutiques::accrual($expense) ?? Books::liveEntriesFor($expense)->first();
        $line = $entry?->lines()->where('debit', '>', 0)->with('account')->first();

        return $line?->account ?? Books::expenseAccount(Boutiques::EXPENSE_TYPE, (int) $expense->business_id);
    }

    /**
     * فرعُ الإشعار في أرباح الفروع — حيث حُمّلت الورقة.
     *
     * ورقةٌ على فرعٍ فعليه، وموزّعةٌ على فروع بنودها ففرعُ الطلب، وعامّةٌ بلا
     * توزيعٍ فعامٌّ مثلُها — وإلّا أُنقص فرعٌ ممّا لم يُحمَّله.
     */
    private static function branchOf(BoutiqueReturn $note, ?Expense $expense): ?int
    {
        if ($expense?->branch_id) {
            return (int) $expense->branch_id;
        }

        return $expense && $expense->allocations()->exists() ? ($note->branch_id ? (int) $note->branch_id : null) : null;
    }

    /* ═══════════ الاسترداد ═══════════ */

    /** الباقي من مطالبة — قيمتُها ناقصًا ما استُردّ منها */
    public static function remaining(BoutiqueReturn $note): float
    {
        $done = (float) BoutiqueReturnRecovery::where('boutique_return_id', $note->id)->sum('amount');

        return max(0.0, round((float) $note->amount - $done, 3));
    }

    /** المطالباتُ المفتوحة على بوتيك — الأقدمُ أوّلًا */
    public static function claims(int $businessId, int $boutiqueId): Collection
    {
        return BoutiqueReturn::where('business_id', $businessId)->where('boutique_id', $boutiqueId)
            ->where('status', BoutiqueReturn::CLAIM)->orderBy('id')->get();
    }

    /**
     * استلامٌ من البوتيك بمستند: مدين الصندوق أو البنك / دائن 1370 — الأقدمُ أوّلًا.
     */
    public static function receipt(Business $business, Boutique $boutique, float $amount, string $method, string $reference): float
    {
        return DB::transaction(function () use ($business, $boutique, $amount, $method, $reference) {
            $claims = BoutiqueReturn::where('business_id', $business->id)->where('boutique_id', $boutique->id)
                ->where('status', BoutiqueReturn::CLAIM)->orderBy('id')->lockForUpdate()->get();

            $open = round($claims->sum(fn (BoutiqueReturn $c) => self::remaining($c)), 3);
            $amount = round($amount, 3);

            if ($amount <= 0 || $amount > $open + 0.0005) {
                throw new SettlementRefused(__('المطالَب به من :name :open — والاستلامُ لا يزيد عليه.', ['name' => $boutique->name, 'open' => number_format($open, 3)]));
            }

            $left = $amount;
            foreach ($claims as $claim) {
                if ($left <= 0.0005) {
                    break;
                }
                $take = round(min($left, self::remaining($claim)), 3);
                if ($take <= 0) {
                    continue;
                }

                $row = BoutiqueReturnRecovery::create([
                    'business_id' => $business->id, 'boutique_id' => $boutique->id, 'boutique_return_id' => $claim->id,
                    'kind' => BoutiqueReturnRecovery::RECEIPT, 'amount' => $take, 'method' => $method,
                    'reference' => $reference, 'user_id' => auth()->id(),
                ]);

                Ledger::post(
                    (int) $business->id,
                    BooksPeriod::fit(__('استرداد مرتجع من البوتيك: ').$boutique->name, $reference),
                    [
                        ['account' => Books::payingAccount($method), 'debit' => $take],
                        ['account' => 'boutique_receivable', 'credit' => $take, 'memo' => $claim->name],
                    ],
                    BooksPeriod::openOr((int) $business->id, now()), self::SOURCE, null, auth()->id(), $row,
                );

                self::closeIfRecovered($claim);
                $left = round($left - $take, 3);
            }

            return $amount;
        });
    }

    /**
     * خصمُ المطالبات المفتوحة من ورقةٍ لاحقة لم تُسدَّد: مدين 2160 / دائن 1370.
     *
     * والورقةُ المخصومُ منها ورقةٌ قُيّدت يومَ صدورها — دَينُها في 2160 فيُنقص
     * منه. والقديمةُ لا دَينَ لها في الدفتر فلا يُخصم منها؛ يُستلم بمستند.
     */
    public static function offset(Business $business, Boutique $boutique, BoutiqueSettlement $target): float
    {
        return DB::transaction(function () use ($business, $boutique, $target) {
            $expense = $target->expense_id ? Expense::whereKey($target->expense_id)->lockForUpdate()->first() : null;

            if ((int) $target->business_id !== (int) $business->id || (int) $target->boutique_id !== (int) $boutique->id
                || $expense === null || $expense->isPaid() || Boutiques::accrual($expense) === null) {
                throw new SettlementRefused(__('لا يُخصم إلّا من ورقةٍ لهذا البوتيك لم تُسدَّد وقُيّدت يومَ صدورها.'));
            }

            $claims = BoutiqueReturn::where('business_id', $business->id)->where('boutique_id', $boutique->id)
                ->where('status', BoutiqueReturn::CLAIM)->orderBy('id')->lockForUpdate()->get();

            $room = self::payable($expense);
            $total = 0.0;

            foreach ($claims as $claim) {
                $take = round(min($room - $total, self::remaining($claim)), 3);
                if ($take <= 0) {
                    continue;
                }

                $row = BoutiqueReturnRecovery::where('boutique_return_id', $claim->id)
                    ->where('boutique_settlement_id', $target->id)->first();
                $row
                    ? $row->update(['amount' => round((float) $row->amount + $take, 3)])
                    : $row = BoutiqueReturnRecovery::create([
                        'business_id' => $business->id, 'boutique_id' => $boutique->id, 'boutique_return_id' => $claim->id,
                        'kind' => BoutiqueReturnRecovery::OFFSET, 'amount' => $take,
                        'boutique_settlement_id' => $target->id, 'user_id' => auth()->id(),
                    ]);

                Ledger::post(
                    (int) $business->id,
                    BooksPeriod::fit(__('خصم مرتجع من ورقة ').$target->number, $claim->name),
                    [
                        ['account' => 'boutique_payable', 'debit' => $take],
                        ['account' => 'boutique_receivable', 'credit' => $take, 'memo' => $claim->name],
                    ],
                    BooksPeriod::openOr((int) $business->id, now()), self::SOURCE, ExpenseScope::journalBranch($expense), auth()->id(), $row,
                );

                self::closeIfRecovered($claim);
                $total = round($total + $take, 3);
            }

            if ($total <= 0) {
                throw new SettlementRefused(__('لا مطالبةَ مفتوحة تُخصم، أو لا متّسعَ في هذه الورقة.'));
            }

            return $total;
        });
    }

    private static function closeIfRecovered(BoutiqueReturn $claim): void
    {
        if (self::remaining($claim) <= 0.0005) {
            $claim->update(['status' => BoutiqueReturn::RECOVERED]);
        }
    }

    /* ═══════════ سدادُ الورقة ═══════════ */

    /** ورقةُ هذا المصروف — و`null` لمصروفٍ ليس ورقةَ بوتيك */
    public static function sheetOf(Expense $expense): ?BoutiqueSettlement
    {
        return BoutiqueSettlement::where('business_id', $expense->business_id)->where('expense_id', $expense->id)->first();
    }

    /** ما خُفّض من مستحقّ الورقة: إشعاراتٌ تحمّلها البوتيك قبل سدادها، وخصومُ مطالباتٍ منها */
    public static function reductions(Expense $expense): float
    {
        $sheet = self::sheetOf($expense);
        if ($sheet === null) {
            return 0.0;
        }

        $notes = (float) BoutiqueReturn::where('boutique_settlement_id', $sheet->id)
            ->where('status', BoutiqueReturn::REDUCED)->sum('amount');
        $offsets = (float) BoutiqueReturnRecovery::where('boutique_settlement_id', $sheet->id)
            ->where('kind', BoutiqueReturnRecovery::OFFSET)->sum('amount');

        return round($notes + $offsets, 3);
    }

    /**
     * ما خُفّض من أوراقٍ لم تُسدَّد — `[رقمُ المصروف => المبلغ]`.
     *
     * شاشةُ «المبالغ المستحقة» وجملتُها تقرأ مبلغَ المصروف كما سُجّل؛ وورقةٌ
     * خفّضها مرتجعٌ أو خصمٌ يُسدَّد صافيها. فيُطرح هذا منها — وإلّا قالت
     * الشاشةُ «عليك» أكثرَ ممّا في 2160 وممّا سيخرج فعلًا.
     *
     * @param  list<int>|null  $expenseIds
     * @return array<int, float>
     */
    public static function unpaidReductions(int $businessId, ?array $expenseIds = null): array
    {
        $sheets = BoutiqueSettlement::where('business_id', $businessId)
            ->whereIn('expense_id', Expense::where('business_id', $businessId)->unpaid()
                ->when($expenseIds !== null, fn ($q) => $q->whereIn('id', $expenseIds))->select('id'))
            ->pluck('id', 'expense_id');

        if ($sheets->isEmpty()) {
            return [];
        }

        $notes = BoutiqueReturn::whereIn('boutique_settlement_id', $sheets->values())->where('status', BoutiqueReturn::REDUCED)
            ->groupBy('boutique_settlement_id')->selectRaw('boutique_settlement_id as s, SUM(amount) as v')->pluck('v', 's');
        $offsets = BoutiqueReturnRecovery::whereIn('boutique_settlement_id', $sheets->values())->where('kind', BoutiqueReturnRecovery::OFFSET)
            ->groupBy('boutique_settlement_id')->selectRaw('boutique_settlement_id as s, SUM(amount) as v')->pluck('v', 's');

        return $sheets->mapWithKeys(fn ($sheet, $expense) => [
            (int) $expense => round((float) ($notes[$sheet] ?? 0) + (float) ($offsets[$sheet] ?? 0), 3),
        ])->filter(fn ($v) => $v > 0)->all();
    }

    /** ما يُسدَّد فعلًا: مبلغُ الورقة ناقصًا ما خُفّض منه */
    public static function payable(Expense $expense): float
    {
        return max(0.0, round((float) $expense->amount - self::reductions($expense), 3));
    }

    /**
     * سُدِّدت ورقةٌ قديمة: إشعاراتُها التي خُفّض بها صافيها تدخل الدفترَ يومَ السداد.
     */
    public static function onSheetPaid(Expense $expense, Carbon $at): void
    {
        $sheet = self::sheetOf($expense);
        if ($sheet === null) {
            return;
        }

        BoutiqueReturn::where('boutique_settlement_id', $sheet->id)->where('status', BoutiqueReturn::REDUCED)
            ->whereNull('booked_at')->update(['booked_at' => $at->toDateString()]);
    }

    /* ═══════════ للتقارير ═══════════ */

    /** إشعاراتٌ دخل أثرُها الدفترَ في الفترة — `[$start, $end)` بيوم دخولها */
    public static function booked(int $businessId, ?Carbon $start, ?Carbon $end)
    {
        return BoutiqueReturn::where('boutique_returns.business_id', $businessId)
            ->whereIn('boutique_returns.status', BoutiqueReturn::BORNE)
            ->whereNotNull('booked_at')
            ->when($start, fn ($q) => $q->where('booked_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->where('booked_at', '<', $end->toDateString()));
    }

    /* ═══════════ الكتابة ═══════════ */

    /**
     * إشعارٌ بحصّة `$qty` قطعة كما حملتها الورقة — بالفرق لا بالضرب.
     *
     * الورقةُ تقرّب على سطر الصنف ونسبته (`Boutiques::statement`). فحصّةُ ما
     * عاد = صافي السطر قبل المرتجع ناقصًا صافيه بعده، بالتقريب نفسه. فمرتجعُ
     * السطر كلِّه قطعةً قطعة يساوي صافيَه على الورقة بالفلس.
     *
     * @param  array<string, mixed>  $line
     */
    private static function write(Order $order, array $line, int $qty, float $gross, string $source, string $condition, string $reason, ?int $editId): BoutiqueReturn
    {
        [$group, $before] = self::groupGross($line, $source === BoutiqueReturn::QUANTITY ? $gross : 0.0);
        $rate = (float) $line['rate'];
        $amount = round(self::netOf($group - $before, $rate) - self::netOf($group - $before - $gross, $rate), 3);

        return BoutiqueReturn::create([
            'business_id' => $order->business_id,
            'boutique_id' => $line['boutique_id'],
            'boutique_settlement_id' => $line['settlement_id'],
            'order_id' => $order->id,
            'order_item_id' => OrderItem::whereKey($line['item_id'])->exists() ? $line['item_id'] : null,
            'order_edit_id' => $editId,
            'branch_id' => $order->branch_id,
            'source' => $source,
            'product_id' => $line['product_id'],
            'name' => $line['name'],
            'variant_name' => $line['variant_name'],
            'rate' => $rate,
            'unit_price' => $line['unit_price'],
            'quantity' => $qty,
            'gross' => round($gross, 3),
            'amount' => max(0.0, $amount),
            'sold_at' => $order->ordered_at ?? $order->created_at,
            'returned_at' => now(),
            'condition' => $condition,
            'condition_at' => $condition === BoutiqueReturn::PENDING ? null : now(),
            'status' => BoutiqueReturn::AWAITING,
            'reason' => mb_substr($reason, 0, 255),
            'user_id' => PosCashier::id() ?? auth()->id(),
        ]);
    }

    /**
     * إجماليُّ سطر الورقة يومَ صدورها، وما عاد منه قبل هذا الإشعار.
     *
     * بنودُ الطلبات الملغاة باقيةٌ بإجماليّها، والمُنقَصةُ في يومها نقص
     * إجماليُّها — فيُردّ إليها ما نقص من إشعاراتها (`QUANTITY`).
     *
     * @param  array<string, mixed>  $line
     * @return array{0: float, 1: float}
     */
    private static function groupGross(array $line, float $pending): array
    {
        $key = fn ($pid, $name, $variant, $rate) => ((int) $pid).'|'.$name.'|'.($variant ?? '').'|'.round((float) $rate, 2);
        $mine = $key($line['product_id'], $line['name'], $line['variant_name'], $line['rate']);

        $group = (float) OrderItem::where('boutique_settlement_id', $line['settlement_id'])
            ->get(['product_id', 'name', 'variant_name', 'boutique_rate', 'total'])
            ->filter(fn ($i) => $key($i->product_id, $i->name, $i->variant_name, $i->boutique_rate) === $mine)
            ->sum('total');

        $notes = BoutiqueReturn::where('boutique_settlement_id', $line['settlement_id'])
            ->get(['product_id', 'name', 'variant_name', 'rate', 'gross', 'source'])
            ->filter(fn ($n) => $key($n->product_id, $n->name, $n->variant_name, $n->rate) === $mine);

        $group += (float) $notes->where('source', BoutiqueReturn::QUANTITY)->sum('gross') + $pending;

        return [round($group, 3), round((float) $notes->sum('gross'), 3)];
    }

    /** صافي سطرٍ بتقريب الورقة نفسِه */
    private static function netOf(float $gross, float $rate): float
    {
        $gross = round($gross, 3);

        return round($gross - round($gross * $rate / 100, 3), 3);
    }

    /**
     * يقسم إشعارًا على أحوال قطعه — والأخيرُ يأخذ ما بقي من مبلغه وإجماليّه.
     *
     * @param  array<string, int>  $parts
     * @return list<BoutiqueReturn>
     */
    private static function split(BoutiqueReturn $note, array $parts, string $reason): array
    {
        // بأرقام الإشعار قبل القسمة — الجزءُ الأوّل يُكتب فوقه
        $qty = (int) $note->quantity;
        $whole = round((float) $note->amount, 3);
        $wholeGross = round((float) $note->gross, 3);
        $amountLeft = $whole;
        $grossLeft = $wholeGross;
        $left = count($parts);
        $out = [];

        foreach ($parts as $condition => $n) {
            $last = --$left === 0;
            $amount = $last ? $amountLeft : round($whole * $n / $qty, 3);
            $gross = $last ? $grossLeft : round($wholeGross * $n / $qty, 3);
            $amountLeft = round($amountLeft - $amount, 3);
            $grossLeft = round($grossLeft - $gross, 3);

            $fields = [
                'quantity' => $n, 'amount' => $amount, 'gross' => $gross, 'condition' => $condition,
                'condition_at' => $condition === BoutiqueReturn::PENDING ? null : now(),
                'status' => BoutiqueReturn::AWAITING,
            ];

            if ($out === []) {
                $note->update($fields);
                $out[] = $note;
            } else {
                $out[] = BoutiqueReturn::create($fields + collect($note->getAttributes())
                    ->except(['id', 'created_at', 'updated_at', ...array_keys($fields)])->all()
                    + ['split_from_id' => $note->id, 'reason' => mb_substr($reason, 0, 255)]);
            }
        }

        return $out;
    }

    /** القطعُ تخرج من الرفّ — كما تُرفع قطعُ المكوّنات التي لم تعد */
    private static function offShelf(Order $order, int $productId, int $qty, string $why): void
    {
        if ($qty <= 0 || $productId <= 0) {
            return;
        }

        StockLedger::move((int) $order->business_id, $order->branch_id, [$productId => -$qty],
            StockLedger::CORRECTION, PosCashier::name() ?? auth()->user()?->name, $order->number.' — '.$why);
    }

    /** أوراقُ الإشعارات مقفلةً بترتيبها — فإشعاران لسطرٍ واحد لا يحسبان من «قبل» واحد */
    private static function lockSheets(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        BoutiqueSettlement::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
    }
}
