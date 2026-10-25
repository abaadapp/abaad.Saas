<?php

namespace App\Support;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymobSettlement;
use App\Models\PaymobSettlementItem;
use App\Models\StorePaymentIntent;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * تسوياتُ Paymob — المالُ الذي حوّلته إلى بنك التاجر ناقصًا رسومَها.
 *
 * ═══ ولمَ يدويّة ═══
 *
 * نجاحُ الدفع لا يعني أنّ المالَ وصل البنك: يجلس في «Paymob تحت التسوية»
 * (`paymob_clearing`) حتّى تُحوّله Paymob بعد أيّام، ناقصًا رسومَها. ولا
 * واجهةَ رسميّةً موثَّقةً من Paymob عُمان تقول ما حُوِّل ومتى ورسومَه بثقة —
 * فلا يُخمَّن تحويلٌ لم يقع. يكتبه التاجر من كشف Paymob أو كشف بنكه.
 *
 * ═══ والتحويلُ يسمّي مدفوعاتِه ═══
 *
 * كانت التسويةُ ثلاثةَ أرقام تُدين البنكَ بالصافي من التسوية. ومنذ #168 تنتظر
 * بيعةُ الموقع بالبطاقة في التسوية (`Books::paymobDebit`) — وما بيع قبله
 * قُيِّد في البنك يومَ البيع بإجماليّه. فأوّلُ تحويلٍ بعد النشر يحمل الاثنين،
 * ورقمٌ واحدٌ لا يفرّق بينهما: مالُ القديمة يدخل البنكَ مرّةً ثانية، وحدُّ
 * «ما ينتظر التسوية» لا يمنعه لأنّ فيها مالَ روابط الدفع.
 *
 * فيختار التاجرُ المدفوعاتِ التي حملها التحويل، ويُسأل الدفترُ عن كلٍّ منها
 * أين مالُه (`classify`):
 *
 * - **في التسوية** (`clearing`): رابطُ دفع، أو بيعةُ موقعٍ رُحّلت بعد #168.
 *   يخرج إجماليُّها من التسوية.
 * - **في البنك منذ يوم البيع** (`bank`): بيعةُ موقعٍ رُحّلت قبل #168. مالُها في
 *   الدفتر أصلًا فلا يُضاف ثانيةً — يخرج من الورقة التي قُيِّد فيها، ويدخل
 *   الصافي ورقةَ التحويل. فإن كانتا ورقةً واحدة بقي فرقُ الرسوم وحده.
 * - **غيرُ مؤكَّد** (`unknown`): طلبٌ ملغى، أو مستردّ، أو رُدّ منه فرقٌ
 *   بالبطاقة، أو مالٌ بلا طلب، أو دفترٌ لا يطابق مبلغَ المدفوعة. لا يُخمَّن:
 *   **تُحفظ التسويةُ كلُّها ولا تُرحَّل** (`held`) وتقول لماذا، حتّى يُراجَع
 *   السبب فيُعاد فحصُها (`retry`) أو تُلغى قبل ترحيلها (`cancel`).
 *
 * ═══ والقيد ═══
 *
 *     مدين  البنك (ورقة التحويل)        الصافي
 *     مدين  رسوم بوابات الدفع           الرسوم
 *         دائن  Paymob تحت التسوية        ما كان فيها
 *         دائن  البنك (ورقة يوم البيع)     ما قُيِّد فيها يومَ البيع
 *
 * يُصفّى كلُّ حسابٍ إلى رقمٍ واحد. فالربحُ يتأثّر بالرسوم وحدها — مصروفًا —
 * والإيرادُ لا يتحرّك. ولا قيدَ قائمٌ يُعدَّل أو يُعكس: قيدٌ جديدٌ واحد بتاريخ
 * التحويل، يكتبه فعلُ التاجر لا غيرُه.
 *
 * ═══ ولا تُسوّى مدفوعةٌ مرّتين ═══
 *
 * فهرسٌ فريدٌ على كلٍّ من النيّة والرابط في `paymob_settlement_items` —
 * والمعلّقةُ تحجز مدفوعاتِها كالمرحّلة.
 */
final class PaymobSettlements
{
    public const SOURCE = 'تسوية Paymob';

    /* ═══════════ حالُ التسوية ═══════════ */

    public const POSTED = 'posted';

    public const HELD = 'held';

    public const CANCELLED = 'cancelled';

    /**
     * كتبها كودٌ لا يسمّي المدفوعات: كلُّ تسويةٍ قبل #168، وكلُّ ما يكتبه إصدارٌ
     * أقدم إن رُجع إليه بعده. افتراضُ العمود — فالكودُ الجديدُ وحده يقول غيرَه.
     */
    public const LEGACY = 'legacy';

    /* ═══════════ أين مالُ المدفوعة ═══════════ */

    public const IN_CLEARING = 'clearing';

    public const IN_BANK = 'bank';

    public const UNSURE = 'unknown';

