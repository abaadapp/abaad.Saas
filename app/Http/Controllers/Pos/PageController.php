<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\FlowerOrder;
use App\Support\PlanFeatures;
use App\Support\PosCashier;
use App\Support\PosTerminal;
use App\Support\ReceiptVisibility;
use App\Support\Shifts;
use App\Support\Vat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * صفحات عرض نقطة البيع — نُقل جلب البيانات من القوالب إلى هنا.
 */
class PageController extends Controller
{
    /** إعدادات الولاء بنفس الافتراضيات التي كانت في القالب */
    private function loyaltySettings(): array
    {
        $s = Demo::businessSettings();

        return [
            /*
             * والباقة شرطٌ مع المفتاح لا بديلٌ عنه.
             *
             * الشاشة تُخفي سطر النقاط حين يُطفئه التاجر، وكانت تعرضه لمن لا
             * تشمله باقتُه: يعِد الكاشيرُ زبونَه بنقاطٍ يردّها الخادم — انظر
             * `PosController::loyaltyOn`. والوعدُ المكسور عند الصندوق أسوأ من
             * ميزةٍ لا تُعرض أصلًا.
             */
            'loyaltyEnabled' => ($s['loyalty_enabled'] ?? '1') !== '0'
                && PlanFeatures::allows(auth()->user()?->business, 'loyalty'),
            'redeemMaxPct' => (float) ($s['loyalty_redeem_max_pct'] ?? 50),
            'earnRate' => (float) ($s['loyalty_earn_rate'] ?? 5),
            'redeemMin' => (float) ($s['loyalty_redeem_min'] ?? 100),
            // الوسائل المأذونة — والخادم يرفض ما عداها، فالإخفاء هنا عرضٌ لقرارٍ
            // مُنفَّذ لا حاجزٌ وحيد (انظر PosController::enabledPaymentMethods)
            'paymentMethods' => PosController::enabledPaymentMethods($s),
            /*
             * الضريبة كما ضبطها التاجر — لا خمسةٌ مكتوبةٌ في شيفرة الشاشة.
             *
             * كانت السلّة تحسب ٥٪ ثابتة: من ضبط نسبته ١٠٪ يقرأ الكاشير على
             * شاشته رقمًا والفاتورة تُسجَّل بآخر، فيُقال للزبون مبلغٌ ويُقبض
             * منه غيره. ومن أطفأ الضريبة كان سطرُها يبقى في شاشته.
             */
            'vat' => [
                'enabled' => Vat::enabled(Demo::bid()),
                'rate' => Vat::rate(Demo::bid()),
                'inclusive' => Vat::inclusive(Demo::bid()),
            ],
        ];
    }

    /**
     * شاشة البيع تُفتح لصاحبها مباشرةً.
     *
     * كانت تحجزه شاشةُ «من على الصندوق؟» قبل أن يبيع، فيقف كلَّ صباحٍ أمام
     * سؤالٍ جوابُه معروف: الداخلُ بحسابه هو الواقف على الصندوق. صار هو
     * الافتراض (انظر `PosCashier::current`)، والشاشة تبقى لمن يتناوب
     * موظفوه على جهازٍ واحد — تُطلب من الترويسة لا تُفرض عند الباب.
     */
    public function index(): Response|RedirectResponse
    {
        /*
         * لا بيع على جهازٍ غير مفعَّل.
         *
         * الجهاز هو من يعرف الفرع. وبلا تفعيل يعود الفرع إلى ما اختاره المدير
         * في تبويبٍ آخر — أو إلى «كل الفروع» فيسقط على أوّل فرع في القائمة،
         * فتُسجَّل مبيعات الخوير على السيب ولا يُكتشف إلا عند جرد آخر الشهر.
         *
         * ولا يُطبَّق على متجرٍ بلا فروع: لا فرع يُختار، ولا سبب للحجز.
         */
        if (! PosTerminal::activated() && Branch::where('business_id', Demo::bid())->exists()) {
            return redirect()->route('pos.setup');
        }

        /*
         * لا شاشة بيع بلا وردية مفتوحة.
         *
         * الترتيب مقصود: يُختار الواقف على الصندوق أولًا، لأن الوردية تُسجَّل
         * باسمه. والتوجيه هنا لا تعطيلُ زرٍّ في الواجهة: الكاشير يجد نفسه
         * أمام شاشة الفتح فيفتح ويعود، بدل أن يجمع سلّة ثم يُرفض دفعُها.
         */
        if (! Shifts::isOpen() && Shifts::blocksSelling()) {
            return redirect()->route('pos.shift');
        }

        return Inertia::render('Pos/Index', [
            // رصيد الفرع الذي سيُخصم منه البيع، لا مجموع الشركة
            'products' => Demo::products(Demo::activeBranchId()),
            'categories' => Demo::posCategories(),
            'customers' => Demo::customers(),
            'addons' => Demo::addons(),
            'coupons' => Demo::activeCoupons(),
            // سلة مستعادة من طلب معلّق (تُمرَّر عبر الجلسة من PosController::resume)
            'resumeCart' => session('resume_cart'),
            'settings' => $this->loyaltySettings(),
            // خيارات طلب الورد من مصدرها الواحد — لا تُكتب في الشاشة ثانيةً
            'orderOptions' => [
                'occasions' => FlowerOrder::occasionOptions(),
                'fulfillments' => FlowerOrder::fulfillmentOptions(),
                'cardMax' => FlowerOrder::CARD_MAX,
            ],
        ]);
    }

