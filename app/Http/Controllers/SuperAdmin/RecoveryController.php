<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Mail\RecoveryOtpMail;
use App\Models\Business;
use App\Models\PasswordRecoveryOtp;
use App\Models\User;
use App\Support\Activity;
use App\Support\Mailer;
use App\Support\MerchantAccount;
use App\Support\RecoveryEmail;
use App\Support\RecoveryOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * المسار الأوّل للمتاجر القديمة — إنسانٌ يتحقّق، ثمّ رمزٌ يُثبت.
 *
 * المتجر الذي لا بريد مختوم له لا يستعيد نفسه، وقد شرحتُ في
 * `AccountRecoveryController` لماذا لا يُطلَب منه رقمُه المسجَّل بدلًا من
 * ذلك: لا رسائل نصّية في هذا النظام، فمطابقةُ رقمٍ مخزَّن تُثبت أنّ الطالب
 * يعرف رقمًا — وهو ما يعرفه كلّ من رأى فاتورةً من المحلّ.
 *
 * فيتحقّق منه إنسانٌ في أبعاد كما يتحقّق اليوم قبل أن يفتح لوحة المنصّة
 * ويضع له كلمة مرور. لكنّ الفرق أنّ هذا يحدث **مرّةً واحدة**: بعدها يملك
 * المتجر بريدًا مختومًا ويستعيد نفسه إلى الأبد.
 *
 * ------------------------------------------------------------------------
 *
 * ومدير المنصّة يكتب العنوان ولا يختمه.
 *
 * الختم لا يضعه إلا رمزٌ عاد من الصندوق. ولو مَلَك موظّفُ الدعم أن يختم
 * عنوانًا بيده لَصار كلُّ حسابٍ في المنصّة مفتوحًا لمن يجلس على تلك الشاشة:
 * يكتب بريده، يختمه، يطلب استعادة، يدخل. وهو الباب الذي جاء هذا العمل
 * كلّه ليُقفله.
 */
class RecoveryController extends Controller
{
    /**
     * يضع العنوان **غير مختوم** ويُرسل إليه رمزًا.
     *
     * فيُنهي صاحبُ المتجر الخطوة من شاشة إعداداته — أو من شاشة الاستعادة إن
     * كان خارج حسابه.
     */
    public function setEmail(Request $request, int $id)
    {
        $business = Business::findOrFail($id);
        $owner = MerchantAccount::owner($business);

        abort_if(! $owner, 422, __('هذه الشركة بلا حساب دخول.'));

        $data = $request->validate([
            'recovery_email' => RecoveryEmail::rules(),
        ], RecoveryEmail::messages());

        $email = RecoveryEmail::normalize($data['recovery_email']);

        if (RecoveryEmail::isInternal($email)) {
            return back()->withErrors([
                'recovery_email' => __('هذا عنوان دخولٍ داخليّ لا صندوق بريد — اكتب بريدًا تصله الرسائل فعلًا.'),
            ]);
        }

        $previous = $owner->recovery_email;

        /*
         * الكتابة تُلغي أيّ ختمٍ سابق.
         *
         * عنوانٌ جديد لم يُثبَت بعدُ لا يرث ثقة الذي قبله؛ ولو ورثها لَكان
         * تغييرُ العنوان من هذه الشاشة استيلاءً كاملًا على الحساب.
         */
        $owner->forceFill([
            'recovery_email' => $email,
            'recovery_email_verified_at' => null,
        ])->save();

        Activity::log('settings', 'مدير المنصة ضبط بريد استعادة (غير موثّق بعد) لمتجر «'.$business->name.'»', [
            'business_id' => $business->id,
            'subject_id' => $business->id,
            'subject_type' => 'business',
            'icon' => 'mail',
        ]);

        $sent = $this->sendVerification($owner, $email);

        if (filled($previous) && $previous !== $email) {
            Activity::log('settings', 'مدير المنصة استبدل بريد استعادةٍ سابقًا لمتجر «'.$business->name.'»', [
                'business_id' => $business->id,
                'subject_id' => $business->id,
                'subject_type' => 'business',
                'icon' => 'shield-alert',
                'color' => 'warning',
            ]);
        }

        return back()->with('toast', [
            'msg' => $sent
                ? __('حُفظ البريد وأُرسل رمز التحقق — يُوثَّق حين يؤكده صاحب المتجر')
                : __('حُفظ البريد، وتعذّر إرسال الرمز الآن'),
            'type' => $sent ? 'success' : 'error',
        ]);
    }

    /** يعيد إرسال رمز التوثيق إلى العنوان المحفوظ */
    public function resend(Request $request, int $id)
    {
        $business = Business::findOrFail($id);
        $owner = MerchantAccount::owner($business);

        abort_if(! $owner || blank($owner->recovery_email), 422, __('لا بريد استعادة محفوظ.'));

        $sent = $this->sendVerification($owner, $owner->recovery_email);

        return back()->with('toast', [
            'msg' => $sent ? __('أُرسل رمز التحقق') : __('تعذّر إرسال الرمز الآن'),
            'type' => $sent ? 'success' : 'error',
        ]);
    }

    /**
     * يمحو البريد وختمه.
     *
     * لحالةٍ واحدة: صاحبٌ فقد صندوقه القديم ولا يستطيع اجتياز رمزه. والمحو
     * يُقيَّد لأنّه ينزع وسيلة الاستعادة كلّها.
     */
    public function clear(Request $request, int $id)
    {
        $business = Business::findOrFail($id);
        $owner = MerchantAccount::owner($business);

        abort_if(! $owner, 422, __('هذه الشركة بلا حساب دخول.'));

        $owner->forceFill(['recovery_email' => null, 'recovery_email_verified_at' => null])->save();

        Activity::log('settings', 'مدير المنصة أزال بريد الاستعادة لمتجر «'.$business->name.'»', [
            'business_id' => $business->id,
            'subject_id' => $business->id,
            'subject_type' => 'business',
            'icon' => 'shield-alert',
            'color' => 'warning',
        ]);

        return back()->with('toast', ['msg' => __('أُزيل بريد الاستعادة'), 'type' => 'success']);
    }

    /** حال الاستعادة لهذا المتجر — يُقرأ في ملفّه */
    public static function view(Business $business): array
    {
        $owner = MerchantAccount::owner($business);

        return [
            'has_owner' => (bool) $owner,
            'email' => $owner?->recovery_email,
            'verified' => $owner?->recovery_email_verified_at !== null,
            'verified_at' => optional($owner?->recovery_email_verified_at)->format('Y-m-d H:i'),
            'mail_ready' => Mailer::configured(),
        ];
    }

    /** الرمز إلى العنوان — ويُنشئ للمالك محاولةً يُكملها من شاشته */
    private function sendVerification(User $owner, string $email): bool
    {
        if (! Mailer::configured()) {
            return false;
        }

        $challenge = RecoveryOtp::openChallenge($owner);
        $code = RecoveryOtp::issue($challenge, PasswordRecoveryOtp::PURPOSE_EMAIL_VERIFICATION, $email);

        try {
            Mail::to($email)->send(new RecoveryOtpMail($code, PasswordRecoveryOtp::PURPOSE_EMAIL_VERIFICATION));
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
