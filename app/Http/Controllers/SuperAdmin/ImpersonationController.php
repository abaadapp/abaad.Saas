<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Support\Activity;
use App\Support\Permissions;
use App\Support\Tenancy;
use App\Support\TenantSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * دخول مدير المنصة إلى حساب تاجر ليرى ما يراه.
 *
 * كان `RequiresBusiness` يقول لمدير المنصة حرفيًّا: «ادخل إليه من صفحة
 * الشركات» — وهذا الفعل لم يكن موجودًا. رسالةٌ تُرشد إلى بابٍ لم يُبنَ.
 * فحين يتصل تاجرٌ يقول «الشاشة عندي فارغة» لم يكن أمام الدعم إلا أن يطلب
 * صورة.
 *
 * وثلاثة قيود تجعله أداة دعمٍ لا بابًا خلفيًّا:
 *  ١) الدخول يُسجَّل في سجلّ النشاط باسم من دخل ومتى، والخروج كذلك.
 *  ٢) الجلسة تحمل `impersonator_id` — فالواجهة تعرض شريطًا أحمر لا يُخفى،
 *     ولا يظنّ أحد أنّه في حسابه.
 *  ٣) لا يُنتحل مدير منصةٍ آخر: انتحالُ من يملك مثل صلاحياتك ليس دعمًا.
 *
 * ═══ وبابان: «دخول كتاجر» و«دخول كمستخدم» ═══
 *
 * الأوّل يدخل المتجرَ بحساب صاحبه. لكنّ شكوى الكاشير «لا أرى زرّ الإرجاع»
 * لا تُرى من حساب المالك — هو يرى كلَّ شيء. فالثاني يدخل **الحسابَ المختار
 * نفسَه** بصلاحياته كما هي، لا يُزاد عليها شيء ولا تُمسّ.
 *
 * والبابان يمرّان بـ`enter` وحدها: سجلٌّ واحد، وتفريغُ جلسةٍ واحد
 * (`TenantSwitch`)، وعودةٌ واحدة — لا نسختان تفترقان يومًا.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, $businessId)
    {
        $business = Business::findOrFail($businessId);

        // صاحب الحساب أوّلًا، وإلا أيّ مستخدمٍ فيه — متجرٌ بلا مستخدم لا يُدخل
        $target = User::where('business_id', $business->id)
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if (! $target) {
            return back()->with('toast', [
                'msg' => __('لا يوجد مستخدم في هذا المتجر للدخول به.'),
                'type' => 'warning',
            ]);
        }

        if ($target->isSuperAdmin()) {
            abort(403);
        }

        return $this->enter($request, $target, 'دخل كتاجر: '.$business->name, [
            'business_id' => null,
            'subject_id' => $business->id,
        ], route('super-admin.businesses.index'));
    }

    /**
     * «دخول كمستخدم» — الحسابُ المختار بعينه، لا صاحبُ متجره.
     *
     * المحذوفُ لا يُقرأ هنا أصلًا (`find` لا يرى المحذوف): 404 كأيّ معرّفٍ
     * مجهول. ومديرُ المنصّة 403 كما في «دخول كتاجر». وما عداهما يُردّ بسببه
     * (`refusal`) والجلسةُ كما هي.
     */
    public function startUser(Request $request, $userId)
    {
        $target = User::with('business')->find($userId);
        abort_if($target === null, 404);

        if ($target->isSuperAdmin()) {
            abort(403);
        }

        if ($reason = self::refusal($target)) {
            return back()->with('toast', ['msg' => $reason, 'type' => 'warning']);
        }

        /*
         * والسطرُ يحمل الطرفين بأسمائهما ومعرّفاتهما: الفاعلُ مديرُ المنصّة
         * (`user_id`/`user_name` — يُكتب قبل الدخول)، والمستهدَفُ في `subject_id`
         * ونصِّ السطر مع متجره (#معرّفه) — فالاسمُ يتبدّل يومًا والرقمُ لا.
         */
        return $this->enter($request, $target, self::who('دخل كمستخدم', $target), [
            'business_id' => null,
            'subject_type' => 'user',
            'subject_id' => $target->id,
        ], route('super-admin.users.index'));
    }

    /**
     * لماذا لا يُدخل هذا الحساب — أو null إن جاز.
     *
     * قاعدةٌ واحدة يقرؤها الخادم قبل الدخول، وتقرؤها القائمةُ وملفُّ المستخدم
     * ليُرسم الزرّ أو لا يُرسم. لو كُتبت في الواجهة ثانيةً لافترقتا يومًا:
     * زرٌّ يُرسم فيُردّ صاحبُه، أو بابٌ مأذونٌ يُخفى.
     *
     * والإيقافُ يُقرأ من `Tenancy::blockReason` — مصدرِ شاشة الدخول نفسِه.
     * لكنّ تعطيلَ **المتجر** لا يمنع هنا: «دخول كتاجر» يدخل المعطَّل عمدًا
     * ليرى الدعمُ ما يشكو منه صاحبه (`CheckTenantStatus`)، والبابان سواء.
     * والموقوفُ هو **الحسابُ** نفسُه: صاحبُه لا يدخله، فلا يدخله أحدٌ باسمه.
     */
    public static function refusal(User $target): ?string
    {
        if ($target->trashed()) {
            return __('حساب محذوف — يُستعاد أولًا ثم يُدخل إليه.');
        }

        if ($target->isSuperAdmin()) {
            return __('حساب مدير منصة — لا يُدخل باسمه.');
        }

        if (in_array(Tenancy::blockReason($target), [Tenancy::USER_SUSPENDED, Tenancy::USER_REMOVED], true)) {
            return __('حساب موقوف — صاحبه لا يدخله، فلا يُدخل باسمه.');
        }

        if (! $target->business_id || ! $target->business) {
            return __('حساب غير مربوط بنشاط تجاري — لا متجر يُدخل إليه.');
        }

        return null;
    }

    /** «دخل كمستخدم: منى #7 — متجر الورد #3» — الاسمُ للقارئ، والرقمُ لما بعد تبدّل الاسم */
    private static function who(string $verb, User $user): string
    {
        $shop = $user->business;

        return $verb.': '.$user->name.' #'.$user->id
            .($shop ? ' — '.$shop->name.' #'.$shop->id : '');
    }

    /**
     * الدخولُ نفسُه — للبابين.
     *
     * والخروجُ يعيده إلى حيث دخل، لا إلى قائمة الشركات دائمًا.
     *
     * قسم الديمو يستثني متاجره من تلك القائمة عمدًا — فمن دخل متجرًا
     * تجريبيًّا ثم خرج كان يقف أمام قائمةٍ لا يجد فيها ما خرج منه للتوّ.
     *
     * ويُقبل المرجع إن كان داخل لوحة المنصة وحدها: القيمة تأتي من ترويسة
     * المتصفّح، وإعادةُ التوجيه إلى ما فيها بلا فحصٍ تُخرج المستخدم إلى
     * أي موقع. ويُقرأ **قبل** تفريغ الجلسة: هي التي تحمله.
     */
    private function enter(Request $request, User $target, string $description, array $log, string $fallback): RedirectResponse
    {
        // يُسجَّل قبل الدخول: الفاعلُ في السطر مديرُ المنصّة لا من دخل باسمه
        Activity::log('login', $description, $log);

        $impersonator = $request->user()->id;
        $back = (string) url()->previous();

        // ولا يرث الحسابُ ما تركه في الجلسة حسابٌ قبله — انظر `TenantSwitch`
        TenantSwitch::reset($request);
        Auth::login($target);
        $request->session()->put('impersonator_id', $impersonator);
        $request->session()->put(
            'impersonate_return',
            str_starts_with($back, url('/super-admin')) ? $back : $fallback,
        );

        return redirect(Permissions::homeFor($target))->with('toast', [
            'msg' => __('تدخل الآن باسم :name', ['name' => $target->name]),
            'type' => 'info',
        ]);
    }

    public function stop(Request $request)
    {
        // من كان يُدخَل باسمه — يُقرأ قبل أن تُفرَّغ الجلسة ويتبدّل الحساب
        $was = $request->user();
        $id = $request->session()->pull('impersonator_id');
        $return = (string) $request->session()->pull('impersonate_return');
        $admin = $id ? User::find($id) : null;

        // ولا تعود لوحةُ المنصّة وفي الجلسة بقايا المتجر — انظر `TenantSwitch`
        TenantSwitch::reset($request);

        if (! $admin || ! $admin->isSuperAdmin()) {
            Auth::logout();

            return redirect()->route('login');
        }

        Auth::login($admin);

        /*
         * والخروجُ يقول من أيّ حسابٍ خرج.
         *
         * كان سطرُه «خرج من حساب التاجر» بلا اسم: دخولان في الساعة نفسها لا
         * يُعرف أيُّهما انتهى. والفاعلُ هنا مديرُ المنصّة (عاد إلى حسابه)،
         * والحسابُ الذي خرج منه في `subject_id`.
         */
        Activity::log('logout', $was ? self::who('خرج من حساب', $was) : 'خرج من حساب التاجر', $was ? [
            'business_id' => null,
            'subject_type' => 'user',
            'subject_id' => $was->id,
        ] : ['business_id' => null]);

        return redirect($return ?: route('super-admin.businesses.index'))->with('toast', [
            'msg' => __('عدتَ إلى لوحة المنصة'),
            'type' => 'success',
        ]);
    }
}