    public function orders(): Response
    {
        return Inertia::render('Pos/Orders', [
            'heldOrders' => Demo::heldOrders(),
        ]);
    }

    public function orderDetails(string $number): Response
    {
        $order = Demo::orderDetails($number);
        abort_if(empty($order), 404);

        return Inertia::render('Pos/OrderDetails', [
            'order' => $order,
        ]);
    }

    /**
     * مقبوضات الوردية المفتوحة — لا آخر ثلاثين فاتورة.
     *
     * كانت تعرض آخر ٣٠ فاتورة للفرع بلا حدٍّ زمني، وهي شاشة تقفيل صندوق:
     * فمحلٌّ يبيع ٥٠ مرّة يوميًّا كان يرى جزء يومه، ومحلٌّ يبيع ٥ مرّات يرى
     * ستّة أيام مخلوطة. وفي الحالتين لا يطابق الرقم ما في الدرج — رقمٌ يبدو
     * دقيقًا وهو ليس كذلك، وذلك أسوأ من غياب الشاشة.
     */
    public function payments(): Response
    {
        $shift = Shifts::current();

        return Inertia::render('Pos/Payments', [
            // سقفٌ يسع يومًا مزدحمًا: بترُ الوردية عند ٣٠ يُنقص المجموع بلا أن يقول
            'receipts' => $shift ? Demo::receipts(limit: 500, shiftId: $shift->id) : [],
            'shift' => $shift ? [
                'opened_at' => $shift->opened_at?->format('Y-m-d H:i'),
                'opening_balance' => (float) $shift->opening_balance,
                'expected' => Shifts::expectedCash($shift),
            ] : null,
        ]);
    }

    public function receipts(): Response
    {
        return Inertia::render('Pos/Receipts', [
            // المبالغ تُنزع للكاشير من الحمولة نفسها، لا من الجدول فقط
            'receipts' => ReceiptVisibility::filter(Demo::receipts()),
            'showsAmounts' => ReceiptVisibility::showsAmounts(),
            'branchName' => Demo::currentBranchName(),
        ]);
    }

    public function customers(): Response
    {
        return Inertia::render('Pos/Customers', [
            'customers' => Demo::customers(),
        ]);
    }

    public function settings(): Response
    {
        return Inertia::render('Pos/Settings', [
            'settings' => Demo::businessSettings(),
            'branchName' => Demo::currentBranchName(),
        ]);
    }

    /**
     * قفل الشاشة: تنتهي جلسة الموظف، ويبقى الجهاز مفعَّلًا.
     *
     * كان يعيد الشاشة إلى أربعة أرقامٍ يدخل بها الكاشير التالي. ولمّا رُفع
     * الدخول بالرمز صار يعيدها إلى شاشة الدخول: يدخل الثاني ببريده وكلمة
     * مروره.
     *
     * والكوكي لا تُمسّ — الجهاز يبقى هو الجهاز، وهو الذي يعرف الفرع.
     */
    public function lock(Request $request): RedirectResponse
    {
        Activity::log('logout', 'قفل شاشة نقطة البيع');
        PosCashier::forget();

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
