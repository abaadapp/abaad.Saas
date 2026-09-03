<?php

namespace App\Support;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\OrderItem;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * تصحيح فاتورةٍ بيعت — البابُ الوحيد الذي تُعدَّل منه بنودها.
 *
 * البيعة تكتب سبعة أشياء مترابطة: الفاتورة وبنودها، ومخزون الفرع، وحركة
 * المخزون، والمعاملة المالية، ونقاط العميل. وتعديلُ بندٍ لا يعني تغيير
 * كميّةٍ في صفّ: يعني إعادةَ هذه السبعة كلِّها إلى ما كانت ستكون عليه لو
 * أُدخلت البيعة صحيحةً من أوّلها.
 *
 * ولذلك بابٌ واحد لا شاشة تكتب بنفسها: شاشةٌ تُنقص كميّةً وتنسى المخزون
 * تجعل الرفّ يقول رقمًا والنظام يقول غيره — وهو عطبٌ لا يظهر إلا في الجرد
 * بعد شهر، ولا يُعرف حينها من أين جاء.
 */
class OrderCorrection
{
    /**
     * يُغيّر كميّة بندٍ في فاتورة — والصفر يحذفه.
     *
     * @throws RuntimeException برسالةٍ تُعرض للكاشير كما هي
     */
    public static function setQuantity(Order $order, OrderItem $item, int $newQty, string $reason): OrderEdit
    {
        if ($item->order_id !== $order->id) {
            throw new RuntimeException(__('هذا البند ليس من هذه الفاتورة.'));
        }

        if ($newQty < 0) {
            throw new RuntimeException(__('الكمية لا تكون سالبة.'));
        }

        $oldQty = (int) $item->quantity;

        if ($newQty === $oldQty) {
            throw new RuntimeException(__('لم تتغيّر الكمية.'));
        }

        /*
         * ولا تُفرَّغ الفاتورة من بنودها.
         *
         * حذفُ آخر بندٍ إلغاءٌ باسمٍ آخر: تبقى فاتورةٌ بإجماليّ صفر في سجلّ
         * المبيعات، لا هي بيعةٌ ولا هي ملغاة. ومن أراد إلغاءها فذلك فعلٌ
         * آخر له بابه.
         */
        if ($newQty === 0 && $order->items()->count() <= 1) {
            throw new RuntimeException(__('لا يمكن حذف آخر بند — الفاتورة لا تبقى بلا أصناف.'));
        }

        return DB::transaction(function () use ($order, $item, $oldQty, $newQty, $reason) {
            $bid = (int) $order->business_id;
            $totalBefore = (float) $order->total;
            $itemName = $item->name;
            $itemId = $item->id;

            self::moveStock($order, $item, $oldQty - $newQty);

            if ($newQty === 0) {
                $item->delete();
            } else {
                $item->update(['quantity' => $newQty, 'total' => round((float) $item->price * $newQty, 3)]);
            }

            self::recompute($order->fresh('items'));
            $order->refresh();

            self::syncTransaction($order);
            self::repost($order, 'تصحيح كمية: '.$itemName);
            self::syncLoyalty($order);

            $edit = OrderEdit::create([
                'business_id' => $bid,
                'order_id' => $order->id,
                'order_item_id' => $newQty === 0 ? null : $itemId,
                'kind' => OrderEdit::LINE,
                'subject' => $itemName,
                'qty_before' => $oldQty,
                'qty_after' => $newQty,
                'order_total_before' => $totalBefore,
                'order_total_after' => (float) $order->total,
                'reason' => $reason,
                'user_id' => PosCashier::id() ?? auth()->id(),
                'employee_name' => PosCashier::name() ?? auth()->user()?->name,
            ]);

            Activity::log('updated', $newQty === 0
                ? 'حذف بند «'.$itemName.'» من الفاتورة '.$order->number.' — '.$reason
                : 'عدّل كمية «'.$itemName.'» في الفاتورة '.$order->number.' من '.$oldQty.' إلى '.$newQty.' — '.$reason,
                ['subject_id' => $order->id, 'subject_type' => 'order']);

            return $edit;
        });
    }

