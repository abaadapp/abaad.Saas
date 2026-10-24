<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\PaymobSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * تسوياتُ Paymob — المالُ الذي حوّلته Paymob إلى بنك التاجر ناقصًا رسومَها.
 *
 * ═══ ولمَ يدويّة ═══
 *
 * نجاحُ الدفع لا يعني أنّ المالَ وصل البنك: يجلس في «Paymob تحت التسوية»
 * (`paymob_clearing`) حتّى تُحوّله Paymob بعد أيّام، ناقصًا رسومَها. ولا
 * واجهةَ رسميّةً موثَّقةً من Paymob عُمان تقول ما حُوِّل ومتى ورسومَه بثقة —
 * فلا يُخمَّن تحويلٌ لم يقع. يكتبه التاجر من كشف Paymob أو كشف بنكه.
 *
 * ═══ والقيد ═══
 *
 *     مدين  البنك                 الصافي
 *     مدين  رسوم بوابات الدفع     الرسوم
 *         دائن  Paymob تحت التسوية   الإجمالي
 *
 * فالربحُ يتأثّر بالرسوم وحدها — مصروفًا — لا بالتحصيل نفسه، والإيرادُ لا
 * يتحرّك.
 */
final class PaymobSettlements
{
    public const SOURCE = 'تسوية Paymob';

    /** ما في «Paymob تحت التسوية» الآن — قُبض من العملاء ولم يصل البنك */
    public static function clearing(int $businessId): float
    {
        return round(Ledger::balance($businessId, 'paymob_clearing'), 3);
    }

    /**
     * يكتب تسوية ويقيدها — والإجماليُّ صافٍ ورسوم، ولا يتجاوز ما ينتظر التسوية.
     *
     * @param  array{gross: float|string, fee: float|string, net: float|string, settled_on: string, bank_account_id?: int|string|null, reference?: ?string, notes?: ?string}  $data
     */
    public static function record(int $businessId, array $data, ?int $userId = null): PaymobSettlement
    {
        $gross = round((float) $data['gross'], 3);
        $fee = round((float) $data['fee'], 3);
        $net = round((float) $data['net'], 3);

        if ($gross <= 0 || $net <= 0 || $fee < 0) {
            throw new RuntimeException(__('الإجمالي والصافي أكبر من صفر، والرسوم لا تكون سالبة.'));
        }

        // والمقارنةُ بالبيسة لا بالكسر العشريّ: ٩٨٫٠٠٠ + ٢٫٠٠٠ قد لا تساوي ١٠٠ في الفاصلة العائمة
        if ((int) round($gross * 1000) !== (int) round(($net + $fee) * 1000)) {
            throw new RuntimeException(__('الإجمالي يجب أن يساوي الصافي + الرسوم.'));
        }

        $bankId = $data['bank_account_id'] ?? null;

        if ($bankId !== null && $bankId !== '') {
            if (! BankAccount::where('business_id', $businessId)->whereKey($bankId)->exists()) {
                throw new RuntimeException(__('هذا الحساب البنكي ليس من حسابات متجرك.'));
            }
            $bankId = (int) $bankId;
        } else {
            $bankId = BankAccount::where('business_id', $businessId)
                ->where('active', true)->where('is_primary', true)->value('id');
        }

        $on = Carbon::parse($data['settled_on'])->startOfDay();

        return DB::transaction(function () use ($businessId, $gross, $fee, $net, $bankId, $on, $data, $userId) {
            /*
             * ولا تُسوّى أكثرُ ممّا ينتظر.
             *
             * «تحت التسوية» لا تكون سالبة: مالٌ حوّلته Paymob أكثرَ ممّا قُبض
             * بروابطها وبيعاتِ الموقع بالبطاقة (`Books::recordSale`) إمّا خطأُ
             * كتابة، وإمّا مالُ بيعاتِ موقعٍ رُحّلت إلى البنك قبل أن تُرحَّل إلى
             * التسوية (#168) — تلك في البنك أصلًا. فيُردّ ويُقال الرقم.
             */
            // ونافذتان تسجّلان في اللحظة نفسها لا تقرآن الرصيدَ نفسه — يُقفل المتجر
            Business::whereKey($businessId)->lockForUpdate()->first();
            $waiting = self::clearing($businessId);

            if ($gross > $waiting + 0.0005) {
                throw new RuntimeException(__('الإجمالي (:g) أكبر ممّا ينتظر التسوية في Paymob (:w).', [
                    'g' => number_format($gross, 3), 'w' => number_format($waiting, 3),
                ]));
            }

            $settlement = PaymobSettlement::create([
                'business_id' => $businessId,
                'bank_account_id' => $bankId,
                'gross' => $gross,
                'fee' => $fee,
                'net' => $net,
                'settled_on' => $on->toDateString(),
                'reference' => filled($data['reference'] ?? null) ? mb_substr(trim((string) $data['reference']), 0, 80) : null,
                'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 500) : null,
                'created_by' => $userId,
            ]);

            $lines = [['account' => Bank::leaf($businessId, $bankId) ?? 'bank', 'debit' => $net]];
            if ($fee > 0) {
                $lines[] = ['account' => 'payment_gateway_fees', 'debit' => $fee];
            }
            $lines[] = ['account' => 'paymob_clearing', 'credit' => $gross];

            $entry = Ledger::post(
                $businessId,
                __('تسوية Paymob').($settlement->reference ? ' — '.$settlement->reference : ''),
                $lines,
                $on,
                self::SOURCE,
                null,
                $userId,
                $settlement,
            );

            $settlement->forceFill(['journal_entry_id' => $entry instanceof JournalEntry ? $entry->id : null])->save();

            Activity::log('created', 'سجّل تسوية Paymob: إجمالي '.number_format($gross, 3).' — رسوم '.number_format($fee, 3).' — صافي '.number_format($net, 3), [
                'subject_id' => $settlement->id, 'subject_type' => 'paymob_settlement', 'business_id' => $businessId,
            ]);

            return $settlement;
        });
    }
}
