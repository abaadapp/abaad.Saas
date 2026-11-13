<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\OrderItem;
use App\Models\PointTransaction;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * نقاطُ بيعة الصندوق حين يُرجَع بعضُ بنودها — تعود بنصيب المرتجَع.
 *
 * ═══ ما كان ═══
 *
 * تصحيحُ البيعة يُبقي الخصمَ كما اتُّفق عليه ولا يردّ نقطة: صنفان بمئةٍ
 * ونقاطٌ بعشرين، يُرجَع أحدُهما فيبقى العشرون على الباقي. وعشرةُ أصنافٍ بعشرة
 * يُرجَع تسعةٌ منها فينقص الخصمُ إلى عشرة (لا يتجاوز الباقي) — وتضيع على
 * الزبون قيمةُ عشرةٍ من نقاطه بلا شيء يقابلها.
 *
 * ═══ وما صار — بقرار المالك (المراجعة السادسة) ═══
 *
 * يُقاس بما سُجّل وقت البيع (`orders.loyalty_basis`): النقاطُ المصروفة P،
 * وخصمُها L، ومجموعُ البنود G₀. فإذا صار المجموعُ G₁:
 *
 *   - الخصمُ الباقي = ⌊L × G₁ ÷ G₀⌋ بالبيسة، ولا يتجاوز ما بقي بعد الكوبون.
 *   - النقاطُ الباقية = ⌈P × الخصمِ الباقي ÷ L⌉ — والفرقُ يعود إلى رصيده.
 *
 * فالنقاطُ تُقصّ لصالح الدفتر والخصمُ يُقصّ لصالحه كذلك: لا يعود للزبون نقطةً
 * ونقدًا أكثرُ من ثمن ما أرجعه، ولا يعود من النقاط أكثرُ ممّا صرف. وما يُرجَع
 * على دفعاتٍ يُحسب من الأصل كلَّ مرّة — فالمجموعُ واحدٌ أُرجع مرّةً أو مرّات.
 * وصنفٌ يُضاف بعد إرجاعٍ لا يُعيد صرفَ نقطةٍ رُدّت: يُدفع ثمنُه نقدًا.
 *
 * ومثالٌ: ٢ × ١٠٠ بـ٢٠٠٠ نقطة (٢٠): يُرجَع واحدٌ ← يعود ١٠٠٠ نقطة، والباقي ٩٠،
 * ويُردّ ٩٠ نقدًا — مجموعُ ما عاد ١٠٠، ثمنُ ما أُرجع بالضبط.
 *
 * ═══ والبيعةُ القديمة ═══
 *
 * ما بيع قبل هذا بلا لقطة: إن كانت بنودُه كما بيعت قُرئت لقطتُه من صفّه
 * وكُتبت عند أوّل تصحيح (`pin`). وإن عُدّلت قبلُ فلا يُعرف ما كان — فيُرفض
 * تعديلُ بنودها برسالةٍ ولا يُخمَّن، ويبقى إلغاؤها كاملةً يردّ نقاطَها كلَّها.
 */
final class TillRedemption
{
    /** أنواعُ التصحيح التي تُغيّر البنودَ أو مبالغَها */
    private const LINE_EDITS = [OrderEdit::LINE, OrderEdit::ADDON, OrderEdit::ADD_LINE, OrderEdit::REPLACE_LINE];

    /**
     * لقطةُ البيعة كما تُكتب عند البيع.
     *
     * @return array{points: int, discount_baisa: int, gross_baisa: int}|null
     */
    public static function atSale(int $points, float $loyaltyDiscount, float $gross): ?array
    {
        if ($points <= 0) {
            return null;
        }

        return [
            'points' => $points,
            'discount_baisa' => Baisa::of($loyaltyDiscount),
            'gross_baisa' => Baisa::of($gross),
        ];
    }

    /**
     * لقطةُ هذه البيعة: المكتوبة، أو المقروءةُ من بيعةٍ قديمةٍ لم تُعدَّل بنودُها.
     *
     * @return array{points: int, discount_baisa: int, gross_baisa: int}|null فراغٌ = لا نقاط، أو لا يُعرف
     */
    public static function basis(Order $order, ?Collection $items = null): ?array
    {
        $stored = $order->loyalty_basis;

        if (is_array($stored) && isset($stored['points'], $stored['discount_baisa'], $stored['gross_baisa'])) {
            return [
                'points' => (int) $stored['points'],
                'discount_baisa' => (int) $stored['discount_baisa'],
                'gross_baisa' => (int) $stored['gross_baisa'],
            ];
        }

        if ((int) $order->redeemed_points <= 0 || self::linesEdited($order)) {
            return null;
        }

        return self::atSale((int) $order->redeemed_points, $order->loyaltyDiscount(), Baisa::toMoney(self::grossOf($items ?? $order->items()->get())));
    }