    /**
     * يردّ إلى الرفّ ما لم يُبَع — أو يأخذ منه ما زاد.
     *
     * `$delta` موجبٌ حين تنقص الكمية المباعة: ذاك ما يعود. وسالبٌ حين تزيد،
     * فيُفحص المتاح قبل أن يُخصم — وإلّا صار التصحيحُ بابًا يتجاوز حدَّ
     * المخزون الذي يُغلق عند البيع.
     */
    private static function moveStock(Order $order, OrderItem $item, int $delta): void
    {
        if ($delta === 0 || ! $item->product_id) {
            return;
        }

        $product = Product::where('business_id', $order->business_id)->lockForUpdate()->find($item->product_id);
        if (! $product) {
            return;
        }

        $branchId = $order->branch_id;

        // الزيادة تمرّ بحارس المخزون نفسه الذي يحرس البيع — وإلّا صار
        // التصحيح بابًا خلفيًّا يتجاوز الحدّ الذي يُغلق عند نقطة البيع
        if ($delta < 0) {
            $allowsNegative = (string) (\App\Models\Setting::where('business_id', $order->business_id)
                ->where('key', 'allow_negative_stock')->value('value') ?? '0') === '1';

            if (! $allowsNegative) {
                $resolve = Stock::availabilityResolver(
                    (int) $order->business_id, $branchId, [$product->id], lock: true,
                );
                $available = $resolve($product->id, (int) $product->quantity);

                if ($available < abs($delta)) {
                    throw new RuntimeException(__(':name — المتوفر :have والمطلوب :want', [
                        'name' => $product->name, 'have' => $available, 'want' => abs($delta),
                    ]));
                }
            }
        }

        /*
         * التوزيع قبل التغيير لا بعده.
         *
         * `ensureAllocated` تُعطى كميّةَ ما قبل الحركة، لأنها تنقل رصيد
         * المنتج غير الموزَّع إلى الفرع الأوّل. وكانت تُنادى بعد `increment`
         * فتُعطى الكمية الجديدة: فصنفٌ لا صفَّ فرعٍ له يُعاد منه اثنان،
         * فيُنشأ صفُّه بالكمية الجديدة ثمّ يُضاف الاثنان ثانيةً — ويصير في
         * الفروع ما ليس في الإجماليّ.
         *
         * وهو الترتيب نفسه في البيع والاستلام وإشعار التسليم.
         */
        if ($branchId) {
            \App\Models\BranchStock::ensureAllocated($order->business_id, $product->id, (int) $product->quantity);
        }

        $product->increment('quantity', $delta);

        if ($branchId) {
            \App\Models\BranchStock::adjust($order->business_id, $branchId, $product->id, $delta);
        }

        InventoryMovement::create([
            'business_id' => $order->business_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'type' => 'تعديل فاتورة',
            'quantity' => ($delta > 0 ? '+' : '').$delta,
            'employee_name' => PosCashier::name() ?? auth()->user()?->name,
        ]);
    }

