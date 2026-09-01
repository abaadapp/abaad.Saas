<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;

/**
 * طبقة الوصول للبيانات لواجهات Abad POS.
 *
 * تقرأ الآن من قاعدة البيانات مع مراعاة المستأجر الحالي (business_id)،
 * وتُعيد نفس أشكال المصفوفات التي تعتمد عليها ملفات Blade
 * (لذلك لم تتغيّر الواجهات). المصدر الثابت للبيانات التجريبية في App\Support\SeedData.
 */
class Demo
{
    /* ============================ مساعدات ============================ */

    private static $baseCur = null;
    private static $displayCur = null;

    /**
     * صاحبُ كلّ ذاكرةٍ على حدة — تُعاد قراءتُها إن تغيّر.
     *
     * كان مفتاحًا واحدًا للذاكرتين، وكلٌّ منهما تُملأ وحدها: فمتجرٌ نُسّق له
     * مبلغٌ (فامتلأت ذاكرة العرض باسمه) ثمّ قُرئ لمتجرٍ آخر أساسُه (فتبدّل
     * المفتاح إلى اسم الثاني) — تصير ذاكرةُ العرض الأولى «صالحةً» لصاحبٍ لم
     * تُملأ له، فيُخدَم بعملة جاره.
     *
     * ولا يقع هذا تحت php-fpm — العمليّة تموت مع الطلب — بل تحت عاملِ طابورٍ
     * يعالج متجرين بالتتابع، أو تحت Octane. وهناك لا شاشة تكشفه: ملخّصٌ
     * يوميّ يُرسَل بالبريد بأرقامٍ صحيحة وعملةٍ ليست عملة صاحبه.
     */
    private static ?int $baseBid = null;

    private static ?int $displayBid = null;

    /**
     * تفريغ ذاكرة العملة المؤقّتة.
     *
     * الذاكرة ساكنة (static) وتعيش ما عاش العمليّة. تحت `php artisan serve`
     * تموت مع كل طلب فلا فرق، لكن تحت Octane أو عامل طابور تبقى — فتُخدَم
     * عملة مستأجرٍ لمستأجرٍ آخر. تُستدعى هنا عند تبديل العملة وفي الاختبارات
     * التي تبدّلها داخل العمليّة نفسها.
     */
    public static function flushCurrency(): void
    {
        self::$baseCur = null;
        self::$displayCur = null;
        self::$baseBid = null;
        self::$displayBid = null;
    }

    /** رموز العملات الشائعة في المنطقة — لمن ضبط عملته من الإعدادات بلا جدول عملات */
    private const SYMBOLS = [
        'OMR' => 'ر.ع', 'AED' => 'د.إ', 'SAR' => 'ر.س', 'KWD' => 'د.ك', 'BHD' => 'د.ب',
        'QAR' => 'ر.ق', 'JOD' => 'د.أ', 'EGP' => 'ج.م', 'USD' => '$', 'EUR' => '€', 'GBP' => '£',
    ];

    /**
     * يُلحق بالعملة كيفيةَ كتابتها كما اختارها التاجر.
     *
     * المنازل كانت مشتقّةً من رمز العملة وحده، وموضع الرمز مثبّتًا بعده —
     * وحقلاهما في الإعدادات يُحفظان ولا يقرؤهما أحد.
     */
    private static function withFormat(array $cur, array $settings): array
    {
        $fallback = in_array($cur['code'], ['OMR', 'KWD', 'BHD'], true) ? 3 : 2;
        $set = $settings['decimals'] ?? null;

        return $cur + [
            'decimals' => $set === null || $set === '' ? $fallback : max(0, min(4, (int) $set)),
            'before' => ($settings['symbol_pos'] ?? 'after') === 'before',
        ];
    }

    /**
     * العملة الأساسية للنشاط.
     *
     * جدول العملات أوّلًا، فإن خلا — وهو حال كل متجرٍ لا يستورد نسخة احتياطية،
     * إذ لا شاشة تملؤه — فإعداد «العملة» في الإعدادات. وكان السقوط على ر.ع
     * مثبّتًا: تاجرٌ في دبي يكتب AED فيرى ر.ع في كل رقمٍ عنده.
     */
    public static function baseCurrency(): array
    {
        // الذاكرة مربوطةٌ بصاحبها: بلا ذلك يُخدَم متجرٌ بعملة متجرٍ سبقه في
        // العمليّة نفسها — تحت Octane أو عامل طابور يعالج متجرين بالتتابع
        if (self::$baseCur !== null && self::$baseBid === self::bid()) {
            return self::$baseCur;
        }
        self::$baseBid = self::bid();
        $s = self::businessSettings();
        $c = \App\Models\Currency::where('business_id', self::bid())->where('is_base', true)->first();

        if ($c) {
            $cur = ['code' => $c->code, 'symbol' => $c->symbol ?: $c->code, 'rate' => (float) $c->rate, 'is_base' => true];
        } else {
            // رمزٌ من ثلاثة أحرف لاتينية أو لا شيء: في القاعدة صفوفٌ قديمة
            // قيمتها «ريال عماني» — تُقرأ رمزًا فيظهر بجانب كلّ مبلغ كما هو
            $code = strtoupper(trim((string) ($s['currency'] ?? '')));
            $code = preg_match('/^[A-Z]{3}$/', $code) ? $code : 'OMR';
            $cur = ['code' => $code, 'symbol' => self::SYMBOLS[$code] ?? $code, 'rate' => 1.0, 'is_base' => true];
        }

        return self::$baseCur = self::withFormat($cur, $s);
    }

    /** عملة العرض المختارة (من الجلسة) أو الأساسية */
    public static function displayCurrency(): array
    {
        if (self::$displayCur !== null && self::$displayBid === self::bid()) {
            return self::$displayCur;
        }
        self::$displayBid = self::bid();
        $code = session('display_currency');
        if ($code) {
            $c = \App\Models\Currency::where('business_id', self::bid())->where('code', $code)->where('active', true)->first();
            if ($c) {
                return self::$displayCur = self::withFormat(
                    ['code' => $c->code, 'symbol' => $c->symbol ?: $c->code, 'rate' => (float) $c->rate, 'is_base' => (bool) $c->is_base],
                    self::businessSettings()
                );
            }
        }

        return self::$displayCur = self::baseCurrency();
    }

    private static function formatMoney(float $value, array $cur): string
    {
        $decimals = (int) ($cur['decimals'] ?? (in_array($cur['code'], ['OMR', 'KWD', 'BHD']) ? 3 : 2));

        // الترجمة هنا لا عند تعريف العملة، لتشمل الرمز القادم من قاعدة البيانات أيضًا
        $symbol = __($cur['symbol']);
        $amount = number_format($value, $decimals, '.', ',');

        return ($cur['before'] ?? false) ? $symbol . ' ' . $amount : $amount . ' ' . $symbol;
    }

    /** المبلغ بعملة العرض المختارة (تحويل تلقائي حسب سعر الصرف) */
    public static function money($value): string
    {
        $cur = self::displayCurrency();

        return self::formatMoney((float) $value * $cur['rate'], $cur);
    }

    /** المبلغ بالعملة الأساسية دائمًا (للفواتير والإيصالات وكشوف الحساب) */
    public static function moneyBase($value): string
    {
        return self::formatMoney((float) $value, self::baseCurrency());
    }

    public static function image(string $seed, int $w = 400, int $h = 400): string
    {
        return "https://picsum.photos/seed/{$seed}/{$w}/{$h}";
    }

    /** معرّف المستأجر الحالي (أو النشاط الأساسي احتياطيًا) */
    /**
     * المتجر الذي تجري عليه هذه الطلبية.
     *
     * كان يخمّن حين لا يجد: يُرجع «زهرة مسقط» أو أوّل متجرٍ في القاعدة. وهذا
     * أخطر سطرٍ في الملف — لأنه لا يفشل، بل ينجح على بيانات شخصٍ آخر. أيّ
     * مسارٍ جديد يُكتب خارج حارس RequiresBusiness كان سيعرض متجرَ غيره
     * بلا أن يطلبه أحد، وبلا أي علامةٍ على الشاشة.
     *
     * فصار يُرجع صفرًا: لا يطابق متجرًا، فتخرج الاستعلامات فارغة. الفشل
     * ظاهرٌ ومغلق، لا صامتٌ ومفتوح.
     *
     * ويبقى للطرفية استثناء صريح: أدوات التطوير والبذر تعمل على أوّل متجر
     * عمدًا — ولا يشمل الاستثناءُ الاختبارات، وإلا لخبّأ عنها ما نحرسه.
     */
    public static function bid(): int
    {
        $u = auth()->user();
        if ($u && $u->business_id) {
            return (int) $u->business_id;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return (int) (Business::min('id') ?? 0);
        }

        return 0;
    }

    /** الفرع الحالي المختار (من الجلسة) — null = كل الفروع */
    public static function currentBranchId(): ?int
    {
        return session('current_branch') ? (int) session('current_branch') : null;
    }

    /**
     * الفرع الذي تجري عليه العمليات فعليًا.
     *
     * currentBranchId() تُرجع null عند «كل الفروع» — وهو عرضٌ لا موضع بيع.
     * البيع لا بدّ أن يقع في فرعٍ بعينه، فيُختار أوّل فرع حين لا اختيار.
     * كان هذا المنطق مكرّرًا في PosController وProductController بصياغتين،
     * فيمكن أن يعرض أحدهما رصيد فرعٍ ويخصم الآخر من فرعٍ آخر.
     */
    public static function activeBranchId(): ?int
    {
        return self::currentBranchId()
            ?? \App\Models\Branch::where('business_id', self::bid())->orderBy('id')->value('id');
    }

    /**
     * ما تقيسه ورقةٌ بعينها — لا ما يختاره الشريط فوق الشاشة.
     *
     * كانت ترويسة كلّ ملفٍّ تكتب اسم الفرع المختار مهما كان ما تحتها، وأكثر
     * الأوراق لا تعرف الفروع أصلًا: المبيعات والمصروفات والحركة المالية
     * والمنتجات تُجمع على المتجر كلّه. فيُرسَل الملفّ إلى المحاسب بترويسةٍ
     * تنسبه إلى فرعٍ واحد وجدولٍ يحمل ثلاثة — ولا تُكتشف إلا حين تُقارَن
     * أوراق الفروع فيوجد كلٌّ منها يحمل أرقام الشركة نفسها.
     *
     * $perBranch: هل رشّح هذا التقرير بالفرع فعلًا؟
     */
    public static function scopeName(bool $perBranch): string
    {
        return $perBranch ? self::currentBranchName() : __('كل الفروع');
    }

    public static function currentBranchName(): string
    {
        $id = self::currentBranchId();
        if (! $id) {
            return __('كل الفروع');
        }

        // مقيّد بالنشاط: الحارس على مسار التبديل يمنع الدخول أصلًا، لكن قيمة
        // عالقة في جلسة قديمة (أو تغيّر مالك الفرع) كانت تكفي لعرض اسم فرع
        // من متجر آخر في الترويسة.
        return \App\Models\Branch::where('id', $id)
            ->where('business_id', self::bid())
            ->value('name') ?? __('كل الفروع');
    }

    /**
     * اسم النشاط الحالي من سجلّه.
     * (كان مكتوبًا يدويًا في القالب، فكان كل مستأجر يرى اسم المتجر الأول.)
     */
    public static function businessName(): string
    {
        $bid = self::bid();

        // مصدر واحد: جدول businesses. كان إعداد business_name يسبقه فيحجبه،
        // ونموذج الإعدادات لا يكتبه أصلًا — فيتغيّر الاسم في الحقل ولا يتغيّر
        // في الترويسة، أو العكس.
        return \App\Models\Business::where('id', $bid)->value('name')
            ?? __('متجري');
    }

    /**
     * كل إعدادات النشاط كخريطة مفتاح→قيمة.
     * (القوالب كانت تقرأ كل مفتاح باستعلام منفصل، وأغلب الحقول كانت قيمها
     * مكتوبة يدويًا فلا تعرض ما حُفظ فعلًا. هذه تُرجع المحفوظ لتربطه الواجهة.)
     */
    public static function businessSettings(): array
    {
        return \App\Models\Setting::where('business_id', self::bid())
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * موقع النشاط الإلكتروني كرابط صالح للفتح، أو null إن لم يُضبط.
     *
     * التاجر يكتب «abaad.om» بلا بروتوكول عادةً، والمتصفح يقرأ ذلك مسارًا
     * نسبيًا داخل اللوحة فيهبط على صفحة 404 بدل موقعه. وما عدا http/https
     * يُرفض: القيمة تصل من نموذج الإعدادات، وزرٌّ يفتح «javascript:» ثغرة
     * لا ميزة.
     */
    public static function websiteUrl(): ?string
    {
        /*
         * النطاق مصدره شاشة «الموقع الإلكتروني» في أدوات التسويق — وحدها.
         *
         * كان مفتاحان لشيءٍ واحد: حقلٌ في بيانات النشاط ونطاقٌ في شاشة
         * التسويق. فيضبط التاجر نطاقه في أحدهما ويبقى الآخر فارغًا، ولا
         * يعرف أيّهما يقرأ الزرّ — وهو ما كان يجعل الزرّ يقود إلى نطاقٍ
         * قديمٍ نُسي أو إلى لا شيء وقد ضُبط.
         *
         * فبقي واحد: ما يُكتب في شاشة الموقع هو ما يفتحه الزرّ. والقديم
         * نُقل إليه بهجرة، فلم يضع نطاقٌ ضُبط قبل هذه النسخة.
         */
        $raw = trim((string) (self::businessSettings()['site_domain'] ?? ''));

        if ($raw === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $raw)) {
            if (str_contains($raw, ':')) {
                return null;
            }
            $raw = 'https://'.$raw;
        }

        return filter_var($raw, FILTER_VALIDATE_URL) ? $raw : null;
    }

