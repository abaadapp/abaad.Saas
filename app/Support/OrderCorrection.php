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
                $item->loadMissing('addons.addon');
                self::releaseAddons($order, $item);
                $item->delete();
            } else {
                $item->update(['quantity' => $newQty, 'total' => round((float) $item->price * $newQty, 3)]);
            }

            self::recompute($order->fresh('items'));
            $order->refresh();

            self::syncTransaction($order);
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

        /*
         * لذي الوصفة تعود مكوّناته لا هو.
         *
         * بيعُ الباقة أنقص الورد والتغليف ولم يمسّ الباقة — فتصحيحُ كميّتها
         * يجب أن يسلك الطريق نفسه. وردُّ «الباقة» إلى الرفّ كان سيخلق رصيدًا
         * لمنتجٍ لا رصيد له أصلًا، ويترك الورد منقوصًا إلى الأبد.
         *
         * والوصفة تُقرأ اليوم لا يوم البيع: لو غُيّرت بينهما لعاد غيرُ ما
         * أُخذ. وهذا حدٌّ معروف — انظر التقرير — وعلاجُه لقطةُ وصفةٍ على
         * البند، وهي كلفةٌ لا تُبرّرها ندرةُ الحالة اليوم.
         */
        $variant = $item->variant_id
            ? \App\Models\ProductVariant::withTrashed()->find($item->variant_id)
            : null;

        $recipe = \App\Support\Recipe::forLine($product, $variant);

        if ($recipe->isNotEmpty()) {
            self::moveComponents($order, $product, $variant, $delta, $branchId);

            return;
        }

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
     * يردّ مكوّنات الوصفة أو يأخذها — بحارس المخزون نفسه الذي يحرس البيع.
     *
     * والفحص على المجموع بعد الرفع لا على كلّ مكوّنٍ بكسره: هي القاعدة
     * نفسها التي يطبّقها Recipe::units عند البيع، وتطبيقُ قاعدتين على
     * الطريقين يجعل التصحيح يردّ غير ما أخذ.
     */
    private static function moveComponents(Order $order, Product $product, ?\App\Models\ProductVariant $variant, int $delta, ?int $branchId): void
    {
        $per = \App\Support\Recipe::consumptionFor($product, $variant, abs($delta));

        $units = [];
        foreach ($per as $pid => $q) {
            $units[$pid] = \App\Support\Recipe::units($q) * ($delta > 0 ? 1 : -1);
        }

        if (! $units) {
            return;
        }

        if ($delta < 0) {
            self::assertAvailable($order, array_map('abs', $units), $branchId);
        }

        \App\Support\StockLedger::move(
            (int) $order->business_id, $branchId, $units,
            \App\Support\StockLedger::CORRECTION,
            PosCashier::name() ?? auth()->user()?->name,
            $order->number,
        );
    }

    /**
     * لا يُخصم أكثر من المتاح في تصحيحٍ أيضًا.
     *
     * وإلّا صار «زد الكمية» بابًا خلفيًّا يتجاوز الحدّ الذي يُغلق عند
     * الصندوق — فيُباع ما ليس على الرفّ بشرط أن يُباع على دفعتين.
     *
     * @param  array<int, int>  $needed
     */
    private static function assertAvailable(Order $order, array $needed, ?int $branchId): void
    {
        $allowsNegative = (string) (\App\Models\Setting::where('business_id', $order->business_id)
            ->where('key', 'allow_negative_stock')->value('value') ?? '0') === '1';

        if ($allowsNegative || ! $needed) {
            return;
        }

        $products = Product::where('business_id', $order->business_id)
            ->whereIn('id', array_keys($needed))->get()->keyBy('id');

        $resolve = Stock::availabilityResolver(
            (int) $order->business_id, $branchId, array_keys($needed), lock: true,
        );

        foreach ($needed as $pid => $want) {
            $p = $products->get($pid);
            if (! $p) {
                continue;
            }
            if ($resolve($pid, (int) $p->quantity) < $want) {
                throw new RuntimeException(__(':name — المتوفر :have والمطلوب :want', [
                    'name' => $p->name, 'have' => $resolve($pid, (int) $p->quantity), 'want' => $want,
                ]));
            }
        }
    }

    /**
     * يردّ بضاعة الإضافات حين يُحذف البند كلُّه.
     *
     * الإضافة كميّةٌ مطلقة على البند لا مضروبةٌ في كميّته: «شوكولاتة ×١»
     * تبقى واحدة سواء بيعت باقةٌ أو اثنتان. فتغيير الكمية لا يمسّها، وحذفُ
     * البند يردّها كاملة — وإلّا بقي الدبّ منقوصًا من الرفّ وهو في الثلاجة.
     */
    private static function releaseAddons(Order $order, OrderItem $item): void
    {
        /*
         * ويُقرأ ما أُخذ من لقطة البند لا من الإضافة اليوم.
         *
         * «زيادة ثلاث وردات» صارت خمسًا بعد شهر: قراءةُ الإضافة الحيّة تردّ
         * خمسًا عن بيعةٍ أخذت ثلاثًا، فيربح الرفّ وردتين لا وجود لهما — ولا
         * يظهر ذلك إلا في جردٍ يقول إنّ عندنا أكثر ممّا عندنا.
         *
         * والصفوف التي كُتبت قبل اللقطة تُقرأ بقاعدة يومها: واحدةٌ لكلّ
         * إضافة. انظر AddonStock::snapshot.
         */
        $back = \App\Support\AddonStock::units(
            \App\Support\AddonStock::consumedBy($item->addons),
        );

        \App\Support\StockLedger::move(
            (int) $order->business_id, $order->branch_id, $back,
            \App\Support\StockLedger::CORRECTION,
            PosCashier::name() ?? auth()->user()?->name,
            $order->number,
        );
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

        // ثمن البند كاملًا: مقاسه وإضافاته. وبإهمال الإضافات كان تصحيحُ
        // كميّةٍ واحدة يُسقط ثمن الشوكولاتة من الفاتورة كلّها بلا أثر
        $gross = round($items->sum(fn ($i) => (float) $i->price * (int) $i->quantity + (float) $i->addons_total), 3);
        $discount = round(min((float) $order->discount, $gross), 3);
        $couponDiscount = round(min((float) $order->coupon_discount, $discount), 3);

        $tax = 0.0;
        if (Vat::enabled($bid) && $gross > 0) {
            $inclusive = Vat::inclusive($bid);
            $products = Product::whereIn('id', $items->pluck('product_id')->filter())->get()->keyBy('id');

            foreach ($items as $i) {
                $net = (float) $i->price * (int) $i->quantity + (float) $i->addons_total;
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
     * يُغيّر كميّة إضافةٍ على بند — والصفر يحذفها.
     *
     * الإضافة تُخطئ كما يُخطئ البند: يضغط الكاشير «شوكولاتة» مرّتين والزبون
     * أخذ واحدة. وكان الطريق الوحيد لتصحيحها حذفَ البند كلّه ثم إعادة
     * بيعه — فتُكسر الفاتورة لتُصلَح إضافة.
     *
     * وما يعود إلى الرفّ هو ما أُخذ منه: إضافةٌ تأكل ثلاث ورداتٍ تُنقص
     * كميّتُها من اثنتين إلى واحدة فتردّ ثلاثًا لا واحدة. واللقطة هي
     * المرجع لا إعدادُ الإضافة اليوم.
     *
     * @throws RuntimeException برسالةٍ تُعرض للكاشير كما هي
     */
    public static function setAddonQuantity(Order $order, \App\Models\OrderItemAddon $row, int $newQty, string $reason): OrderEdit
    {
        $item = $row->orderItem;

        if (! $item || (int) $item->order_id !== (int) $order->id) {
            throw new RuntimeException(__('هذه الإضافة ليست من هذه الفاتورة.'));
        }

        if ($newQty < 0) {
            throw new RuntimeException(__('الكمية لا تكون سالبة.'));
        }

        $oldQty = (int) $row->quantity;

        if ($newQty === $oldQty) {
            throw new RuntimeException(__('لم تتغيّر الكمية.'));
        }

        return DB::transaction(function () use ($order, $item, $row, $oldQty, $newQty, $reason) {
            $bid = (int) $order->business_id;
            $totalBefore = (float) $order->total;
            $name = $row->name;

            [$pid, $each] = \App\Support\AddonStock::snapshot($row);

            /*
             * الفرق وحده يتحرّك — لا الكمية كلّها.
             *
             * ردُّ ما بيع ثم خصمُ الجديد يكتب حركتين حيث تكفي واحدة، ويفتح
             * نافذةً يقول فيها الرفّ رقمًا لا يخصّ شيئًا. والزيادة تمرّ
             * بحارس المخزون نفسه الذي يحرس البيع.
             */
            if ($pid && $each > 0) {
                $delta = ($oldQty - $newQty) * $each;
                $units = \App\Support\AddonStock::units([$pid => abs($delta)]);
                $units = array_map(fn ($u) => $delta > 0 ? $u : -$u, $units);

                if ($delta < 0) {
                    self::assertAvailable($order, array_map('abs', $units), $order->branch_id);
                }

                \App\Support\StockLedger::move(
                    $bid, $order->branch_id, $units,
                    \App\Support\StockLedger::CORRECTION,
                    PosCashier::name() ?? auth()->user()?->name,
                    $order->number,
                );
            }

            if ($newQty === 0) {
                $row->delete();
            } else {
                $row->update([
                    'quantity' => $newQty,
                    'total' => round((float) $row->unit_price * $newQty, 3),
                ]);
            }

            // مجموع إضافات البند يُعاد بناؤه من صفوفه لا يُعدَّل بالفرق:
            // الجمع من المصدر لا يخطئ، والتعديل بالفرق يخطئ مرّةً فيبقى
            $item->load('addons');
            $item->update(['addons_total' => round($item->addons->sum(fn ($a) => (float) $a->total), 3)]);

            self::recompute($order->fresh('items'));
            $order->refresh();

            self::syncTransaction($order);
            self::syncLoyalty($order);

            $edit = OrderEdit::create([
                'business_id' => $bid,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'kind' => OrderEdit::ADDON,
                'subject' => $name,
                'qty_before' => $oldQty,
                'qty_after' => $newQty,
                'order_total_before' => $totalBefore,
                'order_total_after' => (float) $order->total,
                'reason' => $reason,
                'user_id' => PosCashier::id() ?? auth()->id(),
                'employee_name' => PosCashier::name() ?? auth()->user()?->name,
            ]);

            Activity::log('updated', $newQty === 0
                ? 'حذف إضافة «'.$name.'» من الفاتورة '.$order->number.' — '.$reason
                : 'عدّل كمية إضافة «'.$name.'» في الفاتورة '.$order->number.' من '.$oldQty.' إلى '.$newQty.' — '.$reason,
                ['subject_id' => $order->id, 'subject_type' => 'order']);

            return $edit;
        });
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
    /**
     * إلغاء الطلب — ولا يكفي أن تُكتب كلمة «ملغي» في عمود.
     *
     * البيعُ كان قد أخذ من الرفّ، وأعطى نقاطًا، وأحرق استعمالَ كوبون،
     * وقيَّد دخلًا. وكان الإلغاء يقلب الحالة وحدها فيبقى ذلك كلُّه:
     *
     *   - خمسُ ورداتٍ خرجت من الدفتر ولم تخرج من المحلّ، فيقول الجرد
     *     إنّها نقصت ولا أحد يعرف أين ذهبت.
     *   - نقاطٌ يكسبها العميل على طلبٍ لم يقع، ويستبدلها بضاعةً تقع.
     *   - كوبونٌ «مرّة واحدة» يُحرَق على طلبٍ أُلغي، فلا يستطيع صاحبه
     *     استعماله ولا التاجر إعادته — لا باب لتعديل الكوبونات أصلًا.
     *   - وقيدُ دخلٍ يبقى في المالية على بيعةٍ لم تكن.
     *
     * والتقارير كانت تستثني الملغى (`Order::scopeSold`) — فبدا الأمر
     * سليمًا في الشاشة، والخلل تحتها في المخزون والنقاط والدفتر.
     *
     * ويُنفَّذ مرّةً واحدة: نقلٌ ثانٍ إلى «ملغي» لا يردّ المخزون مرّتين.
     */
    public static function cancel(Order $order, ?string $reason = null): void
    {
        if ($order->status === \App\Support\OrderStatus::CANCELLED) {
            return;
        }

        DB::transaction(function () use ($order, $reason) {
            /*
             * والحال تُقرأ ثانيةً تحت قفل — والقراءة الأولى لا تكفي.
             *
             * الفحص أعلاه يقع على نسخةٍ في الذاكرة قُرئت قبل المعاملة. فضغطتان
             * على «إلغاء» — أو موظّفان يفتحان الطلب نفسه — تقرآن «مكتمل»
             * كلتاهما فتدخلان معًا: يعود المخزون مرّتين، ويُردّ الكوبون مرّتين،
             * وتُسحب النقاط مرّتين. والزيادة في الرفّ لا يكشفها شيء إلا الجرد،
             * ولا يعرف أحدٌ حينها من أين جاءت خمسُ ورداتٍ لم تُشترَ.
             *
             * والقفل يُصفّ الطلبين: الثاني ينتظر ثمّ يقرأ «ملغي» فينصرف.
             */
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status === \App\Support\OrderStatus::CANCELLED) {
                return;
            }

            $totalBefore = (float) $order->total;

            $order->loadMissing('items.addons.addon');

            // ما بيع يعود إلى الرفّ — بالطريق نفسه الذي خرج به
            foreach ($order->items as $item) {
                self::moveStock($order, $item, (int) $item->quantity);
                self::releaseAddons($order, $item);
            }

            self::releaseCoupon($order);
            self::reverseLoyalty($order);

            // لا قيدَ دخلٍ على بيعةٍ لم تقع — في الدفترين معًا
            Transaction::where('order_id', $order->id)->delete();
            \App\Support\Books::forgetSale($fresh);

            $order->update(['status' => \App\Support\OrderStatus::CANCELLED]);

            OrderEdit::create([
                'business_id' => $order->business_id,
                'order_id' => $order->id,
                'order_item_id' => null,
                'kind' => OrderEdit::CANCEL,
                'subject' => $order->number,
                'order_total_before' => $totalBefore,
                'order_total_after' => $totalBefore,
                'reason' => $reason ?: __('إلغاء الطلب'),
                'user_id' => PosCashier::id() ?? auth()->id(),
                'employee_name' => PosCashier::name() ?? auth()->user()?->name,
            ]);
        });
    }

    /**
     * يردّ استعمال الكوبون — ولا ينزل تحت الصفر.
     *
     * عدّادٌ سالب يجعل «مرّة واحدة» مرّتين، وهو عطبٌ في الجهة الأخرى.
     */
    private static function releaseCoupon(Order $order): void
    {
        if (blank($order->coupon_code)) {
            return;
        }

        \App\Models\Coupon::where('business_id', $order->business_id)
            ->where('code', $order->coupon_code)
            ->where('used_count', '>', 0)
            ->decrement('used_count');
    }

    /**
     * النقاط: ما اكتُسب يُسحب، وما استُبدل يُردّ.
     *
     * والسحب لا يُنزل رصيد العميل تحت الصفر: قد يكون أنفق نقاطه بين
     * البيعة والإلغاء، فسحبُ ما اكتسبه كاملًا يُنقصه ما لم يأخذه — وهي
     * القاعدة نفسها التي يطبّقها تصحيح الفاتورة.
     */
    private static function reverseLoyalty(Order $order): void
    {
        $customer = $order->customer_id ? \App\Models\Customer::find($order->customer_id) : null;
        if (! $customer) {
            return;
        }

        $earned = (int) $order->points_earned;
        $redeemed = (int) $order->redeemed_points;

        if ($earned > 0) {
            $take = min($earned, (int) $customer->points);
            if ($take > 0) {
                $customer->decrement('points', $take);
                PointTransaction::record($customer, 'redeem', $take, (int) $customer->fresh()->points,
                    $order->id, 'إلغاء فاتورة '.$order->number);
            }
            $order->points_earned = 0;
        }

        if ($redeemed > 0) {
            $customer->increment('points', $redeemed);
            PointTransaction::record($customer, 'earn', $redeemed, (int) $customer->fresh()->points,
                $order->id, 'ردّ نقاط فاتورة ملغاة '.$order->number);
            $order->redeemed_points = 0;
        }

        $order->save();
    }

    private static function syncTransaction(Order $order): void
    {
        Transaction::where('order_id', $order->id)->update([
            'amount' => (float) $order->total,
            'tax_amount' => (float) $order->tax,
        ]);
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
