<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Rules\PlatformEmailDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بريد مدير المنصة على نطاق الشركة وحده.
 *
 * مفتاح المنصّة كلّها لا يُعلَّق على بريدٍ شخصيّ عند مزوّدٍ خارجي: حسابٌ
 * يُسترجَع برقم هاتفٍ قديم، أو يُغلقه المزوّد، ويُفتح به كلُّ متجر. والنطاق
 * ملكُ الشركة — يُغلق بريد من ترك عمله، وهو ما لا يُفعل بحسابٍ على gmail.
 *
 * والحدّ على مدراء المنصة وحدهم: التاجر يدخل ببريده هو.
 */
class PlatformEmailDomainTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = User::create([
            'name' => 'مدير المنصة',
            'email' => 'admin@abaadapp.om',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'status' => 'نشط',
        ]);
    }

    public function test_a_platform_admin_cannot_move_to_an_outside_address(): void
    {
        $this->actingAs($this->platform)
            ->put(route('profile.update'), [
                'name' => 'مدير المنصة',
                'email' => 'someone@gmail.com',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame('admin@abaadapp.om', $this->platform->fresh()->email);
    }

    public function test_an_admin_already_outside_the_domain_can_still_save_their_profile(): void
    {
        /*
         * حسابٌ أُنشئ قبل القاعدة ببريدٍ خارجي — قرارُ المالك. ولو فُرض النطاق
         * على كل حفظ لصار عاجزًا عن تغيير اسمه أو كلمة مروره: يضغط «حفظ»
         * فيُرفض بسبب حقلٍ لم يلمسه. القاعدة تمنع الانتقال، لا تحبس القائم.
         */
        $legacy = User::create([
            'name' => 'مدير قديم',
            'email' => 'owner@gmail.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'status' => 'نشط',
        ]);

        $this->actingAs($legacy)
            ->put(route('profile.update'), [
                'name' => 'اسم جديد',
                'email' => 'owner@gmail.com',
                'phone' => '90000000',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('اسم جديد', $legacy->fresh()->name);
    }

    public function test_but_they_still_cannot_move_to_another_outside_address(): void
    {
        $legacy = User::create([
            'name' => 'مدير قديم',
            'email' => 'owner2@gmail.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'status' => 'نشط',
        ]);

        $this->actingAs($legacy)
            ->put(route('profile.update'), [
                'name' => 'مدير قديم',
                'email' => 'elsewhere@gmail.com',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame('owner2@gmail.com', $legacy->fresh()->email);
    }

    public function test_a_platform_admin_can_move_within_the_domain(): void
    {
        $this->actingAs($this->platform)
            ->put(route('profile.update'), [
                'name' => 'مدير المنصة',
                'email' => 'oday@abaadapp.om',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('oday@abaadapp.om', $this->platform->fresh()->email);
    }

    public function test_a_merchant_keeps_any_address_they_like(): void
    {
        // النطاق حدٌّ على مدراء المنصة؛ فرضُه على التجّار يقفل النظام عليهم
        $business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $merchant = User::create([
            'business_id' => $business->id,
            'name' => 'تاجر',
            'email' => 'merchant@abaad.om',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);

        $this->actingAs($merchant)
            ->put(route('profile.update'), [
                'name' => 'تاجر',
                'email' => 'shop@gmail.com',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('shop@gmail.com', $merchant->fresh()->email);
    }

    public function test_a_taken_address_says_so_instead_of_seeming_to_do_nothing(): void
    {
        /*
         * هذا ما أوقع المستخدم: وضع بريدًا يملكه حسابٌ آخر فرُفض بصمت — رسالةٌ
         * افتراضية لا تقول أين ذهب العنوان، فبدا أن الزرّ لا يفعل شيئًا.
         */
        $business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        User::create([
            'business_id' => $business->id,
            'name' => 'تاجر',
            'email' => 'taken@abaadapp.om',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);

        $this->actingAs($this->platform)
            ->put(route('profile.update'), [
                'name' => 'مدير المنصة',
                'email' => 'taken@abaadapp.om',
            ])
            ->assertSessionHasErrors(['email' => 'هذا البريد مستعمل في حساب آخر — اختر غيره.']);
    }

    public function test_creating_a_platform_admin_from_the_users_screen_obeys_the_domain(): void
    {
        $this->actingAs($this->platform)
            ->post(route('super-admin.users.store'), [
                'name' => 'مدير ثانٍ',
                'email' => 'second@gmail.com',
                'role' => 'super_admin',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'second@gmail.com']);
    }

    public function test_creating_a_merchant_from_the_users_screen_does_not(): void
    {
        $this->actingAs($this->platform)
            ->post(route('super-admin.users.store'), [
                'name' => 'تاجر جديد',
                'email' => 'newshop@gmail.com',
                'role' => 'admin',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'newshop@gmail.com']);
    }

    public function test_editing_a_user_changes_their_password_on_confirm(): void
    {
        /*
         * لم يكن للكلمة حقلٌ في هذه الشاشة ولا سطرٌ في التحقّق: يفتح المشغّل
         * «تعديل بيانات المستخدم» ليصلح حساب من فقد كلمته فلا يجد إلا الاسم
         * والبريد والدور، ويحفظ فلا يتغيّر شيء — ولا مخرج إلا فتح القاعدة.
         */
        $business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $merchant = User::create([
            'business_id' => $business->id,
            'name' => 'تاجر',
            'email' => 'shop@abaadapp.om',
            'password' => bcrypt('old-password'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);

        $this->actingAs($this->platform)
            ->put(route('super-admin.users.update', $merchant->id), [
                'name' => 'تاجر',
                'email' => 'shop@abaadapp.om',
                'role' => 'admin',
                'password' => 'brand-new-pass',
            ])
            ->assertSessionHasNoErrors();

        $merchant->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('brand-new-pass', $merchant->password));
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('old-password', $merchant->password));
    }

    public function test_an_empty_password_field_leaves_the_password_alone(): void
    {
        // وإلا صار كل تعديلٍ لدورٍ أو هاتف يُخرج صاحب الحساب منه
        $business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $merchant = User::create([
            'business_id' => $business->id,
            'name' => 'تاجر',
            'email' => 'shop2@abaadapp.om',
            'password' => bcrypt('keep-me'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);

        $this->actingAs($this->platform)
            ->put(route('super-admin.users.update', $merchant->id), [
                'name' => 'اسم آخر',
                'email' => 'shop2@abaadapp.om',
                'role' => 'admin',
                'password' => '',
            ])
            ->assertSessionHasNoErrors();

        $merchant->refresh();
        $this->assertSame('اسم آخر', $merchant->name);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('keep-me', $merchant->password));
    }

    public function test_a_short_password_is_refused_rather_than_silently_saved(): void
    {
        $business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $merchant = User::create([
            'business_id' => $business->id,
            'name' => 'تاجر',
            'email' => 'shop3@abaadapp.om',
            'password' => bcrypt('keep-me'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);

        $this->actingAs($this->platform)
            ->put(route('super-admin.users.update', $merchant->id), [
                'name' => 'تاجر',
                'email' => 'shop3@abaadapp.om',
                'role' => 'admin',
                'password' => '123',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('keep-me', $merchant->fresh()->password));
    }

    public function test_the_rule_reads_the_address_not_a_substring_of_it(): void
    {
        // عنوانٌ يحمل النطاق في وسطه لا في آخره — ‏abaadapp.om.attacker.com
        $this->assertFalse(PlatformEmailDomain::matches('x@abaadapp.om.attacker.com'));
        $this->assertFalse(PlatformEmailDomain::matches('x@notabaadapp.om'));
        $this->assertFalse(PlatformEmailDomain::matches(null));
        $this->assertTrue(PlatformEmailDomain::matches('X@ABAADAPP.OM'));
        $this->assertTrue(PlatformEmailDomain::matches('  x@abaadapp.om  '));
    }
}
