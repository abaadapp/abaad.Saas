<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * من يبدّل عنوانه لا يشتري محاولاتٍ جديدة.
 *
 * ═══ العطبُ الذي يحرسه هذا ═══
 *
 * حدّا الدخول كانا مربوطين بعنوان المُحاوِل:
 *
 *     'login:'.البريد.'|'.$request->ip()
 *     'login-hour:'.البريد.'|'.$request->ip()
 *
 * والعنوانُ في المفتاح صوابٌ في نصفه: بدونه يُقفل مكتبٌ كاملٌ خلف موجّهٍ
 * واحد لأنّ موظّفًا أخطأ ثلاثًا. لكنّه يعني أنّ **من يبدّل عنوانه يبدأ عدًّا
 * جديدًا**: مئةُ عنوانٍ رخيصٍ تشتري ألفي محاولةٍ في الساعة على الحساب
 * نفسِه، والنظامُ لا يرى إلّا عشرين من كلّ واحد.
 *
 * ولم يكن في النظام كلِّه حدٌّ واحدٌ لا يتبع العنوان — فُحصت مواضع
 * `RateLimiter` كلُّها.
 *
 * ═══ ولمَ هذا أهمُّ من تشديد كلمات المرور ═══
 *
 * تشديدُ الشرط يحمي ما يُكتب **بعده**. وهذا الحدُّ يحمي **كلَّ حسابٍ قائمٍ
 * الليلة** — بما فيها الكلماتُ الضعيفة المكتوبة من قبل، وبلا أن يغيّر أحدٌ
 * شيئًا.
 */
class ABruteForceCannotBuyMoreTriesWithMoreAddressesTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'RightPass123';

    private User $victim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');
        RateLimiter::clear('login-account:target@abaad.om');

        $business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);

        $this->victim = User::create([
            'business_id' => $business->id, 'name' => 'موظّف', 'email' => 'target@abaad.om',
            'password' => bcrypt(self::PASSWORD), 'role' => 'cashier', 'status' => 'نشط',
        ]);
    }

    /** محاولةٌ من عنوانٍ بعينه */
    private function tryFrom(string $ip, string $password = 'غلط')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('login.attempt'), ['email' => 'target@abaad.om', 'password' => $password]);
    }

    /**
     * يقصف الحسابَ من عناوينَ كثيرة — خمسًا من كلّ عنوان.
     *
     * وخمسٌ لا أكثر لأنّ الحدّ الدقيقيّ يقطع ما بعدها **قبل** أن تُحسب:
     * المحاولةُ المردودة لا تزيد عدًّا. فالقاصفُ الذكيّ يأخذ خمسًا ثمّ
     * ينتقل — وهو ما يفعله هذا.
     */
    private function stormFrom(int $addresses, int $each = 5): void
    {
        for ($a = 0; $a < $addresses; $a++) {
            for ($i = 0; $i < $each; $i++) {
                $this->tryFrom('10.0.0.'.$a);
            }
        }
    }

    /**
     * يملأ عدَّ الحساب بلا طلباتٍ حقيقية.
     *
     * والتراكمُ عبر العناوين يقيسه اختبارٌ واحدٌ بطلباتٍ حقيقية (أوّلُ ما
     * تحته). وما بعده يقيس **ما يقع بعد بلوغ الحدّ** — فلا يُعاد خمسون
     * طلبًا في كلّ واحدٍ منها.
     */
    private function budgetSpent(int $times = 50): void
    {
        for ($i = 0; $i < $times; $i++) {
            RateLimiter::hit('login-account:target@abaad.om', 3600);
        }
    }

    /* ═════════════ الحدُّ على الحساب لا على العنوان ═════════════ */

    public function test_changing_address_does_not_reset_the_account_budget(): void
    {
        // خمسةُ عناوين × عشرون = مئةُ محاولة، وكلُّ عنوانٍ يظنّ نفسه في حدّه
        // عشرةُ عناوين × خمس = خمسون، وكلُّ عنوانٍ يظنّ نفسه في حدّه
        $this->stormFrom(10);

        /*
         * ثمّ عنوانٌ حادي عشرَ لم يُحاول قطّ — ومع ذلك يُردّ **بحدّ المحاولات**.
         *
         * والنصُّ هو المقياس لا مجرّدُ وجود خطأ: «بيانات الدخول غير صحيحة»
         * تقع على الحقل نفسِه، فاختبارٌ يكتفي بوجود خطأٍ على `email` ينجح
         * بلا حدِّ الحساب أصلًا — وهو ما وقع لي في أوّل كتابةٍ لهذا الملفّ.
         */
        $this->tryFrom('10.0.0.99')->assertInvalid(['email' => 'محاولات كثيرة']);

        $this->assertGuest();
    }

    /** وحتّى بكلمة المرور الصحيحة: البابُ مقفلٌ حتّى تمضي الساعة */
    public function test_the_door_is_shut_even_for_the_right_password(): void
    {
        $this->budgetSpent();

        $this->tryFrom('10.0.0.99', self::PASSWORD);

        $this->assertGuest();
    }

    /** والنصُّ يقول إنّه حدُّ محاولاتٍ لا «بيانات غير صحيحة» */
    public function test_the_refusal_names_the_limit(): void
    {
        $this->budgetSpent();

        $this->tryFrom('10.0.0.99')->assertInvalid(['email' => 'محاولات كثيرة']);
    }

    /* ═════════════ ولا يقع على صاحبه ═════════════ */

    /**
     * ومن ينسى كلمتَه يُخطئ خمسًا أو عشرًا لا خمسين.
     *
     * والحدُّ الأضيق (خمسٌ في الدقيقة) يقع عليه أوّلًا وهو يمضي بدقيقة —
     * فحدُّ الحساب لا يُقفل عليه بابَه ساعةً.
     */
    public function test_a_forgetful_owner_does_not_trip_the_account_limit(): void
    {
        // خمسٌ هي كلُّ ما يبلغه من عنوانٍ واحد قبل أن يقطعه الحدُّ الدقيقيّ
        for ($i = 0; $i < 10; $i++) {
            $this->tryFrom('10.0.0.1');
        }

        $this->assertLessThan(50, RateLimiter::attempts('login-account:target@abaad.om'),
            'المنسيُّ كلمتَه استهلك حدَّ الحساب');
    }

    /** ومن دخل بكلمته يُمحى عدُّه — فلا يُقفل عليه بعد ساعةٍ من عملٍ صحيح */
    public function test_a_successful_login_clears_the_count(): void
    {
        $this->tryFrom('10.0.0.1');
        $this->tryFrom('10.0.0.1');

        $this->tryFrom('10.0.0.2', self::PASSWORD);

        $this->assertAuthenticated();
        $this->assertSame(0, RateLimiter::attempts('login-account:target@abaad.om'));
    }

    /** وحسابٌ آخر لا يُقفل بقصف جاره: الحدُّ على الحساب المستهدَف وحده */
    public function test_a_neighbour_account_is_untouched(): void
    {
        $other = User::create([
            'business_id' => $this->victim->business_id, 'name' => 'آخر', 'email' => 'other@abaad.om',
            'password' => bcrypt(self::PASSWORD), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->budgetSpent();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.77'])
            ->post(route('login.attempt'), ['email' => $other->email, 'password' => self::PASSWORD]);

        $this->assertAuthenticatedAs($other);
    }
}