    /** أتُعرف نقاطُ هذه البيعة بما يكفي لتعديل بنودها؟ — بلا نقاطٍ لا سؤال */
    public static function traceable(Order $order): bool
    {
        return (int) $order->redeemed_points <= 0 || self::basis($order) !== null;
    }

    /**
     * تُكتب لقطةُ البيعة القديمة قبل أن يتغيّر فيها بند — تحت قفل الطلب.
     *
     * @throws RuntimeException برسالةٍ تُعرض للكاشير كما هي
     */
    public static function pin(Order $order): void
    {
        if ((int) $order->redeemed_points <= 0 || is_array($order->loyalty_basis)) {
            return;
        }

        $basis = self::basis($order);

        if ($basis === null) {
            throw new RuntimeException(self::refusal());
        }

        $order->forceFill(['loyalty_basis' => $basis])->save();
    }

    public static function refusal(): string
    {
        return __('عُدّلت بنود هذه البيعة قبل أن يحفظ النظام ما اشترته نقاطها، فلا يُعرف كم يعود منها بإرجاع بعض البنود. لا تُعدَّل بنودها — ويبقى إلغاؤها كاملةً، فتعود نقاطها كلّها.');
    }

    /**
     * ما يبقى من خصم النقاط ومن النقاط على مجموعٍ جديد.
     *
     * @param  array{points: int, discount_baisa: int, gross_baisa: int}  $basis
     * @param  int  $grossB  مجموعُ البنود الآن بالبيسات
     * @param  int  $couponB  خصمُ الكوبون على البيعة بالبيسات — يُخصم أوّلًا
     * @param  int  $nowPoints  النقاطُ المصروفة الآن (بعد ما رُدّ قبلُ)
     * @return array{discount: int, points: int} الخصمُ بالبيسات
     */
    public static function kept(array $basis, int $grossB, int $couponB, int $nowPoints): array
    {
        $p = $basis['points'];
        $l = $basis['discount_baisa'];
        $g0 = $basis['gross_baisa'];
        $g = $g0 > 0 ? min($grossB, $g0) : $grossB;
        $room = max(0, $grossB - $couponB);

        $share = $g0 > 0 ? BigInteger::of($l)->multipliedBy($g)->quotient($g0)->toInt() : $l;
        $discount = min($share, $room);

        $points = $l > 0
            ? BigInteger::of($p)->multipliedBy($discount)->dividedBy($l, RoundingMode::Ceiling)->toInt()
            : ($g0 > 0 ? BigInteger::of($p)->multipliedBy($g)->dividedBy($g0, RoundingMode::Ceiling)->toInt() : $p);

        // وما رُدّ لا يُصرف ثانيةً: الخصمُ لا يتجاوز ما تشتريه النقاطُ الباقية
        if ($points > $nowPoints) {
            $points = max(0, $nowPoints);
            $discount = $p > 0 ? min($discount, BigInteger::of($l)->multipliedBy($points)->quotient($p)->toInt()) : 0;
        }

        return ['discount' => $discount, 'points' => min($p, $points)];
    }

    /**
     * يردّ إلى رصيد الزبون ما نقص من نقاط البيعة — مرّةً واحدة.
     *
     * والمفتاحُ الفريد (`source_key`) من الطلب ومن الرصيدين قبل وبعد: تصحيحٌ
     * يُعاد بالحالة نفسها تردّه القاعدةُ ولا تُكتب حركتان. وهو تحت قفل الطلب
     * (`OrderCorrection::lockOrder`) فتصحيحان متزامنان يصطفّان.
     */
    public static function giveBack(Order $order, int $before): void
    {
        $after = (int) $order->redeemed_points;
        $back = $before - $after;

        if ($back <= 0) {
            return;
        }

        $customer = $order->customer_id ? Customer::whereKey($order->customer_id)->lockForUpdate()->first() : null;

        if (! $customer) {
            return;
        }

        $customer->increment('points', $back);

        PointTransaction::create([
            'business_id' => $customer->business_id,
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'type' => 'earn',
            'points' => $back,
            'balance_after' => (int) $customer->fresh()->points,
            'note' => 'ردّ نقاط بنودٍ أُرجعت من الفاتورة '.$order->number,
            'source_key' => 'till-return:'.$order->id.':'.$before.'-'.$after,
        ]);
    }

    /** @param  Collection<int, OrderItem>  $items */
    public static function grossOf(Collection $items): int
    {
        return (int) $items->sum(fn ($i) => Baisa::of((float) $i->price) * (int) $i->quantity + Baisa::of((float) $i->addons_total));
    }

    private static function linesEdited(Order $order): bool
    {
        return OrderEdit::where('order_id', $order->id)->whereIn('kind', self::LINE_EDITS)->exists();
    }
}
