<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use App\Support\Permissions;
use App\Support\PlanFeatures;
use App\Support\PosCashier;
use App\Support\PosTerminal;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * البيانات المشتركة مع كل صفحة — ما تحتاجه القشرة (القائمة الجانبية،
     * الشريط العلوي، مبدّل الفرع والعملة، الإشعارات) دون أن يطلبها كل controller.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => fn () => $user ? [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'roleLabel' => $user->roleLabel(),
                    'avatar' => $user->avatar,
                    'branch' => $user->branch,
                    'businessId' => $user->business_id,
                ],
                // هل يدخل هذا المستخدم لوحة النشاط؟ من نفس مصدر حارس المسار،
                // فلا يظهر في نقطة البيع زرُّ عودةٍ يقود إلى 403
                'entersPanel' => Permissions::entersPanel($user),
                // وإلى أين يدخل: الوجهة لا الإذن وحده — انظر Permissions::panelEntry
                'panelUrl' => Permissions::panelEntry($user),
                /*
                 * هل هذه جلسة انتحالٍ من لوحة المنصة؟
                 *
                 * تُعلَن في كل صفحة لا في صفحةٍ واحدة: من ينسى أنه في حساب
                 * غيره يعدّل بيانات تاجرٍ ظنًّا أنها بياناته.
                 */
                'impersonating' => $request->session()->has('impersonator_id'),
                // الأقسام المسموح بها — الواجهة تخفي ما لا يُسمح به بدل تخمينه
                'abilities' => collect(Permissions::sections())
                    ->filter(fn ($section) => $user->allows($section))
                    ->values()
                    ->all(),
                /*
                 * وما تفتحه الباقة — سؤالٌ آخر غير `abilities`.
                 *
                 * ذاك يسأل «هل يملك هذا الموظّف هذا القسم؟» وهذا «هل اشترى
                 * صاحبُ المتجر هذه القدرة؟». والاثنان يقعان على البند الواحد:
                 * مالكٌ يملك كلّ الأقسام في متجرٍ على الباقة الأساسية.
                 *
                 * وتُرسل ليُخفى ما لا يُفتح: بابٌ يُعرض ويردّ بـ403 يجعل صاحبه
                 * يظنّ العطب في النظام ويعيد المحاولة — انظر `Permissions::panelEntry`.
                 */
                'planFeatures' => PlanFeatures::map($user->business),
            ] : null,

            // سياق المتجر: الفرع الحالي والعملة المعروضة ومنازل الكسر
            'context' => fn () => $user?->business_id ? [
                'businessName' => Demo::businessName(),
                // رابط موقع التاجر — يستعمله زرّ «الموقع الإلكتروني» في الهيدر،
                // فصار مشتركًا لا خاصًّا باللوحة. null حين لم يُضبط بعد.
                'website' => Demo::websiteUrl(),
                'branchId' => Demo::currentBranchId(),
                'branchName' => Demo::currentBranchName(),
                'branches' => Demo::branches(),
                // اسم الصندوق الذي يقف عليه — يُعرض في ترويسة نقطة البيع
                'deviceName' => PosTerminal::current()?->name,
                /*
                 * ملحقات هذا الصندوق وحده — لا ملحقات المتجر كلّه.
                 *
                 * الطابعة تُقرأ لتحديد عرض الورق والطباعة التلقائية بعد البيع،
                 * والماسح ليُوجَّه التركيز إلى حقل الباركود من أول الشاشة.
                 * وتُرسل النشطة فقط: ملحقٌ عُطِّل من الإعدادات لأنه معطوب يجب
                 * ألّا يُطبع عليه.
                 */
                'peripherals' => PosTerminal::current()
                    ?->peripherals()->where('active', true)->get()
                    ->map(fn ($p) => [
                        'type' => $p->type,
                        'name' => $p->name,
                        'paperWidth' => $p->paper_width,
                        'autoPrint' => $p->auto_print,
                    ])->values()->all() ?? [],
                'currency' => Demo::displayCurrency(),
                'currencies' => collect(Demo::currencies())->where('active', true)->values()->all(),
                /*
                 * الاشتراك: كم بقي من أيامه.
                 *
                 * الانتهاء كان يقع بلا سابق إنذار — يفتح التاجر لوحته صباحًا
                 * فيجدها مقفلة. والتجديد يحدث حين يُذكَّر به قبل الموعد، لا
                 * حين تُقفل الأبواب.
                 */
                'subscription' => ($ends = $user->business?->ends_at)
                    ? [
                        'endsAt' => $ends->format('Y-m-d'),
                        'daysLeft' => Tenancy::daysLeft($user->business),
                        /*
                         * أيّام المهلة الباقية بعد الانتهاء — الرقم الذي يدفع
                         * إلى الدفع. «انتهى اشتراكك» وحدها لا تقول متى يقف
                         * الصندوق، ومن لا يعرف الموعد يؤجّل إلى أن يفاجئه.
                         */
                        'graceLeft' => Tenancy::graceLeft($user->business),
                    ]
                    : null,
            ] : null,

            // الموظف الواقف على الصندوق — تعرضه ترويسة نقطة البيع، وهو غير
            // الحساب المسجَّل دخوله (انظر App\Support\PosCashier)
            'posCashier' => fn () => $user?->business_id
                ? (PosCashier::current()?->only(['id', 'name']))
                : null,

            'notifications' => fn () => $user?->business_id ? [
                'items' => Demo::notifications(),
                'count' => Demo::notificationsCount(),
            ] : null,

            // رسائل الجلسة — الواجهة تعرضها كـtoast
            'flash' => fn () => [
                'toast' => $request->session()->get('toast'),
                'status' => $request->session()->get('status'),
                /*
                 * كلمةُ مرورٍ وُلِّدت الآن — تمرّ مرّةً واحدة ولا تُحفظ.
                 *
                 * كانت تُرسل داخل نصّ التوست فتختفي بعد ثوانٍ: يقرؤها المدير
                 * نصفَ قراءة، ولا سبيل إلى استرجاعها لأنّها تُحفظ مُجزَّأةً.
                 * فيُعيد التوليد مرّةً بعد مرّة.
                 */
                'password' => $request->session()->get('issued_password'),
            ],

            // الرمز الخام مع كل استجابة: وسم <meta> يُطبع مرّة عند أول تحميل
            // ويتقادم فور تجديد الجلسة عند الدخول، فتُردّ النماذج التي تقرأه بـ419
            'csrf' => fn () => csrf_token(),

            'locale' => fn () => app()->getLocale(),
            'dir' => fn () => app()->getLocale() === 'en' ? 'ltr' : 'rtl',

            // قاموس الترجمة — نفس lang/en.json الذي يقرأه __() في Blade.
            // يُرسل فقط عند الإنجليزية: في العربية المفتاح هو النص نفسه فلا لزوم له.
            'translations' => fn () => app()->getLocale() === 'en' ? self::translations() : null,
        ];
    }

    /** يُقرأ الملف مرة واحدة لكل طلب لا مرة لكل مفتاح */
    private static function translations(): array
    {
        static $cache = null;

        if ($cache === null) {
            $path = lang_path('en.json');
            $cache = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
        }

        return $cache;
    }
}