    /** حملتها تسويةٌ قديمة (`LEGACY`) — بقرار صاحب المتجر من كشف Paymob، لا بتخمين */
    public const IN_LEGACY = 'legacy';

    /* ═══════════ نوعُ المدفوعة ═══════════ */

    /** دفعٌ من الموقع — `StorePaymentIntent` */
    public const INTENT = 'intent';

    /** رابطُ دفع — `CustomerPaymentLink` */
    public const LINK = 'link';

    /**
     * إيقافٌ مؤقّتٌ من المنصّة (`PAYMOB_SETTLEMENTS_PAUSED`): لا تُسجَّل تسويةٌ ولا
     * يُعاد فحصُها ولا يُحدَّد ما حمله تحويلٌ قديم حتّى يُفتح. للنشر المتدرّج
     * وللطوارئ — والإلغاءُ قبل الترحيل يبقى مفتوحًا: لا يكتب في الدفتر شيئًا.
     */
    public static function paused(): bool
    {
        return (bool) config('services.paymob.settlements_paused', false);
    }

    /** ما في «Paymob تحت التسوية» الآن — قُبض من العملاء ولم يصل البنك */
    public static function clearing(int $businessId): float
    {
        return round(Ledger::balance($businessId, 'paymob_clearing'), 3);
    }

    /**
     * ما ينتظر أن يُسوّى: مدفوعاتُ Paymob المقبوضة التي لم تحملها تسويةٌ بعد،
     * الأقدمُ أوّلًا، ومع كلٍّ أين مالُه.
     *
     * @return list<array{key: string, type: string, id: int, amount: float, label: string, paid_at: ?string, booked: string, account_id: ?int, reason: ?string}>
     */
    public static function candidates(int $businessId, int $limit = 300): array
    {
        $open = fn (string $table, string $column, bool $liveOnly = false) => DB::table($table.' as p')
            ->where('p.business_id', $businessId)
            ->where('p.status', 'paid')
            // ورابطُ التجربة ليس مالًا تحوّله Paymob — ولو كُتب له تحصيلٌ قبل أن يُمنع (`classifyLink`)
            ->when($liveOnly, fn ($q) => $q->where('p.is_live', true))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('paymob_settlement_items as s')->whereColumn('s.'.$column, 'p.id'))
            ->orderBy('p.paid_at')->orderBy('p.id')->limit($limit)->pluck('p.id');

