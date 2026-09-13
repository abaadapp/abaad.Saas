<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\BusinessTypes;
use App\Support\PosTerminal;
use App\Support\Signup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * فتحُ متجرٍ جديد — معالجٌ يسأل سؤالًا في كلّ شاشة.
 *
 * ═══ وهو بابٌ لم يكن ═══
 *
 * لم يكن في النظام تسجيلٌ عامّ إطلاقًا: المتاجرُ تُنشأ من لوحة المنصّة وحدها
 * (`SuperAdmin\BusinessController`). فمن أراد أبعادًا يتّصل، ويُنشئ له المشغّل
 * متجرًا ويسلّمه اسمَ مستخدمٍ شفاهًا.
 *
 * ═══ والإنشاءُ يقع مرّةً واحدة عند آخر خطوة ═══
 *
 * الخطواتُ كلُّها في المتصفّح: لا شيءَ يُكتب في القاعدة حتى يضغط «ابدأ إدارة
 * متجرك». فمن تركَ المعالجَ في منتصفه لا يترك خلفه متجرًا نصفَ مبنيٍّ بلا
 * مالك — وهو ما يقع في المعالجات التي تحفظ كلَّ خطوة.
 *
 * والحفظُ معاملةٌ واحدة في `Support\Signup`: متجرٌ ومالكٌ وتصنيفاتٌ معًا أو
 * لا شيء.
 */
class RegisterController extends Controller
{
    public function show(): Response
    {
        abort_unless(Signup::open(), 404);

        return Inertia::render('Auth/Register', [
            // الأنواعُ من سجلّها لا من قائمةٍ في الواجهة — انظر BusinessTypes::options
            'activities' => BusinessTypes::options(),
            'teamSizes' => array_map(fn (string $s) => ['value' => $s, 'label' => __($s)], Signup::TEAM_SIZES),
            'year' => (int) now()->format('Y'),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(Signup::open(), 404);

        /*
         * وخنقٌ قبل التحقّق — كخنق الدخول.
         *
         * بابٌ يكتب في القاعدة بلا حسابٍ سابق هو أسهلُ ما يُستنزف: سكربتٌ
         * يفتح ألفَ متجرٍ في دقيقة. والمفتاحُ العنوانُ وحده — لا بريدَ فيه:
         * من يُغرق الباب يبدّل البريدَ في كلّ محاولة.
         */
        $key = 'signup:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('محاولات كثيرة. حاول بعد :seconds ثانية.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        /*
         * والعدّادُ يُزاد **قبل** التحقّق لا بعده.
         *
         * ═══ وهذا أسقطه اختبار ═══
         *
         * كان بعده، فالمحاولاتُ الفاسدة لا تُعدّ: من يُغرق البابَ لا يرسل
         * بيانًا صالحًا أصلًا — يرسل حمولةً ناقصةً ألفَ مرّة، فتسقط كلُّها
         * عند التحقّق ولا يبلغ العدّادَ منها واحدة. فالخنقُ يحرس الناجحين
         * وحدهم، وهم آخرُ من يحتاج الحراسة.
         */
        RateLimiter::hit($key, 900);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'phone' => ['required', 'string', 'min:7', 'max:20'],
            /*
             * والنوعُ من السجلّ نفسِه: `Rule::in` على `TYPES`.
             *
             * فنوعٌ يُرسَل بيدٍ من خارج الشاشة لا يُكتب في العمود — ولو كُتب
             * لخرج متجرٌ بتصنيفاتٍ عامّةٍ لا تخصّ شيئًا، إذ `provision` لا
             * تعرفه.
             */
            'type' => ['required', Rule::in(BusinessTypes::TYPES)],
            'shop' => ['required', 'string', 'min:2', 'max:80'],
            'team_size' => ['nullable', Rule::in(Signup::TEAM_SIZES)],
            'address' => ['nullable', 'string', 'max:180'],
            'email' => ['required', 'email:rfc', 'max:120', 'unique:users,email'],
            /*
             * وكلمةُ المرور ثمانيةٌ فيها حرفٌ ورقم.
             *
             * وهو ما تعلنه الشاشةُ حرفًا بحرف: شرطٌ يُفرض ولا يُقال يجعل
             * المستخدم يجرّب ويُردّ ولا يعرف ما ينقص.
             */
            'password' => ['required', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ], [
            'email.unique' => __('هذا البريد مسجَّل من قبل — سجّل الدخول به.'),
            'password.regex' => __('كلمة المرور تحتاج حرفًا ورقمًا على الأقل.'),
            'password.min' => __('كلمة المرور ثمانية أحرف على الأقل.'),
        ]);

        $owner = Signup::register($data);

        RateLimiter::clear($key);

        /*
         * ثمّ يدخل مباشرةً — لا يُردّ إلى شاشة الدخول ليكتب ما كتبه للتوّ.
         *
         * ولا تحقّقَ بريدٍ يحجزه: لا مُرسِلَ بريدٍ في النظام أصلًا، فرسالةُ
         * التحقّق لا تصل ويقف الحسابُ الجديد عند بابٍ لا يُفتح. ومتى ضُبط
         * المُرسِل كان هذا موضعَ الحجز.
         */
        Auth::login($owner);
        $request->session()->regenerate();
        PosTerminal::rememberBusiness($owner->business_id);

        Activity::log('login', 'دخل بعد التسجيل: '.$owner->email, ['business_id' => $owner->business_id]);

        /*
         * ووجهتُه شاشةُ التهيئة لا اللوحة.
         *
         * `ShopIdentity::steps` تسأل عمّا لم يُسأل في المعالج: الرقمُ
         * الضريبيّ والشعار. ومتجرٌ يفتح لوحتَه قبلها يطبع أوّلَ فاتورةٍ بلا
         * رقمٍ ضريبيٍّ ولا يعرف أنّه نقصها.
         */
        return redirect()->route('admin.setup.index');
    }
}
