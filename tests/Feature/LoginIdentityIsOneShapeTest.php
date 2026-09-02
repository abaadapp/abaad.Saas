<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\MerchantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عنوانُ الدخول شكلٌ واحد في النظام كلّه.
 *
 * كان يُكتب كاملًا بيد المدير في شاشة الموظفين، فتخرج عناوين على أشكال:
 * `.com` مكان `.om`، ومسافةٌ في الآخر، وحرفٌ عربيّ سقط من لوحةٍ لم تُبدَّل.
 * ثمّ لا يدخل الموظف ولا يعرف أحدٌ لماذا — والعنوان معرّف دخولٍ لا صندوق
 * بريد، فلا معنى لأن يختار كلٌّ نطاقه.
 *
 * وأشدُّ ما يُحرَس هنا الحسابُ القديم خارج النطاق: لا يُنقل كأثرٍ جانبيّ
 * لحفظ. من يصحّح رقم هاتف موظّفه لا يقصد أن يبدّل بريد دخوله.
 */
class LoginIdentityIsOneShapeTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط', 'job_title' => 'مدير',
        ]);
    }

    private function hire(array $over = [])
    {
        return $this->actingAs($this->owner)->post(route('admin.employees.store'), array_merge([
            'name' => 'أحمد', 'login_username' => 'ahmad', 'job_title' => 'كاشير',
        ], $over));
    }

    /* ------------------------------ الإضافة ------------------------------ */

    public function test_the_domain_is_appended_on_the_server(): void
    {
        $this->hire()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'ahmad@abaadapp.om']);
    }

    /** `Ahmad` و`ahmad` حسابان لا واحد — فالحروف تنزل صغيرةً قبل أن تُحفظ */
    public function test_the_username_is_stored_in_lower_case(): void
    {
        $this->hire(['login_username' => 'AhMaD'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'ahmad@abaadapp.om']);
    }

    public function test_a_username_carrying_its_own_domain_is_refused(): void
    {
        $this->hire(['login_username' => 'ahmad@gmail.com'])->assertSessionHasErrors('login_username');

        $this->assertDatabaseCount('users', 1);
    }

    /** العربية لا تُملى على لوحةٍ لاتينية ولا تُكتب على ورقة */
    public function test_an_arabic_username_is_refused(): void
    {
        $this->hire(['login_username' => 'أحمد'])->assertSessionHasErrors('login_username');
    }

    public function test_a_username_that_is_already_taken_is_refused(): void
    {
        $this->hire()->assertSessionHasNoErrors();
        $this->hire(['name' => 'أحمد الثاني'])->assertSessionHasErrors('login_username');

        $this->assertSame(1, User::where('email', 'ahmad@abaadapp.om')->count());
    }

    /** ولو كان الاسم محجوزًا عند الجار: البريد معرّفٌ على المنصّة كلّها */
    public function test_a_username_taken_in_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'الجار', 'status' => 'نشط']);
        User::create([
            'business_id' => $other->id, 'name' => 'أحمدهم', 'email' => 'ahmad@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط', 'job_title' => 'كاشير',
        ]);

        $this->hire()->assertSessionHasErrors('login_username');
    }

    public function test_a_hire_with_no_username_is_refused(): void
    {
        $this->hire(['login_username' => ''])->assertSessionHasErrors('login_username');

        $this->assertDatabaseCount('users', 1);
    }

    /* --------------------------- الحساب القديم --------------------------- */

    private function legacy(): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 'salem@gmail.com',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط', 'job_title' => 'كاشير',
        ]);
    }

    /**
     * حفظٌ لا يذكر اسمًا لا يمسّ العنوان.
     *
     * ولو نُقل معه لَتبدّل بريدُ دخوله وهو يصحّح رقم هاتفه، ثمّ وقف غدًا
     * أمام الشاشة بعنوانٍ لا يعرفه ولا سجلَّ يقول متى تبدّل.
     */
    public function test_an_old_address_outside_the_domain_is_not_moved_by_a_save(): void
    {
        $legacy = $this->legacy();

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $legacy->id), [
                'name' => 'سالم', 'job_title' => 'كاشير', 'phone' => '90000000',
            ])
            ->assertSessionHasNoErrors();

        $legacy->refresh();

        $this->assertSame('salem@gmail.com', $legacy->email);
        $this->assertSame('90000000', $legacy->phone);
    }

    /** ويُنقل حين يُطلب النقل صراحةً — والشاشة تقول إنّ البريد سيتبدّل */
    public function test_it_moves_when_the_move_is_asked_for(): void
    {
        $legacy = $this->legacy();

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $legacy->id), [
                'name' => 'سالم', 'job_title' => 'كاشير', 'login_username' => 'salem',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('salem@abaadapp.om', $legacy->fresh()->email);
    }

    /** والشاشة تعرف أنّه خارج النطاق — وإلّا عرضت له حقل اسمٍ يخفي عنوانه */
    public function test_the_edit_screen_says_whether_the_address_is_on_the_domain(): void
    {
        $legacy = $this->legacy();

        $this->actingAs($this->owner)
            ->get(route('admin.employees.edit', $legacy->id))
            ->assertInertia(fn ($page) => $page
                ->where('employee.on_domain', false)
                ->where('employee.username', 'salem'));

        $this->hire();
        $fresh = User::where('email', 'ahmad@abaadapp.om')->firstOrFail();

        $this->actingAs($this->owner)
            ->get(route('admin.employees.edit', $fresh->id))
            ->assertInertia(fn ($page) => $page->where('employee.on_domain', true));
    }

    /* ------------------------------ المصدر ------------------------------ */

    /** النطاق مكتوبٌ مرّةً على الخادم ومرّةً في الواجهة — ولا ثالثة */
    public function test_the_domain_is_written_in_one_place_on_each_side(): void
    {
        $this->assertSame('@abaadapp.om', MerchantAccount::DOMAIN);

        $hits = [];

        foreach ($this->tsx(resource_path('js')) as $file) {
            if (str_contains(file_get_contents($file), "'@abaadapp.om'")) {
                $hits[] = basename($file);
            }
        }

        $this->assertSame(['username-input.tsx'], array_values(array_unique($hits)));
    }

    /**
     * ولا حقلَ كلمة مرورٍ بلا عين.
     *
     * كانت العينُ في شاشة الدخول وحدها مكتوبةً بيدها، وبقيت سبعةُ حقولٍ
     * معمّاةً بالكامل — منها الذي يكتب فيه المدير كلمةَ موظّفه ويُمليها عليه
     * بالهاتف وهو لا يرى ما كتب. فصارت في مكانٍ واحد، وهذا يمنع عودتها
     * حقلًا حقلًا: حقلان يقولان الشيء نفسه يفترقان يومًا.
     */
    public function test_no_password_field_is_drawn_without_the_shared_eye(): void
    {
        $bare = [];

        foreach ($this->tsx(resource_path('js')) as $file) {
            if (str_contains(file_get_contents($file), 'type="password"')) {
                $bare[] = basename($file);
            }
        }

        $this->assertSame([], $bare, 'حقل كلمة مرور مرسوم بيده — استعمل PasswordInput');
    }

    /** @return array<int, string> */
    private function tsx(string $dir): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
