<?php

namespace App\Support;

use App\Models\Boutique;
use App\Models\BoutiquePriceAgreement;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * سياسةُ البوتيك المقترحة — مكتوبةٌ ومطفأةٌ حتّى يُكتب لمتجرٍ يومُها.
 *
 * ═══ ما تغيّره، لما يُباع بعد يومها وحده ═══
 *
 *   ١. **السعرُ المتّفق عليه** (`boutique_price_agreements`): حصّةُ البوتيك منه — لا ممّا كُتب
 *      في الفاتورة. فالخصمُ والكوبونُ والنقاطُ وتخفيضُ السعر على المتجر، وما زاد عليه للمتجر.
 *      ويُحفظ على البند ساعةَ البيع (`boutique_agreed_price`). وصنفٌ بلا اتّفاقٍ سارٍ يُحسب
 *      بالقاعدة القائمة — لا يُخمَّن له سعر — ويُعلَّم (`boutiques:policy`).
 *   ٢. **انتظارُ التحصيل في كلّ باب**: بندُ الصندوق والموقع (`boutique_policy`) وقطعُه
 *      (`waits`) لا تحملها ورقةٌ قبل أن يكتمل تحصيلُ طلبها — كفاتورة العميل. وفاتورةُ الشهر
 *      تنتظر اكتمالها كلِّها، لا يُطفئ المدفوعُ منها أقدمَ الطلبات.
 *   ٣. **تحصيلٌ أُلغي بعد الورقة** يوقف سدادَ ما تأثّر وحده (`BoutiqueHolds`).
 *
 * ═══ ولا يكتب يومَها بابٌ في النظام ═══
 *
 * `businesses.boutique_policy_from` فارغٌ للجميع. يُكتب بعد اعتماد المالك والمحاسب وحده، ومعه
 * أسعارُ الاتّفاق لكلّ صنف. ولا يُعاد به حسابُ ما مضى.
 */
final class BoutiquePolicy
{
    /** بندٌ بيع بالسياسة: تنتظر حصّتُه تحصيلَ بيعه */
    public const COLLECTED = 'collected';

    /** أتسري السياسةُ على هذا المتجر في هذا الوقت؟ */
    public static function on(int $businessId, ?Carbon $at = null): bool
    {
        $from = DB::table('businesses')->where('id', $businessId)->value('boutique_policy_from');

        return $from !== null && Carbon::parse($from)->startOfDay()->lte($at ?? now());
    }

    /**
     * السعرُ المتّفق عليه لصنفٍ (أو مقاسه) يومَ البيع — أو `null`: لا اتّفاقَ سارٍ.
     *
     * والأخصُّ يغلب: المقاسُ قبل الصنف، والعرضُ الذي له نهايةٌ قبل السعر الدائم، ثمّ الأحدثُ سريانًا.
     */
    public static function agreed(int $businessId, ?Product $product, ?int $variantId, ?Carbon $at = null): ?float
    {
        if ($product === null || $product->boutique_id === null || ! self::on($businessId, $at)) {
            return null;
        }

        $day = ($at ?? now())->toDateString();
        $row = BoutiquePriceAgreement::where('business_id', $businessId)->where('product_id', $product->id)
            ->where('boutique_id', $product->boutique_id)
            ->where(fn ($q) => $q->whereNull('variant_id')->when($variantId, fn ($w) => $w->orWhere('variant_id', $variantId)))
            ->where('starts_on', '<=', $day)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day))
            ->orderByRaw('CASE WHEN variant_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN ends_on IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('starts_on')->orderByDesc('id')->first();

        return $row === null ? null : round((float) $row->price, 3);
    }

    /**
     * يُسجَّل سعرٌ متّفقٌ عليه — سطرٌ جديد بتاريخه ومرجعه، لا تعديلٌ لما قبله ولا لسعر البطاقة.
     *
     * @param  array{product_id: int, variant_id?: ?int, price: float|string, starts_on: string, ends_on?: ?string, reference: string}  $data
     */
    public static function record(int $businessId, Boutique $boutique, array $data, ?int $userId = null): BoutiquePriceAgreement
    {
        $product = Product::where('business_id', $businessId)->where('boutique_id', $boutique->id)->find((int) $data['product_id']);
        if ($product === null) {
            throw ValidationException::withMessages(['product_id' => __('هذا الصنف ليس لهذا البوتيك.')]);
        }
        if ((float) $data['price'] <= 0) {
            throw ValidationException::withMessages(['price' => __('السعر المتفق عليه أكبر من صفر.')]);
        }
        if (! empty($data['ends_on']) && $data['ends_on'] < $data['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => __('نهاية العرض بعد بدايته.')]);
        }

        $row = BoutiquePriceAgreement::create([
            'business_id' => $businessId,
            'boutique_id' => $boutique->id,
            'product_id' => $product->id,
            'variant_id' => $data['variant_id'] ?? null,
            'price' => round((float) $data['price'], 3),
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'reference' => mb_substr(trim((string) $data['reference']), 0, 255),
            'created_by' => $userId,
        ]);

        Activity::log('created', 'سعر متفق عليه لـ«'.$product->name.'» من '.$row->starts_on->toDateString().' بمبلغ '.$row->price.' — '.$row->reference, [
            'business_id' => $businessId, 'subject_id' => $boutique->id, 'subject_type' => 'boutique',
        ]);

        return $row;
    }

    /**
     * «اكتمل تحصيلُ هذا الطلب» بلغة القاعدة — للطلب في `$alias`.
     *
     * مدفوعٌ ولا باقيَ عليه، أو آجلٌ اكتملت فاتورتُه (الشيكُ حين يُصرف، كفاتورة العميل:
     * `BoutiqueInvoiceDues::collectedSql`). وفاتورةُ الشهر تُسأل كلُّها: لا يكفي أن يُغطّي
     * المدفوعُ منها هذا الطلب.
     */
    public static function orderCollectedSql(string $alias = 'orders'): string
    {
        $cancelled = Order::CANCELLED;

        return "({$alias}.status <> '{$cancelled}' AND ("
            ."({$alias}.payment_status <> 'غير مدفوع' AND COALESCE({$alias}.balance_due, 0) <= 0.0005)"
            .' OR EXISTS (SELECT 1 FROM customer_invoice_orders cio JOIN customer_invoices ON customer_invoices.id = cio.customer_invoice_id'
            ." WHERE cio.order_id = {$alias}.id AND ".BoutiqueInvoiceDues::collectedSql().')))';
    }

    public static function orderCollected(int $orderId): bool
    {
        return Order::whereKey($orderId)->whereRaw(self::orderCollectedSql())->exists();
    }

    /** فاتورةُ العميل التي تغطّي هذا الطلب — بها يُعلَّق ما حملته ورقةٌ ثمّ أُلغي تحصيلُه */
    public static function invoiceOf(int $orderId): ?int
    {
        $id = DB::table('customer_invoice_orders as cio')->join('customer_invoices as ci', 'ci.id', '=', 'cio.customer_invoice_id')
            ->where('cio.order_id', $orderId)->where('ci.status', CustomerInvoice::ISSUED)->orderByDesc('ci.id')->value('ci.id');

        return $id === null ? null : (int) $id;
    }
}