    /** فروع النشاط الحالي */
    /**
     * فروع المتجر — ومعها ما يتعلّق بكل فرع.
     *
     * العددان ليسا زينة: زرّ الحذف كان يسأل «حذف هذا الفرع؟» بلا أن يقول إن
     * فيه أربعمئة فاتورة وصندوقَين. وتحذيرٌ يمنع الضغطة أنفع من سلّةٍ
     * تُصلحها بعدها — فمن يقرأ العدد يتوقّف، ومن لا يقرؤه يضغط.
     */
    public static function branches(): array
    {
        return \App\Models\Branch::where('business_id', self::bid())
            ->withCount([
                'orders as orders_count' => fn ($q) => $q->sold(),
                'devices as devices_count',
            ])
            ->orderBy('id')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'name' => $b->name, 'phone' => $b->phone, 'address' => $b->address,
                'orders' => (int) $b->orders_count,
                'devices' => (int) $b->devices_count,
            ])->all();
    }

    /* ============================ Super Admin ============================ */

    public static function superStats(): array
    {
        $mStart = now()->startOfMonth();
        $lmStart = now()->subMonthNoOverflow()->startOfMonth();
        $yStart = now()->startOfYear();
        $lyStart = now()->subYear()->startOfYear();

        // القيم (إجمالي)
        $total = Business::real()->count();
        $active = Business::real()->where('status', 'نشط')->count();
        /*
         * المستخدمون: كما تعدّهم شاشتهم لا كما يعدّهم الجدول.
         *
         * كان `User::count()` يجمع موظّفي المتجر التجريبيّ الثمانية إلى
         * الحقيقيّين — وقائمةُ «المستخدمون» تستثنيهم. فالبطاقة تقول أحد عشر
         * والقائمة تعرض ثلاثة، والرقمان لشيءٍ واحد.
         */
        $realUsers = fn () => User::whereDoesntHave('business', fn ($w) => $w->where('is_demo', true));
        $users = $realUsers()->count();
        /*
         * المشتركون من صفوف المتاجر لا من جدول الاشتراكات — انظر
         * Business::scopeSubscribed. والجدول يبقى للمال لا للعدّ.
         */
        $subscribed = Business::subscribed()->count();
        $trialing = Business::trialing()->count();

        // اتجاهات حقيقية = نمو التسجيلات (هذا الشهر مقابل الشهر السابق)
        $bizNew = Business::real()->where('starts_at', '>=', $mStart)->count();
        $bizNewLast = Business::real()->whereBetween('starts_at', [$lmStart, $mStart])->count();
        $activeNew = Business::real()->where('status', 'نشط')->where('starts_at', '>=', $mStart)->count();
        $activeNewLast = Business::real()->where('status', 'نشط')->whereBetween('starts_at', [$lmStart, $mStart])->count();
        $usersNew = $realUsers()->where('created_at', '>=', $mStart)->count();
        $usersNewLast = $realUsers()->whereBetween('created_at', [$lmStart, $mStart])->count();
        $subsNew = Business::subscribed()->where('starts_at', '>=', $mStart)->count();
        $subsNewLast = Business::subscribed()->whereBetween('starts_at', [$lmStart, $mStart])->count();

        /*
         * الإيراد الشهري المتكرّر: الاشتراكات السارية منسوبةً إلى الشهر.
         *
         * لا مجموع الفواتير: فاتورةٌ سنوية تُدفع مرّةً واحدة تجعل شهرًا يبدو
         * عظيمًا وأحد عشر شهرًا تبدو خرابًا. والمتكرّر يقيس ما يتكرّر — وهو ما
         * يُبنى عليه قرارٌ في هذا العمل.
         *
         * والقسمة على عدد أشهر الدورة: اشتراك سنوي بـ١٢٠ يساوي ١٠ في الشهر.
         */
        $mrrAt = function ($moment) {
            return (float) Subscription::where('status', 'نشط')
                ->where('starts_at', '<=', $moment)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $moment))
                ->get()
                ->sum(function ($sub) {
                    $months = $sub->starts_at && $sub->ends_at
                        ? max(1, round($sub->starts_at->diffInDays($sub->ends_at) / 30))
                        : 1;

                    return (float) $sub->amount / $months;
                });
        };
        $mrr = $mrrAt(now());
        $mrrLast = $mrrAt($lmStart);

        /*
         * الفاقد: من كان معك أول الشهر وليس معك الآن.
         *
         * يُحسب من الحالة الفعلية لا من عدّاد «الاشتراكات المنتهية» — ذاك يعدّ
         * دوراتٍ قديمة لعملاء جدّدوا، فيقول إنك تخسر وأنت تكسب.
         */
        $churned = Business::real()->where('starts_at', '<', $mStart)
            ->where(fn ($q) => $q->whereIn('status', ['معطل', 'معطّل'])->orWhere('ends_at', '<', now()))
            ->where('updated_at', '>=', $mStart)
            ->count();
        $activeAtStart = max(1, Business::real()->where('starts_at', '<', $mStart)->count());
        $churnLabel = $churned.' · '.round($churned / $activeAtStart * 100).'%';

        // الإيرادات: الشهر مقابل السابق، والسنة مقابل السابقة (فواتير مدفوعة فعليًا)
        $paid = fn () => Invoice::where('status', 'مدفوعة');
        $monthly = (float) $paid()->where('issued_at', '>=', $mStart)->sum('amount');
        $monthlyLast = (float) $paid()->whereBetween('issued_at', [$lmStart, $mStart])->sum('amount');
        $yearly = (float) $paid()->where('issued_at', '>=', $yStart)->sum('amount');
        $yearlyLast = (float) $paid()->whereBetween('issued_at', [$lyStart, $yStart])->sum('amount');

        return [
            array_merge(['label' => __('إجمالي الشركات'), 'value' => (string) $total, 'icon' => 'building-2', 'color' => 'primary'], self::trend($bizNew, $bizNewLast)),
            array_merge(['label' => __('الشركات النشطة'), 'value' => (string) $active, 'icon' => 'circle-check', 'color' => 'success'], self::trend($activeNew, $activeNewLast)),
            /*
             * الإيراد الشهري المتكرّر والفاقد — لا عدد المستخدمين.
             *
             * «إجمالي الشركات» و«المستخدمون» أرقامٌ ترتفع ولا تُدار: تصعد وأنت
             * تخسر، ولا تقول متى. والمنصّة تُدار برقمين — كم يدخل شهريًّا، وكم
             * خرج ومن.
             */
            array_merge(['label' => __('الإيراد الشهري المتكرّر'), 'value' => self::money($mrr), 'icon' => 'repeat', 'color' => 'primary'], self::trend($mrr, $mrrLast)),
            [
                'label' => __('الفاقد هذا الشهر'), 'value' => $churnLabel, 'icon' => 'user-minus',
                // ارتفاع الفاقد ليس نموًّا: الاتجاه معكوس عمدًا
                'trend' => $churned > 0 ? '−'.$churned : null, 'up' => false, 'color' => $churned > 0 ? 'danger' : 'success',
            ],
            array_merge(['label' => __('المشتركون'), 'value' => (string) $subscribed, 'icon' => 'badge-check', 'color' => 'success'], self::trend($subsNew, $subsNewLast)),
            /*
             * التجربة بطاقةٌ مستقلّة لا رقمٌ مخلوطٌ بالمشتركين.
             *
             * من دخل بأربعة عشر يومًا مجّانًا ليس إيرادًا ولا مدينًا — وخلطه
             * بمن يدفع يجعلك تقرأ خمسةً وأنت تعرف أن اثنين لم يدفعا ريالًا.
             */
            [
                'label' => __('في التجربة'), 'value' => (string) $trialing, 'icon' => 'hourglass',
                'color' => $trialing > 0 ? 'warning' : 'secondary', 'trend' => null, 'up' => null,
            ],
            array_merge(['label' => __('المستخدمون'), 'value' => (string) $users, 'icon' => 'users', 'color' => 'info'], self::trend($usersNew, $usersNewLast)),
            array_merge(['label' => __('الإيرادات الشهرية'), 'value' => self::money($monthly), 'icon' => 'wallet', 'color' => 'warning'], self::trend($monthly, $monthlyLast)),
            array_merge(['label' => __('الإيرادات السنوية'), 'value' => self::money($yearly), 'icon' => 'trending-up', 'color' => 'primary'], self::trend($yearly, $yearlyLast)),
        ];
    }

    /**
     * صفُّ متجرٍ كما تقرؤه الشاشات وأوراق الطباعة — شكلٌ واحد لا شكلان.
     *
     * @return array<string, mixed>
     */
    private static function businessRow(Business $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'type' => $b->type,
            'owner' => $b->owner_name,
            'phone' => $b->phone,
            'email' => $b->email,
            'plan' => $b->plan?->name ?? '—',
            'status' => $b->status,
            'registered' => optional($b->starts_at)->format('Y-m-d') ?? '—',
            'branches' => $b->branches_count,
            'logo' => $b->logo,
            'city' => $b->city,
            'country' => $b->country,
        ];
    }

    /** قائمة المتاجر في لوحة المنصّة — الحقيقيّة وحدها */
    public static function businesses(): array
    {
        return Business::real()->with('plan')->orderByDesc('id')->get()
            ->map(fn ($b) => self::businessRow($b))->all();
    }

    /**
     * أداء الشركات — لتقرير المنصة.
     *
     * كانت مقصورةً على «محل ورود»: بقيّةٌ من كون النظام محلَّ ورودٍ يومًا.
     * فتقرير المنصة كان يُخرج نوعًا واحدًا ويسقط المخابز والمغاسل والورش —
     * تقريرٌ ناقصٌ لا يقول إنه ناقص.
     */
    public static function businessPerformance(): array
    {
        /*
         * والمتاجر الحقيقية وحدها، والمبيع من الطلبات وحده.
         *
         * كان التقرير يجمع المتجر التجريبيّ إلى التجّار فيتصدّرهم بمبيعاتٍ
         * مخترَعة، ويعدّ الملغى والمعلّق طلبات — فرقمُ «الطلبات» في التقرير
         * يخالف رقمَه في ملفّ المتجر نفسه، وكلاهما عن الشيء ذاته.
         */
        $sold = fn ($q) => $q->sold();

        return Business::real()->with('plan')
            ->withCount(['products', 'users', 'orders as orders_count' => $sold])->get()->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'logo' => $b->logo,
                'owner' => $b->owner_name,
                'city' => $b->city,
                'branches' => $b->branches_count,
                'employees' => (int) $b->users_count,
                'products' => (int) $b->products_count,
                'orders' => (int) $b->orders_count,
                'status' => $b->status,
                'plan' => $b->plan?->name ?? '—',
                'sales' => (float) Order::where('business_id', $b->id)->sold()->sum('total'),
            ])->all();
    }

    public static function plans(): array
    {
        return Plan::orderBy('id')->get()->map(fn ($p) => [
            'name' => $p->name,
            'monthly' => (float) $p->monthly_price,
            'yearly' => (float) $p->yearly_price,
            'color' => $p->color,
            'popular' => (bool) $p->is_popular,
            'features' => $p->features ?? [],
        ])->all();
    }

    public static function subscriptions(): array
    {
        return Subscription::with('business', 'plan')->orderByDesc('id')->get()->map(fn ($s) => [
            'id' => $s->id,
            'business_id' => $s->business_id,
            'plan_id' => $s->plan_id,
            'business' => $s->business?->name ?? '—',
            'plan' => $s->plan?->name ?? '—',
            'start' => optional($s->starts_at)->format('Y-m-d') ?? '—',
            'end' => optional($s->ends_at)->format('Y-m-d') ?? '—',
            'amount' => (float) $s->amount,
            'payment' => $s->payment_status,
            'status' => $s->status,
        ])->all();
    }

    public static function invoices(): array
    {
        return Invoice::with('business', 'plan')->orderByDesc('id')->get()->map(fn ($i) => [
            'id' => $i->id,
            'number' => $i->number,
            'business' => $i->business?->name ?? '—',
            'plan' => $i->plan?->name ?? '—',
            'amount' => (float) $i->amount,
            'date' => optional($i->issued_at)->format('Y-m-d') ?? '—',
            'status' => $i->status,
        ])->all();
    }

    public static function platformUsers(): array
    {
        return User::with('business')->orderByDesc('id')->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'business' => $u->business?->name ?? __('المنصة'),
            'role' => $u->roleLabel(),
            'status' => $u->status,
            'last_login' => optional($u->last_login_at)->format('Y-m-d H:i') ?? '—',
            'created' => optional($u->created_at)->format('Y-m-d') ?? '—',
            'avatar' => $u->avatar ?? self::image('user' . $u->id, 100, 100),
        ])->all();
    }

    /** أحدث الأنشطة من سجل النشاط (حسب الدور) */
    public static function activities(int $limit = 8): array
    {
        $u = auth()->user();
        $q = ActivityLog::query()->latest('id');
        if ($u && ! $u->isSuperAdmin()) {
            $q->where('business_id', self::bid());
        }

        /*
         * بطاقة «أحدث الأنشطة» مراقبةٌ لا مرآة.
         *
         * كانت تعرض صاحبَ الشاشة نفسه: يفتح مدير المنصة لوحته فيقرأ «مدير
         * المنصة — سجّل الدخول»، ويفتح التاجر لوحته فيقرأ فعلَه هو قبل قليل.
         * ثمانية أسطر تُدفع فيها أفعالُ من يجب أن يُراقَبوا خارج الشاشة.
         *
         * والسجلّ الكامل (صفحة «سجل النشاط») لا يُمسّ: هو الدليل، ولا يجوز
         * أن يُنقّى — ما يُخفى من بطاقةٍ يبقى في السجلّ.
         */
        $q->whereNotIn('user_id', User::where('role', 'super_admin')->select('id'));

        if ($u && ! $u->isSuperAdmin()) {
            /*
             * ولوحة التاجر تعرض موظفيه وحدهم: لا أفعاله هو، ولا ما جرى عبر
             * انتحال الدعم. والبطاقة تتبع صفحة «سجل النشاط» في الحكم نفسه،
             * فلا يقرأ التاجر في اللوحة سطرًا لا يجده في السجلّ فيحتار.
             */
            $q->whereNotIn('user_id', User::where('business_id', self::bid())
                ->where('role', 'admin')->select('id'))
                ->whereNull('impersonator_id');
        }

        return $q->limit($limit)->get()->map(fn ($a) => [
            // «عبر الدعم» تُلحق بالاسم: السطر الواحد لا يتّسع لشارة
            'text' => $a->user_name
                . ($a->impersonator_name ? ' (' . __('عبر الدعم') . ')' : '')
                . ' — ' . $a->description,
            'time' => optional($a->created_at)?->diffForHumans() ?? '—',
            'icon' => $a->icon,
            'color' => $a->color,
        ])->all();
    }

    /* ============================ Admin ============================ */

    /** ملخّص أداء يوم واحد لنشاط (يُستخدم في الملخّص اليومي التلقائي) */
    public static function dailySummaryFor(int $bid, ?\Illuminate\Support\Carbon $date = null): array
    {
        $date = $date ? $date->copy() : now();
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $orders = fn () => Order::where('business_id', $bid)->sold()
            ->whereBetween('ordered_at', [$start, $end]);
        $sales = (float) $orders()->sum('total');
        $count = $orders()->count();
        $avg = $count ? $sales / $count : 0;
        $newCustomers = Customer::where('business_id', $bid)->whereBetween('created_at', [$start, $end])->count();
        $expenses = (float) Expense::where('business_id', $bid)->paid()->whereBetween('spent_at', [$start, $end])->sum('amount');

        $top = OrderItem::whereHas('order', fn ($w) => $w->where('business_id', $bid)->sold()
            ->whereBetween('ordered_at', [$start, $end]))
            ->selectRaw('name, SUM(quantity) as q')->groupBy('name')->orderByDesc('q')->first();

        // مقارنة بأمس (لاتجاه المبيعات)
        $prevSales = (float) Order::where('business_id', $bid)->sold()
            ->whereBetween('ordered_at', [$start->copy()->subDay(), $end->copy()->subDay()])->sum('total');

        return [
            'date' => $start->format('Y-m-d'),
            'sales' => round($sales, 3),
            'orders' => $count,
            'avg' => round($avg, 3),
            'new_customers' => $newCustomers,
            'expenses' => round($expenses, 3),
            'net' => round($sales - $expenses, 3),
            'top_product' => $top?->name,
            'top_qty' => (int) ($top->q ?? 0),
            'trend' => self::trend($sales, $prevSales),
        ];
    }

    public static function adminStats(): array
    {
        $bid = self::bid();
        $mStart = now()->startOfMonth();
        $lmStart = now()->subMonthNoOverflow()->startOfMonth();

        $orders = fn () => Order::where('business_id', $bid)->sold()
            ->when(self::currentBranchId(), fn ($q) => $q->where('branch_id', self::currentBranchId()));

        // المبيعات: اليوم/أمس، والشهر/الشهر السابق (بيانات حقيقية بلا تلفيق)
        $salesToday = (float) $orders()->whereDate('ordered_at', today())->sum('total');
        $salesYesterday = (float) $orders()->whereDate('ordered_at', today()->subDay())->sum('total');
        $salesMonth = (float) $orders()->where('ordered_at', '>=', $mStart)->sum('total');
        $salesLastMonth = (float) $orders()->whereBetween('ordered_at', [$lmStart, $mStart])->sum('total');

        // الطلبات: الإجمالي (القيمة) + نمو الشهر (الاتجاه)
        $ordersTotal = $orders()->count();
        $ordersMonth = $orders()->where('ordered_at', '>=', $mStart)->count();
        $ordersLastMonth = $orders()->whereBetween('ordered_at', [$lmStart, $mStart])->count();

        // متوسط قيمة الطلب: هذا الشهر مقابل السابق
        $avg = $ordersMonth ? $salesMonth / $ordersMonth : 0;
        $avgLast = $ordersLastMonth ? $salesLastMonth / $ordersLastMonth : 0;

        // العملاء: الإجمالي (القيمة) + الجدد هذا الشهر مقابل السابق (الاتجاه)
        $customersTotal = Customer::where('business_id', $bid)->count();
        $customersMonth = Customer::where('business_id', $bid)->where('created_at', '>=', $mStart)->count();
        $customersLastMonth = Customer::where('business_id', $bid)->whereBetween('created_at', [$lmStart, $mStart])->count();

        $lowStock = Product::where('business_id', $bid)->whereColumn('quantity', '<', 'alert_qty')->count();

        // المصروفات وصافي الأرباح: هذا الشهر مقابل السابق
        $expMonth = (float) Expense::where('business_id', $bid)->paid()->where('spent_at', '>=', $mStart)->sum('amount');
        $expLastMonth = (float) Expense::where('business_id', $bid)->paid()->whereBetween('spent_at', [$lmStart, $mStart])->sum('amount');
        $net = $salesMonth - $expMonth;
        $netLast = $salesLastMonth - $expLastMonth;

        // اتجاه المصروفات معكوس: زيادتها اتجاه سلبي (أحمر)
        $expTrend = self::trend($expMonth, $expLastMonth);
        $expTrend['up'] = ! $expTrend['up'];

        return [
            array_merge(['label' => __('مبيعات اليوم'), 'value' => self::money($salesToday), 'icon' => 'shopping-bag', 'color' => 'primary'], self::trend($salesToday, $salesYesterday)),
            array_merge(['label' => __('مبيعات الشهر'), 'value' => self::money($salesMonth), 'icon' => 'trending-up', 'color' => 'success'], self::trend($salesMonth, $salesLastMonth)),
            array_merge(['label' => __('عدد الطلبات'), 'value' => (string) $ordersTotal, 'icon' => 'receipt', 'color' => 'info'], self::trend($ordersMonth, $ordersLastMonth)),
            array_merge(['label' => __('متوسط قيمة الطلب'), 'value' => self::money($avg), 'icon' => 'calculator', 'color' => 'secondary'], self::trend($avg, $avgLast)),
            array_merge(['label' => __('عدد العملاء'), 'value' => (string) $customersTotal, 'icon' => 'users', 'color' => 'primary'], self::trend($customersMonth, $customersLastMonth)),
            ['label' => __('منتجات منخفضة المخزون'), 'value' => (string) $lowStock, 'icon' => 'alert-triangle', 'trend' => __('تنبيه'), 'up' => false, 'color' => 'warning'],
            array_merge(['label' => __('المصروفات'), 'value' => self::money($expMonth), 'icon' => 'arrow-down-circle', 'color' => 'danger'], $expTrend),
            array_merge(['label' => __('صافي الأرباح'), 'value' => self::money($net), 'icon' => 'piggy-bank', 'color' => 'success'], self::trend($net, $netLast)),
        ];
    }

    /**
     * ألوان الأقسام المخزَّنة كأسماء رموز → القيمة السداسية المقابلة من نظام
     * التصميم (الدرجة 600). البذور القديمة تكتب 'primary' ونموذج الإضافة يكتب
     * '#7c3aed'، والواجهة تركّب اللون نصًّا (color + '1a') لتشتقّ خلفية شفافة —
     * فكان الاسم يخرج 'primary1a' وهي قيمة CSS باطلة تُسقَط بلا خطأ، فتظهر
     * أيقونة القسم بلا خلفية. التوحيد هنا لا في الواجهة كي يُصلح كل مستهلك.
     */
    private const CATEGORY_COLORS = [
        'primary' => '#7c3aed',
        'secondary' => '#db2777',
        'success' => '#059669',
        'warning' => '#d97706',
        'danger' => '#dc2626',
        'info' => '#2563eb',
    ];

    /** يمرّر السداسي كما هو ويترجم الاسم؛ وما جهلناه يأخذ لون الأساس */
    public static function categoryColor(?string $color): string
    {
        if ($color && str_starts_with($color, '#')) {
            return $color;
        }

        return self::CATEGORY_COLORS[$color] ?? self::CATEGORY_COLORS['primary'];
    }

    public static function categories(): array
    {
        return Category::where('business_id', self::bid())->withCount('products')->orderBy('id')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'name_en' => $c->name_en,
            'products' => $c->products_count,
            'icon' => $c->icon,
            'color' => self::categoryColor($c->color),
        ])->all();
    }

    /** الإضافات (خدمات/عناصر تُضاف على المنتج مثل التغليف والبطاقة) */
    public static function addons(): array
    {
        return \App\Models\Addon::where('business_id', self::bid())->orderBy('id')->get()->map(fn ($a) => [
            'id' => $a->id,
            // للشاشة كي تعرف أنّ هذه الإضافة تنقص من الرفّ — لا لتحسبها
            'inventory_product_id' => $a->inventory_product_id,
            'name' => $a->name,
            'name_en' => $a->name_en,
            'label' => self::ln($a->name, $a->name_en),
            'price' => (float) $a->price,
            'icon' => $a->icon,
            'active' => (bool) $a->active,
        ])->all();
    }

    /**
     * @param  int|null  $branchId  رصيد هذا الفرع بدل مجموع الشركة.
     *
     * بلا معرّف فرع تعود الكمية الإجمالية — وهذا ما تريده التقارير والتصدير
     * ولوحة المنتجات. أما نقطة البيع فتبيع من فرعٍ بعينه، فلا يجوز أن تعرض
     * لكاشير صلالة بضاعةً في مسقط.
     */
    /**
     * @param  \Illuminate\Http\Request|null  $filter  مُرشِّحات الشاشة — الملفّ يتبعها
     */
    public static function products(?int $branchId = null, ?\Illuminate\Http\Request $filter = null): array
    {
        $available = Stock::availabilityResolver(self::bid(), $branchId);

        /*
         * المقاسات والإضافات المسموحة تُحمَّل مرّةً للشاشة كلّها.
         *
         * استعلامان لا استعلامان في كلّ منتج: شاشةُ بيعٍ فيها مئتا صنف كانت
         * ستُطلق أربعمئة استعلامٍ قبل أن تُرسم.
         */
        $variants = \App\Models\ProductVariant::where('business_id', self::bid())
            ->where('active', true)->orderBy('sort_order')->orderBy('id')->get()->groupBy('product_id');
        $addonMap = \App\Support\ProductAddons::map(self::bid());
        $allAddons = \App\Models\Addon::where('business_id', self::bid())->orderBy('id')->get();
        $recipeOwners = \App\Models\RecipeItem::where('business_id', self::bid())->distinct()->pluck('product_id')
            ->flip()->all();

        $query = Product::where('business_id', self::bid())->with('category')->orderBy('id');

        // الملفّ يتبع الشاشة: مَن رشّح «نفد المخزون» وصدّر لا يريد الجرد كلّه
        if ($filter) {
            ListFilters::products($query, $filter);
        }

        return $query->get()->map(function ($p) use ($branchId, $available, $variants, $addonMap, $allAddons, $recipeOwners) {
            $qty = $branchId ? $available($p->id, (int) $p->quantity) : (int) $p->quantity;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'name_en' => $p->name_en,
                'label' => self::ln($p->name, $p->name_en),
                'cat' => $p->category?->name ?? '—',
                'price' => (float) $p->price,
                'cost' => (float) $p->cost,
                'qty' => $qty,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'image' => $p->image,
                'stock_status' => Product::statusFor($qty, (int) $p->alert_qty),
                'active' => (bool) $p->active,
                'alert' => $p->alert_qty,
                /*
                 * النسبة الفعليّة من المصدر الذي يحتسب بها الخادم — لا العمود الخام.
                 *
                 * `(float) $p->tax` كانت تكتب صفرًا لصنفٍ لم تُحدَّد نسبتُه،
                 * والخادم يقرأ الفارغ «نسبة المتجر». فتُعطى الشاشة صفرًا حيث
                 * يحتسب الخادم خمسة — ونسبةٌ تُقرأ هنا وتُحتسب هناك لا يجوز
                 * أن تُشتقّ بقاعدتين.
                 */
                'tax' => \App\Support\Vat::rateFor($p, self::bid()),
                /*
                 * المقاسات — الفعّالة منها وحدها.
                 *
                 * منتجٌ له مقاسات لا يدخل السلّة بضغطةٍ واحدة: الخادم يرفضه
                 * بلا مقاس، فالشاشة تسأل قبل أن تُضيف. والقائمة الفارغة تعني
                 * منتجًا بسيطًا يُباع كما كان.
                 */
                'variants' => ($variants[$p->id] ?? collect())->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'label' => self::ln($v->name, $v->name_en),
                    'price' => (float) $v->price,
                    'sku' => $v->sku,
                ])->values()->all(),
                /*
                 * معرّفات الإضافات المسموحة — قائمةٌ صريحة لا «فراغٌ يعني الكلّ».
                 *
                 * كانت تُرسل خريطة الربط الخام، وغيابُ الربط يُقرأ في الشاشة
                 * «كلّ إضافات المتجر». وإضافةُ منتجٍ خاصّة لا صفَّ ربطٍ لها،
                 * فكانت تُعرض مع كلّ منتج — عكسُ معناها تمامًا. الخادم كان
                 * يردّها عند الدفع (ProductAddons::allows)، لكنّ الكاشير
                 * يعرضها على الزبون ثمّ يُرفض.
                 *
                 * والقاعدة واحدة للشاشة والخادم: ProductAddons::for.
                 */
                'addon_ids' => \App\Support\ProductAddons::for($p, $allAddons, $addonMap)
                    ->pluck('id')->map(fn ($i) => (int) $i)->all(),
                // ذو الوصفة رصيدُه مكوّناتُه لا عمودُه — تقرؤه الشاشة كي لا
                // تحذّر من نفادِ باقةٍ لا يُخصم رصيدها أصلًا
                'has_recipe' => isset($recipeOwners[$p->id]),
                'discount' => (float) $p->discount,
            ];
        })->all();
    }

    /**
     * فواتير المتجر — بلا مُرشِّح للوحة، وبمُرشِّحات الشاشة للملفّ.
     *
     * وبلا `$filter` تبقى على `sold()` كما كانت: لوحةُ التحكّم تعرض آخر ما
     * بيع لا آخر ما أُلغي. ومع مُرشِّحٍ تصير مرآةَ شاشة «المبيعات» بالضبط —
     * والملغى فيها، لأنّ الشاشة تعرضه وتعدّه.
     *
     * وكان التصدير يقرأ الفرع الأوّل وحده: مَن رشّح الملغاة وصدّر لا يجد
     * ملغاةً واحدة في ملفّه.
     *
     * @param  \Illuminate\Http\Request|null  $filter  مُرشِّحات الشاشة
     */
    public static function orders(?\Illuminate\Http\Request $filter = null): array
    {
        $query = Order::where('business_id', self::bid())
            ->when(self::currentBranchId(), fn ($q) => $q->where('branch_id', self::currentBranchId()))
            ->withCount('items')->orderByDesc('ordered_at');

        if ($filter) {
            $query->where('is_held', false);
            ListFilters::orders($query, $filter);
        } else {
            $query->sold();
        }

        return $query->get()->map(fn ($o) => [
                'id' => $o->number,
                'customer' => self::customerLabel($o->customer_name, $o->customer_name_en),
                'employee' => $o->employee_name ?? '—',
                'branch' => $o->branch,
                'items_count' => $o->items_count,
                'total' => (float) $o->total,
                'payment' => $o->payment_method,
                'status' => $o->status,
                'date' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
            ])->all();
    }

    /** تفاصيل طلب كامل بأصنافه الحقيقية (حسب رقم الطلب) */
    public static function orderDetails($number): array
    {
        $o = Order::where('business_id', self::bid())->where('number', $number)->with('items.addons')->first();
        if (! $o) {
            return [];
        }

        $items = $o->items->map(fn ($it) => [
            'id' => $it->id,
            'product_id' => $it->product_id,
            'name' => $it->displayName(),
            'price' => (float) $it->price,
            'qty' => (int) $it->quantity,
            'total' => $it->lineTotal(),
            'addons' => $it->addons->map(fn ($a) => [
                'name' => $a->name,
                'qty' => (int) $a->quantity,
                'total' => (float) $a->total,
            ])->all(),
        ])->all();

        return [
            'id' => $o->number,
            'db_id' => $o->id,
            'customer' => self::customerLabel($o->customer_name, $o->customer_name_en),
            'employee' => $o->employee_name ?? '—',
            'branch' => $o->branch ?? __('الفرع الرئيسي'),
            'status' => $o->status,
            'payment' => $o->payment_method,
            'payment_status' => $o->payment_status ?? 'مدفوع',
            'date' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
            'subtotal' => (float) $o->subtotal,
            'discount' => (float) $o->discount,
            'tax' => (float) $o->tax,
            'delivery' => (float) $o->delivery_fee,
            'total' => (float) $o->total,
            'notes' => $o->notes,
            /*
             * تفاصيل التنفيذ — تُرسل دائمًا ولو كانت فارغة.
             *
             * الشاشة تُخفي البطاقة حين لا شيء فيها، لكنّ الحقول تُملأ من هذه
             * القيم في نموذج التعديل: لو غابت المفاتيح عن الطلبات القديمة
             * لَبدأ النموذج غير مضبوط، فيصير أوّل حرفٍ يُكتب فيه تحذيرًا في
             * المتصفّح وقيمةً لا تُحفظ.
             */
            'recipient_name' => $o->recipient_name,
            'recipient_phone' => $o->recipient_phone,
            'fulfillment_type' => $o->fulfillment_type,
            'scheduled_for' => optional($o->scheduled_for)->format('Y-m-d\TH:i'),
            'occasion_type' => $o->occasion_type,
            'card_message' => $o->card_message,
            'sender_name' => $o->sender_name,
            'hide_sender' => (bool) $o->hide_sender,
            'delivery_address' => $o->delivery_address,
            'delivery_notes' => $o->delivery_notes,
            'internal_notes' => $o->internal_notes,
            // ما يجوز الانتقال إليه من الحالة الحالية — لا كلّ الحالات
            'next_statuses' => \App\Support\OrderStatus::nextFrom($o->status),
            'occasions' => \App\Support\FlowerOrder::occasionOptions(),
            'fulfillments' => \App\Support\FlowerOrder::fulfillmentOptions(),
            'items' => $items,
            'edits' => self::orderEdits($o->id),
            // ما أذن به التاجر وحده يُعرض في التصحيح — لا يُصحَّح إلى وسيلةٍ مُطفأة
            'payment_methods' => \App\Http\Controllers\Pos\PosController::enabledPaymentMethods(self::businessSettings()),
        ];
    }

    /**
     * تصحيحات فاتورةٍ بعينها — تُقرأ في شاشتي الكاشير وصاحب النشاط معًا.
     *
     * فاتورةٌ نقص إجماليّها تُسأل «لماذا؟» في اللحظة نفسها، فالجواب يُعرض
     * تحتها لا في تقريرٍ آخر يُفتح بقصد.
     */
    public static function orderEdits(int $orderId): array
    {
        return \App\Models\OrderEdit::where('order_id', $orderId)->orderBy('id')->get()->map(fn ($e) => [
            'kind' => $e->kind,
            'subject' => $e->subject,
            'qty_before' => $e->qty_before === null ? null : (int) $e->qty_before,
            'qty_after' => $e->qty_after === null ? null : (int) $e->qty_after,
            'value_before' => $e->value_before,
            'value_after' => $e->value_after,
            'total_before' => (float) $e->order_total_before,
            'total_after' => (float) $e->order_total_after,
            'reason' => $e->reason,
            'by' => $e->employee_name ?? '—',
            'at' => optional($e->created_at)->format('Y-m-d H:i') ?? '—',
        ])->all();
    }

    /**
     * @param  \Illuminate\Http\Request|null  $filter  بحث الشاشة — الملفّ يتبعه
     */
    public static function customers(?\Illuminate\Http\Request $filter = null): array
    {
        /*
         * المُباع وحده، وباستعلامٍ واحد.
         *
         * كان الإنفاق وآخر طلبٍ يُسألان لكل عميل على حدة — ثلاثة استعلامات
         * في كل صفّ، فمئتا عميلٍ ستّمئة استعلام — ويجمعان الملغى والمعلّق
         * معًا، فيخالفان صفحة العميل نفسه.
         */
        $sold = fn ($q) => $q->sold();

        $query = Customer::where('business_id', self::bid())
            ->withCount(['orders as orders_count' => $sold])
            ->withSum(['orders as orders_sum_total' => $sold], 'total')
            ->withMax(['orders as orders_max_ordered_at' => $sold], 'ordered_at')
            ->orderBy('id');

        // الملفّ يتبع بحث الشاشة
        if ($filter) {
            ListFilters::customers($query, $filter);
        }

        return $query->get()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'name_en' => $c->name_en,
                'label' => self::ln($c->name, $c->name_en),
                'phone' => $c->phone,
                'email' => $c->email,
                'tax_number' => $c->tax_number,
                /*
                 * ما تقرأه صفحة العميل — لا ما يكفي جدولَ القائمة.
                 *
                 * الصفحة تعرض العنوان، وتفتح صندوق الملاحظات على محتواه،
                 * وتحمّل فرعَه في نموذج التعديل. وهذه الثلاثة لم تكن تُرسَل
                 * أصلًا: فالعنوان يظهر فارغًا وإن كان مكتوبًا، والملاحظة
                 * تُكتب وتُحفظ ثمّ يعود الصندوق خاليًا فتُكتب فوقها، والأدهى
                 * أن الفرع يصل إلى النموذج فارغًا دائمًا — فكلّ حفظٍ لبيانات
                 * العميل كان يفصله عن فرعه بصمت. لا رسالة خطأ في شيءٍ من ذلك،
                 * إنما حقولٌ غائبة تُقرأ فراغًا.
                 */
                'address' => $c->address,
                'notes' => $c->notes,
                'branch_id' => $c->branch_id,
                'orders' => $c->orders_count,
                'total_spent' => (float) ($c->orders_sum_total ?? 0),
                'last_order' => $c->orders_max_ordered_at
                    ? \Illuminate\Support\Carbon::parse($c->orders_max_ordered_at)->format('Y-m-d')
                    : '—',
                'points' => $c->points,
                'avatar' => self::image('cust' . $c->id, 100, 100),
            ])->all();
    }

    /** عملات النشاط (مع العملة الأساسية) */
    public static function currencies(): array
    {
        return \App\Models\Currency::where('business_id', self::bid())->orderByDesc('is_base')->orderBy('code')->get()->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'symbol' => $c->symbol,
            'rate' => (float) $c->rate,
            'is_base' => (bool) $c->is_base,
            'active' => (bool) $c->active,
        ])->all();
    }

    /** طلبات عميل محدّد (سجل مشترياته) */
    public static function customerOrders($id): array
    {
        return Order::where('business_id', self::bid())->where('customer_id', $id)->sold()
            ->withCount('items')->orderByDesc('ordered_at')->get()->map(fn ($o) => [
                'id' => $o->number,
                'items_count' => $o->items_count,
                'total' => (float) $o->total,
                'payment' => $o->payment_method,
                'status' => $o->status,
                'date' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
            ])->all();
    }

    public static function employees(): array
    {
        $bid = self::bid();

        // مبيعات كل موظف خلال الشهر الحالي (من الطلبات المرتبطة به)
        $monthly = Order::where('business_id', $bid)->sold()
            ->whereBetween('ordered_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereNotNull('user_id')
            ->selectRaw('user_id, SUM(total) as s')->groupBy('user_id')->pluck('s', 'user_id');

        return User::where('business_id', $bid)->where('role', '!=', 'super_admin')
            ->orderBy('id')->get()->map(function ($u) use ($monthly) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'avatar' => $u->avatar ?? self::image('emp' . $u->id, 100, 100),
                    'role' => $u->job_title ?: $u->roleLabel(),
                    'branch' => $u->branch ?? __('الفرع الرئيسي'),
                    'phone' => $u->phone,
                    'email' => $u->email,
                    'sales' => (float) $u->sales_total,
                    'status' => $u->status,
                    'joined' => optional($u->created_at)->format('Y-m-d') ?? '—',
                    'has_pin' => $u->hasPin(),
                    // يُستخدم لترتيب لوحة الأداء
                    'achieved' => (float) ($monthly[$u->id] ?? 0),
                ];
            })->all();
    }

    /** ترتيب الموظفين حسب مبيعات الشهر (لوحة الأداء) */
    public static function employeeLeaderboard(): array
    {
        $rows = collect(self::employees())
            ->sortByDesc('achieved')->values();

        return $rows->map(function ($e, $i) {
            $e['rank'] = $i + 1;

            return $e;
        })->all();
    }

    /* ============================ المورّدون والمشتريات ============================ */

    public static function suppliers(): array
    {
        return Supplier::where('business_id', self::bid())->withCount('purchaseOrders')->orderBy('name')->get()->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'name_en' => $s->name_en,
            // ما يُعرض — ويبقى `name` هو ما يُبحث به ويُطبع في أمر الشراء
            'label' => self::ln($s->name, $s->name_en),
            'phone' => $s->phone,
            'email' => $s->email,
            'contact' => $s->contact_person,
            'notes' => $s->notes,
            'orders_count' => $s->purchase_orders_count,
        ])->all();
    }

    public static function purchaseOrders(): array
    {
        $branches = \App\Models\Branch::where('business_id', self::bid())->pluck('name', 'id');

        /*
         * بنود الأوامر المفتوحة وحدها تُرسَل.
         *
         * نافذة الاستلام تحتاج البنود لتعرض ما بقي من كلّ صنف، وأمرٌ اكتمل
         * استلامه لا يُفتح فيها أبدًا. وإرسال بنود الأوامر كلّها يضاعف
         * الحمولة بعدد أصناف كلّ أمرٍ أُغلق منذ سنة.
         */
        return PurchaseOrder::where('business_id', self::bid())->withCount('items')
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->orderByDesc('id')->get()->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'items' => $p->status === 'مستلم' ? [] : $p->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'quantity' => (int) $i->quantity,
                    'received' => (int) $i->received_quantity,
                    'remaining' => $i->remaining,
                    'cost' => (float) $i->cost,
                ])->all(),
                'branch' => $branches[$p->branch_id] ?? '—',
                'supplier' => $p->supplier_name ?? optional($p->supplier)->name ?? '—',
                'status' => $p->status,
                'total' => (float) $p->total,
                'items_count' => $p->items_count,
                'receipt' => $p->receipt,
                'receipt_name' => $p->receipt_name,
                'ordered' => optional($p->ordered_at)->format('Y-m-d') ?? '—',
                'received' => optional($p->received_at)->format('Y-m-d'),
            ])->all();
    }

    public static function purchaseOrderStats(): array
    {
        $bid = self::bid();
        $base = PurchaseOrder::where('business_id', $bid);

        return [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->whereIn('status', ['مسودة', 'مُرسل', 'مستلم جزئيًا'])->count(),
            'received' => (clone $base)->where('status', 'مستلم')->count(),
            'value' => (float) (clone $base)->whereIn('status', ['مسودة', 'مُرسل', 'مستلم جزئيًا'])->sum('total'),
        ];
    }

    /** اقتراح إعادة الطلب: منتجات وصلت حدّ التنبيه (كمية مقترحة تُعيدها لضعف الحدّ) */
    public static function reorderSuggestions(): array
    {
        return Product::where('business_id', self::bid())->where('active', true)
            ->whereColumn('quantity', '<=', 'alert_qty')->orderBy('quantity')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'qty' => (int) $p->quantity,
                'alert' => (int) $p->alert_qty,
                'suggested' => max(1, (int) $p->alert_qty * 2 - (int) $p->quantity),
                'cost' => (float) $p->cost,
            ])->all();
    }

    /* ============================ التسويق والكوبونات ============================ */

    public static function coupons(): array
    {
        return \App\Models\Coupon::where('business_id', self::bid())->orderByDesc('id')->get()->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'type' => $c->type,
            'value' => (float) $c->value,
            'min_order' => (float) $c->min_order,
            'max_uses' => $c->max_uses,
            'used_count' => (int) $c->used_count,
            'expires' => optional($c->expires_at)->format('Y-m-d'),
            // نهاية اليوم لا أوّله — انظر Coupon::endsAt
            'expired' => $c->isExpired(),
            'active' => (bool) $c->active,
            'display' => $c->type === 'نسبة' ? rtrim(rtrim(number_format($c->value, 2, '.', ''), '0'), '.') . '%' : self::money($c->value),
        ])->all();
    }

    /** الكوبونات الصالحة للاستخدام الآن (مفعّلة، غير منتهية، لم تُستنفد) — لعرضها في نقطة البيع */
    public static function activeCoupons(): array
    {
        return \App\Models\Coupon::where('business_id', self::bid())
            ->where('active', true)
            // من ينتهي اليوم يعمل اليوم كلّه: المقارنة ببداية اليوم لا بالساعة
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()->startOfDay()))
            ->whereRaw('(max_uses IS NULL OR used_count < max_uses)')
            ->orderByDesc('id')->get()->map(fn ($c) => [
                'code' => $c->code,
                'min_order' => (float) $c->min_order,
                'display' => $c->type === 'نسبة'
                    ? rtrim(rtrim(number_format($c->value, 2, '.', ''), '0'), '.') . '%'
                    : self::money($c->value),
            ])->all();
    }

    public static function couponStats(): array
    {
        $bid = self::bid();

        return [
            'total' => \App\Models\Coupon::where('business_id', $bid)->count(),
            'active' => \App\Models\Coupon::where('business_id', $bid)->where('active', true)->count(),
            'redemptions' => (int) \App\Models\Coupon::where('business_id', $bid)->sum('used_count'),
        ];
    }

    /** شرائح العملاء للحملات التسويقية (مع أرقام واتساب) */
    public static function marketingSegment(string $segment = 'all'): array
    {
        $bid = self::bid();
        $q = Customer::where('business_id', $bid)->whereNotNull('phone');

        /*
         * استعلامان للكلّ لا استعلامان لكلّ عميل.
         *
         * كان الصفّ الواحد يكلّف استعلامَين — آخر طلبٍ ومجموع إنفاق — فمتجرٌ
         * بألفَي عميل يدفع أربعة آلاف استعلامٍ وواحدًا لفتح صفحة التسويق
         * مرّة. والمعلّق لا يُعدّ طلبًا هنا كما لا يُعدّ في الإنفاق: عميلٌ
         * علّق سلّةً ولم يدفع كان يظهر نشطًا فيسقط من قائمة «لم يشتروا منذ
         * شهرين» — وهي القائمة التي صُنعت الصفحة لأجلها.
         */
        $sales = Order::where('business_id', $bid)
            ->sold()
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, MAX(ordered_at) as last_at, SUM(total) as spent')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $customers = $q->get()->map(function ($c) use ($sales) {
            $row = $sales->get($c->id);

            return [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'wa' => preg_replace('/\D/', '', (string) $c->phone),
                'last_order' => $row?->last_at ? \Illuminate\Support\Carbon::parse($row->last_at) : null,
                'spent' => (float) ($row->spent ?? 0),
            ];
        });

        $filtered = match ($segment) {
            'inactive' => $customers->filter(fn ($c) => ! $c['last_order'] || $c['last_order']->lt(now()->subDays(60))),
            'top' => $customers->sortByDesc('spent')->take(10),
            default => $customers,
        };

        return $filtered->map(fn ($c) => [
            'id' => $c['id'],
            'name' => $c['name'],
            'phone' => $c['phone'],
            'wa' => $c['wa'],
            'spent' => $c['spent'],
            'last_order' => $c['last_order']?->format('Y-m-d') ?? __('لا يوجد'),
        ])->values()->all();
    }

    /* ============================ ضريبة القيمة المضافة ============================ */

    public static function vatSettings(): array
    {
        $bid = self::bid();
        $get = fn ($k, $d) => \App\Models\Setting::where('business_id', $bid)->where('key', $k)->value('value') ?? $d;

        // مطفأةً: النسبة صفرٌ والرقم الضريبي لا يُطبع — ورقةٌ تحمل رقمًا
        // ضريبيًّا لمتجرٍ لا يجبي الضريبة تدّعي تسجيلًا لا يخصّها
        if (! \App\Support\Vat::enabled($bid)) {
            return ['rate' => 0.0, 'number' => ''];
        }

        return [
            'rate' => (float) $get('vat_rate', 5),
            'number' => $get('vat_number', ''),
        ];
    }

    /** تقرير ضريبة القيمة المضافة لفترة (month|quarter|year) */
    public static function vatReport(string $period = 'quarter'): array
    {
        $bid = self::bid();
        $rate = self::vatSettings()['rate'];
        $now = now();
        [$start, $end, $label] = match ($period) {
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), __('هذا الشهر')],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear(), __('هذه السنة')],
            default => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter(), __('هذا الربع')],
        };

        $orders = Order::where('business_id', $bid)->sold()
            ->whereBetween('ordered_at', [$start, $end]);
        $outputVat = (float) (clone $orders)->sum('tax');
        $taxableSales = (float) (clone $orders)->sum('subtotal');
        $grossSales = (float) (clone $orders)->sum('total');

        // ضريبة المدخلات من المشتريات المستلمة (تُعامل قيمة الأمر كصافٍ قبل الضريبة)
        $purchases = PurchaseOrder::where('business_id', $bid)->where('status', 'مستلم')
            ->whereBetween('received_at', [$start, $end]);
        $inputBase = (float) (clone $purchases)->sum('total');
        $inputVat = round($inputBase * $rate / 100, 3);

        // تفصيل شهري ضمن الفترة
        $months = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $mStart = $cursor->copy()->startOfMonth();
            $mEnd = $cursor->copy()->endOfMonth();
            $mOut = (float) Order::where('business_id', $bid)->sold()->whereBetween('ordered_at', [$mStart, $mEnd])->sum('tax');
            $mSales = (float) Order::where('business_id', $bid)->sold()->whereBetween('ordered_at', [$mStart, $mEnd])->sum('subtotal');
            $months[] = ['label' => $cursor->translatedFormat('F Y'), 'taxable' => round($mSales, 3), 'vat' => round($mOut, 3)];
            $cursor->addMonthNoOverflow();
        }

        return [
            'label' => $label,
            'period' => $period,
            'rate' => $rate,
            'taxable_sales' => round($taxableSales, 3),
            'gross_sales' => round($grossSales, 3),
            'output_vat' => round($outputVat, 3),
            'input_base' => round($inputBase, 3),
            'input_vat' => $inputVat,
            'net_vat' => round($outputVat - $inputVat, 3),
            'months' => $months,
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
        ];
    }

    /* ============================ الربحية ============================ */

    /**
     * إيرادُ الإضافات وتكلفتُها — منسوبةً إلى المنتج الذي بيعت معه.
     *
     * الإضافة بيعٌ وشراءٌ لا رسمٌ صافٍ: «زيادة ثلاث وردات» بريالين ونصف
     * تكلّف تسعمئة بيسة. وكانت غائبةً عن الربحية من الطرفين معًا — لا
     * إيرادُها ولا تكلفتُها — بينما تدخل مجموعَ الفاتورة في المالية. فكان
     * صافي الربح يظهر أعلى ممّا هو بمقدار تكلفة كلّ دبٍّ وشوكولاتةٍ بيعت.
     *
     * والقراءة من لقطة البند لا من الإضافة اليوم: تكلفتُها منسوخةٌ لحظة
     * البيع مضروبةً فيما تأكله — انظر AddonStock.
     *
     * @return array<int, array{revenue: float, cost: float}>  [معرّف المنتج => ...]
     */
    private static function addonProfitByProduct(int $bid, ?string $start): array
    {
        $orders = Order::where('business_id', $bid)->sold()
            ->when($start, fn ($q) => $q->where('ordered_at', '>=', $start))
            ->select('id');

        return \App\Models\OrderItemAddon::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_addons.order_item_id')
            ->whereIn('order_items.order_id', $orders)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as pid, SUM(order_item_addons.total) as revenue, '
                .'SUM(COALESCE(order_item_addons.cost, 0) * order_item_addons.quantity) as cost')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->pid => [
                'revenue' => (float) $r->revenue,
                'cost' => (float) $r->cost,
            ]])->all();
    }

    /** ربح كل منتج = (سعر البيع - التكلفة) × الكمية المباعة، من عناصر الطلبات الفعلية */
    public static function productProfitability(string $range = 'month'): array
    {
        $bid = self::bid();
        $costs = Product::where('business_id', $bid)->pluck('cost', 'id');
        $start = self::rangeStart(self::range($range));

        // التكلفة من لقطة البيع — انظر التعليق في categoryProfitability
        $addons = self::addonProfitByProduct($bid, $start);
        // الصنف الواحد قد يظهر بصفّين لو أُعيدت تسميته بين بيعتين — وإضافاته
        // تُنسب إلى أوّلهما مرّةً واحدة لا إلى كليهما
        $spent = [];

        $rows = OrderItem::whereHas('order', fn ($q) => $q->where('business_id', $bid)->sold()
            ->when($start, fn ($x) => $x->where('ordered_at', '>=', $start)))
            ->selectRaw('product_id, name, SUM(quantity) as qty, SUM(total) as revenue, SUM(cost * quantity) as cost_snapshot, SUM(CASE WHEN cost > 0 THEN quantity ELSE 0 END) as costed_qty')
            ->groupBy('product_id', 'name')->get()->map(function ($r) use ($costs, $addons, &$spent) {
                $cost = (float) ($costs[$r->product_id] ?? 0);
                $cogs = (float) $r->cost_snapshot + $cost * ((int) $r->qty - (int) $r->costed_qty);
                $revenue = (float) $r->revenue;

                $pid = (int) $r->product_id;
                if (isset($addons[$pid]) && ! isset($spent[$pid])) {
                    $spent[$pid] = true;
                    $revenue += $addons[$pid]['revenue'];
                    $cogs += $addons[$pid]['cost'];
                }

                $profit = $revenue - $cogs;

                return [
                    'name' => $r->name,
                    'qty' => (int) $r->qty,
                    'revenue' => round($revenue, 3),
                    'cost' => round($cogs, 3),
                    'profit' => round($profit, 3),
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
                ];
            })->sortByDesc('profit')->values()->all();

        return $rows;
    }

    public static function categoryProfitability(string $range = 'month'): array
    {
        $bid = self::bid();
        $start = self::rangeStart(self::range($range));
        // خريطة product_id -> [category, cost]
        $products = Product::where('business_id', $bid)->with('category')->get()
            ->keyBy('id')->map(fn ($p) => ['cat' => optional($p->category)->name ?? __('غير مصنّف'), 'cost' => (float) $p->cost]);

        /*
         * التكلفة من لقطة البيع لا من بطاقة المنتج اليوم.
         *
         * `receive` تكتب آخر سعر شراء فوق تكلفة المنتج، فحسابُ الربح من
         * البطاقة يجعل رفعَ المورّد سعرَه اليوم يُنقص ربحَ الشهر الماضي —
         * تقريرٌ ماليّ يتغيّر بأثرٍ رجعيّ كلّما اشتريتَ، ولا يُرى لأن الأرقام
         * تبقى معقولة. و`cost_snapshot` مجموعُ ما التُقط، ويعود إلى البطاقة
         * للبيعات التي سبقت اللقطة (صفرًا) فلا تنقلب أرقام ما مضى.
         */
        $agg = [];
        $addons = self::addonProfitByProduct($bid, $start);
        $items = OrderItem::whereHas('order', fn ($q) => $q->where('business_id', $bid)->sold()
            ->when($start, fn ($x) => $x->where('ordered_at', '>=', $start)))
            ->selectRaw('product_id, SUM(quantity) as qty, SUM(total) as revenue, SUM(cost * quantity) as cost_snapshot, SUM(CASE WHEN cost > 0 THEN quantity ELSE 0 END) as costed_qty')
            ->groupBy('product_id')->get();
        foreach ($items as $it) {
            $info = $products[$it->product_id] ?? ['cat' => __('غير مصنّف'), 'cost' => 0];
            $cat = $info['cat'];
            $uncosted = (int) $it->qty - (int) $it->costed_qty;
            $agg[$cat] ??= ['revenue' => 0, 'cost' => 0];
            $agg[$cat]['revenue'] += (float) $it->revenue;
            $agg[$cat]['cost'] += (float) $it->cost_snapshot + $info['cost'] * $uncosted;

            $extra = $addons[(int) $it->product_id] ?? null;
            if ($extra) {
                $agg[$cat]['revenue'] += $extra['revenue'];
                $agg[$cat]['cost'] += $extra['cost'];
            }
        }

        $rows = [];
        foreach ($agg as $cat => $v) {
            $profit = $v['revenue'] - $v['cost'];
            $rows[] = [
                'name' => $cat,
                'revenue' => round($v['revenue'], 3),
                'profit' => round($profit, 3),
                'margin' => $v['revenue'] > 0 ? round($profit / $v['revenue'] * 100, 1) : 0,
            ];
        }
        usort($rows, fn ($a, $b) => $b['profit'] <=> $a['profit']);

        return $rows;
    }

    /**
     * تكلفة البضاعة المباعة في فترة — بابٌ واحد يقرؤه كلّ من يقول «ربح».
     *
     * واللقطة أوّلًا وبطاقةُ المنتج بعدها: `receive` تكتب آخر سعر شراء فوق
     * تكلفة المنتج، فحسابُ الربح من البطاقة وحدها يجعل رفعَ المورّد سعرَه
     * اليوم يُنقص ربحَ الشهر الماضي — تقريرٌ يتغيّر بأثرٍ رجعيّ كلّما اشتريت،
     * ولا يُرى لأنّ الأرقام تبقى معقولة. و`cost_snapshot` مجموعُ ما التُقط،
     * ويعود إلى البطاقة لما بيع قبل وجود اللقطة.
     *
     * وتكلفة الإضافات معها: الإيراد يحمل مجموع الفاتورة بإضافاتها، فإغفالُ
     * تكلفتها يجعل كلّ دبٍّ بيع يظهر ربحًا صافيًا وهو مشترًى.
     */
    public static function cogsFor(int $bid, ?\Illuminate\Support\Carbon $start): float
    {
        $costs = Product::where('business_id', $bid)->pluck('cost', 'id');
        $cogs = 0.0;

        OrderItem::whereHas('order', function ($q) use ($bid, $start) {
            $q->where('business_id', $bid)->sold()
                ->when($start, fn ($x) => $x->where('ordered_at', '>=', $start));
        })->selectRaw('product_id, SUM(quantity) as qty, SUM(cost * quantity) as cost_snapshot, SUM(CASE WHEN cost > 0 THEN quantity ELSE 0 END) as costed_qty')
            ->groupBy('product_id')->get()
            ->each(function ($r) use (&$cogs, $costs) {
                $cogs += (float) $r->cost_snapshot
                    + (float) ($costs[$r->product_id] ?? 0) * ((int) $r->qty - (int) $r->costed_qty);
            });

        foreach (self::addonProfitByProduct($bid, $start) as $extra) {
            $cogs += $extra['cost'];
        }

        return $cogs;
    }

    /**
     * صورة الربح الكاملة لفترة: صافي الإيرادات − تكلفة البضاعة المباعة − المصروفات = صافي الربح.
     * صافي الإيراد من معاملات الدخل (بلا ضريبة)، والتكلفة من تكلفة المنتجات × الكميات المباعة.
     */
    public static function profitStats(string $range = 'month'): array
    {
        $bid = self::bid();
        $start = self::rangeStart($range);

        // صافي الإيرادات (بلا ضريبة) من معاملات الدخل في الفترة
        $income = Transaction::where('business_id', $bid)->where('type', 'دخل')
            ->when($start, fn ($q) => $q->where('occurred_at', '>=', $start));
        $netRevenue = (float) (clone $income)->sum('amount') - (float) (clone $income)->sum('tax_amount');

        // تكلفة البضاعة المباعة — من الباب الواحد لا بحسابٍ ثانٍ هنا
        $cogs = self::cogsFor($bid, $start);

        // المصروفات التشغيلية في الفترة
        $expenses = (float) Expense::where('business_id', $bid)->paid()
            ->when($start, fn ($q) => $q->where('spent_at', '>=', $start))->sum('amount');

        $grossProfit = $netRevenue - $cogs;
        $netProfit = $grossProfit - $expenses;

        return [
            'net_revenue' => round($netRevenue, 3),
            'cogs' => round($cogs, 3),
            'gross_profit' => round($grossProfit, 3),
            'expenses' => round($expenses, 3),
            'net_profit' => round($netProfit, 3),
            'margin' => $netRevenue > 0 ? round($netProfit / $netRevenue * 100, 1) : 0,
        ];
    }

    public static function profitSummary(string $range = 'month'): array
    {
        $prods = self::productProfitability($range);
        $revenue = array_sum(array_column($prods, 'revenue'));
        $cost = array_sum(array_column($prods, 'cost'));
        $profit = $revenue - $cost;

        return [
            'revenue' => round($revenue, 3),
            'cost' => round($cost, 3),
            'profit' => round($profit, 3),
            'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
            'best' => $prods[0] ?? null,
            'worst' => ! empty($prods) ? end($prods) : null,
            'loss_makers' => array_values(array_filter($prods, fn ($p) => $p['profit'] < 0)),
        ];
    }

    /**
     * جدول المخزون — وأرقامه تتبع الفرع المختار حين يكون مختارًا.
     *
     * كانت الكمية والحالة من إجمالي الشركة دائمًا، والبيع يقرأ رصيد الفرع
     * (انظر Support\Stock). فصلالة برصيد صفرٍ ومسقط بخمسين تُقرأ على الشاشة
     * «متوفر»، ولا تنبيه ولا قائمة إعادة طلب — والكاشير في صلالة لا يستطيع
     * البيع. ولا يعلم أحدٌ حتى يقف زبونٌ أمام الصندوق.
     *
     * و«كل الفروع» يبقى إجماليًّا: هو عرضُ الشركة لا موضعُ بيع.
     */
    public static function inventory(): array
    {
        $branchNames = \App\Models\Branch::where('business_id', self::bid())->pluck('name', 'id');
        $stocks = \App\Models\BranchStock::where('business_id', self::bid())->get()->groupBy('product_id');
        $books = \App\Models\BranchStock::books(self::bid());
        $here = self::currentBranchId();

        return Product::where('business_id', self::bid())->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'qty' => $here ? (int) ($books[$p->id][$here] ?? 0) : $p->quantity,
            // الإجمالي يبقى معروضًا: من يرى فرعه صفرًا يحتاج أن يعرف أن في غيره بضاعة
            'totalQty' => (int) $p->quantity,
            'min' => $p->alert_qty,
            'status' => $here
                ? Product::statusFor((int) ($books[$p->id][$here] ?? 0), (int) $p->alert_qty)
                : $p->stock_status,
            'cost' => (float) $p->cost,
            /*
             * القيمة تقيس ما تقيسه الكمية — الفرع لا الشركة.
             *
             * كانت الكمية من الفرع المختار والقيمة من إجمالي الشركة: سطرٌ
             * واحد نصفُه هنا ونصفُه هناك. والشاشة كانت تُعيد حسابها بنفسها
             * (cost × qty) فتقرأ الصواب، والملفّ يأخذ ما أُرسل — فيختلف
             * الرقم بين ما رآه التاجر وما أرسله إلى محاسبه.
             */
            'value' => round((float) $p->cost * ($here ? (int) ($books[$p->id][$here] ?? 0) : (int) $p->quantity), 3),
            // وقيمة الشركة تبقى معروضةً باسمها لا تحت اسم الفرع
            'totalValue' => round((float) $p->cost * (int) $p->quantity, 3),
            'updated' => optional($p->updated_at)->format('Y-m-d') ?? '—',
            'branches' => ($stocks[$p->id] ?? collect())
                ->map(fn ($s) => ['name' => $branchNames[$s->branch_id] ?? '—', 'qty' => (int) $s->quantity])
                ->filter(fn ($b) => $b['qty'] > 0)->values()->all(),
        ])->all();
    }

    public static function movements(): array
    {
        $branches = \App\Models\Branch::where('business_id', self::bid())->pluck('name', 'id');

        return InventoryMovement::where('business_id', self::bid())->orderByDesc('id')->get()->map(fn ($m) => [
            // المفتاح الحقيقي: الواجهة كانت تركّب مفتاحًا من (الصنف+التاريخ+الكمية)
            // وهو يتكرّر بمجرّد بيع قطعة من الصنف نفسه مرّتين في اليوم
            'id' => $m->id,
            'product' => $m->product_name,
            'sku' => $m->sku,
            'type' => $m->type,
            'qty' => $m->quantity,
            'branch' => $branches[$m->branch_id] ?? '—',
            // مسار التحويل («مسقط ← صلالة») — بلا هذا تُقرأ الحركتان تلفًا ومكسبًا
            'note' => $m->note,
            'employee' => $m->employee_name,
            'date' => optional($m->created_at)->format('Y-m-d') ?? '—',
        ])->all();
    }

    /**
     * مصروفات المتجر — وشهرُ الشاشة معها حين يُصدَّر.
     *
     * الشاشة تفتح على الشهر الجاري، والملفّ كان يخرج بالتاريخ كلّه: يُرشِّح
     * التاجر سبتمبر ويصدّر، فيفتح ملفًّا فيه ثلاث سنوات.
     *
     * @param  \Illuminate\Http\Request|null  $filter  مُرشِّحات الشاشة
     */
    public static function expenses(?\Illuminate\Http\Request $filter = null): array
    {
        $query = Expense::where('business_id', self::bid())->orderByDesc('spent_at');

        if ($filter) {
            ListFilters::expenses($query, $filter);
        }

        return $query->get()->map(fn ($e) => [
            'type' => $e->type,
            'description' => $e->description,
            'amount' => (float) $e->amount,
            'date' => optional($e->spent_at)->format('Y-m-d') ?? '—',
            'employee' => $e->employee_name,
            'method' => $e->method,
        ])->all();
    }

    /** أنواع المصروفات للنشاط الحالي مع إحصاء الاستخدام */
    public static function expenseTypes(): array
    {
        $bid = self::bid();

        // إجماليات المصروفات حسب النوع (نوع المصروف مخزّن كنص في جدول المصروفات)
        $usage = Expense::where('business_id', $bid)
            ->selectRaw('type, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('type')->get()
            ->keyBy('type');

        return \App\Models\ExpenseType::where('business_id', $bid)->orderBy('name')->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            // الحساب الذي يُرحَّل إليه — فارغًا يعني «يُقرأ من الاسم»
            'account_key' => $t->account_key,
            'count' => (int) ($usage[$t->name]->cnt ?? 0),
            'total' => (float) ($usage[$t->name]->total ?? 0),
        ])->all();
    }

    /* ============================ المالية ============================ */

    /**
     * أوّل أيّام الأسبوع وآخرها — بتقويم عُمان لا بتقويم Carbon.
     *
     * الافتراضيّ في Carbon الاثنين، وأسبوع العمل هنا يبدأ الأحد. فمبيعاتُ
     * الأحد كانت تقع في «الأسبوع الماضي» طوال الأسبوع، ومن يفتح تقرير
     * الأسبوع صباح الاثنين يجد يومَ أمسِه غائبًا عنه ولا شيء يقول لماذا.
     *
     * وموضعٌ واحد لهما: خمسة مواضع كانت تكتب startOfWeek() بيدها، وواحدٌ
     * يُنسى يكفي ليفترق التقرير عن مقارنته.
     */
    public const WEEK_START = \Carbon\CarbonInterface::SUNDAY;

    public const WEEK_END = \Carbon\CarbonInterface::SATURDAY;

    /** بداية الفترة المختارة (اليوم/الأسبوع/الشهر/السنة) — null تعني كل الفترات */
    public static function rangeStart(string $range): ?\Illuminate\Support\Carbon
    {
        return match ($range) {
            'today' => now()->startOfDay(),
            'week' => now()->startOfWeek(self::WEEK_START),
            'year' => now()->startOfYear(),
            'month' => now()->startOfMonth(),
            default => null,
        };
    }

    /** حدود الفترة السابقة المكافئة [البداية، النهاية) — null إذا لا مقارنة (كل الفترات) */
    public static function rangePrev(string $range): ?array
    {
        return match ($range) {
            'today' => [now()->subDay()->startOfDay(), now()->startOfDay()],
            'week' => [now()->subWeek()->startOfWeek(self::WEEK_START), now()->startOfWeek(self::WEEK_START)],
            'year' => [now()->subYear()->startOfYear(), now()->startOfYear()],
            'month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()],
            default => null,
        };
    }

    /** نسبة اتجاه حقيقية مقارنةً بالفترة السابقة */
    private static function trend(float $curr, float $prev): array
    {
        if ($prev <= 0.0) {
            return $curr > 0.0
                ? ['trend' => '+100%', 'up' => true]
                : ['trend' => '0%', 'up' => true];
        }
        $pct = ($curr - $prev) / $prev * 100;
        $up = $pct >= 0;
        return ['trend' => ($up ? '+' : '−') . round(abs($pct)) . '%', 'up' => $up];
    }

    public static function financeStats(string $range = 'month'): array
    {
        $bid = self::bid();
        // الفترة تُردّ إلى المفهوم كما في أخواتها: فترةٌ مجهولة كانت تسقط إلى
        // null فتُقرأ «كل الفترات» بلا أن يقول شيءٌ ذلك
        $start = self::rangeStart(self::range($range));
        $income = Transaction::where('business_id', $bid)->where('type', 'دخل')
            ->when($start, fn ($q) => $q->where('occurred_at', '>=', $start));
        $total = (float) (clone $income)->sum('amount');       // إجمالي المقبوض (شامل الضريبة)
        $tax = (float) (clone $income)->sum('tax_amount');     // ضريبة القيمة المضافة المحصّلة (التزام)
        $net = $total - $tax;                                  // صافي الإيرادات (بلا ضريبة)
        $cash = (float) (clone $income)->where('method', 'نقدي')->sum('amount');

        // الفترة السابقة المكافئة لحساب الاتجاه الحقيقي
        $prev = self::rangePrev($range);
        $pTotal = $pTax = $pNet = $pCash = 0.0;
        if ($prev) {
            $pIncome = Transaction::where('business_id', $bid)->where('type', 'دخل')
                ->whereBetween('occurred_at', $prev);
            $pTotal = (float) (clone $pIncome)->sum('amount');
            $pTax = (float) (clone $pIncome)->sum('tax_amount');
            $pNet = $pTotal - $pTax;
            $pCash = (float) (clone $pIncome)->where('method', 'نقدي')->sum('amount');
        }

        return [
            array_merge(['label' => __('إجمالي المبيعات (شامل الضريبة)'), 'value' => self::money($total), 'icon' => 'wallet', 'color' => 'primary'], self::trend($total, $pTotal)),
            array_merge(['label' => __('صافي الإيرادات (بلا ضريبة)'), 'value' => self::money($net), 'icon' => 'trending-up', 'color' => 'success'], self::trend($net, $pNet)),
            array_merge(['label' => __('ضريبة القيمة المضافة المحصّلة'), 'value' => self::money($tax), 'icon' => 'receipt', 'color' => 'warning'], self::trend($tax, $pTax)),
            array_merge(['label' => __('المدفوعات النقدية'), 'value' => self::money($cash), 'icon' => 'banknote', 'color' => 'info'], self::trend($cash, $pCash)),
        ];
    }

    public static function paymentMethods(string $range = 'month'): array
    {
        $bid = self::bid();
        // الفترة تُردّ إلى المفهوم كما في أخواتها: فترةٌ مجهولة كانت تسقط إلى
        // null فتُقرأ «كل الفترات» بلا أن يقول شيءٌ ذلك
        $start = self::rangeStart(self::range($range));
        $income = Transaction::where('business_id', $bid)->where('type', 'دخل')
            ->when($start, fn ($q) => $q->where('occurred_at', '>=', $start));
        $grand = max(0.001, (float) (clone $income)->sum('amount'));
        $defs = [
            ['name' => __('نقدي'), 'key' => 'نقدي', 'icon' => 'banknote', 'color' => 'success'],
            ['name' => __('تحويل بنكي'), 'key' => 'تحويل بنكي', 'icon' => 'landmark', 'color' => 'info'],
            ['name' => __('بطاقة (فيزا)'), 'key' => 'بطاقة', 'icon' => 'credit-card', 'color' => 'primary'],
        ];
        return array_map(function ($d) use ($income, $grand) {
            $total = (float) (clone $income)->where('method', $d['key'])->sum('amount');
            $count = (clone $income)->where('method', $d['key'])->count();
            return array_merge($d, [
                'total' => $total,
                'count' => $count,
                'percent' => (int) round($total / $grand * 100),
            ]);
        }, $defs);
    }

    /**
     * حركات المالية — بسقفٍ لا بلا نهاية.
     *
     * كلّ بيعةٍ تكتب صفًّا هنا، وكانت الصفحة تُحمّل الجدول كلّه منذ أوّل يوم:
     * متجرٌ بعشرة آلاف فاتورة يرسل عشرة آلاف صفٍّ إلى المتصفّح في كل فتح،
     * حتى تعجز الصفحة عن الفتح أصلًا. والسقف هنا لا تصفّحٌ عن قصد: البحث
     * والتصفية في الجدول تعملان على ما وصل، وبترُها إلى صفحاتٍ يجعل البحث
     * يفتّش صفحةً واحدة ويقول «لا نتائج». وللدفتر كاملًا: التصدير.
     */
    public static function transactions(string $range = 'all', ?int $limit = 500): array
    {
        $start = self::rangeStart($range);
        return Transaction::where('business_id', self::bid())
            ->when($start, fn ($q) => $q->where('occurred_at', '>=', $start))
            // والسقف للشاشة وحدها: null يعني الدفتر كاملًا، وهو ما يَعِد به
            // هذا التعليق نفسه منذ كُتب. كان التصدير يقرأ الدالة بسقفها،
            // فيخرج ملفُّ «كل الحركات» بأحدث خمسمئةٍ ولا سطرَ فيه يقول ذلك —
            // ومحاسبٌ يجمع عمودًا مبتورًا لا يعرف أنه مبتور.
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->orderByDesc('occurred_at')->get()->map(fn ($t) => [
            // المرجع للعرض، والمفتاح للهوية: مرجعان متطابقان (تصحيح يشير
            // لفاتورة أصلية مثلًا) كانا يجعلان React يُسقط صفًّا من دفتر مالي
            'key' => $t->id,
            'id' => $t->reference,
            'date' => optional($t->occurred_at)->format('Y-m-d H:i') ?? '—',
            'description' => $t->description,
            'method' => $t->method,
            'type' => $t->type,
            'amount' => (float) $t->amount,
            'employee' => $t->employee_name,
        ])->all();
    }

    /* ============================ بيانات المخططات (من قاعدة البيانات) ============================ */

    private const AR_MONTHS = [1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'];

    /**
     * اسم الشهر بلغة الواجهة.
     *
     * كانت ثلاثة مخططات تقرأ AR_MONTHS مباشرةً بلا فحص اللغة، فتظهر «مارس»
     * و«يوليو» على لوحةٍ إنجليزية كاملة — ومن لا يقرأ العربية لا يعرف أيّ
     * عمودٍ أيّ شهر، فيقرأ المخطط بالعكس ولا يشكّ.
     */
    private static function monthLabel(\Carbon\Carbon|\Illuminate\Support\Carbon $m): string
    {
        return app()->getLocale() === 'ar' ? self::AR_MONTHS[$m->month] : $m->translatedFormat('F');
    }

    /**
     * أشهر السنة الجارية: يناير … ديسمبر.
     *
     * كانت المخططات تعرض «آخر ١٢ شهرًا» — نافذةً متدحرجة تبدأ من شهر اليوم
     * فتقرأ سبتمبر · أكتوبر … أغسطس. صحيحةٌ حسابيًّا، لكن العين تقرأ محور
     * الأشهر بترتيبه المعروف، فمن ينظر سريعًا يظن العمود الأول يناير.
     *
     * والثمن مذكورٌ لا مخفيّ: الأشهر التي لم تأتِ بعد تُرسم أصفارًا حتى آخر
     * السنة، وما قبل يناير يخرج من الرسم — فمقارنة العام بالعام لا تُقرأ من
     * هنا، بل من تقرير الفترات في «تحليلات متقدمة».
     *
     * startOfMonth ثم setMonth: البناء من يوم اليوم يفيض في ٢٩–٣١ (٣٠ يوليو
     * ← ٣٠ فبراير ← ٢ مارس) فيتكرّر شهر ويسقط آخر.
     *
     * @return array<int, \Illuminate\Support\Carbon>
     */
    private static function yearMonths(): array
    {
        $start = now()->startOfYear();

        return array_map(fn (int $m) => $start->copy()->setMonth($m), range(1, 12));
    }

    /** الفترات التي تفهمها التقارير — مصدرٌ واحد يقرأه الخادم والواجهة */
    public const RANGES = ['today', 'week', 'month', 'year', 'all'];

    /** يردّ فترةً مفهومة، ويردّ ما سواها إلى الافتراضي — الرابط مُدخَل لا يُوثق به */
    public static function range(?string $range, string $fallback = 'month'): string
    {
        return in_array($range, self::RANGES, true) ? $range : $fallback;
    }

    /**
     * وصف الفترة بالكلمات وبتاريخيها — يُطبع في ترويسة كل ملفٍّ يغادر الشاشة.
     *
     * الشاشة يصحّحها المبدّل الذي فوقها، والملفّ لا يصحّحه شيء: يُرسَل إلى
     * المحاسب ويُطبع ويُفتح بعد شهرين، فإن لم يقل أيّ فترةٍ يحمل قُرئ على
     * أنه فترة قارئه.
     */
    public static function rangeLabel(?string $range): string
    {
        [$name, $start] = match (self::range($range)) {
            'today' => [__('اليوم'), now()->startOfDay()],
            'week' => [__('هذا الأسبوع'), now()->startOfWeek(self::WEEK_START)],
            'year' => [__('هذه السنة'), now()->startOfYear()],
            'all' => [__('كل الفترات'), null],
            default => [__('هذا الشهر'), now()->startOfMonth()],
        };

        return $start
            ? $name.' ('.$start->format('Y-m-d').' — '.now()->format('Y-m-d').')'
            : $name;
    }

    /**
     * محور الزمن في مخطّط المبيعات — دقّتُه تتبع الفترة المطلوبة.
     *
     * كان المخطّط يرسم أشهر السنة الجارية دائمًا، مهما كان ما تحته من أرقام.
     * فبطاقات الصفحة تجمع عمر المتجر كلّه والمخطّط يعرض هذه السنة — رقمان
     * لفترتين مختلفتين في شاشةٍ واحدة، وأسوأ من ذلك أن من يختار «اليوم» يرى
     * اثني عشر عمودًا شهريًّا فيظنّ مبيعات اليوم موزّعةً على السنة.
     *
     * والدقّة تتبع المدى: يومٌ يُقرأ بالساعات، وشهرٌ بالأيّام، وسنةٌ بالأشهر.
     * فالفراغات تُملأ أصفارًا: يومٌ بلا بيع فراغٌ في المحور لا سقوطٌ منه، وإلا
     * تقاربت نقطتان بينهما أسبوع فبدا الخطّ متّصلًا وهو منقطع.
     *
     * واستعلامٌ واحد لا استعلامٌ لكل عمود: كان اثنا عشر استعلامًا لرسم سنة،
     * وسيصير واحدًا وثلاثين لرسم شهر بالأيّام.
     *
     * والمحور كاملٌ دائمًا — أربع وعشرون ساعة، أو أيّام الشهر كلّها، أو اثنا
     * عشر شهرًا — ليُقرأ اليومُ في موضعه من فترته: من يرى عمودًا في منتصف
     * محورٍ يعرف أنه في منتصف شهره، ومن يراه في آخره يظنّ الشهر انتهى.
     *
     * لكن ما لم يأتِ بعدُ فراغٌ لا صفر: null لا 0. أيّام لم تُعش ليست أيّامًا
     * بلا بيع، وبرسمها أصفارًا يهوي الخطّ إلى القاع في منتصف كل شهر فيُقرأ
     * انهيارًا وهو تقويمٌ لم يُستهلك. فيبقى المحور كاملًا وينتهي الخطّ عند
     * اليوم.
     *
     * ولكل عمود عدد طلباته إلى جانب مبلغه: مئة ريالٍ من طلبٍ واحد غير مئةٍ من
     * أربعين طلبًا، والمبلغ وحده لا يفرّق بينهما.
     */
    public static function salesTrend(string $range = 'month'): array
    {
        $bid = self::bid();
        $range = self::range($range);
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();

        [$unit, $start, $end] = match ($range) {
            'today' => ['hour', now()->startOfDay(), now()->endOfDay()],
            'week' => ['day', now()->startOfWeek(self::WEEK_START), now()->endOfWeek(self::WEEK_END)],
            'year' => ['month', now()->startOfYear(), now()->endOfYear()],
            // الكلّ: آخر اثني عشر شهرًا — عمر المتجر كلّه على محورٍ واحد يصير خطًّا لا يُقرأ
            'all' => ['month', now()->copy()->subMonths(11)->startOfMonth(), now()->endOfMonth()],
            default => ['day', now()->startOfMonth(), now()->endOfMonth()],
        };

        // الاستعلام يقف عند الآن، والمحور لا يقف: ما بعدها فراغٌ يُملأ null
        $cutoff = $end->gt(now()) ? now()->copy() : $end->copy();

        // التقويم من المحرّك لا من PHP: تجميعُ آلاف الصفوف في الذاكرة لرسم
        // اثني عشر عمودًا يقرأ الجدول كلّه بلا سبب
        $format = match ([$driver, $unit]) {
            ['pgsql', 'hour'] => "to_char(ordered_at, 'HH24')",
            ['pgsql', 'day'] => "to_char(ordered_at, 'YYYY-MM-DD')",
            ['pgsql', 'month'] => "to_char(ordered_at, 'YYYY-MM')",
            ['mysql', 'hour'], ['mariadb', 'hour'] => "DATE_FORMAT(ordered_at, '%H')",
            ['mysql', 'day'], ['mariadb', 'day'] => "DATE_FORMAT(ordered_at, '%Y-%m-%d')",
            ['mysql', 'month'], ['mariadb', 'month'] => "DATE_FORMAT(ordered_at, '%Y-%m')",
            default => match ($unit) {
                'hour' => "strftime('%H', ordered_at)",
                'day' => "strftime('%Y-%m-%d', ordered_at)",
                default => "strftime('%Y-%m', ordered_at)",
            },
        };

        $rows = Order::where('business_id', $bid)
            ->sold()
            ->whereBetween('ordered_at', [$start, $cutoff])
            ->selectRaw("{$format} as bucket, SUM(total) as s, COUNT(*) as c")
            ->groupBy('bucket')
            ->get();

        $sums = $rows->pluck('s', 'bucket');
        $counts = $rows->pluck('c', 'bucket');

        $labels = [];
        $full = [];
        $data = [];
        $orders = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            /*
             * تسميتان لكل عمود: قصيرةٌ على المحور لأن واحدًا وثلاثين يومًا
             * لا تتّسع لأكثر من رقم، وكاملةٌ في التلميح لأن «١٠» وحدها لا
             * تقول أيّ شهرٍ ولا أيّ يومٍ من الأسبوع.
             */
            [$key, $label, $detail] = match ($unit) {
                'hour' => [
                    $cursor->format('H'),
                    $cursor->format('H') . ':00',
                    $cursor->format('H:00') . ' — ' . $cursor->format('H') . ':59',
                ],
                /*
                 * السنة تُكتب على المحور متى عبَرت النافذةُ رأسَ سنة.
                 *
                 * «آخر ١٢ شهرًا» تبدأ من سبتمبر وتنتهي بأغسطس، فيقرأ الناظر
                 * سبتمبر · أكتوبر · نوفمبر · ديسمبر · يناير — ديسمبر قبل
                 * يناير — فيظنّ الأشهر غير مرتّبة وهي مرتّبة على سنتين.
                 */
                'month' => [
                    $cursor->format('Y-m'),
                    self::monthLabel($cursor).($start->year === $end->year ? '' : ' '.$cursor->format('y')),
                    $cursor->translatedFormat('F Y'),
                ],
                default => [
                    $cursor->format('Y-m-d'),
                    $range === 'week' ? $cursor->translatedFormat('D') : $cursor->format('j'),
                    $cursor->translatedFormat('l j F'),
                ],
            };

            // العمود الجاري ليس مستقبلًا: ساعةٌ نحن فيها أو يومٌ نعيشه يُقرأ
            // بما تحقّق منه حتى الآن
            $future = $cursor->gt($cutoff);

            $labels[] = $label;
            $full[] = $detail;
            $data[] = $future ? null : round((float) ($sums[$key] ?? 0), 3);
            $orders[] = $future ? null : (int) ($counts[$key] ?? 0);

            $cursor = match ($unit) {
                'hour' => $cursor->addHour(),
                'month' => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return [
            'labels' => $labels,
            'full' => $full,
            'data' => $data,
            'counts' => $orders,
            'range' => $range,
            'unit' => $unit,
        ];
    }

    /** مبيعات النشاط الحالي في السنة الجارية — يناير … ديسمبر */
    public static function salesSeries(): array
    {
        $bid = self::bid();
        $labels = [];
        $data = [];
        foreach (self::yearMonths() as $m) {
            // اسم الشهر بلغة الواجهة — SetLocale يضبط لغة Carbon لكل طلب
            $labels[] = self::monthLabel($m);
            $data[] = round((float) Order::where('business_id', $bid)->sold()
                ->whereYear('ordered_at', $m->year)->whereMonth('ordered_at', $m->month)->sum('total'), 3);
        }
        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * مبيعات موظف بعينه في السنة الجارية — يناير … ديسمبر.
     *
     * القالب القديم كان يرسم أرقامًا ثابتة مكتوبة يدويًا لكل موظف — فتُظهر
     * لموظف لم يبع شيئًا منحنى صاعدًا. هذه تقرأ طلباته الحقيقية.
     */
    public static function employeeSalesSeries($id): array
    {
        $bid = self::bid();
        $name = \App\Models\User::where('business_id', $bid)->whereKey($id)->value('name');

        $labels = [];
        $data = [];
        foreach (self::yearMonths() as $m) {
            $labels[] = self::monthLabel($m);
            $data[] = $name === null ? 0 : round((float) Order::where('business_id', $bid)
                ->sold()
                ->where('employee_name', $name)
                ->whereYear('ordered_at', $m->year)->whereMonth('ordered_at', $m->month)
                ->sum('total'), 3);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** توزيع المبيعات حسب وسيلة الدفع للنشاط الحالي */
    /** الاسم المعروض لوسيلة الدفع (المفتاح المخزّن في القاعدة يبقى كما هو) */
    /**
     * اسم وسيلة الدفع كما تُعرض.
     *
     * القيمة مخزَّنة عربيةً في عمود الطلب — كتبها نظامنا لا التاجر — فتمريرها
     * على __() يترجمها. وكانت تُرجَع كما هي، فتظهر «نقدي» و«تحويل بنكي» على
     * مخطّطٍ في لوحةٍ إنجليزية كاملة.
     *
     * وما لا مقابل له يعود كما هو: وسيلةٌ سمّاها التاجر بنفسه بياناتُه، وترجمة
     * بياناته ليست إصلاحًا.
     */
    public static function methodLabel(string $key): string
    {
        return __(['بطاقة' => 'بطاقة (فيزا)'][$key] ?? $key);
    }

    /**
     * توزيع المبيعات على وسائل الدفع — بمبلغه وعدد عملياته ونسبته.
     *
     * مصدرٌ واحد يقرؤه مخطّط الشاشة وتقرؤه الملفّات الثلاثة. وكانت الملفّات
     * تأخذ التوزيع من `paymentMethods` — وهي تقرأ دفتر المقبوضات لا الطلبات،
     * فيخرج الملفّ بأرقامٍ غير التي على الشاشة تحت العنوان نفسه. ورقمان
     * لاسمٍ واحد يُبطلان الاثنين: التاجر لا يعرف أيّهما يصدّق.
     *
     * والنسبة تُحسب من مجموع ما هنا لا من دفترٍ آخر، فتجمع مئةً دائمًا.
     */
    public static function paymentBreakdown(string $range = 'month'): array
    {
        $start = self::rangeStart(self::range($range));
        $rows = Order::where('business_id', self::bid())->sold()
            ->when($start, fn ($q) => $q->where('ordered_at', '>=', $start))
            ->selectRaw('payment_method, SUM(total) as s, COUNT(*) as c')
            ->groupBy('payment_method')->orderByDesc('s')->get();

        $grand = (float) $rows->sum('s');

        return $rows->map(fn ($r) => [
            'key' => (string) $r->payment_method,
            'name' => self::methodLabel((string) $r->payment_method),
            'total' => round((float) $r->s, 3),
            'count' => (int) $r->c,
            'percent' => $grand > 0 ? (int) round((float) $r->s / $grand * 100) : 0,
        ])->all();
    }

    /** المخطّط على الشاشة: أسماءٌ ومبالغ من التوزيع نفسه لا من استعلامٍ ثانٍ */
    public static function paymentDistribution(string $range = 'month'): array
    {
        $rows = self::paymentBreakdown($range);

        return [
            'labels' => array_column($rows, 'name'),
            'series' => array_column($rows, 'total'),
        ];
    }

    /** أرقام بطاقات صفحة العملاء — محسوبة فعليًا من قاعدة البيانات (صفر عند فراغها) */
    public static function customerStats(): array
    {
        $bid = self::bid();
        $total = Customer::where('business_id', $bid)->count();
        $newThisMonth = Customer::where('business_id', $bid)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $totalPurchases = (float) Order::where('business_id', $bid)->sold()
            ->whereNotNull('customer_id')->sum('total');

        return [
            'total' => $total,
            'new_this_month' => $newThisMonth,
            'total_purchases' => $totalPurchases,
            'avg_spend' => $total > 0 ? $totalPurchases / $total : 0,
        ];
    }

    /** إجمالي الكمية المباعة من منتج معيّن (حقيقي) */
    public static function productSold(int $productId): int
    {
        return (int) OrderItem::where('product_id', $productId)
            ->whereHas('order', fn ($q) => $q->sold())->sum('quantity');
    }

    /** عدد طلبات موظف معيّن (حقيقي) */
    public static function employeeOrderCount(int $userId): int
    {
        return (int) Order::where('user_id', $userId)->sold()->count();
    }

    /** أعداد نشاط معيّن للوحة المشرف (حقيقية) */
    public static function businessCounts(int $businessId): array
    {
        return [
            'employees' => User::where('business_id', $businessId)->where('role', '!=', 'super_admin')->count(),
            'products' => Product::where('business_id', $businessId)->count(),
            'orders' => Order::where('business_id', $businessId)->sold()->count(),
        ];
    }

    /* ------------------- بيانات شركة بعينها في لوحة المنصة -------------------
     |
     | مدير المنصة بلا business_id، فـbid() ترجع أول نشاط. لذلك كانت صفحات
     | ملف الشركة ومحل الورود تعرض طلبات وموظفي ومنتجات نشاط آخر تحت اسم
     | الشركة المفتوحة. هذه الدوال تأخذ المعرّف صراحةً فلا تخمّنه.
     */

    /**
     * فروع الشركة الحقيقية — القالب كان يولّدها بحلقة وهمية بأسماء مديرين
     * ثابتة وعدد موظفين من rand().
     *
     * users.branch اسم نصّي لا مفتاح أجنبي، فالعدّ بالاسم؛ ويُجمع في استعلام
     * واحد لا واحد لكل فرع.
     */
    public static function businessBranches(int $businessId): array
    {
        $staff = User::where('business_id', $businessId)->where('role', '!=', 'super_admin')
            ->whereNotNull('branch')
            ->selectRaw('branch, COUNT(*) as c')->groupBy('branch')->pluck('c', 'branch');

        return \App\Models\Branch::where('business_id', $businessId)->orderBy('id')->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'phone' => $b->phone,
                'address' => $b->address,
                'employees' => (int) ($staff[$b->name] ?? 0),
            ])->all();
    }

    /** موظفو الشركة */
    public static function businessEmployees(int $businessId): array
    {
        return User::where('business_id', $businessId)->where('role', '!=', 'super_admin')
            ->orderBy('id')->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->roleLabel(),
                'branch' => $u->branch,
                'phone' => $u->phone,
                'status' => $u->status,
            ])->all();
    }

    /** منتجات الشركة */
    public static function businessProducts(int $businessId, int $limit = 12): array
    {
        return Product::where('business_id', $businessId)->with('category')
            ->orderBy('id')->limit($limit)->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category?->name ?? '—',
                'price' => (float) $p->price,
                'qty' => (int) $p->quantity,
                'image' => $p->image,
            ])->all();
    }

    /** آخر طلبات الشركة */
    public static function businessOrders(int $businessId, int $limit = 8): array
    {
        return Order::where('business_id', $businessId)->sold()
            ->withCount('items')->orderByDesc('ordered_at')->limit($limit)->get()
            ->map(fn ($o) => [
                'id' => $o->number,
                'customer' => self::customerLabel($o->customer_name, $o->customer_name_en),
                'items_count' => (int) $o->items_count,
                'total' => (float) $o->total,
                'payment' => $o->payment_method,
                'status' => $o->status,
                'date' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
            ])->all();
    }

    /** أرقام «نظرة عامة» للشركة — كانت ثلاثة أرقام مكتوبة يدويًا في القالب */
    public static function businessOverview(int $businessId): array
    {
        $q = Order::where('business_id', $businessId)->sold();
        $count = (clone $q)->count();
        $sales = (float) (clone $q)->sum('total');

        return [
            'sales' => $sales,
            'orders' => $count,
            'average' => $count > 0 ? round($sales / $count, 3) : 0.0,
        ];
    }

    /** مبيعات الشركة في السنة الجارية — كانت سلسلة أرقام ثابتة في القالب */
    public static function businessSalesSeries(int $businessId): array
    {
        $labels = [];
        $data = [];
        foreach (self::yearMonths() as $m) {
            $labels[] = self::monthLabel($m);
            $data[] = round((float) Order::where('business_id', $businessId)->sold()
                ->whereYear('ordered_at', $m->year)->whereMonth('ordered_at', $m->month)->sum('total'), 3);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** نشاط مستخدم بعينه — صفحة المستخدم كانت تعرض نشاط المنصة كله */
    public static function userActivities(int $userId, int $limit = 10): array
    {
        return ActivityLog::where('user_id', $userId)->latest('id')->limit($limit)->get()
            ->map(fn ($a) => [
                'text' => $a->description,
                'time' => optional($a->created_at)?->diffForHumans() ?? '—',
                'icon' => $a->icon,
                'color' => $a->color,
            ])->all();
    }

    /**
     * أرقام بطاقات الاشتراكات — من صفوف المتاجر لا من جدول الاشتراكات.
     *
     * الجدول يعدّ دوراتٍ لا تجّارًا: من جدّد ثلاث مرّات كان يُحسب اشتراكًا
     * نشطًا وثلاثة منتهية، فتقول الشاشة إنك تخسر وأنت تكسب. والمتجر واحدٌ
     * لا أربعة.
     */
    public static function subscriptionStats(): array
    {
        $subscribed = Business::subscribed()->count();
        $trialing = Business::trialing()->count();
        // المنتهي: تاجرٌ حقيقيّ لم يعد ساريًا — لا دورةٌ قديمة لمن جدّد
        $expired = Business::real()->count() - Business::live()->count();

        // الإيراد المتكرّر من آخر اشتراكٍ لكل متجرٍ مشترك، لا من كل دوراته
        $monthly = (float) Subscription::whereIn('business_id', Business::subscribed()->select('id'))
            ->whereIn('id', Subscription::selectRaw('MAX(id)')->groupBy('business_id'))
            ->sum('amount');

        return [
            'active' => $subscribed, 'trialing' => $trialing, 'expired' => $expired,
            'monthly_revenue' => $monthly, 'yearly_revenue' => $monthly * 12,
        ];
    }

    /** أرقام بطاقات فواتير الاشتراكات (حقيقية) */
    public static function invoiceStats(): array
    {
        $paid = (float) Invoice::where('status', 'مدفوعة')->sum('amount');
        $unpaid = (float) Invoice::where('status', '!=', 'مدفوعة')->sum('amount');

        return ['paid' => $paid, 'unpaid' => $unpaid, 'count' => Invoice::count()];
    }

    /** ملخّص أرقام بطاقات التقارير — كلها محسوبة فعليًا من قاعدة البيانات (صفر عند فراغها) */
    public static function reportSummary(string $range = 'month'): array
    {
        $bid = self::bid();
        /*
         * البطاقات تتبع الفترة كما يتبعها المخطّط.
         *
         * كانت تجمع عمر المتجر كلّه بينما المخطّط تحتها يرسم السنة الجارية —
         * رقمان لفترتين مختلفتين في شاشةٍ واحدة، ولا شيء يقول ذلك.
         *
         * وما لا زمن له يبقى كما هو: عدد المنتجات والموظفين والعملاء حالةٌ
         * الآن لا حصيلةُ فترة، و«منتجات تحت حدّ التنبيه» كذلك.
         */
        $start = self::rangeStart(self::range($range));
        $ordersQ = Order::where('business_id', $bid)->sold()
            ->when($start, fn ($q) => $q->where('ordered_at', '>=', $start));
        $sales = (float) (clone $ordersQ)->sum('total');
        $tax = (float) (clone $ordersQ)->sum('tax');
        $expenses = (float) Expense::where('business_id', $bid)->paid()
            ->when($start, fn ($q) => $q->where('spent_at', '>=', $start))->sum('amount');

        /*
         * «صافي الربح» ربحٌ لا فرقُ طرحٍ بين رقمين.
         *
         * كان `المبيعات − المصروفات`: بلا تكلفةِ بضاعةٍ أصلًا، وبالضريبة
         * داخلَ الإيراد. فمحلٌّ باع بألفٍ اشتراه بستّمئة وأنفق مئتين يقرأ
         * «صافي ربح ٨٠٠» وربحُه مئتان — أربعةُ أضعاف، على بطاقة الصدارة في
         * شاشة التقارير وفي الملفّات الثلاثة التي تخرج منها إلى المحاسب.
         *
         * والتعريف واحدٌ في النظام كلّه (انظر profitStats):
         *   (المبيعات − الضريبة) − تكلفة البضاعة المباعة − المصروفات
         *
         * والضريبة تُطرح لأنها التزامٌ يُورَّد لا إيرادٌ يُملك.
         */
        $cogs = self::cogsFor($bid, $start);

        return [
            'sales' => $sales,
            'profit' => round($sales - $tax - $cogs - $expenses, 3),
            'cogs' => round($cogs, 3),
            'expenses' => $expenses,
            'tax' => $tax,
            'products' => Product::where('business_id', $bid)->count(),
            'inventory_alerts' => Product::where('business_id', $bid)->whereColumn('quantity', '<', 'alert_qty')->count(),
            'employees' => User::where('business_id', $bid)->where('role', '!=', 'super_admin')->count(),
            'customers' => Customer::where('business_id', $bid)->count(),
            'payment_methods' => (int) (clone $ordersQ)->distinct('payment_method')->count('payment_method'),
        ];
    }

    /** أفضل المنتجات مبيعًا (بيانات حقيقية من الطلبات) — مع الفئة ونسبة المبيعات */
    public static function topSellingProducts(int $limit = 5, string $range = 'month'): array
    {
        $bid = self::bid();
        $start = self::rangeStart(self::range($range));
        $rows = OrderItem::whereHas('order', fn ($q) => $q->where('business_id', $bid)->sold()
            ->when($start, fn ($x) => $x->where('ordered_at', '>=', $start)))
            ->selectRaw('name, SUM(quantity) as sold, SUM(total) as revenue')
            ->groupBy('name')->orderByDesc('revenue')->limit($limit)->get();
        $totalRev = (float) $rows->sum('revenue');

        return $rows->map(function ($r) use ($bid, $totalRev) {
            $catId = Product::where('business_id', $bid)->where('name', $r->name)->value('category_id');
            $catName = $catId ? (Category::where('id', $catId)->value('name') ?? '—') : '—';

            return [
                'name' => $r->name,
                'cat' => $catName,
                'sold' => (int) $r->sold,
                'revenue' => round((float) $r->revenue, 3),
                'pct' => $totalRev > 0 ? round($r->revenue / $totalRev * 100) . '%' : '0%',
            ];
        })->all();
    }

    /** مؤشرات الأهداف (KPI): الهدف الشهري مقابل المحقّق */
    public static function kpi(): array
    {
        $bid = self::bid();
        $target = (float) (\App\Models\Setting::where('business_id', $bid)->where('key', 'monthly_target')->value('value') ?? 0);
        $now = now();
        $achieved = (float) Order::where('business_id', $bid)->sold()
            ->whereBetween('ordered_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])->sum('total');
        $daysInMonth = $now->daysInMonth;
        $dayNow = max(1, $now->day);
        $projected = $achieved / $dayNow * $daysInMonth;

        return [
            'target' => $target,
            'achieved' => $achieved,
            'pct' => $target > 0 ? min(100, round($achieved / $target * 100, 1)) : 0,
            'remaining' => max(0, $target - $achieved),
            'projected' => $projected,
            'days_left' => $daysInMonth - $now->day,
            'on_track' => $target > 0 ? $projected >= $target : null,
        ];
    }

    /**
     * تنبيهات ذكية لمتجر محدّد: تراجع المبيعات وعملاء غير نشطين.
     * مستقلّة عن الجلسة كي يستخدمها أمر البريد المجدول.
     *
     * كان فيها «منتج راكد» (لم يُبَع خلال 30 يومًا) وأُزيل بطلب المالك: الركود
     * حالة طبيعية في أصناف الموسم والهدايا، فكان يملأ اللوحة بثلاثة تنبيهات
     * دائمة لا تحتاج تدخّلًا. تقرير المخزون يبقى مرجعه لمن أراده.
     */
    public static function smartAlertsFor(int $bid): array
    {
        $alerts = [];

        // تراجع المبيعات: هذا الشهر مقابل السابق
        $sum = fn ($start, $end) => (float) Order::where('business_id', $bid)->sold()
            ->whereBetween('ordered_at', [$start, $end])->sum('total');
        $cur = $sum(now()->startOfMonth(), now()->endOfMonth());
        $prev = $sum(now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth());
        if ($prev > 0) {
            $delta = round(($cur - $prev) / $prev * 100, 1);
            if ($delta < 0) {
                $alerts[] = ['type' => __('تراجع المبيعات'), 'text' => __('انخفضت مبيعات هذا الشهر بنسبة :pct% مقارنةً بالشهر السابق', ['pct' => abs($delta)]), 'icon' => 'trending-down', 'color' => 'danger', 'url' => route('admin.orders.index')];
            }
        }

        /*
         * «عميل متعثّر»: آخر شراءٍ فعليّ لا آخر صفٍّ باسمه.
         *
         * كان يُقرأ `max(ordered_at)` من كل طلباته بلا تمييز، فطلبٌ ألغي —
         * أو سلّةٌ عُلّقت ولم تُدفع — يجعله يبدو مشتريًا اليوم فيسقط من
         * التنبيه. والتنبيه الذي لا يُطلق أسوأ من غيابه: صاحبه يظنّ أن لا
         * متعثّر عنده لأن الشاشة ساكتة.
         *
         * وباستعلامٍ واحد: كان يُقرأ صفُّ كل عميلٍ على حدة، فمتجرٌ بألفَي
         * عميل يدفع ألفَي استعلامٍ في مهمّةٍ مجدولة تعمل كل يوم.
         */
        $inactive = Customer::where('business_id', $bid)
            ->withMax(['orders as last_sold_at' => fn ($q) => $q->sold()], 'ordered_at')
            ->get()
            ->filter(fn ($c) => $c->last_sold_at
                && \Illuminate\Support\Carbon::parse($c->last_sold_at)->lt(now()->subDays(60)))
            ->take(3);
        foreach ($inactive as $c) {
            $alerts[] = ['type' => __('عميل متعثّر'), 'text' => __('العميل «:name» لم يشترِ منذ أكثر من 60 يومًا', ['name' => $c->name]), 'icon' => 'user-x', 'color' => 'info', 'url' => route('admin.customers.show', $c->id)];
        }

        return $alerts;
    }

    /**
     * مقارنة الفترة المختارة بالفترة السابقة المكافئة.
     *
     * كانت الشهر بالشهر دائمًا مهما اختار التاجر — فمن يقرأ «اليوم» في بقيّة
     * الشاشة يجد هنا شهرًا، ويظنّ الأرقام متناقضة وهي عن فترتين.
     */
    public static function periodComparison(string $range = 'month'): array
    {
        $bid = self::bid();
        $range = self::range($range);
        $now = now();
        $metric = function ($start, $end) use ($bid) {
            $q = Order::where('business_id', $bid)->sold()->whereBetween('ordered_at', [$start, $end]);
            $sales = (float) (clone $q)->sum('total');
            $orders = (clone $q)->count();

            return ['sales' => $sales, 'orders' => $orders, 'avg' => $orders ? $sales / $orders : 0];
        };
        [$curStart, $curEnd] = match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(self::WEEK_START), $now->copy()->endOfWeek(self::WEEK_END)],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            // «الكلّ» لا سابقَ له يُقارَن به: يُقرأ شهرًا كي تبقى المقارنة ذات معنى
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };

        $prevBounds = self::rangePrev($range === 'all' ? 'month' : $range);

        $cur = $metric($curStart, $curEnd);
        $prev = $prevBounds ? $metric($prevBounds[0], $prevBounds[1]) : ['sales' => 0.0, 'orders' => 0, 'avg' => 0.0];
        $delta = fn ($c, $p) => $p > 0 ? round(($c - $p) / $p * 100, 1) : ($c > 0 ? 100.0 : 0.0);

        $salesLabel = match ($range) {
            'today' => __('مبيعات اليوم'),
            'week' => __('مبيعات الأسبوع'),
            'year' => __('مبيعات السنة'),
            default => __('مبيعات الشهر'),
        };

        return [
            ['label' => $salesLabel, 'cur' => self::money($cur['sales']), 'prev' => self::money($prev['sales']), 'delta' => $delta($cur['sales'], $prev['sales']), 'icon' => 'trending-up'],
            ['label' => __('عدد الطلبات'), 'cur' => (string) $cur['orders'], 'prev' => (string) $prev['orders'], 'delta' => $delta($cur['orders'], $prev['orders']), 'icon' => 'receipt'],
            ['label' => __('متوسط قيمة الطلب'), 'cur' => self::money($cur['avg']), 'prev' => self::money($prev['avg']), 'delta' => $delta($cur['avg'], $prev['avg']), 'icon' => 'calculator'],
        ];
    }

    /**
     * استخراج جزء من تاريخ بلغة المحرّك الحالي.
     *
     * strftime() دالة SQLite وحدها: تنفجر على PostgreSQL وMySQL. وهذا
     * الاستعلام لا يُغطّيه اختبار، فالانتقال كان سيكسر تقريرين بصمت لا
     * يُكتشفان إلا حين يفتحهما التاجر.
     *
     * $part: 'dow' يوم الأسبوع (0=الأحد)، 'hour' الساعة (0-23).
     * النتيجة نصّية في المحرّكات الثلاثة كي يبقى مفتاح pluck موحّدًا.
     */
    private static function datePartSql(string $part, string $column): string
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => $part === 'dow'
                // EXTRACT يعيد رقمًا عشريًا — نقصّه ثم نحوّله نصًّا
                ? "LPAD(EXTRACT(DOW FROM {$column})::int::text, 1, '0')"
                : "LPAD(EXTRACT(HOUR FROM {$column})::int::text, 2, '0')",
            'mysql', 'mariadb' => $part === 'dow'
                // DAYOFWEEK يبدأ من 1=الأحد، فنطرح 1 ليوافق strftime
                ? "CAST(DAYOFWEEK({$column}) - 1 AS CHAR)"
                : "LPAD(HOUR({$column}), 2, '0')",
            default => $part === 'dow'
                ? "strftime('%w', {$column})"
                : "strftime('%H', {$column})",
        };
    }

    /** المبيعات حسب أيام الأسبوع */
    public static function salesByWeekday(string $range = 'month'): array
    {
        $labels = [__('الأحد'), __('الاثنين'), __('الثلاثاء'), __('الأربعاء'), __('الخميس'), __('الجمعة'), __('السبت')];
        $expr = self::datePartSql('dow', 'ordered_at');
        $start = self::rangeStart(self::range($range));
        $rows = Order::where('business_id', self::bid())->sold()
            ->when($start, fn ($q) => $q->where('ordered_at', '>=', $start))
            ->selectRaw("{$expr} as w, SUM(total) as s")->groupBy('w')->pluck('s', 'w');
        $data = [];
        for ($i = 0; $i < 7; $i++) {
            $data[] = round((float) ($rows[(string) $i] ?? 0), 3);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** المبيعات حسب ساعات اليوم */
    public static function salesByHour(string $range = 'month'): array
    {
        $expr = self::datePartSql('hour', 'ordered_at');
        $start = self::rangeStart(self::range($range));
        $rows = Order::where('business_id', self::bid())->sold()
            ->when($start, fn ($q) => $q->where('ordered_at', '>=', $start))
            ->selectRaw("{$expr} as h, SUM(total) as s")->groupBy('h')->pluck('s', 'h');
        $labels = [];
        $data = [];
        for ($i = 8; $i <= 22; $i++) {
            $labels[] = sprintf('%02d:00', $i);
            $data[] = round((float) ($rows[sprintf('%02d', $i)] ?? 0), 3);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** أفضل العملاء إنفاقًا */
    public static function topCustomers(int $limit = 7, string $range = 'month'): array
    {
        $start = self::rangeStart(self::range($range));

        return Order::where('business_id', self::bid())->sold()->whereNotNull('customer_name')
            ->when($start, fn ($q) => $q->where('ordered_at', '>=', $start))
            ->selectRaw('customer_name, SUM(total) as t, COUNT(*) as c')
            ->groupBy('customer_name')->orderByDesc('t')->limit($limit)->get()
            ->map(fn ($r) => [
                'name' => self::customerLabel($r->customer_name),
                'total' => round((float) $r->t, 3),
                'orders' => (int) $r->c,
            ])->all();
    }

    /** المبيعات حسب القسم */
    public static function categorySales(string $range = 'month'): array
    {
        $start = self::rangeStart(self::range($range));
        $rows = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            // بيدٍ لأنه انضمام — انظر Order::scopeSold
            ->where('orders.business_id', self::bid())->where('orders.is_held', false)
            ->where('orders.status', '!=', \App\Models\Order::CANCELLED)
            ->when($start, fn ($q) => $q->where('orders.ordered_at', '>=', $start))
            // علامة تنصيص مفردة لا مزدوجة: SQLite يتساهل ويعدّ "..." نصًّا،
            // أما PostgreSQL فيعدّها اسم عمود ويفشل بـ«column does not exist».
            // ومربوطة كمعامل لا مدموجة في النص أصلًا.
            ->selectRaw('COALESCE(categories.name, ?) as cat, SUM(order_items.total) as s', [__('غير مصنّف')])
            ->groupBy('cat')->orderByDesc('s')->get();

        return ['labels' => $rows->pluck('cat')->all(), 'series' => $rows->pluck('s')->map(fn ($v) => round((float) $v, 3))->all()];
    }

    /** إيرادات المنصة (فواتير) في السنة الجارية — يناير … ديسمبر */
    public static function revenueSeries(): array
    {
        $labels = [];
        $data = [];
        foreach (self::yearMonths() as $m) {
            $labels[] = self::monthLabel($m);
            $data[] = round((float) Invoice::whereYear('issued_at', $m->year)->whereMonth('issued_at', $m->month)->sum('amount'), 3);
        }
        return ['labels' => $labels, 'data' => $data];
    }

    /** نمو الشركات (عدد التسجيلات) في السنة الجارية — يناير … ديسمبر */
    public static function businessesGrowthSeries(): array
    {
        $labels = [];
        $data = [];
        foreach (self::yearMonths() as $m) {
            $labels[] = self::monthLabel($m);
            $data[] = Business::real()->whereYear('starts_at', $m->year)->whereMonth('starts_at', $m->month)->count();
        }
        return ['labels' => $labels, 'data' => $data];
    }

    /** توزيع الشركات على الباقات */
    public static function planDistribution(): array
    {
        $rows = Business::with('plan')->get()->groupBy(fn ($b) => $b->plan?->name ?? __('بدون باقة'))->map->count();
        return ['labels' => $rows->keys()->all(), 'series' => $rows->values()->all()];
    }

    /* ============================ باحثات مفردة (حسب المعرّف) ============================ */

    /**
     * السجل المطابق للمعرّف، أو [] إن لم يوجد.
     *
     * لا ترجع أبدًا إلى $rows[0] عند الإخفاق: المستدعون يحرسون بـ abort_if(empty(...), 404)،
     * فالرجوع لأول سجل كان يُبطل كل تلك الحراسات ويعرض سجلًا آخر تحت معرّف غير موجود.
     */
    private static function findById(array $rows, $id, string $key = 'id'): array
    {
        return collect($rows)->firstWhere($key, is_numeric($id) ? (int) $id : $id) ?? [];
    }

    public static function product($id): array { return self::findById(self::products(), $id); }
    public static function order($id): array { return self::findById(self::orders(), $id); }
    public static function customer($id): array { return self::findById(self::customers(), $id); }
    public static function employee($id): array { return self::findById(self::employees(), $id); }
    /**
     * متجرٌ بعينه — صفٌّ واحد باستعلامٍ واحد، والتجريبيّ منها.
     *
     * كانت تُنادى `businesses()` فتُحمَّل صفوف المتاجر كلُّها لتُلتقط منها
     * واحدة. والأسوأ من الكلفة أن تلك القائمة مقصورةٌ على `real()`: فمتجرٌ
     * تجريبيّ — أو متجرٌ رُفعت عنه صفة الحقيقة بالخطأ — يُرجَع فارغًا هنا.
     * وأوراقه كلُّها تُطبع حينئذٍ باسم «Abad POS» بدل اسمه: كلّ فاتورة، وكلّ
     * إيصال، وكلّ تصدير — ورمزُ الفاتورة الضريبيّة يحمل بائعًا ليس البائع.
     * ولا رسالةَ خطأ في ذلك كلِّه، إنما اسمٌ آخر مكتوبٌ في مكان الاسم.
     */
    public static function business($id): array
    {
        $business = is_numeric($id) ? Business::with('plan')->find((int) $id) : null;

        return $business ? self::businessRow($business) : [];
    }
    public static function platformUser($id): array { return self::findById(self::platformUsers(), $id); }

    /* ============================ POS ============================ */

    /** الاسم المعروض حسب اللغة: الإنجليزي إن وُجد والواجهة إنجليزية، وإلا العربي */
    public static function ln(?string $ar, ?string $en): string
    {
        return (app()->getLocale() === 'en' && filled($en)) ? $en : (string) $ar;
    }

    /**
     * اسم العميل كما يُعرض — و«عميل نقدي» ليس اسمًا.
     *
     * الطلب النقدي يُخزَّن باسمٍ نصّي لا بمرجعٍ إلى عميل، فيصل إلى الشاشة
     * عربيًّا مهما كانت اللغة: كاشيرٌ لا يقرأ العربية يرى أغلبَ صفوف الطلبات
     * بلفظٍ واحد لا يفهمه. وهو وسمٌ من النظام لا اسمُ شخص، فيُترجَم.
     */
    public static function customerLabel(?string $ar, ?string $en = null): string
    {
        $label = self::ln($ar, $en);

        return ($label === '' || $label === 'عميل نقدي') ? __('عميل نقدي') : $label;
    }

    /**
     * أقسام نقطة البيع: القيمة تبقى الاسم العربي (لمطابقة الفلترة)،
     * والتسمية المعروضة تُترجَم حسب اللغة.
     */
    public static function posCategories(): array
    {
        $cats = Category::where('business_id', self::bid())->orderBy('id')->get(['name', 'name_en']);
        $list = [['value' => 'الكل', 'label' => __('الكل')]];
        foreach ($cats as $c) {
            $list[] = ['value' => $c->name, 'label' => self::ln($c->name, $c->name_en)];
        }
        return $list;
    }

    /**
     * الطلبات المعلّقة والمحفوظة — للفرع لا للشخص.
     *
     * كانت تُرشَّح على `employee_name == auth()->user()->name`، وهو ما كان
     * صحيحًا يوم كان اسم الموظف هو اسم الحساب المسجَّل. ثم صار البيع يُنسب
     * إلى الكاشير المختار على الصندوق، فصار الطلب يُحفظ باسم «أحمد» ويُبحث
     * عنه باسم «مالك النشاط» — فيُخزَّن بنجاح، وتظهر رسالة «تم تعليق الطلب»،
     * ولا يظهر في القائمة أبدًا. أسوأ أنواع العطب: يقول لك إنه نجح.
     *
     * والصواب أن تكون للفرع: الطلب المعلّق زبونٌ ينتظر عند الصندوق، فمن يقف
     * عليه حين يعود الزبون هو من يستكمله — لا صاحب الجلسة التي أنشأته.
     */
    public static function heldOrders(): array
    {
        return Order::where('business_id', self::bid())->where('is_held', true)
            ->when(self::currentBranchId(), fn ($q) => $q->where('branch_id', self::currentBranchId()))
            ->withCount('items')->orderByDesc('id')->get()->map(fn ($o) => [
                'order_id' => $o->id,
                'id' => $o->number,
                'customer' => self::customerLabel($o->customer_name, $o->customer_name_en),
                'items' => $o->items_count,
                'total' => (float) $o->total,
                'time' => optional($o->ordered_at)->format('H:i') ?? '—',
                // كان الاحتياط اسمًا تجريبيًّا («سارة حسن») يظهر في متجرٍ حقيقي
                'employee' => $o->employee_name ?: __('غير محدّد'),
                'status' => $o->status ?? 'معلّق',
                'saved' => ($o->status ?? '') === 'محفوظ',
            ])->all();
    }

    /**
     * فواتير المتجر. بلا بحث: أحدث $limit فاتورة. مع $search: يبحث في كل الفواتير
     * (بلا حدّ الأحدث) برقم الفاتورة أو اسم العميل أو رقم هاتفه.
     */
    public static function receipts(?string $search = null, int $limit = 30, ?int $shiftId = null): array
    {
        $bid = self::bid();
        // خريطة الاسم → الهاتف لعملاء النشاط (لعرض الهاتف والبحث به)
        $phones = \App\Models\Customer::where('business_id', $bid)
            ->whereNotNull('phone')->pluck('phone', 'name');

        $query = Order::where('business_id', $bid)->sold()
            ->when(self::currentBranchId(), fn ($q) => $q->where('branch_id', self::currentBranchId()))
            // وردية بعينها: شاشة تقفيل الصندوق تسأل عن درجٍ واحد لا عن آخر ٣٠ بيعة
            ->when($shiftId, fn ($q) => $q->where('shift_id', $shiftId))
            ->with('items.addons');

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $op = Search::like();
            // أسماء العملاء الذين يطابق هاتفهم كلمة البحث (للبحث برقم الهاتف)
            $namesByPhone = \App\Models\Customer::where('business_id', $bid)
                ->where('phone', $op, "%{$term}%")->pluck('name')->all();
            $query->where(function ($w) use ($term, $namesByPhone, $op) {
                $w->where('number', $op, "%{$term}%")
                    ->orWhere('customer_name', $op, "%{$term}%");
                if ($namesByPhone) {
                    $w->orWhereIn('customer_name', $namesByPhone);
                }
            });
        }

        return $query->orderByDesc('ordered_at')->limit($limit)->get()->map(fn ($o) => [
                'number' => $o->number,
                'customer' => self::customerLabel($o->customer_name, $o->customer_name_en),
                'phone' => $phones[$o->customer_name] ?? '',
                'total' => (float) $o->total,
                'subtotal' => (float) $o->subtotal,
                'discount' => (float) $o->discount,
                'tax' => (float) $o->tax,
                'delivery_fee' => (float) $o->delivery_fee,
                'payment' => $o->payment_method,
                'time' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
                'employee' => $o->employee_name ?? '—',
                'lines' => $o->items->map(fn ($it) => [
                    // الاسم بمقاسه من لقطة البند لا من علاقةٍ حيّة: مقاسٌ
                    // أُعيد تسميته لا يُغيّر فاتورةً طُبعت — انظر OrderItem::displayName
                    'name' => $it->displayName(),
                    'qty' => $it->quantity,
                    'price' => (float) $it->price,
                    'total' => $it->lineTotal(),
                    'note' => $it->note,
                    'addons' => $it->addons->map(fn ($a) => [
                        'name' => $a->name,
                        'qty' => (int) $a->quantity,
                        'total' => (float) $a->total,
                    ])->all(),
                ])->all(),
            ])->all();
    }

    /* ============================ الإشعارات والوردية ============================ */

    /** إشعارات حقيقية حسب الدور (مخزون منخفض / طلبات / اشتراكات) */
    /** مفاتيح التنبيهات التي أخفاها المستخدم الحالي (حذفها) */
    public static function dismissedNotificationKeys(): array
    {
        $u = auth()->user();
        if (! $u) {
            return [];
        }

        return \App\Models\DismissedNotification::where('user_id', $u->id)->pluck('notif_key')->all();
    }

    /**
     * يبني قائمة التنبيهات مع مفتاح ثابت لكل تنبيه، بعد استبعاد ما حذفه المستخدم.
     * المفتاح ثابت لكل مصدر (منتج/طلب/اشتراك) حتى يبقى محذوفًا بعد إعادة التحميل.
     */
    private static function buildNotifications(int $limit): array
    {
        $u = auth()->user();
        $dismissed = self::dismissedNotificationKeys();
        $items = [];
        $add = function (string $key, array $item) use (&$items, $dismissed) {
            if (! in_array($key, $dismissed, true)) {
                $items[] = array_merge(['key' => $key], $item);
            }
        };

        if ($u && $u->isSuperAdmin()) {
            $subs = Subscription::with('business')
                ->whereNotNull('ends_at')->whereDate('ends_at', '>=', now())
                ->whereDate('ends_at', '<=', now()->addDays(30))
                ->orderBy('ends_at')->limit($limit)->get();
            foreach ($subs as $s) {
                $add('sub-' . $s->id, [
                    'text' => __('اشتراك «:name» ينتهي قريبًا', ['name' => $s->business?->name ?? '—']),
                    'time' => optional($s->ends_at)->format('Y-m-d'),
                    'icon' => 'badge-x', 'color' => 'warning',
                    'url' => route('super-admin.subscriptions.index'),
                ]);
            }

            return $items;
        }

        $bid = self::bid();

        // ملخّص اليوم (بطاقة في الجرس) — يظهر فقط إذا كان مفعّلًا في الإعدادات وهناك نشاط اليوم
        $dailyPref = \App\Models\Setting::where('business_id', $bid)->where('key', 'notify_daily_summary')->value('value');
        if ($dailyPref !== '0') {
            $sum = self::dailySummaryFor($bid);
            if ($sum['orders'] > 0 || $sum['sales'] > 0) {
                $add('daily-' . $sum['date'], [
                    'text' => __('ملخّص اليوم: :sales · :orders طلب · صافي :net', [
                        'sales' => self::money($sum['sales']),
                        'orders' => $sum['orders'],
                        'net' => self::money($sum['net']),
                    ]),
                    'time' => __('ملخّص اليوم'),
                    'icon' => 'bar-chart-3', 'color' => 'success',
                    'url' => route('admin.dashboard'),
                ]);
            }
        }

        $low = Product::where('business_id', $bid)->whereColumn('quantity', '<', 'alert_qty')
            ->orderBy('quantity')->limit($limit)->get();
        foreach ($low as $p) {
            $add('low-' . $p->id, [
                'text' => $p->quantity <= 0
                    ? __('نفد المخزون: :name (:qty متبقٍ)', ['name' => $p->name, 'qty' => $p->quantity])
                    : __('مخزون منخفض: :name (:qty متبقٍ)', ['name' => $p->name, 'qty' => $p->quantity]),
                'time' => __('تنبيه مخزون'),
                'icon' => 'alert-triangle', 'color' => $p->quantity <= 0 ? 'danger' : 'warning',
                'url' => route('admin.inventory.index'),
            ]);
        }
        /*
         * العميل الراكد: اشترى يومًا ثم انقطع. من لم يشترِ قطّ ليس راكدًا بل
         * لم يبدأ — انظر AlertMetrics::dormantCustomers. المدّة تُضبط من
         * الإعدادات (dormant_customer_days) وافتراضها ٦٠ يومًا.
         */
        $dormantPref = \App\Models\Setting::where('business_id', $bid)
            ->where('key', 'notify_dormant_customers')->value('value');
        if ($dormantPref !== '0') {
            $days = \App\Support\AlertMetrics::dormantDays($bid);
            foreach (\App\Support\AlertMetrics::dormantCustomers($bid, $days)->take($limit) as $c) {
                $since = \Illuminate\Support\Carbon::parse($c->last_at);
                $add('dormant-' . $c->id, [
                    'text' => __('عميل راكد: :name — آخر شراء قبل :days يومًا', [
                        'name' => self::ln($c->name, $c->name_en),
                        'days' => $since->diffInDays(now()),
                    ]),
                    'time' => $since->format('Y-m-d'),
                    'icon' => 'user-x', 'color' => 'warning',
                    'url' => route('admin.customers.show', $c->id),
                ]);
            }
        }

        // تنبيهات عرّفها صاحب النشاط بنفسه — قواعد تُفحص الآن، وتذكيرات بموعد
        foreach (\App\Models\CustomAlert::where('business_id', $bid)->where('active', true)->get() as $alert) {
            $due = $alert->type === 'reminder'
                ? ($alert->due_at !== null && $alert->due_at->lte(now()))
                : \App\Support\AlertMetrics::triggered($alert, $bid);

            if (! $due) {
                continue;
            }

            $add('custom-' . $alert->id, [
                'text' => $alert->message,
                'time' => $alert->type === 'reminder'
                    ? optional($alert->due_at)->format('Y-m-d')
                    : __('تنبيه مخصّص'),
                'icon' => $alert->type === 'reminder' ? 'bell-ring' : 'target',
                'color' => $alert->color ?: 'warning',
                'url' => $alert->url(),
            ]);
        }

        $pending = Order::where('business_id', $bid)->sold()
            ->whereIn('status', ['جديد', 'قيد التجهيز'])->orderByDesc('id')->limit($limit)->get();
        foreach ($pending as $o) {
            $add('order-' . $o->number, [
                'text' => __('طلب :number بانتظار التجهيز', ['number' => $o->number]),
                'time' => optional($o->ordered_at)->format('Y-m-d H:i'),
                'icon' => 'receipt', 'color' => 'info',
                'url' => route('admin.orders.show', $o->number),
            ]);
        }

        return $items;
    }

    public static function notifications(): array
    {
        return self::buildNotifications(6);
    }

    public static function notificationsCount(): int
    {
        return count(self::notifications());
    }

    /** كامل قائمة التنبيهات المرسلة (بلا اختصار) — لعرضها في الإعدادات */
    public static function allNotifications(): array
    {
        return self::buildNotifications(100);
    }


    /* ============================ الحساب البنكي وكشف الحساب ============================ */

    /**
     * بيانات حسابٍ بنكيّ للنشاط — المطلوب، وإلا الرئيسيّ.
     *
     * صار للنشاط أكثر من حساب، فمن يقرأ بلا تحديد يقرأ الرئيسيّ لا «أوّل ما
     * يوجد»: الأوّل يتبدّل بترتيب الصفوف فيتبدّل الكشف بلا أن يمسّه أحد.
     */
    public static function bankAccount(?int $accountId = null): array
    {
        $a = $accountId
            ? \App\Models\BankAccount::where('business_id', self::bid())->find($accountId)
            : null;
        $a ??= \App\Support\Bank::account(self::bid());

        return [
            'id' => $a->id,
            'label' => $a->displayName(),
            'bank_name' => $a->bank_name,
            'account_name' => $a->account_name,
            'iban' => $a->iban,
            'opening_balance' => (float) $a->opening_balance,
            'opening_date' => optional($a->opening_date)->format('Y-m-d'),
        ];
    }

    /**
     * كشف حساب محسوب من معاملات النظام برصيد تراكمي.
     *
     * ما مرّ بالبنك وحده وبعد تاريخ الرصيد الافتتاحي — انظر Bank::transactions.
     */
    public static function bankStatement(?int $accountId = null): array
    {
        $acc = self::bankAccount($accountId);
        $balance = $acc['opening_balance'];

        $rows = \App\Support\Bank::transactions(self::bid())
            ->orderBy('occurred_at')->orderBy('id')->get()->map(function ($t) use (&$balance) {
                // المصروفات مخزّنة بإشارة سالبة — نوحّد على القيمة المطلقة والاتجاه من النوع
                $in = $t->type === 'دخل';
                $amount = abs((float) $t->amount);
                $balance += $in ? $amount : -$amount;

                return [
                    'id' => $t->id,
                    'reference' => $t->reference,
                    'date' => optional($t->occurred_at)->format('Y-m-d') ?? '—',
                    'description' => $t->description,
                    'method' => $t->method,
                    'debit' => $in ? 0.0 : $amount,
                    'credit' => $in ? $amount : 0.0,
                    'balance' => round($balance, 3),
                ];
            })->all();

        return ['opening' => $acc['opening_balance'], 'rows' => $rows, 'closing' => round($balance, 3)];
    }

    /** أسطر كشف البنك المستوردة مع حالة المطابقة — لحسابٍ واحد */
    public static function bankLines(?int $accountId = null): array
    {
        return \App\Models\BankStatementLine::where('business_id', self::bid())
            ->when($accountId, fn ($q) => $q->where(fn ($w) => $w
                ->where('bank_account_id', $accountId)->orWhereNull('bank_account_id')))
            ->with('transaction')->orderBy('date')->get()->map(fn ($l) => [
                'id' => $l->id,
                'date' => optional($l->date)->format('Y-m-d') ?? '—',
                'description' => $l->description ?: '—',
                'reference' => $l->reference ?: '—',
                'amount' => (float) $l->amount,
                'status' => $l->match_status,
                'matched' => $l->match_status === 'مطابق',
                'transaction' => $l->transaction?->reference,
            ])->all();
    }

    /** ملخّص المطابقة: الفروقات بين البنك والنظام */
    public static function reconciliationSummary(?int $accountId = null): array
    {
        $bid = self::bid();
        $lines = \App\Models\BankStatementLine::where('business_id', $bid)
            ->when($accountId, fn ($q) => $q->where(fn ($w) => $w
                ->where('bank_account_id', $accountId)->orWhereNull('bank_account_id')))
            ->get();
        $matchedIds = $lines->whereNotNull('transaction_id')->pluck('transaction_id')->all();

        /*
         * «غير مطابق في النظام» داخل مدى الكشف وحده.
         *
         * كان يعدّ عمر المتجر كلّه: تستورد كشف شهرٍ فيقول إنّ معاملات الأشهر
         * السابقة «ناقصة من البنك» — وهي في كشوفها هي. رقمٌ يخيف بلا سبب،
         * ويُفقد الرقمَ معناه حين يكبر.
         */
        $unmatchedSystem = \App\Support\Bank::transactions($bid)
            ->when($matchedIds, fn ($q) => $q->whereNotIn('id', $matchedIds))
            ->when($lines->count(), fn ($q) => $q
                ->whereBetween('occurred_at', [
                    $lines->min('date')->copy()->startOfDay(),
                    $lines->max('date')->copy()->endOfDay(),
                ]))
            ->count();

        return [
            'lines' => $lines->count(),
            'matched' => $lines->where('match_status', 'مطابق')->count(),
            'unmatched_bank' => $lines->where('match_status', '!=', 'مطابق')->count(),
            'unmatched_system' => $lines->count() ? $unmatchedSystem : 0,
            'bank_total' => round((float) $lines->sum('amount'), 3),
        ];
    }
}