    /**
     * يُعيد حساب الفاتورة من بنودها الباقية — بالمعادلة نفسها التي بيعت بها.
     *
     * الخصم يبقى كما اتُّفق عليه إلّا أن يتجاوز المجموع الجديد فيُقصّ إليه:
     * كوبونٌ بعشرة على سلّةٍ صارت بثمانية لا يجعل الفاتورة سالبة. والضريبة
     * تُحتسب سطرًا سطرًا بنسبة كل صنف كما في البيع، لا بنسبةٍ واحدة.
     */
    private static function recompute(Order $order): void
    {
        $bid = (int) $order->business_id;
        $items = $order->items;

        $gross = round($items->sum(fn ($i) => (float) $i->price * (int) $i->quantity), 3);
        $discount = round(min((float) $order->discount, $gross), 3);
        $couponDiscount = round(min((float) $order->coupon_discount, $discount), 3);

        $tax = 0.0;
        if (Vat::enabled($bid) && $gross > 0) {
            $inclusive = Vat::inclusive($bid);
            $products = Product::whereIn('id', $items->pluck('product_id')->filter())->get()->keyBy('id');

            foreach ($items as $i) {
                $net = (float) $i->price * (int) $i->quantity;
                $taxable = $net - ($discount * ($net / $gross));
                $rate = Vat::rateFor($products->get($i->product_id), $bid);
                $tax += $inclusive ? ($taxable * $rate) / (100 + $rate) : ($taxable * $rate) / 100;
            }
        }
        $tax = round($tax, 3);

        // «مشمولة»: المعروض هو المستحقّ، فالمجموع الفرعيّ يُنقص منه ما استُخرج
        $subtotal = Vat::inclusive($bid) ? round($gross - $tax, 3) : $gross;

        $order->update([
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon_discount' => $couponDiscount,
            'tax' => $tax,
            'total' => round($subtotal - $discount + $tax + (float) $order->delivery_fee, 3),
        ]);
    }

    /**
     * تصحيح وسيلة الدفع — خطأٌ شائع كخطأ الكميّة، وأثره في الدرج لا في الرفّ.
     *
     * يضغط الكاشير «نقدي» والزبون دفع بالبطاقة، فيُنتظر في الدرج مالٌ لم
     * يدخله ويظهر النقص عند الإقفال بلا سبب — أو العكس فيبدو الدرج زائدًا.
     * والوردية المفتوحة تُعيد حساب المتوقَّع فورًا لأنها تقرأ الفواتير حيّةً؛
     * والمقفلة تبقى على أرقامها المجمَّدة عمدًا: عدُّ الدرج وقع يومها فعلًا،
     * وتغييره بأثرٍ رجعيّ يجعل سجلّ الوردية يكذب على قارئه.
     *
     * @throws RuntimeException برسالةٍ تُعرض للكاشير كما هي
     */
    public static function setPaymentMethod(Order $order, string $method, string $reason): OrderEdit
    {
        $allowed = \App\Http\Controllers\Pos\PosController::enabledPaymentMethods(
            \App\Models\Setting::where('business_id', $order->business_id)->pluck('value', 'key')->all(),
        );

        // ولا تُصحَّح إلى وسيلةٍ أطفأها التاجر — الباب المغلق مغلقٌ من الجهتين
        if (! in_array($method, $allowed, true)) {
            throw new RuntimeException(__('وسيلة دفع غير مأذون بها في هذا المتجر.'));
        }

        $before = (string) $order->payment_method;

        if ($before === $method) {
            throw new RuntimeException(__('وسيلة الدفع لم تتغيّر.'));
        }

        return DB::transaction(function () use ($order, $before, $method, $reason) {
            $order->update(['payment_method' => $method]);
            Transaction::where('order_id', $order->id)->update(['method' => $method]);

            /*
             * والوسيلة تغيّر الحساب المدين: نقدًا يدخل الصندوق، وبطاقةً يدخل
             * البنك. فقيدٌ رُحّل على الصندوق وبيعتُه كانت بالبطاقة يترك الدرجَ
             * زائدًا في الدفتر والبنكَ ناقصًا — وهو العطب نفسه الذي يُصحَّح
             * هنا في الدرج، مكرَّرًا في الأستاذ.
             */
            self::repost($order, 'تصحيح وسيلة الدفع إلى '.$method);

            $edit = OrderEdit::create([
                'business_id' => (int) $order->business_id,
                'order_id' => $order->id,
                'kind' => OrderEdit::PAYMENT,
                'subject' => __('وسيلة الدفع'),
                'value_before' => $before,
                'value_after' => $method,
                // الإجمالي لا يتغيّر بتغيّر وسيلة الدفع — ويُقيَّد ليُقرأ السطر وحده
                'order_total_before' => (float) $order->total,
                'order_total_after' => (float) $order->total,
                'reason' => $reason,
                'user_id' => PosCashier::id() ?? auth()->id(),
                'employee_name' => PosCashier::name() ?? auth()->user()?->name,
            ]);

            Activity::log('updated', 'صحّح وسيلة الدفع في الفاتورة '.$order->number
                .' من «'.$before.'» إلى «'.$method.'» — '.$reason,
                ['subject_id' => $order->id, 'subject_type' => 'order']);

            return $edit;
        });
    }

