<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\MerchantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * الموظّفُ يدخل بالكلمة التي أُعطيها — من الباب الذي يدخل منه فعلًا.
 *
 * ═══ لمَ يُكتب هذا الملفّ ═══
 *
 * السلسلةُ من «أضف موظفًا» إلى «تسجيل الدخول» تمرّ بأربعة مواضع يستطيع كلُّ
 * واحدٍ منها أن يكسرها صامتًا:
 *
 *   ١ · بناءُ البريد من اسم الدخول (`MerchantAccount::email`) — وهو ما
 *       يُخزَّن، وما يُكتب في شاشة الدخول لاحقًا.
 *   ٢ · `Hash::make` في المتحكّم فوق عمودٍ عليه `'password' => 'hashed'`
 *       في `User::casts`. ولو ضاعف الصبُّ التجزئةَ لَحُفظ حسابٌ لا يُفتح
 *       أبدًا — ولا شيء في الشاشة يقول ذلك.
 *   ٣ · «إعادة تعيين كلمة المرور» — تكتب واحدةً وتعرض أخرى فينسخ التاجرُ
 *       ما لا يفتح.
 *   ٤ · `Auth::attempt` في `LoginController` — ولا يُتجاوز ولا يُضعَّف.
 *
 * وكلُّ اختباراتنا قبله كانت تدخل بـ`actingAs` — أي تقفز فوق البابِ كلِّه.
 * فهذا يفتحه بالطلب: `POST /login` بالبريد والكلمة، كما يفعل الموظّف.
 *
 * والقياسُ لكلّ الشركات لا لشركةٍ بعينها: المسارُ واحدٌ يخدم الجميع.
 */
