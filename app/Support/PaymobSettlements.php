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

    /* ═══════════ أين مالُ المدفوعة ═══════════ */

    public const IN_CLEARING = 'clearing';

    public const IN_BANK = 'bank';

    public const UNSURE = 'unknown';

    /* ═══════════ نوعُ المدفوعة ═══════════ */

    /** دفعٌ من الموقع — `StorePaymentIntent` */
    public const INTENT = 'intent';

    /** رابطُ دفع — `CustomerPaymentLink` */
    public const LINK = 'link';

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
        $open = fn (string $table, string $column) => DB::table($table.' as p')
            ->where('p.business_id', $businessId)
            ->where('p.status', 'paid')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('paymob_settlement_items as s')->whereColumn('s.'.$column, 'p.id'))
            ->orderBy('p.paid_at')->orderBy('p.id')->limit($limit)->pluck('p.id');

        return collect()
            ->merge($open('store_payment_intents', 'store_payment_intent_id')->map(fn ($id) => self::classify($businessId, self::INTENT, (int) $id)))
            ->merge($open('customer_payment_links', 'customer_payment_link_id')->map(fn ($id) => self::classify($businessId, self::LINK, (int) $id)))
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
        ];

        $why = match (true) {
            $intent->refund_status !== null => __('طُلب ردُّها عند Paymob'),
            ! $order => __('مالٌ قُبض في Paymob ولا طلبَ له في المتجر'),
            (string) $order->status === OrderStatus::CANCELLED => __('الطلب ملغى'),
            default => null,
        };

        if ($why) {
            return self::unsure($row, $why);
        }

        /*
         * وتصحيحُ الطلب بعد الدفع يُقيَّد على حركةٍ (`Transaction`) لا على الطلب
         * نفسه (`OrderCorrection::settlementRow`) — فتُقرأ قيودُها معه. وفرقٌ
         * رُدّ بالبطاقة ردّته Paymob من رصيدها فنقص به تحويلُها: ما ينتظر من
         * هذه المدفوعة مبلغُها ناقصًا ما رُدّ.
         */
        $moves = DB::table('transactions')->where('business_id', $businessId)->where('order_id', $order->id)->whereNull('deleted_at');
        $refunded = round((float) (clone $moves)->where('kind', Transaction::ORDER_REFUND)->where('method', PaymentMethods::CARD)->sum('amount'), 3);

        return self::whereIs($businessId, [Order::class => [(int) $order->id], Transaction::class => $moves->pluck('id')->map(fn ($id) => (int) $id)->all()], $row, $refunded);
    }

    private static function classifyLink(int $businessId, int $id): array
    {
        $link = DB::table('customer_payment_links')->where('business_id', $businessId)->where('id', $id)
            ->where('status', CustomerPaymentLink::PAID)->first();

        if (! $link) {
            throw new RuntimeException(__('مدفوعةٌ ليست من مدفوعات Paymob المقبوضة في متجرك.'));
        }

        $payment = $link->customer_payment_id
            ? DB::table('customer_payments')->where('business_id', $businessId)->where('id', $link->customer_payment_id)->first(['id', 'number', 'amount', 'cancelled_at'])
            : null;

        $row = [
            'key' => self::LINK.':'.$id, 'type' => self::LINK, 'id' => $id,
            'amount' => round((float) ($payment->amount ?? $link->amount), 3),
            'label' => $payment ? __('رابط دفع — تحصيل :n', ['n' => $payment->number]) : __('رابط دفع'),
            'paid_at' => $link->paid_at ? substr((string) $link->paid_at, 0, 10) : null,
        ];

        $why = match (true) {
            ! $payment => __('دُفع الرابط ولم يُسجَّل تحصيلُه'),
            $payment->cancelled_at !== null => __('التحصيل ملغى'),
            default => null,
        };

        return $why ? self::unsure($row, $why) : self::whereIs($businessId, [CustomerPayment::class => [(int) $payment->id]], $row);
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
     * يكتب تسويةً لمدفوعاتٍ بعينها ويقيدها — أو يحفظها معلّقةً إن لم يتأكّد
     * أين مالُ إحداها.
     *
     * @param  array{items: list<array{type: string, id: int|string}|string>, fee: float|string, net: float|string, settled_on: string, bank_account_id?: int|string|null, reference?: ?string, notes?: ?string}  $data
     */
    public static function record(int $businessId, array $data, ?int $userId = null): PaymobSettlement
    {
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
                PaymobSettlementItem::create([
                    'business_id' => $businessId,
                    'paymob_settlement_id' => $settlement->id,
                    self::column($row['type']) => $row['id'],
                    'source' => $row['key'],
                ] + self::itemFields($row));
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
                    $row = self::unsure(['key' => $item->source, 'type' => $type, 'id' => (int) $sid,
                        'amount' => (float) $item->amount, 'label' => $item->source, 'paid_at' => null], __('لم تعد مدفوعةً مقبوضة'));
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

    private static function account(int $businessId, string $key): Account
    {
        return Account::where('business_id', $businessId)->where('system_key', $key)->firstOrFail();
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

    private static function same(float $a, float $b): bool
    {
        return (int) round($a * 1000) === (int) round($b * 1000);
    }
}
