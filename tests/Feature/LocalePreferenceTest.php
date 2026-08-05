<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لغة الواجهة تفضيل شخصي.
 *
 * كانت تُحفظ في settings[business_id,'locale'] المشترك، فالكاشير الذي
 * يبدّل نقطة البيع إلى الإنجليزية يقلب لوحة المالك إنجليزية أيضًا، والمالك
 * حين يعيدها عربية يسلب الكاشير إنجليزيته. متجر فيه موظفون لا يقرأون
 * العربية ومالك لا يقرأ الإنجليزية لا يعمل بإعداد واحد.
 *
 * الأولوية: الجلسة ← تفضيل المستخدم ← إعداد النشاط ← العربية.
 */
class LocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
    }

    private function user(string $role, string $email): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => $role, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    /**
     * صفحة الطلبات لا شاشة البيع: شاشة البيع تسأل صاحب النشاط «من على
     * الصندوق؟» متى وُجد موظف، فتُرجع تحويلًا لا صفحة. اللغة مشتركة بين
     * صفحات نقطة البيع كلها، فأيّ صفحة منها تكفي لفحصها.
     */
    private function localeOf(User $user): string
    {
        return $this->actingAs($user)->get(route('pos.orders'))->viewData('page')['props']['locale'];
    }

    public function test_arabic_is_the_default_when_nobody_chose(): void
    {
        $this->assertSame('ar', $this->localeOf($this->user('admin', 'a@test.local')));
    }

    public function test_a_cashiers_choice_does_not_change_the_owners_language(): void
    {
        $owner = $this->user('admin', 'owner@test.local');
        $cashier = $this->user('cashier', 'cashier@test.local');

        $this->actingAs($cashier)
            ->post(route('pos.language.update'), ['locale' => 'en'])
            ->assertRedirect();

        $this->assertSame('en', $cashier->refresh()->locale);
        $this->assertNull($owner->refresh()->locale, 'المالك لم يختر شيئًا فلا يُكتب عنه');

        // متصفّح المالك جلسة أخرى — والخروج يستدعي session()->invalidate()
        // فلا تتسرّب لغة الكاشير حتى على الجهاز نفسه
        $this->flushSession();

        $this->assertSame('ar', $this->localeOf($owner), 'لغة المالك يجب ألّا تتأثر');
    }

    public function test_the_choice_survives_logging_out_and_back_in(): void
    {
        $cashier = $this->user('cashier', 'cashier@test.local');

        $this->actingAs($cashier)->post(route('pos.language.update'), ['locale' => 'en']);
        // جلسة جديدة تمامًا — لا أثر لجلسة التبديل
        $this->flushSession();

        $this->assertSame('en', $this->localeOf($cashier->refresh()));
    }

    public function test_it_no_longer_writes_a_shared_business_setting(): void
    {
        $cashier = $this->user('cashier', 'cashier@test.local');

        $this->actingAs($cashier)->post(route('pos.language.update'), ['locale' => 'en']);

        $this->assertDatabaseMissing('settings', [
            'business_id' => $this->business->id,
            'key' => 'locale',
        ]);
    }

    public function test_the_business_setting_still_acts_as_the_default(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'locale', 'value' => 'en']);
        $newcomer = $this->user('cashier', 'new@test.local');

        $this->assertSame('en', $this->localeOf($newcomer), 'من لم يختر يرث افتراضي المتجر');
    }

    public function test_a_personal_choice_beats_the_business_default(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'locale', 'value' => 'en']);
        $owner = $this->user('admin', 'owner@test.local');
        $owner->update(['locale' => 'ar']);

        $this->assertSame('ar', $this->localeOf($owner));
    }

    public function test_an_unsupported_locale_is_refused(): void
    {
        $cashier = $this->user('cashier', 'cashier@test.local');

        $this->actingAs($cashier)
            ->post(route('pos.language.update'), ['locale' => 'fr'])
            ->assertSessionHasErrors('locale');

        $this->assertNull($cashier->refresh()->locale);
    }

    public function test_a_guest_can_switch_the_login_page_without_an_account(): void
    {
        $this->post(route('language.guest'), ['locale' => 'en'])->assertRedirect();
        $this->assertSame('en', session('locale'));
    }
}