    /** المعاملة المالية تتبع الفاتورة — رقمٌ في المالية لا يقابله بيعٌ يضلّل التقرير */
    private static function syncTransaction(Order $order): void
    {
        Transaction::where('order_id', $order->id)->update([
            'amount' => (float) $order->total,
            'tax_amount' => (float) $order->tax,
        ]);
    }

    /**
     * وقيدُ الدفتر يتبعها كذلك — بعكسٍ ثمّ ترحيلٍ جديد لا بتعديلٍ في مكانه.
     *
     * صفُّ `transactions` يُكتب فوقه لأنه سجلٌّ تشغيليّ: يقول «كم في الدرج
     * الآن». وقيدُ الأستاذ لا يُكتب فوقه: هو سجلُّ ما وقع، ومن قرأ ميزان
     * المراجعة أمس يجب أن يجد اليوم ما يفسّر اختلافه — قيدًا عكسيًّا بتاريخه
     * وسببه، لا رقمًا تبدّل بلا أثر.
     *
     * ولا يُحبس التصحيح إن تعثّر الدفتر: الفاتورة صُحّحت والمخزون عاد، ورفضُ
     * ذلك كلّه لأنّ حسابًا في الشجرة أُغلق يترك الرفّ يقول رقمًا والنظام يقول
     * غيره. فيُكتب التعثّر في السجلّ ويُستدرَك.
     */
    private static function repost(Order $order, string $reason): void
    {
        Books::tryRepostSale($order->fresh(), PosCashier::id() ?? auth()->id(), $reason);
    }

    /**
     * النقاط المكتسبة تتبع الإجمالي الجديد.
     *
     * وتُصحَّح بالفرق لا بالمحو: العميل قد يكون أنفق نقاطه بين البيعة
     * والتصحيح، فطرحُ ما اكتسبه كاملًا يُنقصه ما لم يأخذه. والنقاط
     * المستبدَلة لا تُمسّ — تلك دفعةٌ وقعت.
     */
    private static function syncLoyalty(Order $order): void
    {
        $customer = $order->customer_id ? \App\Models\Customer::find($order->customer_id) : null;
        if (! $customer) {
            return;
        }

        $rate = (float) (\App\Models\Setting::where('business_id', $order->business_id)
            ->where('key', 'loyalty_earn_rate')->value('value') ?? 5);

        $enabled = (string) (\App\Models\Setting::where('business_id', $order->business_id)
            ->where('key', 'loyalty_enabled')->value('value') ?? '1') !== '0';

        $should = ($enabled && $rate > 0) ? (int) floor((float) $order->total * $rate) : 0;
        $delta = $should - (int) $order->points_earned;

        if ($delta === 0) {
            return;
        }

        // ولا تُدفع نقاطه إلى ما دون الصفر بتصحيحٍ لاحق
        $delta = max($delta, -(int) $customer->points);
        if ($delta === 0) {
            return;
        }

        $customer->increment('points', $delta);
        PointTransaction::record(
            $customer,
            $delta > 0 ? 'earn' : 'redeem',
            abs($delta),
            (int) $customer->fresh()->points,
            $order->id,
            'تصحيح فاتورة '.$order->number,
        );

        $order->update(['points_earned' => (int) $order->points_earned + $delta]);
    }
}