        return collect()
            ->merge($open('store_payment_intents', 'store_payment_intent_id')->map(fn ($id) => self::classify($businessId, self::INTENT, (int) $id)))
            ->merge($open('customer_payment_links', 'customer_payment_link_id', true)->map(fn ($id) => self::classify($businessId, self::LINK, (int) $id)))
            ->sortBy(fn (array $r) => ($r['paid_at'] ?? '').'|'.$r['key'])
            ->values()->all();
    }

    /**
     * أين مالُ هذه المدفوعة في الدفتر الآن.
     *
     * يُسأل الدفترُ لا تاريخُ النشر: بيعةٌ رُحّلت قبل #168 ثمّ صُحّحت بعده تبقى
     * في البنك (`Books::paymobDebit`)، وأخرى رُحّلت بعده ثمّ صُحّحت بكودٍ أقدم
     * بعد تراجعٍ انتقلت إلى البنك. فالجوابُ ما في القيود الحيّة، لا متى بيعت.
     *
     * @return array{key: string, type: string, id: int, amount: float, label: string, paid_at: ?string, booked: string, account_id: ?int, reason: ?string}
     */
    public static function classify(int $businessId, string $type, int $id): array
    {
        return match ($type) {
            self::INTENT => self::classifyIntent($businessId, $id),
            self::LINK => self::classifyLink($businessId, $id),
            default => throw new RuntimeException(__('مدفوعةٌ ليست من مدفوعات Paymob المقبوضة في متجرك.')),
        };
    }

    private static function classifyIntent(int $businessId, int $id): array
    {
        $intent = DB::table('store_payment_intents')->where('business_id', $businessId)->where('id', $id)
            ->where('status', StorePaymentIntent::PAID)->first();

        if (! $intent) {
            throw new RuntimeException(__('مدفوعةٌ ليست من مدفوعات Paymob المقبوضة في متجرك.'));
        }

        $order = $intent->order_id
            ? DB::table('orders')->where('business_id', $businessId)->where('id', $intent->order_id)->first(['id', 'number', 'status'])
            : null;

        $row = [
            'key' => self::INTENT.':'.$id, 'type' => self::INTENT, 'id' => $id,
            'amount' => round((float) $intent->amount, 3),
            'label' => $order ? __('طلب الموقع :n', ['n' => $order->number]) : __('دفعٌ من الموقع بلا طلب'),
            'paid_at' => $intent->paid_at ? substr((string) $intent->paid_at, 0, 10) : null,
            'paid_ts' => $intent->paid_at ? (string) $intent->paid_at : null,
        ];

        $why = match (true) {
            $intent->refund_status !== null => __('طُلب ردُّها عند Paymob'),
            ! $order => __('مالٌ قُبض في Paymob ولا طلبَ له في المتجر'),
            (string) $order->status === OrderStatus::CANCELLED => __('الطلب ملغى'),
            default => null,
        };

        if ($why) {
            return self::orOldPayout($businessId, self::unsure($row, $why));
        }

        /*
         * وتصحيحُ الطلب بعد الدفع يُقيَّد على حركةٍ (`Transaction`) لا على الطلب
         * نفسه (`OrderCorrection::settlementRow`) — فتُقرأ قيودُها معه. وفرقٌ
         * رُدّ بالبطاقة ردّته Paymob من رصيدها فنقص به تحويلُها: ما ينتظر من
         * هذه المدفوعة مبلغُها ناقصًا ما رُدّ.
         */
        $moves = DB::table('transactions')->where('business_id', $businessId)->where('order_id', $order->id)->whereNull('deleted_at');
        $refunded = round((float) (clone $moves)->where('kind', Transaction::ORDER_REFUND)->where('method', PaymentMethods::CARD)->sum('amount'), 3);

        return self::orOldPayout($businessId, self::whereIs($businessId, [Order::class => [(int) $order->id], Transaction::class => $moves->pluck('id')->map(fn ($id) => (int) $id)->all()], $row, $refunded));
    }

    private static function classifyLink(int $businessId, int $id): array
    {
        $link = DB::table('customer_payment_links')->where('business_id', $businessId)->where('id', $id)
            ->where('status', CustomerPaymentLink::PAID)->first();

        if (! $link) {
            throw new RuntimeException(__('مدفوعةٌ ليست من مدفوعات Paymob المقبوضة في متجرك.'));
        }

        /*
         * ورابطُ التجربة لا يُسوّى أبدًا: دُفع ببطاقة تجربة، فلا تحوّل Paymob عنه
         * شيئًا. وما سُجّل له تحصيلٌ قبل أن يُمنع (`PaymentLinks::settle`) يبقى
         * مالُه المخترَعُ في التسوية حتّى يُلغى التحصيلُ بيد صاحبه — لا يخرج
         * إلى البنك فيشهد الدفترُ بتحويلٍ لم يقع.
         */
        if (! $link->is_live) {
            throw new RuntimeException(__('رابط دفعٍ تجريبيّ (Test) — دُفع ببطاقة تجربة فلا تحوّل Paymob عنه شيئًا، ولا يُسوّى.'));
        }

        $payment = $link->customer_payment_id
            ? DB::table('customer_payments')->where('business_id', $businessId)->where('id', $link->customer_payment_id)->first(['id', 'number', 'amount', 'cancelled_at'])
            : null;

        $row = [
            'key' => self::LINK.':'.$id, 'type' => self::LINK, 'id' => $id,
            'amount' => round((float) ($payment->amount ?? $link->amount), 3),
            'label' => $payment ? __('رابط دفع — تحصيل :n', ['n' => $payment->number]) : __('رابط دفع'),
            'paid_at' => $link->paid_at ? substr((string) $link->paid_at, 0, 10) : null,
            'paid_ts' => $link->paid_at ? (string) $link->paid_at : null,
        ];

        $why = match (true) {
            ! $payment => __('دُفع الرابط ولم يُسجَّل تحصيلُه'),
            $payment->cancelled_at !== null => __('التحصيل ملغى'),
            default => null,
        };

        return self::orOldPayout($businessId, $why ? self::unsure($row, $why) : self::whereIs($businessId, [CustomerPayment::class => [(int) $payment->id]], $row));
    }

    /**
     * يقرأ القيودَ المرحّلة لمستنداتِ مدفوعةٍ واحدة: ما ينتظر منها كلُّه في
     * التسوية، أم قيدُ بيعها الحيُّ أدان البنكَ بها وحده — وما سوى ذلك غيرُ
     * مؤكَّد.
     *
     * @param  array<class-string, list<int>>  $sources
     */
    private static function whereIs(int $businessId, array $sources, array $row, float $refunded = 0.0): array
    {
        $clearing = self::account($businessId, 'paymob_clearing');

        $inClearing = round((float) DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.business_id', $businessId)->where('e.posted', true)
            ->where(function ($q) use ($sources) {
                foreach ($sources as $type => $ids) {
                    $q->orWhere(fn ($w) => $w->where('e.sourceable_type', $type)->whereIn('e.sourceable_id', $ids === [] ? [0] : $ids));
                }
            })
            ->where('l.account_id', $clearing->id)
            ->sum(DB::raw('l.debit - l.credit')), 3);

        $waits = round($row['amount'] - $refunded, 3);

        if ($waits > 0 && self::same($inClearing, $waits)) {
            return ['amount' => $waits, 'booked' => self::IN_CLEARING, 'account_id' => $clearing->id, 'reason' => null] + $row;
        }

        $orderId = $sources[Order::class][0] ?? null;

        if ($orderId !== null && self::same($inClearing, 0) && self::same($refunded, 0)) {
            $sale = DB::table('journal_entries')->where('business_id', $businessId)
                ->where('sourceable_type', Order::class)->where('sourceable_id', $orderId)->where('source', Books::SALE)
                ->where('posted', true)->whereNull('reverses_id')->whereNull('reversed_at')
                ->orderByDesc('id')->first(['id']);

            $banks = self::bankLeaves($businessId);
            // ما أدانه قيدُ البيع من مالٍ: البنكُ أو الصندوقُ أو الذممُ أو التسوية
            $money = $sale ? DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('l.journal_entry_id', $sale->id)->where('l.debit', '>', 0)
                ->where(fn ($q) => $q->whereIn('l.account_id', $banks)->orWhereIn('a.system_key', ['cash', 'receivable', 'paymob_clearing']))
                ->get(['l.account_id', 'l.debit']) : collect();

            if ($money->count() === 1 && in_array((int) $money[0]->account_id, $banks, true) && self::same((float) $money[0]->debit, $row['amount'])) {
                return $row + ['booked' => self::IN_BANK, 'account_id' => (int) $money[0]->account_id, 'reason' => null];
            }
        }

        return self::unsure($row, __('في الدفتر :c منها في التسوية، وينتظر منها :a', [
            'c' => number_format($inClearing, 3), 'a' => number_format($waits, 3),
        ]));
    }

    /**
     * أحملها تحويلٌ سُجّل قبل التحديث؟
     *
     * تسويةٌ قديمة (`LEGACY`) رُحّلت بإجماليٍّ لا يسمّي مدفوعاتِه. فكلُّ مدفوعةٍ
     * دُفعت قبل أن تُسجَّل ربما كانت فيها — وغيابُ اسمها من
     * `paymob_settlement_items` لا يقول إنّها لم تُسوَّ. والدليلُ الذي لا يخطئ هو
     * الزمن وحده: تحويلٌ سُجّل في لحظةٍ لا يحمل مدفوعةً دُفعت بعدها، فحدُّه يومها
     * كان رصيدَ التسوية ولا يحوي إلّا ما دُفع قبلها. فالمدفوعةُ مشتبهٌ فيها ما بقي
     * تحويلٌ قديمٌ بعدها لم يُحدَّد كلُّ ما حمله (`attribute`): «غيرُ مؤكَّد»، ولا
     * تُرحَّل تسويةٌ تضمّها. ولا يُوزَّع الإجماليُّ بتشابه مبلغٍ أو تاريخ.
     *
     * والصفُّ يحمل `legacy`: التحويلاتُ القديمة التي ربما حملتها — ليختار منها
     * صاحبُ المتجر.
     */
    private static function orOldPayout(int $businessId, array $row): array
    {
        $old = self::openLegacy($businessId, $row['paid_ts'] ?? null);
        $row['legacy'] = $old->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($old->isEmpty() || $row['booked'] === self::UNSURE) {
            return $row;
        }

        return ['booked' => self::UNSURE, 'account_id' => null, 'reason' => __('ربما حملها تحويلٌ سُجّل قبل التحديث (:d) ولا يسمّي مدفوعاته — حدّد ما حمله ذاك التحويل', [
            'd' => substr((string) $old->first()->settled_on, 0, 10),
        ])] + $row;
    }

    /**
     * تحويلٌ قديمٌ على الشاشة: ما بقي من إجماليّه غيرَ محدَّد، وما حُدّد أنّه حمله
     * ومن حدّده ومتى وبأيّ مستند.
     *
     * @return array{open: float, attributed: list<array{id: int, label: string, amount: float, reason: ?string, by: ?string, at: ?string}>}
     */
    public static function legacyPanel(PaymobSettlement $settlement): array
    {
        $businessId = (int) $settlement->business_id;
        $items = PaymobSettlementItem::where('paymob_settlement_id', $settlement->id)->where('booked', self::IN_LEGACY)->orderBy('id')->get();
        $names = DB::table('users')->where('business_id', $businessId)->whereIn('id', $items->pluck('decided_by')->filter()->all())->pluck('name', 'id');

        return [
            'open' => self::unattributed((int) $settlement->id, (float) $settlement->gross),
            'attributed' => $items->map(function (PaymobSettlementItem $item) use ($businessId, $names) {
                [$type, $sid] = array_pad(explode(':', $item->source, 2), 2, 0);
                try {
                    $label = self::classify($businessId, (string) $type, (int) $sid)['label'];
                } catch (RuntimeException) {
                    $label = $item->source;
                }

                return ['id' => (int) $item->id, 'label' => $label, 'amount' => (float) $item->amount, 'reason' => $item->reason,
                    'by' => $names[$item->decided_by] ?? null, 'at' => $item->created_at?->format('Y-m-d H:i')];
            })->all(),
        ];
    }

    /** تحويلاتُ المتجر القديمة التي لم يُحدَّد كلُّ ما حملته، المسجَّلةُ بعد هذه اللحظة (أو كلُّها إن لم تُعرف) */
    private static function openLegacy(int $businessId, ?string $paidAt): Collection
    {
        return DB::table('paymob_settlements')
            ->where('business_id', $businessId)->where('status', self::LEGACY)
            ->when($paidAt !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('created_at')->orWhere('created_at', '>=', $paidAt)))
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'settled_on', 'gross', 'created_at'])
            ->filter(fn ($s) => self::unattributed((int) $s->id, (float) $s->gross) > 0.0005)
            ->values();
    }

    /** ما بقي من إجماليّ تسويةٍ قديمة لم يُحدَّد ما حمله */
    private static function unattributed(int $settlementId, float $gross): float
    {
        return round($gross - (float) DB::table('paymob_settlement_items')->where('paymob_settlement_id', $settlementId)->sum('amount'), 3);
    }

    /**
     * يكتب تسويةً لمدفوعاتٍ بعينها ويقيدها — أو يحفظها معلّقةً إن لم يتأكّد
     * أين مالُ إحداها.
     *
     * @param  array{items: list<array{type: string, id: int|string}|string>, fee: float|string, net: float|string, settled_on: string, bank_account_id?: int|string|null, reference?: ?string, notes?: ?string}  $data
     */
    public static function record(int $businessId, array $data, ?int $userId = null): PaymobSettlement
    {
        self::refuseWhilePaused();
        $fee = round((float) ($data['fee'] ?? 0), 3);
        $net = round((float) ($data['net'] ?? 0), 3);

        if ($net <= 0 || $fee < 0) {
            throw new RuntimeException(__('الصافي أكبر من صفر، والرسوم لا تكون سالبة.'));
        }

        $picked = self::picked($data['items'] ?? []);
        $bankId = self::payoutBank($businessId, $data['bank_account_id'] ?? null);
        $on = Carbon::parse($data['settled_on'])->startOfDay();

        return DB::transaction(function () use ($businessId, $picked, $fee, $net, $bankId, $on, $data, $userId) {
            // ونافذتان تسجّلان في اللحظة نفسها لا تقرآن الدفترَ نفسه — يُقفل المتجر
            Business::whereKey($businessId)->lockForUpdate()->first();

            $rows = collect($picked)->map(function (array $p) use ($businessId) {
                $row = self::classify($businessId, $p['type'], $p['id']);

                if (PaymobSettlementItem::where(self::column($p['type']), $p['id'])->exists()) {
                    throw new RuntimeException(__(':what سبقت تسويتُها — لا تُسوّى مدفوعةٌ مرّتين.', ['what' => $row['label']]));
                }

                return $row;
            });

            // والمقارنةُ بالبيسة لا بالكسر العشريّ: ٩٨٫٠٠٠ + ٢٫٠٠٠ قد لا تساوي ١٠٠ في الفاصلة العائمة
            $gross = round((float) $rows->sum('amount'), 3);
            if (! self::same($gross, $net + $fee)) {
                throw new RuntimeException(__('مجموعُ المدفوعات المختارة (:g) لا يساوي الصافيَ والرسوم (:s).', [
                    'g' => number_format($gross, 3), 's' => number_format($net + $fee, 3),
                ]));
            }

            $settlement = PaymobSettlement::create([
                'business_id' => $businessId,
                'bank_account_id' => $bankId,
                'gross' => $gross,
                'fee' => $fee,
                'net' => $net,
                'status' => self::HELD,
                'settled_on' => $on->toDateString(),
                'reference' => filled($data['reference'] ?? null) ? mb_substr(trim((string) $data['reference']), 0, 80) : null,
                'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 500) : null,
                'created_by' => $userId,
            ]);

            foreach ($rows as $row) {
                self::once(fn () => PaymobSettlementItem::create([
                    'business_id' => $businessId,
                    'paymob_settlement_id' => $settlement->id,
                    self::column($row['type']) => $row['id'],
                    'source' => $row['key'],
                ] + self::itemFields($row)));
            }

            self::settle($settlement, $rows, $userId);

            Activity::log('created', 'سجّل تسوية Paymob: إجمالي '.number_format($gross, 3).' — رسوم '.number_format($fee, 3).' — صافي '.number_format($net, 3)
                .($settlement->status === self::HELD ? ' — معلّقة للمراجعة' : ''), [
                    'subject_id' => $settlement->id, 'subject_type' => 'paymob_settlement', 'business_id' => $businessId,
                ]);

            return $settlement->fresh();
        });
    }

    /** يعيد فحصَ تسويةٍ معلّقة — فإن تأكّد مالُ كلِّ مدفوعاتها رُحّلت */
    public static function retry(int $businessId, int $id, ?int $userId = null): PaymobSettlement
    {
        self::refuseWhilePaused();

        return DB::transaction(function () use ($businessId, $id, $userId) {
            Business::whereKey($businessId)->lockForUpdate()->first();
            $settlement = PaymobSettlement::where('business_id', $businessId)->whereKey($id)->firstOrFail();

            if ($settlement->status !== self::HELD) {
                throw new RuntimeException(__('هذه التسوية ليست معلّقة.'));
            }

            $rows = $settlement->items()->orderBy('id')->get()->map(function (PaymobSettlementItem $item) use ($businessId) {
                [$type, $sid] = explode(':', $item->source, 2);
                try {
                    $row = self::classify($businessId, $type, (int) $sid);
                } catch (RuntimeException) {
                    $row = self::unsure(['key' => $item->source, 'type' => $type, 'id' => (int) $sid, 'amount' => (float) $item->amount,
                        'label' => $item->source, 'paid_at' => null, 'paid_ts' => null, 'legacy' => []], __('لم تعد مدفوعةً مقبوضة'));
                }
                $item->forceFill(self::itemFields($row))->save();

                return $row;
            });

            self::settle($settlement, $rows, $userId);

            Activity::log('updated', 'أعاد فحص تسوية Paymob معلّقة: '.($settlement->status === self::POSTED ? 'رُحّلت' : 'ما زالت معلّقة'), [
                'subject_id' => $settlement->id, 'subject_type' => 'paymob_settlement', 'business_id' => $businessId,
            ]);

            return $settlement->fresh();
        });
    }

    /**
     * يلغي تسويةً معلّقةً قبل ترحيلها: تعود مدفوعاتُها لتُختار، ويبقى صفُّها
     * بأرقامه وسببِ تعليقه وأسماءِ مدفوعاته. والمرحّلةُ لا تُلغى من هنا.
     */
    public static function cancel(int $businessId, int $id): PaymobSettlement
    {
        return DB::transaction(function () use ($businessId, $id) {
            Business::whereKey($businessId)->lockForUpdate()->first();
            $settlement = PaymobSettlement::where('business_id', $businessId)->whereKey($id)->firstOrFail();

            if ($settlement->status !== self::HELD) {
                throw new RuntimeException(__('لا تُلغى إلّا تسويةٌ معلّقةٌ لم تُرحَّل.'));
            }

            $settlement->items()->update(['store_payment_intent_id' => null, 'customer_payment_link_id' => null]);
            $settlement->forceFill(['status' => self::CANCELLED])->save();

            Activity::log('updated', 'ألغى تسوية Paymob معلّقة قبل ترحيلها: إجمالي '.number_format((float) $settlement->gross, 3), [
                'subject_id' => $settlement->id, 'subject_type' => 'paymob_settlement', 'business_id' => $businessId,
            ]);

            return $settlement->fresh();
        });
    }

    /**
     * صاحبُ المتجر يقول ما حمله تحويلٌ سُجّل قبل التحديث (`LEGACY`) — من كشف
     * Paymob أو البنك، لا من تشابه مبلغ.
     *
     * لا قيدَ يُكتب: ذاك التحويلُ رُحّل يومَه. يُكتب سطرٌ يحجز المدفوعة — فلا تُعرض
     * «تنتظر» ولا تُسوّى ثانيةً — ومن قرّر وما استند إليه. ويُردّ:
     * - مدفوعةٌ دُفعت بعد ذاك التحويل: لا يمكن أن يكون حملها؛
     * - ما يتجاوز ما بقي من إجماليّه غيرَ محدَّد؛
     * - مدفوعةٌ حجزتها تسويةٌ أخرى، أو ليست من مدفوعات المتجر المقبوضة.
     *
     * فإذا حُدّد إجماليُّه كلُّه لم تعد مدفوعةٌ أخرى قبله مشتبهًا فيها بسببه: لم يبق
     * منه ما يحملها (`orOldPayout`).
     *
     * @param  list<array{type: string, id: int|string}|string>  $items
     */
    public static function attribute(int $businessId, int $id, array $items, string $note, ?int $userId = null): PaymobSettlement
    {
        self::refuseWhilePaused();
        $note = trim($note);

        if (mb_strlen($note) < 5) {
            throw new RuntimeException(__('اكتب ما استندتَ إليه — رقمَ كشف Paymob أو تاريخَه.'));
        }

        $picked = self::picked($items);

        return DB::transaction(function () use ($businessId, $id, $picked, $note, $userId) {
            Business::whereKey($businessId)->lockForUpdate()->first();
            $settlement = PaymobSettlement::where('business_id', $businessId)->whereKey($id)->firstOrFail();

            if ($settlement->status !== self::LEGACY) {
                throw new RuntimeException(__('لا يُحدَّد ما حمله إلّا تحويلٌ سُجّل قبل التحديث.'));
            }

            $rows = collect($picked)->map(function (array $p) use ($businessId, $settlement) {
                $row = self::classify($businessId, $p['type'], $p['id']);

                if (PaymobSettlementItem::where(self::column($p['type']), $p['id'])->exists()) {
                    throw new RuntimeException(__(':what سبقت تسويتُها — لا تُسوّى مدفوعةٌ مرّتين.', ['what' => $row['label']]));
                }

                if ($row['paid_ts'] !== null && $settlement->created_at !== null && Carbon::parse($row['paid_ts'])->gt($settlement->created_at)) {
                    throw new RuntimeException(__(':what دُفعت بعد ذاك التحويل — لا يمكن أن يكون حملها.', ['what' => $row['label']]));
                }

                return $row;
            });

            $open = self::unattributed((int) $settlement->id, (float) $settlement->gross);
            $sum = round((float) $rows->sum('amount'), 3);

            if ($sum > $open + 0.0005) {
                throw new RuntimeException(__('مجموعُ ما حدّدته (:s) أكبر ممّا بقي غيرَ محدَّد من ذاك التحويل (:o).', [
                    's' => number_format($sum, 3), 'o' => number_format($open, 3),
                ]));
            }

            foreach ($rows as $row) {
                self::once(fn () => PaymobSettlementItem::create([
                    'business_id' => $businessId,
                    'paymob_settlement_id' => $settlement->id,
                    self::column($row['type']) => $row['id'],
                    'source' => $row['key'],
                    'amount' => $row['amount'],
                    'booked' => self::IN_LEGACY,
                    'account_id' => null,
                    'reason' => mb_substr($note, 0, 255),
                    'decided_by' => $userId,
                ]));
            }

            Activity::log('updated', 'حدّد ما حمله تحويل Paymob سُجّل قبل التحديث: '.$rows->count().' مدفوعة بـ '.number_format($sum, 3).' — '.mb_substr($note, 0, 120), [
                'subject_id' => $settlement->id, 'subject_type' => 'paymob_settlement', 'business_id' => $businessId,
            ]);

            return $settlement->fresh();
        });
    }

    /** تراجعٌ عن تحديدٍ خاطئ: تعود المدفوعةُ مشتبهًا فيها، ويبقى القرارُ وتراجعُه في السجلّ */
    public static function unattribute(int $businessId, int $itemId): void
    {
        DB::transaction(function () use ($businessId, $itemId) {
            Business::whereKey($businessId)->lockForUpdate()->first();
            $item = PaymobSettlementItem::where('business_id', $businessId)->whereKey($itemId)->firstOrFail();

            if ($item->booked !== self::IN_LEGACY) {
                throw new RuntimeException(__('لا يُتراجع إلّا عن تحديدِ ما حمله تحويلٌ قديم.'));
            }

            $item->delete();

            Activity::log('deleted', 'تراجع عن أنّ تحويل Paymob قديمًا حمل '.$item->source.' ('.number_format((float) $item->amount, 3).') — وكان المستند: '.mb_substr((string) $item->reason, 0, 120), [
                'subject_id' => $item->paymob_settlement_id, 'subject_type' => 'paymob_settlement', 'business_id' => $businessId,
            ]);
        });
    }

    /**
     * يرحّل التسويةَ إن تأكّد كلُّ شيء، وإلّا يعلّقها ويقول لماذا.
     *
     * @param  Collection<int, array>  $rows
     */
    private static function settle(PaymobSettlement $settlement, Collection $rows, ?int $userId): void
    {
        $businessId = (int) $settlement->business_id;
        $fee = round((float) $settlement->fee, 3);
        $net = round((float) $settlement->net, 3);

        $why = $rows->where('booked', self::UNSURE)->map(fn (array $r) => $r['label'].': '.$r['reason'])->values()->all();

        if (! self::same((float) $rows->sum('amount'), $net + $fee)) {
            $why[] = __('تغيّر مبلغُ مدفوعةٍ: مجموعُها :g والصافي والرسوم :s', [
                'g' => number_format((float) $rows->sum('amount'), 3), 's' => number_format($net + $fee, 3),
            ]);
        }

        /*
         * ولا تُسوّى من التسوية أكثرُ ممّا فيها: كلُّ مدفوعةٍ «فيها» قرأ الدفترُ
         * مالَها فيها، فمجموعٌ أكبرُ من الرصيد يعني أنّ شيئًا آخر سحب منها.
         */
        $fromClearing = round((float) $rows->where('booked', self::IN_CLEARING)->sum('amount'), 3);
        $waiting = self::clearing($businessId);
        if ($fromClearing > $waiting + 0.0005) {
            $why[] = __('في التسوية :w وتقول المدفوعاتُ إنّ فيها :c', ['w' => number_format($waiting, 3), 'c' => number_format($fromClearing, 3)]);
        }

        if ($why !== []) {
            $settlement->forceFill(['status' => self::HELD, 'hold_reason' => mb_substr(implode(' · ', $why), 0, 500)])->save();

            return;
        }

        // كلُّ حسابٍ رقمٌ واحد: المدينُ موجب
        $move = [];
        $add = function (int $accountId, float $amount) use (&$move) {
            $move[$accountId] = round(($move[$accountId] ?? 0) + $amount, 3);
        };

        $add((Bank::leaf($businessId, $settlement->bank_account_id) ?? self::account($businessId, 'bank'))->id, $net);
        $add(self::account($businessId, 'payment_gateway_fees')->id, $fee);
        $add(self::account($businessId, 'paymob_clearing')->id, -$fromClearing);
        foreach ($rows->where('booked', self::IN_BANK) as $row) {
            $add((int) $row['account_id'], -$row['amount']);
        }

        $lines = collect($move)->reject(fn (float $v) => abs($v) < 0.0005)->sortDesc()
            ->map(fn (float $v, int $accountId) => [
                'account' => Account::where('business_id', $businessId)->findOrFail($accountId),
                $v > 0 ? 'debit' : 'credit' => abs($v),
            ])->values()->all();

        // تحويلٌ كلُّه مالٌ في البنك أصلًا وبلا رسوم: لا شيءَ يتحرّك، ولا قيد
        $entry = $lines === [] ? null : Ledger::post(
            $businessId,
            __('تسوية Paymob').($settlement->reference ? ' — '.$settlement->reference : ''),
            $lines,
            Carbon::parse($settlement->settled_on),
            self::SOURCE,
            null,
            $userId,
            $settlement,
        );

        $settlement->forceFill([
            'status' => self::POSTED,
            'hold_reason' => null,
            'journal_entry_id' => $entry instanceof JournalEntry ? $entry->id : null,
        ])->save();
    }

    /** @return list<array{type: string, id: int}> */
    private static function picked(array $items): array
    {
        $picked = collect($items)->map(function ($item) {
            [$type, $id] = is_array($item) ? [$item['type'] ?? '', $item['id'] ?? 0] : array_pad(explode(':', (string) $item, 2), 2, 0);

            return ['type' => (string) $type, 'id' => (int) $id];
        })->filter(fn (array $p) => in_array($p['type'], [self::INTENT, self::LINK], true) && $p['id'] > 0)
            ->unique(fn (array $p) => $p['type'].':'.$p['id'])->values()->all();

        if ($picked === []) {
            throw new RuntimeException(__('اختر مدفوعات Paymob التي حملها هذا التحويل.'));
        }

        return $picked;
    }

    private static function payoutBank(int $businessId, int|string|null $bankId): ?int
    {
        if ($bankId !== null && $bankId !== '') {
            if (! BankAccount::where('business_id', $businessId)->whereKey($bankId)->exists()) {
                throw new RuntimeException(__('هذا الحساب البنكي ليس من حسابات متجرك.'));
            }

            return (int) $bankId;
        }

        return BankAccount::where('business_id', $businessId)->where('active', true)->where('is_primary', true)->value('id');
    }

    /** أوراقُ البنك في شجرة المتجر: ورقةُ «البنك» النظاميّة، وورقةُ كلِّ حسابٍ بنكيّ */
    private static function bankLeaves(int $businessId): array
    {
        return DB::table('accounts')->where('business_id', $businessId)->where('system_key', 'bank')->pluck('id')
            ->merge(DB::table('bank_accounts')->where('business_id', $businessId)->whereNotNull('account_id')->pluck('account_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** والحسابُ يُبنى إن غاب (`Ledger::account`) — متجرٌ جديدٌ تُسوّى أوّلُ مدفوعاته بلا إعدادٍ يدويّ */
    private static function account(int $businessId, string $key): Account
    {
        return Ledger::account($businessId, $key)
            ?? throw new RuntimeException(__('حسابٌ غير موجود في الشجرة: :key', ['key' => $key]));
    }

    private static function column(string $type): string
    {
        return $type === self::INTENT ? 'store_payment_intent_id' : 'customer_payment_link_id';
    }

    private static function itemFields(array $row): array
    {
        return ['amount' => $row['amount'], 'booked' => $row['booked'], 'account_id' => $row['account_id'],
            'reason' => $row['reason'] ? mb_substr($row['reason'], 0, 255) : null];
    }

    private static function unsure(array $row, string $why): array
    {
        return $row + ['booked' => self::UNSURE, 'account_id' => null, 'reason' => $why];
    }

    private static function refuseWhilePaused(): void
    {
        if (self::paused()) {
            throw new RuntimeException(__('تسجيلُ تسويات Paymob موقوفٌ مؤقّتًا من المنصّة — لم يُكتب شيء.'));
        }
    }

    /**
     * الفهرسُ الفريدُ ردّ سطرًا: نافذةٌ أخرى حجزت المدفوعةَ للتوّ ولم يرها فحصُنا
     * قبل أن تُثبَّت. يُقال بالعربيّة، والمعاملةُ كلُّها تُرجَع — لا تسويةَ نصفيّة.
     */
    private static function once(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23505' || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new RuntimeException(__('مدفوعةٌ هنا حُجزت للتوّ من نافذةٍ أخرى — لا تُسوّى مدفوعةٌ مرّتين.'));
            }

            throw $e;
        }
    }

    private static function same(float $a, float $b): bool
    {
        return (int) round($a * 1000) === (int) round($b * 1000);
    }
}
