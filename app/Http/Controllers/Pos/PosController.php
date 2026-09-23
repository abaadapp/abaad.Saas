<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PdfController;
use App\Mail\NewOrderMail;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\AddonStock;
use App\Support\Bank;
use App\Support\Books;
use App\Support\Boutiques;
use App\Support\Contention;
use App\Support\CreditSales;
use App\Support\CustomerInvoices;
use App\Support\CustomArrangement;
use App\Support\CustomerPayments;
use App\Support\CustomerFlags;
use App\Support\Customers;
use App\Support\Demo;
use App\Support\Document\Snapshot;
use App\Support\PaymentMethods;
use App\Support\FlowerOrder;
use App\Support\Loyalty;
use App\Support\Money;
use App\Support\OrderNumbers;
use App\Support\OrderStatus;
use App\Support\PlanFeatures;
use App\Support\PosCashier;
use App\Support\PosTerminal;
use App\Support\ProductAddons;
use App\Support\ReceiptVisibility;
use App\Support\Recipe;
use App\Support\SaleLines;
use App\Support\SalesChannel;
use App\Support\SeasonSales;
use App\Support\Stock;
use App\Support\StockLedger;
use App\Support\Vat;
use App\Support\WhatsAppEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    /** صرفُ النقاط إلى مال — من بابه الواحد (انظر Support\Loyalty) */
    private const POINTS_PER_UNIT = Loyalty::POINTS_PER_UNIT;

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /** بنودُ البيعة — تسعيرًا وضريبةً ورفًّا: القاعدةُ في `SaleLines`، والصندوقُ ينادي عليها */
    private function lines(): SaleLines
    {
        return new SaleLines($this->bid());
    }

    private function taxFor(array $lines, float $subtotal, float $discount): float
    {
        return $this->lines()->taxFor($lines, $subtotal, $discount);
    }

    private function priceItems(array $items, bool $lock = false): array
    {
        return $this->lines()->priceItems($items, $lock);
    }

    private function assertStock(array $lines, ?int $branchId = null): void
    {
        $this->lines()->assertStock($lines, $branchId);
    }

    private static function addonConsumption(array $lines): array
    {
        return SaleLines::addonConsumption($lines);
    }

    /** رقمُ الفاتورة وبادئتُها — القاعدةُ في `OrderNumbers` */
    private function numbers(): OrderNumbers
    {
        return new OrderNumbers($this->bid());
    }

    private function salePrefix(): string
    {
        return $this->numbers()->salePrefix();
    }

    private function createNumbered(array $attrs, string $prefix, int $start = 1): Order
    {
        return $this->numbers()->createNumbered($attrs, $prefix, $start);
    }

    private function setting(string $key, $default = null)
    {
        return Setting::where('business_id', $this->bid())->where('key', $key)->value('value') ?? $default;
    }

    /**
     * تغذية المخزون — الكميات وحدها، تُستطلَع من شاشة البيع كل بضع ثوانٍ.
     *
     * بعد بيعِه هو يحدّث الكاشير قائمته بـreload جزئي، لكن بيع زميله على
     * جهاز آخر (أو تعديل المخزون من اللوحة، أو استلام أمر شراء) كان يبقى
     * خفيًّا حتى تُحدَّث الصفحة — فيَعِد الزبون بصنف نفد ثم يُرفض عند الدفع.
     *
     * لا يُعيد المنتجات كاملة عن قصد: الاسم والسعر والصورة لا تتغيّر كل
     * عشرين ثانية، والكمية هي وحدها المتحرّكة. حمولة أخفّ عشرات المرّات
     * على شبكة متجر قد تكون بطيئة.
     */
    public function stockFeed()
    {
        // نفس مصدر الشاشة عند فتحها: رصيد الفرع النشط. تغذيةٌ تقيس شيئًا
        // آخر غير ما عُرض أول مرّة تجعل الرقم يقفز بلا سبب ظاهر.
        $available = Stock::availabilityResolver($this->bid(), Demo::activeBranchId());

        $products = Product::where('business_id', $this->bid())
            ->orderBy('id')->get(['id', 'quantity', 'alert_qty', 'tracks_stock'])
            ->map(function ($p) use ($available) {
                $qty = $available($p->id, (int) $p->quantity);

                return [
                    'id' => $p->id,
                    'qty' => $qty,
                    'stock_status' => $p->stockStatusAt($qty),
                ];
            })->values();

        return response()->json([
            'products' => $products,
            'updated_at' => now()->format('H:i:s'),
        ]);
    }

    /**
     * طرق الدفع التي أذن بها التاجر.
     *
     * كانت مفاتيح pay_* تُحفظ ولا يقرؤها أحد: يُطفئ التاجر «بطاقة» فتبقى
     * معروضةً في الصندوق ومقبولةً — ثم يحاسب موظفًا قبِل ما ظنّ أنه منعه.
     *
     * ولا تعود فارغةً أبدًا: من أطفأ الثلاث لا يُراد به أن يقف البيع، فيبقى
     * النقد. حجبُ وسيلةٍ إعدادٌ، وإيقافُ الصندوق عطل.
     */

    /**
     * وسيلة الدفع تُختار ولا تُخمَّن.
     *
     * كانت تُردّ إلى أوّل المأذون حين تغيب أو لا تُعرف — أي أنّ بيعةً بالبطاقة
     * وصلت بوسيلةٍ خاطئة تُقيَّد «نقدي»، وبيعةً بلا وسيلةٍ أصلًا تُقيَّد نقدًا.
     * وأثرُ ذلك في الدرج لا في الشاشة: إقفال الوردية يطلب مالًا لم يدخل
     * الصندوق، فيقف الكاشير أمام عجزٍ لم يُحدثه ويُعوّضه من جيبه أو يُسجّله
     * فرقًا. وهو أسوأ صنف من العطب: كلّ ما يُرى منه سليم.
     *
     * فصارت مطلوبةً في التحقّق، وهذه تحرس ما بعده: قيمةٌ خارج المأذون تُردّ
     * بخطأ تحقّقٍ لا بتخمين.
     */
    private function paymentMethod(?string $requested): string
    {
        $allowed = PaymentMethods::enabled(Demo::businessSettings());

        if (! in_array($requested, $allowed, true)) {
            throw ValidationException::withMessages([
                'payment_method' => __('اختر وسيلة الدفع.'),
            ]);
        }

        return $requested;
    }

    /** فرع الطلب: الفرع المختار حاليًا، وإلا أول فرع للنشاط — حتى يظهر الطلب تحت فلتر الفروع */
    private function branch(): array
    {
        $branch = Branch::where('business_id', $this->bid())
            ->find(Demo::activeBranchId());

        return [
            'id' => $branch?->id,
            'name' => $branch?->name ?? 'الفرع الرئيسي',
        ];
    }

    /** بحث خادمي في كل فواتير المتجر (رقم/عميل/هاتف) — يغطّي كامل التاريخ لا آخر 30 فقط */
    public function searchReceipts(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['receipts' => []]);
        }

        // نفس التجريد المطبَّق على القائمة: البحث بلا هذا يُبطل الحجب كلّه،
        // لأنه يتجاوز الثلاثين إلى تاريخ الفرع كلّه
        return response()->json([
            'receipts' => ReceiptVisibility::filter(Demo::receipts($q, 50)),
        ]);
    }

    /**
     * فاتورة واحدة بتفاصيلها — تُفتح بالنقر على رقمها.
     *
     * تفصيلُ فاتورةٍ بعينها متاح للجميع: الزبون يستلمها مطبوعة على أي حال،
     * والكاشير يحتاجها عند الإرجاع. الممنوع هو الاطّلاع بالجملة.
     */
    public function showReceipt(string $number)
    {
        $receipt = collect(Demo::receipts($number, 50))->firstWhere('number', $number);

        abort_if($receipt === null, 404);

        return response()->json(['receipt' => $receipt]);
    }

    /**
     * ورقةُ الإيصال مرسومةً — HTML لا PDF.
     *
     * ═══ ولمَ بابٌ ثانٍ إلى جانب `receipt.pdf` ═══
     *
     * الكاشيرُ بعد البيع يريد أن **يرى** ما سيُسلَّم، لا أن يغادر صندوقَه.
     * وبابُ الـPDF يخرج ملفًّا: يفتحه المتصفّح بقارئه في لسانٍ آخر، فتختفي
     * شاشةُ البيع خلفه — وعلى الآيباد والهاتف لا يظهر شريطُ الألسنة أصلًا،
     * فيقف صاحبُ المحلّ أمام ورقةٍ لا يعرف كيف يرجع منها.
     *
     * فالمعاينةُ تُرسَم داخل الشاشة (`Components/DocumentPreview`)، وهذا
     * البابُ يعطيها نصَّها.
     *
     * ووصفتُها من `PdfController::saleHtml` نفسِها التي تُطبع، وبـ`thermal`
     * مثلِها — فما يراه الكاشير هو ما يخرج من الطابعة حرفًا بحرف. ولو بُنيت
     * هنا بيدها لَافترقت المعروضةُ عن المطبوعة يومًا، ولا يُكتشف ذلك إلّا
     * بعد أن يأخذ الزبون ورقته.
     *
     * ولا يُنتَج ملفٌّ: من جاء يقرأ لا يُشغَّل له محرّكُ طباعةٍ كامل.
     *
     * ويردّ `{html, size}` — نصَّ الورقة واسمَ مقاسها («80mm» أو «58mm»).
     */
    public function receiptPaper(string $number)
    {
        $bid = $this->bid();

        // بـ`business_id` لا `findOrFail`: إيصالُ متجرٍ آخر لا يوجد، لا «ممنوع»
        $order = Order::where('business_id', $bid)
            ->where('number', $number)
            ->with('items')
            ->first();

        abort_if($order === null, 404);

        $paper = PdfController::saleHtml($bid, $order, thermal: true);

        return response()->json(['html' => $paper['html'], 'size' => $paper['paper']]);
    }

    /** إتمام البيع وحفظ الطلب */
    /**
     * كوبون النشاط بالكود (غير حسّاس لحالة الأحرف).
     *
     * $lock: يُقرأ بقفلٍ عند الدفع — الفحص «هل بقيت مرّة؟» وزيادةُ العدّاد
     * يجب أن يقعا على صفٍّ لا يتغيّر تحتهما. وبلا ذلك يمرّ صندوقان معًا على
     * كوبونٍ محدودٍ بمرّةٍ واحدة فيستهلكانه مرّتين، ويخرج الخصم مرّتين من
     * كوبونٍ بيع مرّة.
     */
    private function findCoupon(?string $code, bool $lock = false): ?Coupon
    {
        if (empty($code)) {
            return null;
        }

        return Coupon::where('business_id', $this->bid())
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    /** التحقق من كود الخصم وتطبيقه (يُستدعى من السلة قبل الدفع) */
    public function applyCoupon(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $coupon = $this->findCoupon($data['code']);
        $subtotal = (float) $data['subtotal'];

        $error = match (true) {
            ! $coupon => __('كود الخصم غير صحيح'),
            ! $coupon->active => __('هذا الكوبون موقوف'),
            $coupon->isExpired() => __('انتهت صلاحية الكوبون'),
            $coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses => __('انتهت مرات استخدام الكوبون'),
            $subtotal < (float) $coupon->min_order => __('الحد الأدنى للطلب :amount', ['amount' => Demo::money($coupon->min_order)]),
            default => null,
        };

        if ($error) {
            return response()->json(['ok' => false, 'error' => $error], 422);
        }

        return response()->json([
            'ok' => true,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'discount' => $coupon->discountFor($subtotal),
            'message' => __('تم تطبيق الكوبون: :code', ['code' => $coupon->code]),
        ]);
    }

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.addon_id' => ['nullable', 'integer'],
            // المقاس والإضافات يُتحقّق منهما في priceItems: هناك وحده تُعرف
            // مقاسات المنتج وإضافاته المسموحة، والقاعدة لا تُكتب مرّتين
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.addons' => ['nullable', 'array'],
            'items.*.addons.*.addon_id' => ['required_with:items.*.addons', 'integer'],
            'items.*.addons.*.qty' => ['nullable', 'integer', 'min:1'],
            'items.*.name' => ['required', 'string'],
            // السعر يُقرأ من القاعدة لا من الطلب؛ يُقبل الحقل للتوافق ويُتجاهل
            'items.*.price' => ['nullable', 'numeric'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            // موسمُ البند كما اختاره الكاشير — يُتحقَّق منه في الخادم ويُطرح إن لم يصحّ (SeasonSales::attribute)
            'items.*.season_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'string'],
            // المعرّف هو ما تتبعه النقاط؛ والهاتف مرجعٌ ثانٍ حين يغيب
            'customer_id' => ['nullable', 'integer'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            /*
             * لغةُ رسائل واتساب لزبونٍ سُجّل قبل أن تُسأل — تُختار على
             * الصندوق وتُحمل مع البيعة لا في طلبٍ منفصل: بيعةٌ كُتبت في
             * الطابور بلا اتّصال ترفع لغتَها معها حين تعود الشبكة.
             */
            'customer_language' => ['nullable', 'string', \Illuminate\Validation\Rule::in(WhatsAppEvent::LANGUAGES)],
            // مطلوبة: انظر `paymentMethod` أدناه لأثر تخمينها في إقفال الوردية
            'payment_method' => ['required', 'string'],
            /*
             * البيعُ الآجل — والمدفوعُ الآن قد يكون بعضَه.
             *
             * ولا يُقرأ الباقي من الطلب: يُحسب في الخادم من الإجمالي ناقصَ
             * المدفوع. رقمٌ يرسله المتصفّح يجعل ذمّةً بمئة تُسجَّل بعشرة.
             */
            'credit' => ['nullable', 'boolean'],
            'paid_now' => ['nullable', 'numeric', 'min:0'],
            'due_at' => ['nullable', 'date'],
            'credit_override_reason' => ['nullable', 'string', 'max:200'],
            // سببُ تجاوز حظر البيع — يُقرأ في CustomerFlags::assertSellable وحدَها
            'block_override_reason' => ['nullable', 'string', 'max:200'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'resume_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'client_uuid' => ['nullable', 'string', 'max:64'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            /*
             * تفاصيل طلب الورد — اختياريّةٌ كلّها.
             *
             * بيعةُ المارّ يجب أن تبقى ثلاث نقرات: يضع الباقة، يضغط الدفع،
             * ينتهي. وإلزامُ المستلِم والموعد على كلّ بيعةٍ يجعل الكاشير يملأ
             * حقولًا لا معنى لها في نصف بيعات اليوم — فيملؤها بأيّ شيء،
             * وتصير البيانات أسوأ من غيابها.
             */
            /*
             * والطلبُ المخصَّص — بندٌ رُكّب على الطاولة لا صنفٌ من الكتالوج.
             *
             * وقواعدُه في `CustomArrangement::rules` لا مكتوبةً هنا: يقرؤها
             * هذا المسار ويقرؤها الحارس، وقائمتان تُكتبان باليد تفترقان يومًا.
             */
        ] + CustomArrangement::rules('items.*.custom') + FlowerOrder::rules(), [
            'payment_method.required' => __('اختر وسيلة الدفع.'),
        ] + FlowerOrder::messages());

        // والتوصيل وحده يُسأل عن مستلِمه وعنوانه — شرطٌ بين حقول لا على حقل
        if ($flowerErrors = FlowerOrder::afterValidation($data)) {
            throw ValidationException::withMessages($flowerErrors);
        }

        // صمود الانقطاع: لو أُعيد رفع نفس الطلب (بعد عودة الاتصال) نعيد الفاتورة الأصلية بدل تكراره
        if (! empty($data['client_uuid'])) {
            $existing = Order::where('business_id', $this->bid())
                ->where('client_uuid', $data['client_uuid'])
                ->first();
            if ($existing) {
                return response()->json(['ok' => true, 'invoice' => $existing->number, 'duplicate' => true]);
            }
        }

        /*
         * ومن خسر السباق يُردّ إليه رقمُ الفاتورة الأولى — لا خطأُ خادم.
         *
         * الفحص أعلاه يسبق الكتابة، وبينهما فرجة. والقيد في القاعدة هو ما
         * يمنع فعلًا (انظر الهجرة one_uuid_one_invoice): فحين يصل طلبان
         * بالمفتاح نفسه معًا يكتب الأوّل ويُردّ الثاني بانتهاك القيد — وهو
         * ليس عطبًا بل الحارس يعمل. فيُقرأ كما يُقرأ الفحص: بيعةٌ واحدة،
         * وفاتورةٌ واحدة تُطبع، ولا مخزونَ يُخصم مرّتين ولا دخلَ يُقيَّد مرّتين.
         */
        try {
            $result = $this->completeSale($data);
        } catch (QueryException $e) {
            // وأيّ صنفٍ كان الاستثناء: الشاهد وجودُ التوأم لا اسمُ الخطأ
            $twin = filled($data['client_uuid'] ?? null)
                ? Order::where('business_id', $this->bid())->where('client_uuid', $data['client_uuid'])->first()
                : null;

            if (! $twin) {
                throw $e;
            }

            return response()->json(['ok' => true, 'invoice' => $twin->number, 'duplicate' => true]);
        }

        $order = $result['order'];

        // وسطرُ السجلّ يُقرأ في شاشة «النشاط» — فالمبلغُ بعملة المتجر لا مثبَّتًا
        Activity::log('checkout', 'أتمّ بيعًا '.$order->number.' بقيمة '.Money::format((float) $order->total, Money::of($this->bid())), ['subject_id' => $order->id]);

        // البريد خارج المعاملة: بطؤه أو فشله يجب ألّا يُبقي القفل أو يُلغي بيعًا تمّ
        $this->notifyNewOrder($order);

        return response()->json([
            'ok' => true,
            'invoice' => $order->number,
            'total' => (float) $order->total,
            'points_earned' => $result['loyalty']['earned'],
            'points_redeemed' => $result['loyalty']['redeemed'],
        ]);
    }

    /**
     * البيع سبع كتابات مترابطة (طلب، بنود، مخزون، حركات، معاملة، نقاط، تنظيف
     * المعلّق). انقطاعٌ في المنتصف كان يترك طلبًا بلا معاملة مالية أو مخزونًا
     * منقوصًا بلا فاتورة — فتُنفَّذ كلها أو لا تُنفَّذ أيٌّ منها.
     *
     * @return array{order: Order, loyalty: array{earned: int, redeemed: int}}
     */
    private function completeSale(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $bid = $this->bid();
            $branch = $this->branch();

            /*
             * والسلّة المعلّقة تُحجَز أوّلًا — لا تُحذف آخرًا وحسب.
             *
             * كانت تُقرأ في نهاية المعاملة لتُمحى. فجهازان يستأنفان السلّة
             * نفسها — وهي معروضةٌ على كلّ أجهزة الفرع — يقرآنها موجودةً
             * كلاهما، فتصير سلّةٌ واحدة فاتورتين: بضاعةٌ تُخصم مرّتين وزبونٌ
             * يُطالَب بضعف ثمنها.
             *
             * والقفل يُصفّهما: الثاني ينتظر، فلا يجدها، فيُقال له إنّها
             * أُتمّت — وهو ما حدث فعلًا.
             */
            if (! empty($data['resume_id'])) {
                $heldNow = Order::where('business_id', $bid)->where('is_held', true)
                    ->lockForUpdate()->find($data['resume_id']);

                if (! $heldNow) {
                    throw ValidationException::withMessages([
                        'resume_id' => __('هذه السلّة المعلّقة أُتمّت من جهازٍ آخر.'),
                    ]);
                }
            }

            // بقفل: الفحص والخصم يجب أن يقعا على كمية لا تتغيّر تحتهما،
            // وعلى رصيد الفرع الذي سيُخصم منه لا على مجموع الشركة
            $lines = $this->priceItems($data['items'], lock: true);
            $this->assertStock($lines, $branch['id']);

            /*
             * موسمُ كلّ بند — يُقرّر هنا ويُكتب لقطةً على البند.
             *
             * تحليلٌ لا قيد: لا يُغيّر ثمنًا ولا ضريبةً ولا ترحيلًا، ولا تُردّ
             * بيعةٌ لأنّ موسمَها لم يصحّ — يُطرح الموسمُ وتمضي البيعة.
             */
            $seasonOf = SeasonSales::attribute($bid, $lines);
            // وصاحبُ الصنف ونسبتُه — يُقرآن من الصنف لا ممّا أرسلته الشاشة
            $boutiqueOf = Boutiques::attribute($bid, $lines);

            // الإضافات جزءٌ من ثمن البند لا سطرٌ منفصل: «بوكيه + شوكولاتة»
            // بندٌ واحد يقرؤه الزبون على الفاتورة، ومجموعُه يدخل الحساب معه
            $subtotal = round(collect($lines)->sum(fn ($l) => $l['price'] * $l['qty'] + ($l['addons_total'] ?? 0)), 3);

            /*
             * الكوبون: يُعاد التحقق منه خادميًا وتُحتسب قيمته من أسعارنا نحن.
             *
             * وما لا يصلح يُقال لا يُبتلع. كان يسقط صمتًا — `couponApplied`
             * تصير false وتمضي البيعة — فيُقال للزبون سعرٌ عند السلّة ويُطبع
             * له غيره على الفاتورة، ولا شيء على شاشة الكاشير يقول لماذا.
             * وأكثر ما يقع بين اللحظتين: كوبونٌ نفدت مرّاته من صندوقٍ آخر،
             * أو انتهت صلاحيته عند منتصف الليل والوردية ما زالت مفتوحة.
             *
             * وبقفلٍ: العدّاد يُقرأ ويُزاد على صفٍّ لا يتغيّر تحته.
             */
            $couponCode = $data['coupon_code'] ?? null;
            $coupon = $this->findCoupon($couponCode, lock: true);

            if (filled($couponCode)) {
                $refusal = match (true) {
                    ! $coupon => __('كود الخصم غير صحيح'),
                    ! $coupon->active => __('هذا الكوبون موقوف'),
                    $coupon->isExpired() => __('انتهت صلاحية الكوبون'),
                    $coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses => __('انتهت مرات استخدام الكوبون'),
                    $subtotal < (float) $coupon->min_order => __('الحد الأدنى للطلب :amount', ['amount' => Demo::money($coupon->min_order)]),
                    default => null,
                };

                if ($refusal !== null) {
                    throw ValidationException::withMessages([
                        'coupon_code' => $refusal,
                    ]);
                }
            }

            $couponApplied = $coupon !== null;
            $couponDiscount = $couponApplied ? min((float) $coupon->discountFor($subtotal), $subtotal) : 0.0;

            // موعدٌ في المستقبل يعني طلبًا يُجهَّز لا بيعةً انتهت — انظر 'status' أدناه
            $scheduled = filled($data['scheduled_for'] ?? null);

            $customer = $this->customerFor($data['customer'] ?? null, $data['customer_id'] ?? null, $data['customer_phone'] ?? null);
            /*
             * والزبونُ الموقوف يُردّ هنا لا في الشاشة وحدها — طلبٌ عُدّل بيده
             * يصل إلى السطر نفسه. والتجاوزُ بسببٍ ممّن يملكه، ويُقيَّد.
             */
            CustomerFlags::assertSellable(
                $customer,
                CustomerFlags::settings($bid),
                auth()->user(),
                $data['block_override_reason'] ?? null,
            );
            /*
             * ولا تُتمّ بيعةٌ لزبونٍ لا يُعرف بأيّ لغةٍ يُراسَل.
             *
             * الشاشةُ تسأل قبل الدفع، والخادمُ يردّ إن لم تُسأل: الرسالةُ تخرج
             * وحدَها بعد البيعة، فهذه آخرُ لحظةٍ يُسأل فيها أحد. والجوابُ
             * يُكتب في بطاقته فلا يُسأل ثانيةً.
             */
            if ($customer !== null && $customer->language === null) {
                if (($data['customer_language'] ?? null) === null) {
                    throw ValidationException::withMessages([
                        'customer_language' => __('اختر لغة رسائل واتساب للعميل قبل إتمام البيع.'),
                    ]);
                }
                $customer->forceFill(['language' => $data['customer_language']])->save();
            }
            $redeem = $this->resolveRedemption($customer, $subtotal, $couponDiscount, (int) ($data['redeem_points'] ?? 0));

            $discount = round(min($couponDiscount + $redeem['discount'], $subtotal), 3);
            $delivery = (float) ($data['delivery_fee'] ?? 0);
            $tax = $this->taxFor($lines, $subtotal, $discount);

            /*
             * «مشمولة»: المعروض هو المستحقّ، فالمجموع الفرعي يُنقص منه ما
             * استُخرج ضريبةً — ويبقى `subtotal - discount + tax` مساويًا لما
             * قرأه الزبون على الشاشة. وبلا هذا تُجمع الضريبة مرّتين: مرّةً
             * داخل السعر ومرّةً فوقه.
             */
            if (Vat::inclusive($bid)) {
                $subtotal = round($subtotal - $tax, 3);
            }

            $total = round($subtotal - $discount + $tax + $delivery, 3);

            /*
             * البيعُ الآجل — وشروطُه تُفحص قبل أن يُكتب صفٌّ واحد.
             *
             * والمدفوعُ الآن لا يتجاوز الإجمالي: زيادةٌ عليه ليست بيعًا آجلًا
             * بل دفعةً مقدَّمة، ولها بابٌ آخر.
             */
            $isCredit = (bool) ($data['credit'] ?? false);
            $paidNow = $isCredit ? round(min((float) ($data['paid_now'] ?? 0), $total), 3) : $total;
            $creditAmount = $isCredit ? round($total - $paidNow, 3) : 0.0;

            if ($isCredit) {
                // مقبضُ الإعدادات قبل شروط العميل: من أطفأ الآجلَ لا يُسأل عن حدّه
                if (! PaymentMethods::creditAllowedFor($bid)) {
                    throw ValidationException::withMessages([
                        'credit' => __('البيع الآجل مُطفأ في إعدادات المتجر — يُفعَّل من الإعدادات: طرق الدفع.'),
                    ]);
                }
                CreditSales::assertAllowed(
                    $customer,
                    $creditAmount,
                    auth()->user(),
                    $data['credit_override_reason'] ?? null,
                );
            }

            if ($couponApplied) {
                $coupon->increment('used_count');
            }

            $method = $this->paymentMethod($data['payment_method'] ?? null);
            $device = PosTerminal::current();

            $order = $this->createNumbered([
                'business_id' => $bid,
                'client_uuid' => $data['client_uuid'] ?? null,
                'customer_name' => $customer?->name ?? $data['customer'] ?? 'عميل نقدي',
                'customer_name_en' => $customer?->name_en,
                'customer_id' => $customer?->id,
                'employee_name' => PosCashier::name(),
                // المعرّف لا الاسم وحده: لوحة أداء الموظفين تجمع المبيعات
                // على user_id، وكان لا يُكتب أصلًا فتظهر الأرقام أصفارًا
                'user_id' => PosCashier::id(),
                'branch_id' => $branch['id'],
                'branch' => $branch['name'],
                /*
                 * الصندوق الذي خرجت منه الفاتورة.
                 *
                 * يُقرأ من الخادم لا من الطلب: القيمة الوحيدة الموثوقة هي
                 * رمز الجهاز في الكوكي الموقَّعة. وحين ينقص الدرج عشرين ريالًا
                 * في محلٍّ فيه ثلاثة صناديق، هذا العمود وحده يقول أيّها.
                 */
                'pos_device_id' => $device?->id,
                // البابُ الذي دخل منه الطلب — يُكتب حين يُعرف، والصندوقُ يعرفه
                'channel' => SalesChannel::POS,
                /*
                 * والبنكُ الذي دخله المال — لقطةً، لا قراءةً متأخّرة.
                 *
                 * جهازُ الشبكة موصولٌ ببنكٍ بعينه، والمديرُ قد ينقله إلى بنكٍ
                 * آخر بعد شهر. فلو قُرئ الجهازُ يومَ الترحيل لقال عن بيعةٍ
                 * قديمة غيرَ ما وقع — ولانتقل رصيدٌ في الميزانية من حسابٍ إلى
                 * حساب بلا قيد. انظر `Bank::depositFor`.
                 *
                 * والنقدُ لا حسابَ بنكيًّا له: مالٌ في الدرج يحمل اسمَ بنكٍ لا
                 * يطابقه كشفُه أبدًا لأنّه لم يمرّ به — وهي القاعدة نفسُها في
                 * `CustomerPayments::accountFor`، ولا تفترق عنها هنا.
                 */
                'bank_account_id' => in_array($method, Bank::METHODS, true)
                    ? Bank::depositFor($bid, $device?->id)
                    : null,
                'payment_method' => $method,
                /*
                 * وحالُ السداد تتبع ما وقع فعلًا.
                 *
                 * وهي مفتاحُ القيد: `Books::recordSale` تُدين `receivable`
                 * حين تكون الفاتورة غير مدفوعة، و`cash`/`bank` حين تكون
                 * مدفوعة. فالبيعُ الآجل لا يحتاج مسارَ ترحيلٍ ثانيًا — يحتاج
                 * أن يُقال للطلب إنّه لم يُدفع. ومسارُ ترحيلٍ ثانٍ للبيعة
                 * نفسها يعني إيرادًا مضاعفًا على كلّ متجر.
                 *
                 * والمدفوعُ الآن — إن دفع بعضَه — يُسجَّل تحصيلًا بعد الترحيل:
                 * مدين الصندوق / دائن الذمم. فيصير الأثر: ذمّةٌ بالباقي ونقدٌ
                 * بما قُبض، بلا عدٍّ مزدوج.
                 */
                'payment_status' => $isCredit ? 'غير مدفوع' : 'مدفوع',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'coupon_code' => $couponApplied ? $coupon->code : null,
                // ثمن العرض وحده: `discount` يجمعه مع نقاط الولاء فلا يُعرف
                // كم كلّف كوبونٌ بعينه — وهو ما يقرّر إعادته أو إيقافه
                'coupon_discount' => $couponDiscount,
                'tax' => $tax,
                'delivery_fee' => $delivery,
                'total' => $total,
                'ordered_at' => now(),
                /*
                 * الحالة تتبع الطلب لا العكس.
                 *
                 * بيعةُ المنضدة تُدفع وتُؤخذ في اللحظة نفسها فهي «مكتمل» كما
                 * كانت. أمّا ما له موعدٌ في المستقبل فلم يكتمل شيء منه بعد:
                 * يُسجَّل «جديد» ليدخل لوحة التجهيز. ولو بقي «مكتمل» لَما ظهر
                 * لعامل التجهيز أبدًا — فيُجهَّز الطلب بورقةٍ على الجدار كما
                 * كان قبل النظام.
                 */
                'status' => $scheduled
                    ? OrderStatus::PENDING
                    : OrderStatus::COMPLETED,
            ] + FlowerOrder::attributes($data),
                $this->salePrefix(), max(1, (int) $this->setting('inv_start', 1)));

            foreach ($lines as $idx => $l) {
                $item = $order->items()->create([
                    'product_id' => $l['product']?->id,
                    // موسمُ البند كما نُسب ساعةَ البيع — معرّفًا واسمًا، انظر SeasonSales
                    'season_id' => $seasonOf[$idx]['id'] ?? null,
                    'season_name' => $seasonOf[$idx]['name'] ?? null,
                    /*
                     * لقطة المقاس — لا علاقةٌ تُقرأ لاحقًا.
                     *
                     * مقاسٌ أُعيد تسميته «وسط فاخر» ورُفع سعره بعد شهر لا
                     * يجوز أن يغيّر فاتورةً طُبعت. والمعرّف يبقى للتجميع،
                     * والاسم والرمز هما ما يُعرض.
                     */
                    'variant_id' => $l['variant']?->id,
                    'variant_name' => $l['variant']?->name,
                    'variant_sku' => $l['variant']?->sku,
                    'name' => $l['name'],
                    'price' => $l['price'],
                    /*
                     * تكلفة القطعة تُلتقط يوم البيع لا تُقرأ يوم التقرير.
                     *
                     * تكلفة المنتج تُكتب فوقها عند كل استلامٍ بآخر سعر شراء،
                     * فقراءتُها لاحقًا تجعل ربح الشهر الماضي يتغيّر لأن المورّد
                     * رفع سعره اليوم. واللقطة تُثبّت ما مضى. ولذي الوصفة
                     * تكلفتُه مجموعُ مكوّناته بأسعار اليوم — انظر Recipe::unitCost.
                     */
                    /*
                     * وصاحبُ البند ونسبتُه وتكلفتُه — من موضعٍ واحد.
                     *
                     * وتكلفةُ ما هو أمانةٌ صفر: البوتيكُ يملكها حتّى تُباع،
                     * فقيدُ تكلفةِ بضاعةٍ مباعة لها يحسب ثمنَها مرّتين —
                     * مرّةً هنا ومرّةً مصروفًا يومَ التسوية. انظر `Boutiques`.
                     */
                    ...Boutiques::itemColumns($boutiqueOf[$idx] ?? null, (float) ($l['cost'] ?? 0)),
                    'quantity' => $l['qty'],
                    'note' => $l['note'],
                    'total' => round($l['price'] * $l['qty'], 3),
                    'addons_total' => (float) ($l['addons_total'] ?? 0),
                    // وصفُ الطلب المخصَّص — فارغٌ لكلّ بندٍ من الكتالوج
                    'custom_details' => isset($l['custom'])
                        ? CustomArrangement::details(
                            $l['custom'],
                            (float) $l['cost'],
                            $l['template'] ?? null,
                            $l['fields'] ?? [],
                        )
                        : null,
                ]);

                /*
                 * ولقطةُ الموادّ تُكتب مع البند — لا تُقرأ من وصفةٍ يومًا.
                 *
                 * الوصفةُ العاديّة معرَّفةٌ في `recipe_items` وتخصّ المنتج،
                 * وهذه اختِيرت لهذا الطلب وحده. فإن تغيّر اسمُ الورد أو
                 * تكلفتُه أو حُذف من الكتالوج، بقي طلبُ الشهر الماضي مفهومًا.
                 *
                 * وتُكتب في البيع وفي التعليق معًا: سلّةٌ عُلّقت ثمّ استُؤنفت
                 * يجب أن تعود بموادّها — وإلّا خرجت الباقةُ نفسُها بمكوّناتٍ
                 * أقلّ ولا أحدَ يعلم.
                 */
                foreach ($l['components'] ?? [] as $c) {
                    $item->components()->create([
                        'product_id' => $c['product']->id,
                        'name' => $c['name'],
                        'sku' => $c['sku'],
                        'kind' => $c['kind'],
                        'quantity' => $c['quantity'],
                        'unit_cost' => $c['unit_cost'],
                        'total_cost' => $c['total_cost'],
                        'restockable' => $c['restockable'],
                    ]);
                }

                foreach ($l['addons'] ?? [] as $a) {
                    $item->addons()->create([
                        'addon_id' => $a['addon']->id,
                        'name' => $a['addon']->name,
                        'name_en' => $a['addon']->name_en,
                        'unit_price' => $a['price'],
                        'quantity' => $a['qty'],
                        'total' => $a['total'],
                        'cost' => $a['cost'],
                        /*
                         * لقطةُ ما أُخذ من الرفّ — لا علاقةٌ تُقرأ يوم الإلغاء.
                         *
                         * إضافةٌ كانت ثلاث ورداتٍ فصارت خمسًا تردّ خمسًا عن
                         * بيعةٍ أخذت ثلاثًا لو قُرئت اليوم — فيربح الرفّ
                         * وردتين لا وجود لهما.
                         */
                        'inventory_product_id' => $a['inventory_product_id'] ?? null,
                        'inventory_quantity' => ($a['inventory_product_id'] ?? null) ? $a['each'] : null,
                    ]);
                }
            }

            /*
             * المخزون يُخصم مرّةً واحدة للسلّة كلّها لا بندًا بندًا.
             *
             * لأنّ مكوّنًا واحدًا يدخل في باقاتٍ شتّى: خصمُه في كلّ بندٍ على
             * حدة يقرّب كسرَه مرّاتٍ — انظر Recipe::units — ويكتب أربع حركات
             * حيث تكفي واحدة، فيصير سجلّ التدقيق أطول وأقلّ إفادة.
             *
             * وذو الوصفة لا يُخصم هو: مكوّناته هي مخزونه. وخصمُه معها كان
             * سيُنقص الباقة والورد معًا عن بيعةٍ واحدة.
             */
            $sale = [];
            $recipeUse = [];
            // الخصم يقرأ ما قرأه الفحص قبله بالحرف — نفس الدالّة لا نسختها
            $addonUse = AddonStock::units(self::addonConsumption($lines));

            foreach ($lines as $l) {
                // الخصمُ يقرأ ما قرأه `demand` بالحرف — نفس الدالّة لا نسختها
                foreach (CustomArrangement::consumption($l['components'] ?? [], (int) $l['qty']) as $pid => $q) {
                    $recipeUse[$pid] = ($recipeUse[$pid] ?? 0.0) + $q;
                }

                if (! $l['product']) {
                    continue;
                }

                if (! ($l['has_recipe'] ?? false)) {
                    $sale[$l['product']->id] = ($sale[$l['product']->id] ?? 0) + $l['qty'];

                    continue;
                }

                foreach (Recipe::consumptionFor($l['product'], $l['variant'] ?? null, $l['qty'], $l['recipe']) as $pid => $q) {
                    $recipeUse[$pid] = ($recipeUse[$pid] ?? 0.0) + $q;
                }
            }

            $cashier = PosCashier::name();

            StockLedger::move($bid, $branch['id'],
                array_map(fn ($q) => -$q, $sale), 'بيع', $cashier);

            StockLedger::move($bid, $branch['id'],
                array_map(fn ($q) => -Recipe::units($q), $recipeUse),
                StockLedger::RECIPE, $cashier, $order->number);

            StockLedger::move($bid, $branch['id'],
                array_map(fn ($q) => -$q, $addonUse),
                StockLedger::ADDON, $cashier, $order->number);

            // تسجيل البيع كمعاملة دخل في المالية تلقائيًا (لتظهر المبيعات في لوحات المالية فورًا)
            Transaction::create([
                'business_id' => $bid,
                'order_id' => $order->id,
                'reference' => $order->number,
                'description' => 'مبيعات نقطة البيع — '.($order->customer_name ?? 'عميل نقدي'),
                /*
                 * ونوعُ الحركة يُكتب هنا لا يُترك فارغًا.
                 *
                 * `kind` هو ما ترشّح به «الحركة المالية» وتسمّي به الصفّ،
                 * وما تقرأ به `Transaction::scopeSales` المبيعاتِ وحدها.
                 * وكان يُترك فارغًا منذ أُضيف العمود — فكلُّ بيعةٍ بعده تُقرأ
                 * «حركة» لا «مبيعات»، ولا يبلغها مُرشِّحٌ ولا نطاقُ مبيعات.
                 */
                'kind' => Transaction::SALE,
                'method' => $order->payment_method ?? 'نقدي',
                // ولقطةُ البنك تُنقل إلى المعاملة: بها تُرشَّح مطابقةُ كشف
                // الحساب، فلا يُطابَق كشفُ بنكٍ بحركةٍ لم تمرّ به
                'bank_account_id' => $order->bank_account_id,
                'type' => 'دخل',
                'amount' => $order->total,
                'tax_amount' => $order->tax ?? 0,
                'employee_name' => PosCashier::name(),
                'occurred_at' => $order->ordered_at ?? now(),
            ]);

            // الطلب المعلّق الذي استُكمل لم يعد لازمًا بعد إتمام بيعه
            if (! empty($data['resume_id'])) {
                $held = Order::where('business_id', $bid)->where('is_held', true)->find($data['resume_id']);
                if ($held) {
                    $held->items()->delete();
                    $held->delete();
                }
            }

            $loyalty = $this->recordLoyalty($order, $customer, $redeem['points']);

            /*
             * والبيعة تصل إلى دفتر الأستاذ — لا إلى دفتر الصندوق وحده.
             *
             * وترحيلٌ يسقط لا يُسقط بيعةً وقعت: شجرةُ حساباتٍ عدّلها التاجر —
             * حسابٌ أُغلق أو صار له فرعٌ تحته — تجعل `Ledger::post` ترفض،
             * ولو رُبط بها البيع لتوقّف الصندوق عن العمل والزبون واقف. فيُقيَّد
             * الإخفاق في السجلّ باسمه ليُستدرَك بأمر finance:post-missing-sales.
             */
            try {
                Books::recordSale($order);
            } catch (\Throwable $e) {
                Activity::log('updated', 'تعذّر ترحيل قيد البيع '.$order->number.': '.$e->getMessage(), [
                    'subject_id' => $order->id, 'subject_type' => 'order',
                ]);
            }

            /*
             * والبيعةُ الآجلة تحمل ورقتَها.
             *
             * الطلبُ تنفيذٌ ومخزون، والفاتورةُ التزامٌ على العميل: لها تاريخُ
             * استحقاقٍ وتُسدَّد على دفعات وتدخل كشفَ الحساب وتقريرَ الأعمار.
             * وحالُ السداد في الطلب لا تحمل شيئًا من ذلك.
             *
             * ولا تُرحَّل هذه الفاتورة: بيعتُها رُحّلت قبل سطرين. انظر
             * `CustomerInvoices::post`.
             */
            /*
             * وعميلُ الفوترة الشهريّة لا تُطبع لبيعته ورقةٌ لحظتَها.
             *
             * شركةٌ تشتري ثلاث مرّاتٍ في الشهر لا تريد ثلاثَ فواتير. وتتراكم
             * بيعاتُها بلا ورقة حتّى تُجمع آخرَ الشهر — ورصيدُها ظاهرٌ من
             * لحظته في «الذمم» غيرَ مفوتَر، لا يختفي حتّى تُطبع ورقتُه.
             *
             * ودفعةٌ الآن على بيعةٍ لن تُفوتَر تُسجَّل رصيدًا للعميل: دفعةٌ بلا
             * فاتورةٍ تُخصَّص لها تبقى غيرَ مخصَّصة، وتُخصَّم من ورقة الشهر
             * حين تُطبع.
             */
            if ($isCredit && $customer?->monthly_billing) {
                if ($paidNow > 0) {
                    CustomerPayments::record(
                        (int) $order->business_id,
                        $customer,
                        $paidNow,
                        ['method' => $order->payment_method, 'occurred_at' => now()],
                        [],
                        PosCashier::id(),
                    );
                }
            } elseif ($isCredit) {
                $invoice = CustomerInvoices::issue(
                    CustomerInvoices::fromOrder($order, [
                        'due_at' => $data['due_at'] ?? null,
                    ], PosCashier::id()),
                    PosCashier::id(),
                );

                if ($paidNow > 0) {
                    CustomerPayments::record(
                        (int) $order->business_id,
                        $customer,
                        $paidNow,
                        ['method' => $order->payment_method, 'occurred_at' => now()],
                        [$invoice->id => $paidNow],
                        PosCashier::id(),
                    );
                }
            }

            return ['order' => $order, 'loyalty' => $loyalty];
        });
    }

    /** تعليق الطلب */
    public function hold(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.addon_id' => ['nullable', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.addons' => ['nullable', 'array'],
            'items.*.addons.*.addon_id' => ['required_with:items.*.addons', 'integer'],
            'items.*.addons.*.qty' => ['nullable', 'integer', 'min:1'],
            'items.*.name' => ['required', 'string'],
            'items.*.price' => ['nullable', 'numeric'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            // موسمُ البند كما اختاره الكاشير — يُتحقَّق منه في الخادم ويُطرح إن لم يصحّ (SeasonSales::attribute)
            'items.*.season_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'string'],
            'total' => ['nullable', 'numeric'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            // معلّق = بانتظار الاستكمال الآن · محفوظ = مسودّة للرجوع إليها لاحقًا
            'kind' => ['nullable', 'in:hold,save'],
            // والطلبُ المخصَّص يُعلَّق بموادّه — وإلّا عاد سطرًا بسعرٍ بلا مواد
        ] + CustomArrangement::rules('items.*.custom'));
        $saved = ($data['kind'] ?? 'hold') === 'save';

        // المعلّق يُستكمل لاحقًا فيصير فاتورة، فأسعاره تُقرأ من القاعدة أيضًا.
        // ولا حارس مخزون هنا: التعليق لا يخصم شيئًا، والحارس يعمل عند الدفع.
        return DB::transaction(function () use ($data, $saved) {
            $lines = $this->priceItems($data['items']);
            // والموسمُ يُعلَّق مع البند ليعود معه — ويُقرَّر ثانيةً عند الدفع
            $seasonOf = SeasonSales::attribute($this->bid(), $lines);
            $boutiqueOf = Boutiques::attribute($this->bid(), $lines);
            // الإضافات جزءٌ من ثمن البند لا سطرٌ منفصل: «بوكيه + شوكولاتة»
            // بندٌ واحد يقرؤه الزبون على الفاتورة، ومجموعُه يدخل الحساب معه
            $subtotal = round(collect($lines)->sum(fn ($l) => $l['price'] * $l['qty'] + ($l['addons_total'] ?? 0)), 3);
            $branch = $this->branch();

            $order = $this->createNumbered([
                'business_id' => $this->bid(),
                'customer_name' => $data['customer'] ?? 'عميل نقدي',
                'employee_name' => PosCashier::name(),
                // المعرّف لا الاسم وحده: لوحة أداء الموظفين تجمع المبيعات
                // على user_id، وكان لا يُكتب أصلًا فتظهر الأرقام أصفارًا
                'user_id' => PosCashier::id(),
                'branch_id' => $branch['id'],
                'branch' => $branch['name'],
                'status' => $saved ? 'محفوظ' : 'معلّق',
                'is_held' => true,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                // الكود وحده يُحفظ لا قيمة خصمه: الطلب قد يُستكمل غدًا وقد
                // يكون الكوبون انتهى أو نفدت مرات استخدامه، فيُعاد التحقق
                // منه وقت الدفع لا وقت التعليق.
                'coupon_code' => $data['coupon_code'] ?? null,
                'ordered_at' => now(),
            ], $saved ? 'SAVE-' : 'HOLD-');

            // حفظ الأصناف — بدونها لا يمكن استكمال الطلب لاحقًا. والمقاس
            // والإضافات معها: طلبٌ عُلّق بمقاسٍ وشوكولاتة يجب أن يعود كما
            // عُلّق، لا مجرّدًا من نصف اختيار الزبون
            foreach ($lines as $idx => $l) {
                $item = $order->items()->create([
                    'product_id' => $l['product']?->id,
                    // موسمُ البند كما نُسب ساعةَ البيع — معرّفًا واسمًا، انظر SeasonSales
                    'season_id' => $seasonOf[$idx]['id'] ?? null,
                    'season_name' => $seasonOf[$idx]['name'] ?? null,
                    'variant_id' => $l['variant']?->id,
                    'variant_name' => $l['variant']?->name,
                    'variant_sku' => $l['variant']?->sku,
                    'name' => $l['name'],
                    'price' => $l['price'],
                    // لقطة التكلفة — انظر التعليق في إتمام البيع
                    /*
                     * وصاحبُ البند ونسبتُه وتكلفتُه — من موضعٍ واحد.
                     *
                     * وتكلفةُ ما هو أمانةٌ صفر: البوتيكُ يملكها حتّى تُباع،
                     * فقيدُ تكلفةِ بضاعةٍ مباعة لها يحسب ثمنَها مرّتين —
                     * مرّةً هنا ومرّةً مصروفًا يومَ التسوية. انظر `Boutiques`.
                     */
                    ...Boutiques::itemColumns($boutiqueOf[$idx] ?? null, (float) ($l['cost'] ?? 0)),
                    'quantity' => $l['qty'],
                    'note' => $l['note'],
                    'total' => round($l['price'] * $l['qty'], 3),
                    'addons_total' => (float) ($l['addons_total'] ?? 0),
                    // وصفُ الطلب المخصَّص — فارغٌ لكلّ بندٍ من الكتالوج
                    'custom_details' => isset($l['custom'])
                        ? CustomArrangement::details(
                            $l['custom'],
                            (float) $l['cost'],
                            $l['template'] ?? null,
                            $l['fields'] ?? [],
                        )
                        : null,
                ]);

                /*
                 * ولقطةُ الموادّ تُكتب مع البند — لا تُقرأ من وصفةٍ يومًا.
                 *
                 * الوصفةُ العاديّة معرَّفةٌ في `recipe_items` وتخصّ المنتج،
                 * وهذه اختِيرت لهذا الطلب وحده. فإن تغيّر اسمُ الورد أو
                 * تكلفتُه أو حُذف من الكتالوج، بقي طلبُ الشهر الماضي مفهومًا.
                 *
                 * وتُكتب في البيع وفي التعليق معًا: سلّةٌ عُلّقت ثمّ استُؤنفت
                 * يجب أن تعود بموادّها — وإلّا خرجت الباقةُ نفسُها بمكوّناتٍ
                 * أقلّ ولا أحدَ يعلم.
                 */
                foreach ($l['components'] ?? [] as $c) {
                    $item->components()->create([
                        'product_id' => $c['product']->id,
                        'name' => $c['name'],
                        'sku' => $c['sku'],
                        'kind' => $c['kind'],
                        'quantity' => $c['quantity'],
                        'unit_cost' => $c['unit_cost'],
                        'total_cost' => $c['total_cost'],
                        'restockable' => $c['restockable'],
                    ]);
                }

                foreach ($l['addons'] ?? [] as $a) {
                    $item->addons()->create([
                        'addon_id' => $a['addon']->id,
                        'name' => $a['addon']->name,
                        'name_en' => $a['addon']->name_en,
                        'unit_price' => $a['price'],
                        'quantity' => $a['qty'],
                        'total' => $a['total'],
                        'cost' => $a['cost'],
                        /*
                         * لقطةُ ما أُخذ من الرفّ — لا علاقةٌ تُقرأ يوم الإلغاء.
                         *
                         * إضافةٌ كانت ثلاث ورداتٍ فصارت خمسًا تردّ خمسًا عن
                         * بيعةٍ أخذت ثلاثًا لو قُرئت اليوم — فيربح الرفّ
                         * وردتين لا وجود لهما.
                         */
                        'inventory_product_id' => $a['inventory_product_id'] ?? null,
                        'inventory_quantity' => ($a['inventory_product_id'] ?? null) ? $a['each'] : null,
                    ]);
                }
            }

            return response()->json(['ok' => true, 'number' => $order->number]);
        });
    }

    /** استكمال طلب معلّق/محفوظ: يعيد أصنافه إلى السلة */
    /**
     * السلّة المعلّقة يقيّدها فرعُها كما تقيّده قائمتُها.
     *
     * `Demo::heldOrders` تعرض سلال الفرع الحالي وحدها، والاستئنافُ والحذف
     * كانا يقرآن بالمعرّف على المتجر كلّه: منعٌ في الشاشة لا وجود له عند
     * الباب. والحذف أشدّ من الاطّلاع — القائمة تُخفي سلّة الفرع الآخر،
     * فصاحبُها لا يعلم أنها ذهبت: يقف الزبون في صلالة، والسلّةُ التي جُمعت
     * له محاها كاشيرٌ في مسقط بمعرّفٍ مُخمَّن.
     *
     * و«كل الفروع» يبقى بلا قيد كما في القائمة: هو عرضُ الشركة لا موضعُ بيع.
     */
    private function heldOrder(int $id): Order
    {
        return Order::where('business_id', $this->bid())->where('is_held', true)
            ->when(Demo::currentBranchId(), fn ($q) => $q->where('branch_id', Demo::currentBranchId()))
            ->with('items.addons')->findOrFail($id);
    }

    public function resume($id)
    {
        $order = $this->heldOrder((int) $id);

        session()->flash('resume_cart', [
            'id' => $order->id,
            'customer' => $order->customer_name,
            'items' => $order->items->load('components')->map(fn ($i) => [
                'id' => $i->product_id,
                // المقاس يعود بمعرّفه: السلّة تُسعَّر من جديد عند الدفع،
                // والاسم وحده لا يكفي الخادم ليعرف أيّ صفٍّ يقرأ
                'variant_id' => $i->variant_id,
                'variant_name' => $i->variant_name,
                'name' => $i->name,
                'price' => (float) $i->price,
                'qty' => (int) $i->quantity,
                // موسمُه كما عُلّق — والدفعُ يتحقّق منه من جديد
                'season_id' => $i->season_id,
                'note' => $i->note ?? '',
                'addons' => $i->addons->map(fn ($a) => [
                    'addon_id' => $a->addon_id,
                    'name' => $a->name,
                    'price' => (float) $a->unit_price,
                    'qty' => (int) $a->quantity,
                ])->all(),
                /*
                 * والطلبُ المخصَّص يعود بموادّه — من اللقطة لا من وصفة.
                 *
                 * `null` لكلّ بندٍ من الكتالوج، فلا تتغيّر سلّةٌ قائمة.
                 * ولولا هذا لَعادت الباقةُ المخصَّصة سطرًا بسعرٍ بلا موادّ:
                 * تُباع فلا يَنقص الرفُّ شيئًا — وهو أسوأ من ألّا تعود أصلًا.
                 */
                'custom' => $i->isCustom() ? [
                    'template_id' => $i->custom_details['template']['id'] ?? null,
                    // اسمُ اللقطة لا اسمُ القالب الحيّ — البندُ يبقى كما بيع
                    'template_name' => $i->name,
                    'mode' => $i->custom_details['mode'] ?? CustomArrangement::MODE_VALUE,
                    'base_value' => $i->custom_details['base_value'] ?? ($i->custom_details['flower_value'] ?? null),
                    'price' => (float) $i->price,
                    'fields' => CustomArrangement::resumeFields($i->custom_details),
                    'components' => $i->components->map(fn ($c) => [
                        'product_id' => $c->product_id,
                        'name' => $c->name,
                        'quantity' => (float) $c->quantity,
                        // والسياسةُ تعود كما كُتبت، فلا تُستأنف سلّةٌ بسياسةٍ أخرى
                        'restockable' => (bool) $c->restockable,
                    ])->all(),
                ] : null,
            ])->all(),
            // يعود الكود إلى السلة لتُعيد الواجهة تطبيقه، فيراه الكاشير
            // ويُحتسب عند الدفع. لا نُعيد قيمة الخصم — تُحسب من جديد.
            'coupon_code' => $order->coupon_code,
        ]);

        return redirect()->route('pos.index');
    }

    /** حذف طلب معلّق/محفوظ */
    public function discard($id)
    {
        $order = $this->heldOrder((int) $id);
        $number = $order->number;
        $order->items()->delete();
        $order->delete();

        return back()->with('toast', ['msg' => __('تم حذف الطلب :number', ['number' => $number]), 'type' => 'warning']);
    }

    /** إضافة عميل سريع من نقطة البيع */
    /**
     * إضافة مناسبةٍ للمتجر من نافذة الدفع.
     *
     * لا شاشة إعداداتٍ لها: من لم يجد مناسبته يجدها وهو واقفٌ أمام الزبون،
     * لا بعد أن يخرج من الصندوق ويفتح الإعدادات ويعود. والزبون ينتظر.
     */
    public function storeOccasion(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:'.FlowerOrder::CUSTOM_LABEL_MAX],
        ], [
            'label.required' => __('اكتب اسم المناسبة.'),
            'label.min' => __('اسم المناسبة قصير جدًّا.'),
        ]);

        try {
            $added = FlowerOrder::addOccasion($data['label'], $this->bid());
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        Activity::log('created', 'أضاف مناسبة: '.$added['value']);

        return response()->json(['ok' => true] + $added);
    }

    public function storeCustomer(Request $request)
    {
        $data = $request->validate([
            // لا name_en: localizeName أدناه يشتقّه من الاسم المُدخَل
            'name' => ['required', 'string', 'max:255'],
            'phone' => Customers::phoneRule($this->bid()),
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'language' => Customers::languageRule(),
        ]);
        $data['business_id'] = $this->bid();
        $data = Customers::localizeName($data);
        $customer = Customer::create($data);
        Activity::log('created', 'أضاف عميلًا من نقطة البيع: '.$data['name']);

        // طلب AJAX من السلة: نُعيد العميل ليُحدَّد تلقائيًا للطلب الجاري بلا إعادة تحميل
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'label' => (app()->getLocale() === 'en' && filled($customer->name_en)) ? $customer->name_en : $customer->name,
                    'phone' => $customer->phone ?? '',
                    'language' => $customer->language,
                ],
            ]);
        }

        return back()->with('toast', ['msg' => __('تم إضافة العميل'), 'type' => 'success']);
    }

    /**
     * نقاط الولاء للعميل المسجّل: تستبدل النقاط المطلوبة (خصم) ثم تمنح نقاط الشراء.
     * تحترم إعداد التفعيل والمعدّل، وتربط الطلب بالعميل. تُرجِع ['earned'=>x, 'redeemed'=>y].
     */
    /**
     * عميل النشاط — بالمعرّف، ثم بالهاتف، ثم بالاسم إن كان فريدًا.
     *
     * كان يُطابَق بالاسم وحده ويُؤخذ أوّل ما يعود. والاسم ليس مفتاحًا: متجرٌ
     * فيه ثلاثة باسم «محمد» كان يمنح نقاط شراء كلٍّ منهم لأوّلهم في الجدول،
     * ويخصم رصيده هو عند استبدال غيره. النقاط مالٌ فعلي، فالخلط فيها خسارة
     * لصاحبها وهبةٌ لسواه — ولا يظهر شيء من ذلك في أي شاشة.
     *
     * والهاتف هو ما يعرّف الشخص عند التاجر فعلًا، فهو المرجع الثاني.
     *
     * وحين يبقى الاسم وحده ويطابق أكثر من واحد: لا يُربط أحد. بيعةٌ بلا نقاط
     * يشتكي منها العميل فتُصحَّح، ونقاطٌ تذهب لغير صاحبها لا يلحظها أحد.
     */
    private function customerFor(?string $name, ?int $id = null, ?string $phone = null): ?Customer
    {
        $scope = fn () => Customer::where('business_id', $this->bid());

        if ($id) {
            return $scope()->find($id);
        }

        if (filled($phone)) {
            $found = $scope()->where('phone', $phone)->first();
            if ($found) {
                return $found;
            }
        }

        if (empty($name) || $name === 'عميل نقدي') {
            return null;
        }

        // الكاشير الإنجليزي يرى name_en ويرسله، فنطابق العمودين معًا:
        // المطابقة بالعربي وحده كانت تُسقط ربط العميل ونقاط ولائه.
        $matches = $scope()
            ->where(fn ($q) => $q->where('name', $name)->orWhere('name_en', $name))
            ->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * هل الولاء عاملٌ في هذا المتجر الآن؟ — مفتاحُ التاجر وباقتُه معًا.
     *
     * والباقة تُسأل هنا لا في الشاشة وحدها: النقاط تُمنح عند إتمام البيعة
     * لا عند فتح شاشة الولاء، فقفلٌ في اللوحة يترك الرصيد ينمو لمن لم يشترِ
     * البرنامج — ثمّ يُستبدَل. والنقاط مالٌ لا عدّاد.
     */
    private function loyaltyOn(): bool
    {
        if ((string) $this->setting('loyalty_enabled', '1') === '0') {
            return false;
        }

        return PlanFeatures::allows(auth()->user()?->business, 'loyalty');
    }

    /**
     * كم نقطة تُستبدَل فعلًا وكم تساوي خصمًا — يُحتسب قبل بناء الفاتورة.
     *
     * يطابق سقف usePosCart: نسبة من المجموع الفرعي، ولا يتجاوز المتبقّي بعد الكوبون،
     * ولا رصيد العميل. النقاط مالٌ فعلي، فلا تُؤخذ قيمة الخصم من العميل.
     */
    private function resolveRedemption(?Customer $customer, float $subtotal, float $couponDiscount, int $requested): array
    {
        $none = ['points' => 0, 'discount' => 0.0];

        if (! $customer || $requested <= 0 || ! $this->loyaltyOn()) {
            return $none;
        }

        // الحد الأدنى لبدء الاستبدال: تحته تتراكم النقاط فقط
        $redeemMin = max(0, (int) $this->setting('loyalty_redeem_min', 100));
        if ((int) $customer->points < $redeemMin) {
            return $none;
        }

        $maxPct = max(0, min(100, (int) $this->setting('loyalty_redeem_max_pct', 50)));
        $cap = min($subtotal * $maxPct / 100, max(0.0, $subtotal - $couponDiscount));

        $points = min($requested, (int) $customer->points, (int) floor($cap * self::POINTS_PER_UNIT));
        if ($points <= 0) {
            return $none;
        }

        return ['points' => $points, 'discount' => $points / self::POINTS_PER_UNIT];
    }

    /** يقيّد الاستبدال والاكتساب على العميل بعد اكتمال الفاتورة */
    private function recordLoyalty(Order $order, ?Customer $customer, int $redeemPoints): array
    {
        if (! $customer || ! $this->loyaltyOn()) {
            return ['earned' => 0, 'redeemed' => 0];
        }

        if ($redeemPoints > 0) {
            /*
             * الرصيد يُخصم بشرطه لا بطرحٍ أعمى.
             *
             * `resolveRedemption` تقرأ الرصيد ثمّ يُخصم هنا، وبين القراءة
             * والخصم بيعةٌ أخرى للزبون نفسه على صندوقٍ آخر: كلتاهما ترى خمسمئة
             * نقطة، وكلتاهما تطرح خمسمئة — فيصير رصيده سالبًا، ويكون قد اشترى
             * بخصمٍ لم يدفع ثمنه. والنقاط مالٌ لا عدّاد.
             *
             * فالشرط في جملة التحديث نفسها: من سبق أخذ، ومن تأخّر يُردّ بلا
             * فاتورةٍ ناقصة الثمن.
             */
            $taken = Customer::whereKey($customer->id)
                ->where('points', '>=', $redeemPoints)
                ->update(['points' => \DB::raw('points - '.$redeemPoints), 'updated_at' => now()]);

            if (! $taken) {
                throw ValidationException::withMessages([
                    'redeem_points' => __('تغيّر رصيد نقاط العميل — أعد احتساب الاستبدال.'),
                ]);
            }

            $customer->refresh();
            PointTransaction::record($customer, 'redeem', $redeemPoints, (int) $customer->points, $order->id, 'استبدال عند البيع — فاتورة '.$order->number);
        }

        // اكتساب نقاط الشراء (على الإجمالي بعد الخصم)
        $earned = 0;
        $rate = (float) $this->setting('loyalty_earn_rate', 5);
        if ($rate > 0) {
            $earned = (int) floor((float) $order->total * $rate);
            if ($earned > 0) {
                $customer->increment('points', $earned);
                PointTransaction::record($customer, 'earn', $earned, (int) $customer->points, $order->id, 'اكتساب من الشراء — فاتورة '.$order->number);
            }
        }

        $order->points_earned = $earned;
        $order->redeemed_points = $redeemPoints;
        $order->save();

        return ['earned' => $earned, 'redeemed' => $redeemPoints];
    }

    /** إشعار صاحب المتجر بطلب جديد عبر البريد (غير مُعطِّل عند الفشل، ويحترم إعداد التفعيل) */
    private function notifyNewOrder(Order $order): void
    {
        $business = Business::find($this->bid());
        if (! $business || ! $business->email) {
            return;
        }
        $enabled = Setting::where('business_id', $this->bid())->where('key', 'notify_new_order')->value('value');
        if ($enabled === '0') {
            return;
        }
        try {
            Mail::to($business->email)->send(new NewOrderMail($order));
        } catch (\Throwable $e) {
            report($e); // لا نُفشل عملية البيع بسبب البريد
        }
    }
}
