<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CustomOrderTemplate;
use App\Models\Order;
use App\Support\Activity;
use App\Support\CustomArrangement;
use App\Support\CustomerFlags;
use App\Support\Demo;
use App\Support\FlowerOrder;
use App\Support\PlanFeatures;
use App\Support\PosCashier;
use App\Support\PosTerminal;
use App\Support\ReceiptVisibility;
use App\Support\Vat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\PaymentMethods;

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
            'paymentMethods' => PaymentMethods::enabled($s),
            // والآجلُ مقبضٌ بجانبها — والخادمُ يردّه كذلك (PosController::checkout)
            'creditSale' => PaymentMethods::creditAllowed($s),
            /*
             * تجاوزُ حظر البيع — يُعرض حقلُ السبب لمن يملكه وحده، والخادمُ
             * يقيسه ثانيةً (CustomerFlags::assertSellable).
             */
            'canOverrideBlock' => CustomerFlags::on($s, CustomerFlags::MANAGER_OVERRIDE)
                && (bool) auth()->user()?->may(CustomerFlags::OVERRIDE),
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
            /*
             * ═══ ومتجرُ الفرع الواحد يُربط وحدَه ═══
             *
             * التفعيلُ يحمي من خلط فرعٍ بفرع، ولا فرعَ يُخلط به هنا. وكان
             * الكاشيرُ على متصفّحٍ جديد — سفاري بعد كروم، أو هاتفٌ بعد
             * الحاسوب — يُحوَّل إلى شاشة تفعيلٍ لا يملكها فيقف على 403 وقد
             * كتب بريدَه وكلمتَه صحيحَين. فيُربط الجهازُ بالفرع الوحيد ويُقيَّد
             * في السجلّ، ويُعاد إلى الصندوق بكوكيه. والمتجرُ ذو الفروع يبقى
             * على بابه: هناك اختيارٌ لا يُخمَّن.
             */
            $branches = Branch::where('business_id', Demo::bid())->orderBy('id')->get(['id', 'name']);
            if ($branches->count() === 1 && auth()->user()?->allows('pos')) {
                $only = Branch::find($branches->first()->id);
                $device = PosTerminal::activate($only, __('جهاز :name', ['name' => auth()->user()->name]), auth()->id());
                Activity::log('created', 'فُعّل جهاز نقطة بيع تلقائيًّا: '.$device->name.' — فرع '.$only->name.' (الفرع الوحيد)', [
                    'subject_id' => $device->id,
                ]);

                return redirect()->route('pos.index');
            }

            return redirect()->route('pos.setup');
        }

        return Inertia::render('Pos/Index', [
            // رصيد الفرع الذي سيُخصم منه البيع، لا مجموع الشركة
            'products' => Demo::products(Demo::activeBranchId()),
            'categories' => Demo::posCategories(),
            // ومع كلّ زبونٍ ما يُقال عنه للكاشير — بالإعدادات، وبلا ملاحظةٍ أُطفئت
            'customers' => $this->customersWithContext(),
            'addons' => Demo::addons(),
            'coupons' => Demo::activeCoupons(),
            /*
             * قوالبُ الطلب المخصَّص — النشطةُ وحدها، وبلغة الواجهة.
             *
             * ═══ ولمَ تُرسل جاهزةً للعرض ═══
             *
             * الشاشةُ تعرض ما تُعطى ولا تختار: لو أُرسل الموقوفُ لَاحتاجت أن
             * ترشّح، ولو أُرسل الاسمان لَاحتاجت أن تختار بينهما — وقاعدتان
             * للاختيار (هنا وفي الخادم) تفترقان يوم تُبدَّل إحداهما، فيُعرض
             * قالبٌ يرفضه الخادم.
             *
             * وفارغةٌ حين تُطفأ الميزة: الزرُّ لا يُعرض، والبابُ مقفلٌ في
             * الخادم أيضًا — انظر `PosController::priceItems`.
             */
            'customOrder' => CustomArrangement::enabled(Demo::bid())
                ? ['templates' => self::customTemplates(Demo::bid())]
                : ['templates' => []],
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

    /**
     * قوالبُ الصندوق — ومتجرٌ لم يكتب قالبًا يجد القالبَ الأوّل.
     *
     * ═══ العطب ═══
     *
     * الهجرةُ بذرت قالبًا افتراضيًّا لكلّ متجرٍ قائم، فبقيت الميزةُ تعمل
     * عندهم بعد الترقية. أمّا من سجّل متجرَه **بعدها** فلا قالبَ له —
     * والشاشةُ لا ترسم الزرَّ إلّا لقالب. فالميزةُ التي يقول الإعدادُ إنّها
     * «مفعّلة» لا بابَ لها في صندوقه، ولا شيءَ يقول له لماذا.
     *
     * والخادمُ كان يقبله طَوالَ الوقت: `CustomArrangement::template` تُنادي
     * `ensureDefault` حين لا يُسمَّى قالب، فتبذر الأوّلَ وتبيع به. أي أنّ
     * البابَ مفتوحٌ من الداخل ولا يُعرض من الخارج — وفرعٌ من الكود يحرس
     * حالًا لا تصله يدٌ أبدًا.
     *
     * ═══ والصفُّ لا يُكتب في فتحةِ شاشة ═══
     *
     * البذرُ هنا يعني كتابةً في قراءة: كلُّ متجرٍ يفتح صندوقَه — باع أو لم
     * يبع — يُكتب له صفّ. فيُعرض القالبُ الأوّل بمعرّف `0` كما يقبله
     * الخادمُ بالضبط، ويُكتب صفُّه في أوّل بيعةٍ به لا قبلها.
     *
     * وشكلُه من `CustomOrderTemplate::STARTER` — هي نفسُها التي يُكتب بها
     * الصفّ، فلا يُعرض قالبٌ بوضعين ويُنشأ بواحد.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function customTemplates(int $businessId): array
    {
        $rows = CustomOrderTemplate::sellable($businessId)
            ->with([
                'fields' => fn ($q) => $q->where('active', true),
                'fields.options' => fn ($q) => $q->where('active', true),
            ])
            ->get()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->display(),
                'modes' => $t->modes,
                'default_mode' => $t->default_mode ?: ($t->modes[0] ?? null),
                'base_label' => $t->baseLabel(),
                'allow_components' => $t->allow_components,
                'allow_addons' => $t->allow_addons,
                'restockable_default' => $t->components_restockable_default,
                'fields' => $t->fields->map(fn ($f) => [
                    'id' => $f->id,
                    'label' => $f->display(),
                    'type' => $f->type,
                    'required' => $f->required,
                    'internal' => $f->internal,
                    'options' => $f->options->map(fn ($o) => [
                        'id' => $o->id,
                        'label' => $o->display(),
                    ])->values(),
                ])->values(),
            ])->values()->all();

        if ($rows) {
            return $rows;
        }

        /*
         * ولا يُعرض الأوّلُ لمن حذف قوالبَه كلَّها.
         *
         * `ensureDefault` تنصرف متى وُجد صفٌّ ولو محذوفًا أو موقوفًا — فمن
         * أغلق الميزةَ بيده يُردّ طلبُه بـ«قالب غير متاح». وعرضُ زرٍّ يقود
         * إلى ذلك الردّ بابٌ يُفتح ليُغلق في وجهه.
         */
        if (CustomOrderTemplate::withTrashed()->where('business_id', $businessId)->exists()) {
            return [];
        }

        $starter = CustomOrderTemplate::STARTER;

        return [[
            // صفرٌ لا معرّف: `template()` تقرؤه «لم يُسمَّ قالب» فتبذر وتبيع
            'id' => 0,
            'name' => Demo::ln($starter['name'], $starter['name_en']),
            'modes' => $starter['modes'],
            'default_mode' => $starter['default_mode'],
            'base_label' => __('القيمة الأساسية'),
            'allow_components' => $starter['allow_components'],
            'allow_addons' => $starter['allow_addons'],
            'restockable_default' => $starter['components_restockable_default'],
            'fields' => [],
        ]];
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
            /*
             * هل يُصحَّح هذا المستند الآن؟ — تقولها الشاشة قبل الضغط.
             *
             * وشرطان لا واحد: صلاحيةٌ باسمها، ويومُ البيع لم ينتهِ. والحارس
             * الحقيقيّ في الخادم (`OrderCorrection::assertSameDay` و`may`)،
             * وهذا ليُخفى القلمُ لا ليُمنع به شيء: قلمٌ يُعرض ثمّ يُردّ عند
             * الضغط يجعل الكاشير يظنّ العطب في النظام فيعيد المحاولة.
             */
            'canEdit' => $this->correctable($number),
        ]);
    }

    /** الفاتورة تُصحَّح: بصلاحيةٍ باسمها، وفي يومها */
    private function correctable(string $number): bool
    {
        if (! auth()->user()?->may('order.edit')) {
            return false;
        }

        $sold = Order::where('business_id', Demo::bid())
            ->where('number', $number)->value('ordered_at')
            ?? Order::where('business_id', Demo::bid())
                ->where('number', $number)->value('created_at');

        return $sold !== null && Carbon::parse($sold)->isSameDay(now());
    }

    /**
     * مقبوضات اليوم — لا آخر ثلاثين فاتورة.
     *
     * كانت تعرض آخر ٣٠ فاتورة للفرع بلا حدٍّ زمني: فمحلٌّ يبيع ٥٠ مرّة يوميًّا
     * يرى جزء يومه، ومحلٌّ يبيع ٥ مرّات يرى ستّة أيام مخلوطة. وفي الحالتين لا
     * يطابق الرقم ما قُبض اليوم — رقمٌ يبدو دقيقًا وهو ليس كذلك.
     *
     * ثمّ صارت مدى الوردية المفتوحة، فلمّا رُفعت الوردية صار المدى **يومًا
     * من منتصف الليل**: حدٌّ يعرفه كلُّ من يقف على الصندوق بلا أن يُفتح له
     * شيءٌ أو يُقفل. والمدى يُقال في الشاشة صراحةً لا يُترك للتخمين.
     */
    public function payments(): Response
    {
        return Inertia::render('Pos/Payments', [
            // سقفٌ يسع يومًا مزدحمًا: بترٌ عند ٣٠ يُنقص المجموع بلا أن يقول
            'receipts' => Demo::receipts(limit: 500, today: true),
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
            'customers' => $this->customersWithContext(),
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

    /**
     * زبائنُ الصندوق ومع كلٍّ منهم ما يُقال عنه — لا الصفُّ كلُّه.
     *
     * `Demo::customers` تحمل الملاحظةَ الداخليّة لأنّ شاشةَ الملفّ تقرؤها؛
     * والصندوقُ يقرؤها بإذن الإعدادات وحده، فتُنزَع هنا وتُعاد في
     * `context.note` إن أُذن. والتنبيهُ وعيدُ الميلاد كذلك.
     */
    private function customersWithContext(): array
    {
        $settings = CustomerFlags::settings(Demo::bid());
        $models = \App\Models\Customer::where('business_id', Demo::bid())->get()->keyBy('id');

        return array_map(function (array $row) use ($settings, $models) {
            $model = $models->get($row['id']);
            unset($row['notes'], $row['alert_type'], $row['alert_reason'], $row['birth_day'], $row['birth_month'], $row['birth_year']);
            $row['context'] = $model ? CustomerFlags::context($model, $settings) : ['alert' => null, 'birthday_in' => null, 'note' => null];

            return $row;
        }, Demo::customers());
    }
}