class AnEmployeeEntersWithThePasswordHeWasGivenTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaadapp.om',
            'password' => 'password12345', 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** يضيف موظّفًا من الشاشة نفسِها — ويُرجع ما عُرض من كلمةٍ مولَّدة إن وُلِّدت */
    private function hire(string $username, ?string $password = null): ?string
    {
        $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), [
                'name' => 'موظّف '.$username,
                'job_title' => 'كاشير',
                'branch' => 'الرئيسي',
                'branches' => [],
                'login_username' => $username,
                'password' => $password ?? '',
                'manual_permissions' => false,
                'permissions' => [],
            ])
            ->assertSessionHasNoErrors();

        $issued = session('issued_password');
        $this->flushSession();
        Auth::logout();

        return $issued;
    }

    /** يطرق بابَ الدخول كما يطرقه الموظّف — بالبريد الكامل وكلمته */
    private function knock(string $email, string $password)
    {
        return $this->post(route('login.attempt'), ['email' => $email, 'password' => $password]);
    }

    /* ══════════ ١ · الكلمةُ التي كتبها المدير تفتح الباب ══════════ */

    public function test_an_employee_signs_in_with_the_password_his_manager_typed(): void
    {
        $this->hire('salem', 'Passw0rd123');

        $this->knock(MerchantAccount::email('salem'), 'Passw0rd123')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertAuthenticated();
        $this->assertSame(MerchantAccount::email('salem'), Auth::user()->email);
    }

    /**
     * ولا تُضاعَف التجزئة.
     *
     * المتحكّم يكتب `Hash::make($password)` والعمودُ عليه صبُّ `hashed`.
     * فلو صبَّ الصبُّ فوق المصبوب لَصار في القاعدة تجزئةُ تجزئةٍ — والكلمةُ
     * التي أُعطيها الموظّفُ لا تفتح شيئًا أبدًا.
     */
    public function test_and_the_stored_hash_answers_the_plain_password_once(): void
    {
        $this->hire('rashid', 'Passw0rd123');

        $stored = (string) User::where('email', MerchantAccount::email('rashid'))->value('password');

        $this->assertTrue(Hash::check('Passw0rd123', $stored), 'التجزئةُ لا تُجيب الكلمةَ — ضُوعفت في الطريق');
        $this->assertFalse(Hash::check(Hash::make('Passw0rd123'), $stored), 'التجزئةُ تُجيب تجزئةً — صُبّت مرّتين');
    }

    /* ══════════ ٢ · والمولَّدةُ المعروضةُ هي المحفوظة ══════════ */

    public function test_the_generated_password_shown_once_is_the_one_that_opens(): void
    {
        $issued = $this->hire('nasser');

        $this->assertNotNull($issued, 'تُركت الكلمةُ فارغةً ولم تُعرض مولَّدة');

        $this->knock(MerchantAccount::email('nasser'), $issued)
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    /* ══════════ ٣ · وإعادةُ التعيين تعطي ما تكتب ══════════ */

    public function test_a_reset_password_opens_the_door_and_the_old_one_no_longer_does(): void
    {
        $this->hire('fahad', 'Passw0rd123');
        $employee = User::where('email', MerchantAccount::email('fahad'))->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('admin.employees.resetPassword', $employee->id))
            ->assertSessionHasNoErrors();

        $fresh = session('issued_password');
        $this->flushSession();
        Auth::logout();

        $this->assertNotNull($fresh, 'أُعيد التعيينُ ولم تُعرض كلمةٌ تُنسخ');

        $this->knock(MerchantAccount::email('fahad'), $fresh)->assertSessionHasNoErrors();
        $this->assertAuthenticated();

        Auth::logout();
        $this->flushSession();

        $this->knock(MerchantAccount::email('fahad'), 'Passw0rd123')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /* ══════════ ٤ · وما يُكتب في خانة البريد يُقاس كما يُكتب ══════════ */

    /** حرفٌ زائدٌ أو ناقصٌ في العنوان يُردّ — وهو أكثرُ ما يقع فعلًا */
    public function test_a_misspelled_address_is_refused_and_the_account_stays_untouched(): void
    {
        $this->hire('3ady', 'Passw0rd123');

        $this->knock('3day@abaadapp.om', 'Passw0rd123')->assertSessionHasErrors('email');
        $this->assertGuest();

        // والحسابُ نفسُه سليمٌ يُفتح بعنوانه
        $this->knock('3ady@abaadapp.om', 'Passw0rd123')->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /** وحرفٌ كبيرٌ في العنوان لا يمنع صاحبَه: العنوانُ يُخزَّن صغيرًا ويُقاس كذلك */
    public function test_and_capital_letters_in_the_address_do_not_lock_him_out(): void
    {
        $this->hire('huda', 'Passw0rd123');

        $this->knock('Huda@Abaadapp.om', 'Passw0rd123')->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    /**
     * وعنوانٌ خُزّن بحرفٍ كبيرٍ يبقى يُفتح كما هو.
     *
     * فالمحاولةُ الثانيةُ إضافةٌ لا استبدال: لو صُغّر العنوانُ دائمًا وأُهمل
     * ما كُتب لانقلب الإصلاحُ قفلًا على أصحاب البُرد الخارجيّة — يكتبها
     * صاحبُها بيده كما سجّلها، فلا تُطابق شيئًا.
     */
    public function test_but_an_address_stored_with_capitals_still_opens_as_stored(): void
    {
        User::create([
            'business_id' => $this->shop->id, 'name' => 'بريدٌ خارجيّ', 'email' => 'Mixed.Case@Example.com',
            'password' => 'Passw0rd123', 'role' => 'cashier', 'job_title' => 'كاشير', 'status' => 'نشط',
        ]);

        $this->knock('Mixed.Case@Example.com', 'Passw0rd123')->assertSessionHasNoErrors();

        $this->assertAuthenticated();
        $this->assertSame('Mixed.Case@Example.com', Auth::user()->email);
    }

    /* ══════════ ٥ · ولا يدخل موظّفُ متجرٍ إلى متجرٍ آخر ══════════ */

    public function test_the_door_belongs_to_the_shop_that_hired_him(): void
    {
        $this->hire('omar', 'Passw0rd123');

        $this->knock(MerchantAccount::email('omar'), 'Passw0rd123');

        $this->assertSame($this->shop->id, (int) Auth::user()->business_id);
    }
}
