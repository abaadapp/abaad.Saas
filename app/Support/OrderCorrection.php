<?php

namespace App\Support;

use App\Models\BranchStock;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\Store\GiftCardProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use App\Support\CouponLimits;
use App\Support\PaymentMethods;

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

        self::assertSameDay($order);

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

            /*
             * ═══ والبندُ يُقرأ من جديدٍ مقفلًا قبل أن يُكتب فوقه ═══
             *
             * ما في اليد نسخةُ شاشةٍ قُرئت قبل دقيقة، والفاتورةُ الواحدة تُفتح
             * على صندوقين: الكاشير على الطاولة والمحاسبُ من شاشة المبيعات —
             * البابُ واحدٌ تحتهما (`OrderEditController`).
             *
             * فلو خفضها الأوّلُ من ثلاثٍ إلى واحدة، ثمّ أرسل الثاني «اجعلها
             * اثنتين» وهو يقرأ ثلاثًا: يُحسب الفرقُ ٣−٢ = ١ فيعود إلى الرفّ
             * صنفٌ لم يُبَع أصلًا — قِسناه: الرفُّ يعود إلى ما كان **قبل
             * البيع** والفاتورةُ تقول إنّ قطعتين بِيعتا. ولا يُكتشف ذلك إلّا
             * في الجرد، ولا شيءَ فيه يقول من أين جاءت الزيادة.
             *
             * ═══ ولمَ الردّ لا الحسابُ من القيمة الجديدة ═══
             *
             * حسابُ الفرق من الجديدة يُصلح الرفّ ويُبقي فعلًا آخر: من أراد
             * «٣ ← ٢» — وهي خفض — يقع أمرُه «١ ← ٢»، وهي زيادةُ بندٍ على
             * فاتورةٍ ضريبيّةٍ سُلّمت. وليسا فعلًا واحدًا.
             *
             * انظر `ACorrectionReadsTheLineItRewritesTest`.
             */
            $locked = OrderItem::whereKey($item->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new RuntimeException(__('حُذف هذا البند من الفاتورة قبل أن يصل تصحيحك.'));
            }

            if ((int) $locked->quantity !== $oldQty) {
                throw new RuntimeException(__('تغيّر هذا البند من جهازٍ آخر — أعد فتح الفاتورة ثمّ صحّحها.'));
            }

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
            // وما نقص يُنقص المتبقّي على العميل أوّلًا — لا أثرَ حيث لا متبقّي
            self::absorb($order, $totalBefore);
            self::assertPaidAtSaleStands($order);

            self::syncTransaction($order);
            self::syncBooks($order, $reason);
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
    /**
     * فاتورةٌ دخلت إقرارًا قُدِّم لا تُلغى — والإقرارُ ورقةٌ سُلِّمت.
     *
     * الإلغاءُ يُخرج البيعةَ من `Order::scopeSold`، ومنها يُبنى الإقرار. فإلغاءُ
     * فاتورةٍ من ربعٍ قُدِّم **يُعيد كتابة رقمٍ سُلِّم إلى جهةٍ حكوميّة**: يفتح
     * التاجر تقريرَه بعد شهرين فيجد غيرَ ما قدّم، ولا شيء يقول لماذا.
     *
     * والحدُّ يقوله التاجر بنفسه في إعدادات الضريبة («آخرُ يومٍ قُدِّم إقرارُه»)
     * ولا يُخمَّن: الرُّبعُ يبدأ عند من تبدأ سنتُه المالية في يوليو غيرَ حيث
     * يبدأ عند سواه. ومن لم يقدّم شيئًا لا يُقفل عليه شيء.
     *
     * وما بعد القفل يُصحَّح بمستنده — إشعارُ دائنٍ في فترةٍ مفتوحة — لا
     * بمحو الأصل.
     *
     * @throws RuntimeException برسالةٍ تُعرض كما هي
     */
    private static function assertNotFiled(Order $order): void
    {
        $soldOn = $order->ordered_at ?? $order->created_at;

        if (! Vat::isFiled((int) $order->business_id, $soldOn)) {
            return;
        }

        throw new RuntimeException(__(
            'هذه الفاتورة داخلةٌ في إقرارٍ ضريبيّ قُدِّم في :date — لا تُلغى. وتصحيحُها يكون بإشعارِ دائنٍ في فترةٍ مفتوحة.',
            ['date' => optional(Vat::filedThrough((int) $order->business_id))->format('Y-m-d')],
        ));
    }

    /**
     * فاتورةٌ ضريبيّة تُقفل بانتهاء يومها.
     *
     * كانت تُعدَّل **بلا حدٍّ زمنيّ**: يفتحها الكاشير بعد ثلاثة أسابيع فيُنقص
     * كميّةً، فتتغيّر الفاتورةُ التي في يد الزبون والإقرارُ الضريبيّ الذي
     * قُدّم عن شهرٍ أُغلق — بلا مستندٍ ثالثٍ يقول إنّ شيئًا تغيّر.
     *
     * والفرقُ بين حالتين كان ضائعًا: «الكاشير كتب ٣ بدل ٢ قبل ثلاثين ثانية»
     * و«الزبون أعاد البضاعة بعد ثلاثة أسابيع». الأولى تصحيحُ خطأٍ في الإدخال،
     * والثانية إرجاعٌ له مستندُه ومالُه وضريبتُه — وهما لا يُعالجان بالباب نفسه.
     *
     * فالحدُّ يومُ البيع: ما دام لم ينتهِ، فما يُكتب تصحيحُ لحظته. وبعده
     * الفاتورة مغلقة، ويبقى **الإلغاء الكامل** بابًا مفتوحًا — وهو لا يعيد
     * كتابة المستند بل يعكس قيدَه ويترك الاثنين مقروءين (انظر `cancel`).
     *
     * ملاحظة: القاعدة المقصودة أصلًا «ما دامت الورديّة مفتوحة». وميزةُ
     * الورديّات لا وجود لها في هذا النظام — لا مسار ولا شاشة، و`orders.shift_id`
     * عمودٌ لا يكتبه شيء. واليومُ أقربُ حدٍّ يُنفَّذ ببيانٍ قائم، ويلتقي بها
     * حين تُبنى: ورديّةٌ نادرًا ما تعبر يومين.
     *
     * @throws RuntimeException برسالةٍ تُعرض للكاشير كما هي
     */
    private static function assertSameDay(Order $order): void
    {
        $soldOn = $order->ordered_at ?? $order->created_at;

        // فاتورةٌ بلا تاريخٍ أصلًا لا يُقاس عليها — ولا تُفتح لذلك
        if (! $soldOn) {
            throw new RuntimeException(__('لا يُعرف تاريخ هذه الفاتورة — لا تُصحَّح. وإن لزم إلغاؤها فذلك بابٌ آخر.'));
        }

        if (! Carbon::parse($soldOn)->isSameDay(now())) {
            throw new RuntimeException(__('انتهى يومُ هذه الفاتورة فأُقفلت — التصحيح في يوم البيع وحده. وما بعده إلغاءٌ كامل لا تعديلٌ في الأصل.'));
        }
    }

    private static function moveStock(Order $order, OrderItem $item, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        /*
         * والطلبُ المخصَّص لا صنفَ له — وموادُّه لقطةٌ على البند.
         *
         * `product_id` فيه فارغٌ بطبيعته، فكان يسقط من هذا الباب صامتًا:
         * يُلغى طلبٌ فيبقى الكيسُ منقوصًا من الرفّ وهو في الدرج. وموادُّه
         * تُقرأ من `order_item_components` لا من وصفةِ منتجٍ لا وجود له.
         */
        if ($item->isCustom()) {
            self::moveArrangement($order, $item, $delta);

            return;
        }

        if (! $item->product_id) {
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
            ? ProductVariant::withTrashed()->find($item->variant_id)
            : null;

        $recipe = Recipe::forLine($product, $variant);

        if ($recipe->isNotEmpty()) {
            self::moveComponents($order, $product, $variant, $delta, $branchId);

            return;
        }

        // الزيادة تمرّ بحارس المخزون نفسه الذي يحرس البيع — وإلّا صار
        // التصحيح بابًا خلفيًّا يتجاوز الحدّ الذي يُغلق عند نقطة البيع
        if ($delta < 0 && $product->tracksStock()) {
            $allowsNegative = (string) (Setting::where('business_id', $order->business_id)
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
            BranchStock::ensureAllocated($order->business_id, $product->id, (int) $product->quantity);
        }

        $product->increment('quantity', $delta);

        if ($branchId) {
            BranchStock::adjust($order->business_id, $branchId, $product->id, $delta);
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
    private static function moveComponents(Order $order, Product $product, ?ProductVariant $variant, int $delta, ?int $branchId): void
    {
        $per = Recipe::consumptionFor($product, $variant, abs($delta));

        $units = [];
        foreach ($per as $pid => $q) {
            $units[$pid] = Recipe::units($q) * ($delta > 0 ? 1 : -1);
        }

        if (! $units) {
            return;
        }

        if ($delta < 0) {
            self::assertAvailable($order, array_map('abs', $units), $branchId);
        }

        StockLedger::move(
            (int) $order->business_id, $branchId, $units,
            StockLedger::CORRECTION,
            PosCashier::name() ?? auth()->user()?->name,
            $order->number,
        );
    }

    /**
     * يردّ موادَّ الطلب المخصَّص — أو يأخذها ثانيةً حين تزيد كميّتُه.
     *
     * ═══ والطريقان غيرُ متماثلين، عن قصد ═══
     *
     * زيادةُ الكميّة تأخذ **كلّ** الموادّ: باقةٌ ثانيةٌ تُركَّب من وردٍ
     * وتغليفٍ كما رُكّبت الأولى. أمّا النقصانُ والإلغاء فلا يردّان إلّا ما
     * `restockable`: وردٌ قُصّ ورُكّب لا يعود إلى الدلو، وكيسٌ لم يُفتح
     * يعود. والسياسةُ محفوظةٌ في الصفّ منذ البيع لا تُخمَّن اليوم.
     *
     * ═══ والمردودُ فرقُ رفعين لا رفعُ فرق ═══
     *
     * البيعُ رفع مجموعَ الصنف كلِّه إلى الصحيح مرّةً واحدة. فلو رُفع
     * المردودُ وحده لَجاز أن يعود أكثرُ ممّا أُخذ حين يظهر الصنفُ نفسُه
     * وردًا وتغليفًا في طلبٍ واحد: نصفٌ ونصفٌ أُخذا واحدًا، ويعود المردودُ
     * منهما واحدًا كاملًا. فالمردودُ = رفعُ الكلّ ناقصَ رفعِ ما لا يُردّ —
     * وهو أبدًا لا يتجاوز ما خرج.
     */
    private static function moveArrangement(Order $order, OrderItem $item, int $delta): void
    {
        $item->loadMissing('components');

        $lines = (int) abs($delta);
        $all = [];
        $keep = [];

        foreach ($item->components as $c) {
            $pid = (int) $c->product_id;
            $q = (float) $c->quantity * $lines;

            // صنفٌ مُحي من الكتالوج، أو صفرُ كميّة — تنقيةٌ كالتي في
            // `CustomArrangement::consumption`، لا حارسٌ يدّعي حمايةً
            if ($pid < 1 || $q <= 0) {
                continue;
            }

            $all[$pid] = ($all[$pid] ?? 0.0) + $q;

            if (! $c->restockable) {
                $keep[$pid] = ($keep[$pid] ?? 0.0) + $q;
            }
        }

        $units = [];
        foreach ($all as $pid => $q) {
            $n = $delta > 0
                ? Recipe::units($q) - Recipe::units($keep[$pid] ?? 0.0)
                : Recipe::units($q);

            if ($n > 0) {
                $units[$pid] = $n;
            }
        }

        if (! $units) {
            return;
        }

        if ($delta < 0) {
            self::assertAvailable($order, $units, $order->branch_id);
            $units = array_map(fn ($n) => -$n, $units);
        }

        StockLedger::move(
            (int) $order->business_id, $order->branch_id, $units,
            StockLedger::CORRECTION,
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
        $allowsNegative = (string) (Setting::where('business_id', $order->business_id)
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
            if (! $p || ! $p->tracksStock()) {
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
        $back = AddonStock::units(
            AddonStock::consumedBy($item->addons),
        );

        StockLedger::move(
            (int) $order->business_id, $order->branch_id, $back,
            StockLedger::CORRECTION,
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
            /*
             * ═══ والصنفُ المحذوف يُقرأ، وإلّا رجعت نسبتُه إلى نسبة المتجر ═══
             *
             * `Product::whereIn` تُسقط المحذوفَ ليّنًا (`SoftDeletes`)، فيردّ
             * `$products->get($id)` فراغًا، و`Vat::rateFor(null)` تردّ نسبة
             * المتجر. فصنفٌ صفريُّ الضريبة — خبزٌ أو حليب — يُحذف من الكتالوج
             * بعد بيعه، ثمّ تُصحَّح كميّتُه في اليوم نفسه، **فتُضاف إليه ضريبةٌ
             * لم تُجبَ من الزبون**: قِيس فخرجت ٥ على فاتورةٍ ضريبتُها صفر.
             *
             * وهي تُكتب في الفاتورة وفي الإقرار معًا — لا في الشاشة وحدها.
             *
             * والحصرُ بالمتجر عُرفُ كلّ استعلامٍ هنا، لا حارسٌ يدّعي منعًا:
             * المعرّفاتُ تأتي من بنود هذه الفاتورة، وهي فريدةٌ في الجدول كلِّه
             * — فلا يبلغه معرّفُ متجرٍ آخر. وطفرةٌ تنزعه لا يقتلها شيء،
             * ويُقال ذلك ولا يُدَّعى غيره.
             */
            $products = Product::withTrashed()
                ->where('business_id', $bid)
                ->whereIn('id', $items->pluck('product_id')->filter())
                ->get()->keyBy('id');

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
    public static function setAddonQuantity(Order $order, OrderItemAddon $row, int $newQty, string $reason): OrderEdit
    {
        $item = $row->orderItem;

        if (! $item || (int) $item->order_id !== (int) $order->id) {
            throw new RuntimeException(__('هذه الإضافة ليست من هذه الفاتورة.'));
        }

        self::assertSameDay($order);

        if ($newQty < 0) {
            throw new RuntimeException(__('الكمية لا تكون سالبة.'));
        }

        $oldQty = (int) $row->quantity;

        if ($newQty === $oldQty) {
            throw new RuntimeException(__('لم تتغيّر الكمية.'));
        }

        return DB::transaction(function () use ($order, $item, $row, $oldQty, $newQty, $reason) {
            $bid = (int) $order->business_id;

            // وصفُّ الإضافة يُقرأ مقفلًا كما يُقرأ البند — العلّةُ واحدة
            $locked = OrderItemAddon::whereKey($row->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new RuntimeException(__('حُذفت هذه الإضافة قبل أن يصل تصحيحك.'));
            }

            if ((int) $locked->quantity !== $oldQty) {
                throw new RuntimeException(__('تغيّرت هذه الإضافة من جهازٍ آخر — أعد فتح الفاتورة ثمّ صحّحها.'));
            }

            $totalBefore = (float) $order->total;
            $name = $row->name;

            [$pid, $each] = AddonStock::snapshot($row);

            /*
             * الفرق وحده يتحرّك — لا الكمية كلّها.
             *
             * ردُّ ما بيع ثم خصمُ الجديد يكتب حركتين حيث تكفي واحدة، ويفتح
             * نافذةً يقول فيها الرفّ رقمًا لا يخصّ شيئًا. والزيادة تمرّ
             * بحارس المخزون نفسه الذي يحرس البيع.
             */
            if ($pid && $each > 0) {
                $delta = ($oldQty - $newQty) * $each;
                $units = AddonStock::units([$pid => abs($delta)]);
                $units = array_map(fn ($u) => $delta > 0 ? $u : -$u, $units);

                if ($delta < 0) {
                    self::assertAvailable($order, array_map('abs', $units), $order->branch_id);
                }

                StockLedger::move(
                    $bid, $order->branch_id, $units,
                    StockLedger::CORRECTION,
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
            self::absorb($order, $totalBefore);
            self::assertPaidAtSaleStands($order);

            self::syncTransaction($order);
            self::syncBooks($order, $reason);
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
        self::assertSameDay($order);

        $allowed = PaymentMethods::enabled(
            Setting::where('business_id', $order->business_id)->pluck('value', 'key')->all(),
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
            // وصفُّ تحصيلٍ أو ردٍّ لاحق له وسيلتُه — لا تُكتب فوقها وسيلةُ البيعة
            self::saleRows($order)->update(['method' => $method]);
            // والدفترُ يتبع: الجانبُ المدين (صندوق/بنك/ذمّة) يُقرأ من الوسيلة
            self::syncBooks($order->fresh(), $reason);

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
    /* ═══════════════ إضافةُ صنفٍ واستبدالُه وملاحظتُه — بعد صدور الفاتورة ═══════════════ */

    /**
     * الفرقُ في فاتورةٍ مدفوعة: يبقى على العميل، أو حُصِّل الآن بوسيلةٍ، أو
     * رُدّ إليه الآن بوسيلةٍ — ولكلٍّ من الأخيرين حركتُه وقيدُه (`settleDifference`).
     */
    public const SETTLE_COLLECTED = 'collected';

    public const SETTLE_DUE = 'due';

    public const SETTLE_REFUNDED = 'refunded';

    public const SETTLES = [self::SETTLE_COLLECTED, self::SETTLE_DUE, self::SETTLE_REFUNDED];

    /**
     * يُضيف صنفًا إلى فاتورةٍ صدرت — بسعر القاعدة، ومخزونِه، وضريبتِه، وقيدِه.
     *
     * لا بندًا يُكتب ثمّ إجماليٌّ يُغيَّر بيد: السعرُ من `SaleLines::priceItems`
     * (لا من الشاشة)، والبندُ يُكتب كما يكتبه الصندوق، والمخزونُ من بابه
     * (`moveStock`)، ثمّ الحسابُ والمعاملةُ والدفترُ والنقاط من الأبواب نفسِها
     * التي يمرّ بها تصحيحُ الكمّيّة. وكلُّه في معاملةٍ واحدة: يسقط شيءٌ فلا
     * يبقى شيء.
     *
     * @param  array{product_id: int, variant_id?: ?int, qty: int, addons?: array<int, array{addon_id: int, qty: int}>, note?: ?string}  $wanted
     *
     * @throws RuntimeException|ValidationException
     */
    public static function addLine(Order $order, array $wanted, string $reason, ?string $settle = null, ?string $method = null): OrderEdit
    {
        self::assertLinesMayChange($order);
        $method = self::settleMethod($order, $settle, $method);

        return DB::transaction(function () use ($order, $wanted, $reason, $settle, $method) {
            $order = self::lockOrder($order);
            $totalBefore = (float) $order->total;

            $item = self::writeLine($order, self::priceOne($order, $wanted));
            self::takeStock($order, $item);

            self::recompute($order->fresh('items'));
            $order->refresh();
            $settlement = self::settleDifference($order, $totalBefore, $settle, $method);

            self::syncTransaction($order);
            self::syncBooks($order, $reason);
            self::syncLoyalty($order);
            // والتحصيلُ أو الردُّ الآن حركةٌ وقيدٌ مستقلّان — بعد قيد البيعة لا فيه
            self::recordSettlement($order, $settlement, $reason);

            $edit = self::trace($order, OrderEdit::ADD_LINE, $item->displayName(), $totalBefore, $reason, [
                'order_item_id' => $item->id,
                'qty_before' => 0,
                'qty_after' => (int) $item->quantity,
                'value_after' => self::describe($item),
            ]);

            Activity::log('updated', 'أضاف «'.$item->displayName().'» ×'.$item->quantity.' إلى الفاتورة '.$order->number.' — '.$reason,
                ['subject_id' => $order->id, 'subject_type' => 'order']);

            return $edit;
        });
    }

    /**
     * يستبدل صنفًا بآخر — يُحذف القديمُ ويُكتب الجديد، ولا يُغيَّر صنفُ بندٍ في مكانه.
     *
     * بندٌ يتبدّل صنفُه في صفّه يكذب على كلّ ما قرأه قبلُ: لقطةُ التكلفة،
     * ومقاسُه، وإضافاتُه، وحركةُ المخزون التي خرج بها. فالقديمُ يعود إلى الرفّ
     * بطريقه ويُحذف (كما يحذفه تصحيحُ الكمّيّة إلى صفر)، والجديدُ يُسعَّر
     * ويُكتب ويُخصم كأيّ إضافة — في معاملةٍ واحدة: إن ردّ الجديدَ المخزونُ أو
     * السعرُ بقي القديمُ كما كان.
     *
     * @param  array{product_id: int, variant_id?: ?int, qty: int, addons?: array<int, array{addon_id: int, qty: int}>, note?: ?string}  $wanted
     *
     * @throws RuntimeException|ValidationException
     */
    public static function replaceLine(Order $order, OrderItem $old, array $wanted, string $reason, ?string $settle = null, ?string $method = null): OrderEdit
    {
        if ($old->order_id !== $order->id) {
            throw new RuntimeException(__('هذا البند ليس من هذه الفاتورة.'));
        }

        self::assertLinesMayChange($order);
        self::assertNotCardLine($order, $old);
        $method = self::settleMethod($order, $settle, $method);

        return DB::transaction(function () use ($order, $old, $wanted, $reason, $settle, $method) {
            $order = self::lockOrder($order);
            $locked = OrderItem::whereKey($old->id)->lockForUpdate()->first();

            if (! $locked || $locked->order_id !== $order->id) {
                throw new RuntimeException(__('حُذف هذا البند من الفاتورة قبل أن يصل تصحيحك.'));
            }

            $totalBefore = (float) $order->total;
            $oldName = $locked->displayName();
            $oldQty = (int) $locked->quantity;
            $oldDesc = self::describe($locked);

            // القديمُ يعود إلى الرفّ بطريقه — ثمّ يُحذف كما يحذفه تصحيحُ الكمّيّة إلى صفر
            self::moveStock($order, $locked, $oldQty);
            $locked->loadMissing('addons.addon');
            self::releaseAddons($order, $locked);
            $locked->delete();

            $item = self::writeLine($order, self::priceOne($order, $wanted));
            self::takeStock($order, $item);

            self::recompute($order->fresh('items'));
            $order->refresh();
            $settlement = self::settleDifference($order, $totalBefore, $settle, $method);

            self::syncTransaction($order);
            self::syncBooks($order, $reason);
            self::syncLoyalty($order);
            // والتحصيلُ أو الردُّ الآن حركةٌ وقيدٌ مستقلّان — بعد قيد البيعة لا فيه
            self::recordSettlement($order, $settlement, $reason);

            $edit = self::trace($order, OrderEdit::REPLACE_LINE, $oldName.' ← '.$item->displayName(), $totalBefore, $reason, [
                'order_item_id' => $item->id,
                'qty_before' => $oldQty,
                'qty_after' => (int) $item->quantity,
                'value_before' => $oldDesc,
                'value_after' => self::describe($item),
            ]);

            Activity::log('updated', 'استبدل «'.$oldName.'» بـ«'.$item->displayName().'» في الفاتورة '.$order->number.' — '.$reason,
                ['subject_id' => $order->id, 'subject_type' => 'order']);

            return $edit;
        });
    }

    /**
     * يعدّل ملاحظةَ منتجٍ في فاتورة — نصٌّ وحده، لا مالَ ولا مخزون.
     *
     * لا يمسّ الكمّيّةَ ولا الإجماليَّ ولا المعاملةَ ولا القيد، فلا يُقفل بانتهاء
     * اليوم: طلبُ موقعٍ يُسلَّم غدًا يُصحَّح وصفُ تغليفه اليوم. والإلغاءُ وحده
     * يُغلقه — طلبٌ لن يُجهَّز لا تُكتب له ملاحظة.
     *
     * والموظّفُ يكتب بما شاء: «بالإنجليزيّة وحدها» قاعدةُ العميل في الموقع.
     * ورسالةُ كرت الهدية ليست ملاحظةَ منتج — لها بابُها في تفاصيل الطلب.
     *
     * @throws RuntimeException
     */
    public static function setNote(Order $order, OrderItem $item, ?string $note, string $reason): OrderEdit
    {
        if ($item->order_id !== $order->id) {
            throw new RuntimeException(__('هذا البند ليس من هذه الفاتورة.'));
        }

        self::assertFeatureOn($order);

        if ($order->status === Order::CANCELLED) {
            throw new RuntimeException(__('الفاتورة ملغاة — لا تُعدَّل.'));
        }

        self::assertNotCardLine($order, $item);

        $text = trim(str_replace("\r\n", "\n", (string) $note));
        $text = $text === '' ? null : $text;

        if ($text !== null && mb_strlen($text) > NotesAndEdits::PRODUCT_NOTE_MAX) {
            throw new RuntimeException(__('ملاحظة المنتج أطول من :max حرفًا.', ['max' => NotesAndEdits::PRODUCT_NOTE_MAX]));
        }

        return DB::transaction(function () use ($order, $item, $text, $reason) {
            $locked = OrderItem::whereKey($item->id)->lockForUpdate()->first();

            if (! $locked || $locked->order_id !== $order->id) {
                throw new RuntimeException(__('حُذف هذا البند من الفاتورة قبل أن يصل تصحيحك.'));
            }

            $before = $locked->note;

            if ((string) $before === (string) $text) {
                throw new RuntimeException(__('لم تتغيّر الملاحظة.'));
            }

            $locked->update(['note' => $text]);

            $edit = self::trace($order, OrderEdit::NOTE, $locked->displayName(), (float) $order->total, $reason, [
                'order_item_id' => $locked->id,
                'value_before' => self::clip($before),
                'value_after' => self::clip($text),
            ]);

            Activity::log('updated', 'عدّل ملاحظة «'.$locked->displayName().'» في الفاتورة '.$order->number.' — '.$reason,
                ['subject_id' => $order->id, 'subject_type' => 'order']);

            return $edit;
        });
    }

    /**
     * يُحصِّل ما بقي على فاتورةٍ دُفعت ثمّ زادت — قيدُ تحصيلٍ يومَ يقع، لا إعادةُ كتابةٍ للبيعة.
     *
     * المالُ دخل اليومَ لا يومَ البيع: فلا يُعاد ترحيلُ البيعة بتاريخها (كان
     * سيُدخل في صندوقِ يومٍ أُقفل مالًا لم يكن فيه). يُكتب صفٌّ مستقلٌّ في
     * الحركة الماليّة بوسيلته، وقيدٌ بتاريخه: مدينٌ الصندوقُ أو البنك، دائنٌ
     * ذممُ العملاء. وصفُّ البيعة لا يزيد — هو ما دُفع في البيعة نفسِها.
     *
     * @throws RuntimeException
     */
    public static function collectBalance(Order $order, string $method, string $reason): OrderEdit
    {
        self::assertFeatureOn($order);

        if ($order->status === Order::CANCELLED) {
            throw new RuntimeException(__('الفاتورة ملغاة — لا تُعدَّل.'));
        }

        self::assertMethodAllowed($order, $method);

        return DB::transaction(function () use ($order, $method, $reason) {
            $order = self::lockOrder($order);
            $amount = round((float) $order->balance_due, 3);

            if ($amount <= 0) {
                throw new RuntimeException(__('لا متبقّي على هذه الفاتورة.'));
            }

            return self::collect($order, $amount, $method, $reason);
        });
    }

    /**
     * التحصيلُ نفسُه — يقرؤه زرُّ «تحصيل المتبقّي» وخيارُ «حُصِّل الآن» في حوار الصنف.
     *
     * بابٌ واحد: يُنقص المتبقّي، ويُجمع في «ما سُوّي بعد البيع»، ويكتب صفَّه
     * وقيدَه. فالتحصيلُ لحظةَ الإضافة وتحصيلُه غدًا قيدان من شكلٍ واحد.
     */
    private static function collect(Order $order, float $amount, string $method, string $reason): OrderEdit
    {
        $order->update([
            'balance_due' => round(max(0.0, (float) $order->balance_due - $amount), 3),
            'paid_after_sale' => round((float) $order->paid_after_sale + $amount, 3),
        ]);

        self::settlementRow($order, Transaction::ORDER_BALANCE, $amount, $method, __('تحصيل متبقّي الفاتورة ').$order->number, [
            ['account' => self::sideFor($order, $method), 'debit' => $amount],
            ['account' => 'receivable', 'credit' => $amount],
        ]);

        $edit = self::trace($order, OrderEdit::COLLECT, $order->number, (float) $order->total, $reason, [
            'value_before' => (string) $amount,
            'value_after' => $method,
        ]);

        Activity::log('updated', 'حصّل متبقّي الفاتورة '.$order->number.' ('.$amount.') — '.$method,
            ['subject_id' => $order->id, 'subject_type' => 'order']);

        return $edit;
    }

    /**
     * ردُّ ما نقص من فاتورةٍ مدفوعة — صفٌّ خارجٌ وقيدٌ بيومه، بإقرار الموظّف.
     *
     * لا يُطلب من Paymob شيء: الموظّفُ يقول إنّه ردّ المبلغَ الآن وبأيّ وسيلة
     * (من الدرج، أو تحويلًا، أو من لوحة البوّابة بيده)، والنظامُ يكتب ما قيل.
     * والقيد: مدينٌ ذممُ العملاء (أقفلها قيدُ البيعة دائنةً بالمبلغ)، دائنٌ
     * الصندوقُ أو البنك. وصفُّ البيعة لا ينقص — هو ما دُفع فيها.
     *
     * و«ما سُوّي بعد البيع» نقص قبل ترحيل البيعة (`settleDifference`).
     */
    private static function refund(Order $order, float $amount, string $method, string $reason): OrderEdit
    {
        self::settlementRow($order, Transaction::ORDER_REFUND, $amount, $method, __('ردّ فرق الفاتورة ').$order->number, [
            ['account' => 'receivable', 'debit' => $amount],
            ['account' => self::sideFor($order, $method), 'credit' => $amount],
        ]);

        $edit = self::trace($order, OrderEdit::REFUND, $order->number, (float) $order->total, $reason, [
            'value_before' => (string) $amount,
            'value_after' => $method,
        ]);

        Activity::log('updated', 'ردّ فرق الفاتورة '.$order->number.' ('.$amount.') — '.$method,
            ['subject_id' => $order->id, 'subject_type' => 'order']);

        return $edit;
    }

    /**
     * صفُّ التسوية في الحركة الماليّة وقيدُه — والقيدُ مصدرُه الصفّ لا الطلب.
     *
     * لو كان مصدرُه الطلب لَعكسه أوّلُ تصحيحٍ بعده مع قيد البيعة
     * (`Books::unpostSale` تعكس كلَّ قيدٍ حيٍّ للطلب) — فيضيع تحصيلٌ وقع.
     *
     * @param  list<array{account: string, debit?: float, credit?: float}>  $lines
     */
    private static function settlementRow(Order $order, string $kind, float $amount, string $method, string $description, array $lines): void
    {
        $row = Transaction::create([
            'business_id' => $order->business_id,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'reference' => $order->number,
            'description' => $description,
            'kind' => $kind,
            'method' => $method,
            'type' => $kind === Transaction::ORDER_REFUND ? 'مصروف' : 'دخل',
            'amount' => $amount,
            'tax_amount' => 0,
            'employee_name' => auth()->user()?->name,
            'occurred_at' => now(),
        ]);

        $entry = Ledger::post(
            (int) $order->business_id,
            $description,
            $lines,
            now(),
            $kind === Transaction::ORDER_REFUND ? self::REFUND_SOURCE : self::BALANCE_SOURCE,
            $order->branch_id,
            auth()->id(),
            $row,
        );

        $row->update(['journal_entry_id' => $entry->id]);
    }

    /** الوسيلةُ تقول أين دخل المالُ أو خرج: النقدُ الصندوق، وما سواه البنك */
    private static function sideFor(Order $order, string $method): string
    {
        return in_array($method, ['نقدي', 'كاش'], true)
            ? 'cash'
            : (Bank::leaf((int) $order->business_id) ?? 'bank');
    }

    /** وسيلةٌ أطفأها التاجر لا يُحصَّل بها ولا يُردّ */
    private static function assertMethodAllowed(Order $order, ?string $method): void
    {
        $allowed = PaymentMethods::enabled(
            Setting::where('business_id', $order->business_id)->pluck('value', 'key')->all()
        );

        if ($method === null || ! in_array($method, $allowed, true)) {
            throw new RuntimeException(__('وسيلة دفع غير مأذون بها في هذا المتجر.'));
        }
    }

    /**
     * «حُصِّل الآن» و«رُدّ الآن» لا يكفيان وحدهما — بأيّ وسيلة؟
     *
     * والسؤالُ قبل المعاملة: وسيلةٌ ناقصة تُردّ قبل أن يُمسّ مخزونٌ أو قيد.
     */
    private static function settleMethod(Order $order, ?string $settle, ?string $method): ?string
    {
        if (! in_array($settle, [self::SETTLE_COLLECTED, self::SETTLE_REFUNDED], true)) {
            return null;
        }

        if (blank($method)) {
            throw new RuntimeException($settle === self::SETTLE_COLLECTED
                ? __('اختر وسيلة الدفع التي حُصِّل بها الفرق.')
                : __('اختر الوسيلة التي رُدّ بها الفرق للعميل.'));
        }

        self::assertMethodAllowed($order, $method);

        return $method;
    }

    /** مصدرُ قيد ردّ الفرق في الدفتر */
    public const REFUND_SOURCE = 'ردّ فرق فاتورة';

    /** مصدرُ قيد تحصيل المتبقّي في الدفتر */
    public const BALANCE_SOURCE = 'تحصيل متبقّي';

    /**
     * ما يحرس الإضافةَ والاستبدال — قيودُ التصحيح كلُّها، وما يخصّهما.
     *
     * يومُ البيع وإقرارُ الضريبة كما في تصحيح الكمّيّة. وفاتورةٌ ملغاة لا تُعدَّل.
     * وطلبٌ صدرت له فاتورةُ عميل لا تُغيَّر بنودُه من هنا: تلك ورقةٌ في يد
     * الشركة بمبلغها، وتغييرُ الطلب تحتها يُفرّق الذمّةَ عن الورقة — تصحيحُها
     * بإشعار دائن.
     */
    private static function assertLinesMayChange(Order $order): void
    {
        self::assertFeatureOn($order);

        if ($order->status === Order::CANCELLED) {
            throw new RuntimeException(__('الفاتورة ملغاة — لا تُعدَّل.'));
        }

        self::assertSameDay($order);
        self::assertNotFiled($order);

        if ($order->customerInvoices()->where('status', '!=', CustomerInvoice::CANCELLED)->exists()) {
            throw new RuntimeException(__('صدرت لهذا الطلب فاتورة عميل — صحّحها بإشعار دائن لا بتعديل البنود.'));
        }
    }

    /** والميزةُ لنشاطٍ فُتحت له — والخادمُ يسأل ولو أُخفي الزرّ */
    private static function assertFeatureOn(Order $order): void
    {
        if (! NotesAndEdits::on((int) $order->business_id)) {
            throw new RuntimeException(__('تعديل أصناف الفاتورة غير متاح في هذا النشاط.'));
        }
    }

    /** بندُ كرت الهدية: نصُّه رسالةٌ لا ملاحظة، ولا يُستبدل من هنا */
    private static function assertNotCardLine(Order $order, OrderItem $item): void
    {
        if (GiftCardProduct::cardLine($item, $order)) {
            throw new RuntimeException(__('هذا بند كرت هدية — رسالته تُعدَّل من تفاصيل الطلب.'));
        }
    }

    private static function lockOrder(Order $order): Order
    {
        $locked = Order::whereKey($order->id)->where('business_id', $order->business_id)->lockForUpdate()->first();

        if (! $locked || $locked->status === Order::CANCELLED) {
            throw new RuntimeException(__('الفاتورة ملغاة — لا تُعدَّل.'));
        }

        return $locked;
    }

    /**
     * سطرٌ واحد بسعر القاعدة — بالباب الذي يسعّر به الصندوقُ والموقع.
     *
     * لا طلبًا مخصَّصًا (سعرُه من الطلب نفسِه، وموادُّه تُبنى في الصندوق)، ولا
     * كرتَ هدية (رسالتُه وثمنُه من صفحته)، ولا إضافةً مستقلّة.
     *
     * @return array<string, mixed>
     */
    private static function priceOne(Order $order, array $wanted): array
    {
        $productId = (int) ($wanted['product_id'] ?? 0);
        $qty = (int) ($wanted['qty'] ?? 0);

        if ($productId <= 0) {
            throw new RuntimeException(__('اختر الصنف.'));
        }
        if ($qty < 1 || $qty > 9999) {
            throw new RuntimeException(__('الكمية بين 1 و9999.'));
        }

        $note = trim(str_replace("\r\n", "\n", (string) ($wanted['note'] ?? '')));
        if (mb_strlen($note) > NotesAndEdits::PRODUCT_NOTE_MAX) {
            throw new RuntimeException(__('ملاحظة المنتج أطول من :max حرفًا.', ['max' => NotesAndEdits::PRODUCT_NOTE_MAX]));
        }

        $lines = (new SaleLines((int) $order->business_id))->priceItems([[
            'id' => $productId,
            'variant_id' => ! empty($wanted['variant_id']) ? (int) $wanted['variant_id'] : null,
            'qty' => $qty,
            'name' => '',
            'note' => $note === '' ? null : $note,
            'addons' => array_values(array_filter((array) ($wanted['addons'] ?? []), fn ($a) => (int) ($a['qty'] ?? 0) > 0)),
        ]], lock: true);

        $line = $lines[0];

        if ($line['product']?->is_gift_card) {
            throw new RuntimeException(__('كرت الهدية لا يُضاف بتعديل الفاتورة — يُباع من صفحته برسالته.'));
        }

        return $line;
    }

    /** البندُ يُكتب كما يكتبه الصندوق — لقطةُ المقاس والتكلفة والبوتيك والإضافات */
    private static function writeLine(Order $order, array $l): OrderItem
    {
        $boutique = Boutiques::attribute((int) $order->business_id, [$l])[0] ?? null;

        $item = $order->items()->create([
            'product_id' => $l['product']?->id,
            'variant_id' => $l['variant']?->id,
            'variant_name' => $l['variant']?->name,
            'variant_sku' => $l['variant']?->sku,
            'name' => $l['name'],
            'price' => $l['price'],
            ...Boutiques::itemColumns($boutique, (float) ($l['cost'] ?? 0)),
            'quantity' => $l['qty'],
            'note' => $l['note'],
            'total' => round($l['price'] * $l['qty'], 3),
            'addons_total' => (float) ($l['addons_total'] ?? 0),
            'custom_details' => null,
        ]);

        foreach ($l['addons'] ?? [] as $a) {
            $item->addons()->create([
                'addon_id' => $a['addon']->id,
                'name' => $a['addon']->name,
                'name_en' => $a['addon']->name_en,
                'unit_price' => $a['price'],
                'quantity' => $a['qty'],
                'total' => $a['total'],
                'cost' => $a['cost'],
                'inventory_product_id' => $a['inventory_product_id'] ?? null,
                'inventory_quantity' => ($a['inventory_product_id'] ?? null) ? $a['each'] : null,
            ]);
        }

        return $item->fresh(['addons.addon']);
    }

    /** البندُ الجديد يأخذ من الرفّ بالحارس نفسِه — صنفُه أو وصفتُه، ثمّ إضافاتُه */
    private static function takeStock(Order $order, OrderItem $item): void
    {
        self::moveStock($order, $item, -(int) $item->quantity);

        $units = AddonStock::units(AddonStock::consumedBy($item->addons));

        if ($units) {
            self::assertAvailable($order, $units, $order->branch_id);
            StockLedger::move(
                (int) $order->business_id, $order->branch_id,
                array_map(fn ($q) => -$q, $units),
                StockLedger::CORRECTION,
                auth()->user()?->name,
                $order->number,
            );
        }
    }

    /**
     * الفرقُ في فاتورةٍ مدفوعة لا يُكتب مدفوعًا من تلقاء نفسه.
     *
     * - **زاد الإجماليّ:** يُكتب الفرقُ أوّلًا متبقّيًا على العميل
     *   (`orders.balance_due` — والدفترُ يُدين به الذمم). فإن قال الموظّف
     *   «حُصِّل الآن» حُصِّل بوسيلةٍ يختارها، من بابِ `collectBalance` نفسِه
     *   (`collect`): صفٌّ مستقلٌّ وقيدٌ بيومه — لا زيادةَ في صفّ البيعة.
     * - **نقص:** يُنقص المتبقّي أوّلًا، وما زاد عنه دُفع ولم يعد مستحقًّا:
     *   لا يمرّ إلّا بردٍّ يُقِرّه الموظّف الآن بوسيلةٍ يختارها (`refund`) —
     *   صفٌّ خارجٌ وقيدٌ بيومه. ولا استردادَ آليّ.
     *
     * ولا تُشحن بطاقةٌ ولا يُطلب من Paymob شيء في الحالين. وفاتورةٌ غيرُ
     * مدفوعة كلُّها ذمّةٌ أصلًا (`Books::recordSale`): لا سؤال.
     *
     * يُعيد التسويةَ التي تُكتب بعد قيد البيعة — أو لا شيء.
     *
     * @return array{kind: string, amount: float, method: string}|null
     *
     * @throws RuntimeException
     */
    private static function settleDifference(Order $order, float $before, ?string $settle, ?string $method): ?array
    {
        $dueBefore = (float) $order->balance_due;
        self::absorb($order, $before);

        if ((string) $order->payment_status === 'غير مدفوع') {
            return null;
        }

        $delta = round((float) $order->total - $before, 3);

        if ($delta > 0) {
            if (! in_array($settle, [self::SETTLE_DUE, self::SETTLE_COLLECTED], true)) {
                throw new RuntimeException(__('الفاتورة مدفوعة وزاد إجماليها :amount — حدّد: يبقى على العميل، أم حُصِّل الآن ومن أيّ وسيلة.', ['amount' => number_format($delta, 3)]));
            }

            $order->update(['balance_due' => round((float) $order->balance_due + $delta, 3)]);

            return $settle === self::SETTLE_COLLECTED
                ? ['kind' => self::SETTLE_COLLECTED, 'amount' => $delta, 'method' => (string) $method]
                : null;
        }

        // ما نقص فوق المتبقّي دُفع ولم يعد مستحقًّا — يُردّ بإقرارٍ لا آليًّا
        $refund = round(-$delta - ($dueBefore - (float) $order->balance_due), 3);

        if ($refund <= 0.0005) {
            return null;
        }

        if ($settle !== self::SETTLE_REFUNDED) {
            throw new RuntimeException(__('الفاتورة مدفوعة ونقص إجماليها :amount — اختر «رُدّ الفرق الآن» ووسيلةَ الردّ؛ النظام لا يستردّ آليًّا.', ['amount' => number_format($refund, 3)]));
        }

        // قبل ترحيل البيعة: قيدُها يبقى مدينًا بما دُفع فيها، والفرقُ ذمّةٌ يُقفلها قيدُ الردّ
        $order->update(['paid_after_sale' => round((float) $order->paid_after_sale - $refund, 3)]);

        return ['kind' => self::SETTLE_REFUNDED, 'amount' => $refund, 'method' => (string) $method];
    }

    /** التسويةُ بعد قيد البيعة — تحصيلٌ أو ردٌّ بحركته وقيده */
    private static function recordSettlement(Order $order, ?array $settlement, string $reason): void
    {
        if ($settlement === null) {
            return;
        }

        $settlement['kind'] === self::SETTLE_COLLECTED
            ? self::collect($order, $settlement['amount'], $settlement['method'], $reason)
            : self::refund($order, $settlement['amount'], $settlement['method'], $reason);
    }

    /**
     * ما دُفع في البيعة نفسِها لا ينزل تحت الصفر بتصحيح كمّيّة.
     *
     * فاتورةٌ حُصِّل عليها بعد البيع ثمّ نقصت كمّيّتُها نقصًا يتجاوز ما
     * حُصِّل كانت ستقول إنّ البيعة دفعت سالبًا. تُصحَّح بالاستبدال، وفيه ردٌّ
     * بوسيلته.
     */
    private static function assertPaidAtSaleStands(Order $order): void
    {
        if (self::paidAtSale($order) < -0.0005) {
            throw new RuntimeException(__('حُصِّل على هذه الفاتورة بعد البيع، وهذا التخفيض يتجاوز ما دُفع فيها — صحّحها بالاستبدال مع ردّ الفرق.'));
        }
    }

    /** ما دُفع في البيعة نفسِها: الإجماليّ − المتبقّي − ما سُوّي بعدها */
    private static function paidAtSale(Order $order): float
    {
        return round((float) $order->total - (float) $order->balance_due - (float) $order->paid_after_sale, 3);
    }

    /**
     * ما نقص من الإجماليّ يُنقص المتبقّي أوّلًا — ولا يزيد المتبقّي على الإجماليّ.
     *
     * يقرؤه كلُّ تصحيحٍ يغيّر الإجماليّ، وأثرُه صفرٌ على فاتورةٍ لا متبقّيَ عليها.
     */
    private static function absorb(Order $order, float $before): void
    {
        $due = (float) $order->balance_due;

        if ($due <= 0) {
            return;
        }

        $drop = max(0.0, $before - (float) $order->total);
        $left = round(min(max(0.0, $due - $drop), (float) $order->total), 3);

        if ($left !== round($due, 3)) {
            $order->update(['balance_due' => $left]);
        }
    }

    /** وصفُ البند في الأثر: اسمُه ومقاسُه وكمّيّتُه وإضافاتُه */
    private static function describe(OrderItem $item): string
    {
        $item->loadMissing('addons');
        $addons = $item->addons->map(fn ($a) => $a->name.' ×'.$a->quantity)->implode('، ');

        return self::clip($item->displayName().' ×'.$item->quantity.($addons !== '' ? ' + '.$addons : '')) ?? '';
    }

    private static function clip(?string $text): ?string
    {
        return $text === null ? null : mb_substr($text, 0, 255);
    }

    /**
     * أثرُ التعديل — والفاعلُ هو الحسابُ المسجَّل فعلًا.
     *
     * الإذنُ يُسأل عن الحساب المسجَّل لا عن الكاشير المختار في الصندوق
     * (`OrderEditController::mayEdit`)، فالأثرُ يقول من أذن له النظام.
     *
     * @param  array<string, mixed>  $extra
     */
    private static function trace(Order $order, string $kind, string $subject, float $totalBefore, string $reason, array $extra = []): OrderEdit
    {
        return OrderEdit::create([
            'business_id' => $order->business_id,
            'order_id' => $order->id,
            'kind' => $kind,
            'subject' => mb_substr($subject, 0, 255),
            'order_total_before' => $totalBefore,
            'order_total_after' => (float) $order->total,
            'reason' => mb_substr($reason, 0, 255),
            'user_id' => auth()->id(),
            'employee_name' => auth()->user()?->name,
        ] + $extra);
    }

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
        self::assertNotFiled($order);

        if ($order->status === OrderStatus::CANCELLED) {
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
            if (! $fresh || $fresh->status === OrderStatus::CANCELLED) {
                return;
            }

            $totalBefore = (float) $order->total;

            $order->loadMissing('items.addons.addon', 'items.components');

            // ما بيع يعود إلى الرفّ — بالطريق نفسه الذي خرج به
            foreach ($order->items as $item) {
                self::moveStock($order, $item, (int) $item->quantity);
                self::releaseAddons($order, $item);
            }

            self::releaseCoupon($order);
            self::reverseLoyalty($order);

            /*
             * لا قيدَ دخلٍ على بيعةٍ لم تقع.
             *
             * ودفترُ الصندوق يُمحى منه الصفّ: هو سجلُّ ما في الدرج الآن،
             * والتقاريرُ تقرأ منه، فبقاءُ صفٍّ لبيعةٍ أُلغيت يجعل الرصيد
             * يقول ما ليس فيه. أمّا دفترُ الأستاذ فيُعكس ولا يُمحى — انظر
             * `Books::unpostSale`.
             */
            // وما سُوّي بعد البيع قيدُه مصدرُه صفُّه — يُعكس معه قبل أن يُخفى الصفّ
            Transaction::where('order_id', $order->id)->whereIn('kind', Transaction::SETTLEMENT_KINDS)->get()
                ->each(fn (Transaction $t) => Books::liveEntriesFor($t)->each(
                    fn ($e) => Ledger::reverse($e, null, PosCashier::id() ?? auth()->id(), $reason ?: __('إلغاء الطلب'))
                ));
            Transaction::where('order_id', $order->id)->delete();
            Books::unpostSale(
                $fresh,
                PosCashier::id() ?? auth()->id(),
                $reason ?: __('إلغاء الطلب'),
            );

            $order->update(['status' => OrderStatus::CANCELLED]);

            /*
             * والورقةُ التي تحمل هذا الطلب تعرف بإلغائه.
             *
             * وبلا هذا يُنقص الإلغاءُ الدفترَ ولا يُنقص الفاتورة: يُلغى طلبٌ
             * في فاتورة شهرٍ فيبقى مبلغُه مطلوبًا من الشركة، ويصلها تذكيرٌ
             * بدَينٍ لم يعد عليها — والفارقُ يظهر في شريط المطابقة ولا يُعرف
             * سببُه.
             *
             * وإشعارُ دائنٍ لا إعادةَ كتابة: الورقةُ في يدها تبقى كما استلمتها.
             * ولا قيدَ له — `unpostSale` عكست قيد الطلب قبل سطرين.
             */
            CustomerInvoices::onOrderCancelled(
                $fresh,
                PosCashier::id() ?? auth()->id(),
                $reason ?: null,
            );

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

        Coupon::where('business_id', $order->business_id)
            ->where('code', $order->coupon_code)
            ->where('used_count', '>', 0)
            ->decrement('used_count');

        /*
         * وسجلُّ استعمال هذا الزبون يُمحى معه.
         *
         * وإلّا بقي ممنوعًا بكوبونٍ لم ينتفع به: طلبٌ ألغاه المحلُّ نفسُه —
         * نفد الصنف، أو غيَّر الزبون رأيه — يأكل مرّةً من مرّاته ولا يردّها
         * شيء. والعدّادُ الإجماليُّ يُردّ في السطر الذي فوق، فيُردّ الحدّان معًا.
         */
        CouponLimits::release($order);
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
        $customer = $order->customer_id ? Customer::find($order->customer_id) : null;
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

    /**
     * صفُّ البيعة في الحركة يتبع الفاتورة — بما دُفع في البيعة نفسِها.
     *
     * ما بقي على العميل (`balance_due`) لم يدخل، وما حُصِّل أو رُدّ بعدُ
     * (`paid_after_sale`) صفٌّ مستقلٌّ بيومه ووسيلته لا يُكتب فوقه. وفاتورةٌ
     * بلا شيءٍ منهما يُكتب صفُّها بإجماليّها كما كان.
     */
    private static function syncTransaction(Order $order): void
    {
        self::saleRows($order)->update([
            'amount' => max(0.0, self::paidAtSale($order)),
            'tax_amount' => (float) $order->tax,
        ]);
    }

    /** صفوفُ البيعة في الحركة — لا صفوفُ ما سُوّي بعدها */
    private static function saleRows(Order $order)
    {
        return Transaction::where('order_id', $order->id)
            ->where(fn ($q) => $q->whereNull('kind')->orWhereNotIn('kind', Transaction::SETTLEMENT_KINDS));
    }

    /**
     * والدفترُ يتبع الفاتورة كما تتبعها معاملةُ المالية.
     *
     * ═══ ما كان ═══
     *
     * تصحيحُ كميّةٍ يعدّل الفاتورة والمعاملة والمخزون — والقيدُ يبقى على
     * الإيراد والضريبة والتكلفة القديمة. فشاشةُ الحركة المالية تقول ١١٫٥٥
     * وقائمةُ الدخل تقول ٢٣٫١ عن الفاتورة نفسها. حقلان يقولان الشيء نفسه
     * يفترقان يومًا — وقد افترقا. ومثلُه تصحيحُ وسيلة الدفع: المعاملةُ
     * تقول «بطاقة» والقيدُ ما زال يُدين الصندوق.
     *
     * ═══ وما صار ═══
     *
     * القيدُ القديم يُعكس (لا يُمحى — الدفتر لا يُمحى) ويُرحَّل قيدٌ جديد من
     * الفاتورة بعد تصحيحها عبر البابِ نفسه الذي رحّل الأوّل. وفاتورةٌ لم
     * يكن لها قيدٌ أصلًا تكسب واحدًا — وهو ما كانت `finance:post-missing-sales`
     * ستفعله ليلًا.
     */
    private static function syncBooks(Order $order, string $reason): void
    {
        $userId = PosCashier::id() ?? auth()->id();

        Books::unpostSale($order, $userId, __('تصحيح فاتورة: ').$reason);
        Books::recordSale($order->fresh(['items.addons']));
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
        $customer = $order->customer_id ? Customer::find($order->customer_id) : null;
        if (! $customer) {
            return;
        }

        $rate = (float) (Setting::where('business_id', $order->business_id)
            ->where('key', 'loyalty_earn_rate')->value('value') ?? 5);

        $enabled = (string) (Setting::where('business_id', $order->business_id)
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
