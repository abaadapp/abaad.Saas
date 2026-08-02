<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تبويب «بيانات النشاط» في الإعدادات.
 *
 * الحقول الأربعة تُقرأ من جدول businesses وكانت تُكتب كصفوف settings —
 * فتختفي عند إعادة التحميل بينما يقول التنبيه «تم الحفظ بنجاح». عطل صامت
 * أسوأ من الظاهر: التاجر يمضي ظانًّا أن بياناته محفوظة.
 */
class BusinessProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'متجري', 'status' => 'نشط',
            'phone' => '', 'email' => 'old@abaad.om', 'address' => '',
        ]);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك',
            'email' => 'owner@abaad.om', 'password' => bcrypt('password'),
            'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function save(array $data)
    {
        return $this->actingAs($this->owner)->post(route('admin.settings.update'), $data);
    }

    public function test_the_four_profile_fields_land_in_the_business_row(): void
    {
        $this->save([
            'shop_name' => 'متجر الاختبار',
            'phone' => '+96899887766',
            'email' => 'new@abaad.om',
            'address' => 'مسقط، الخوير',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('businesses', [
            'id' => $this->business->id,
            'name' => 'متجر الاختبار',
            'phone' => '+96899887766',
            'email' => 'new@abaad.om',
            'address' => 'مسقط، الخوير',
        ]);
    }

    public function test_they_are_not_written_as_settings_rows_that_nothing_reads(): void
    {
        $this->save(['shop_name' => 'متجر الاختبار', 'phone' => '+96899887766']);

        foreach (['shop_name', 'phone', 'email', 'address'] as $key) {
            $this->assertDatabaseMissing('settings', [
                'business_id' => $this->business->id,
                'key' => $key,
            ]);
        }
    }

    public function test_the_form_shows_back_what_was_saved(): void
    {
        $this->save(['shop_name' => 'متجر الاختبار', 'phone' => '+96899887766']);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.settings.index'))->viewData('page')['props'];

        $this->assertSame('متجر الاختبار', $props['business']['name']);
        $this->assertSame('+96899887766', $props['business']['phone']);
    }

    public function test_the_new_name_reaches_the_header_too(): void
    {
        $this->save(['shop_name' => 'متجر الاختبار']);

        $this->actingAs($this->owner);
        $this->assertSame('متجر الاختبار', Demo::businessName());
    }

    public function test_a_stale_business_name_setting_no_longer_shadows_the_record(): void
    {
        // صفّ قديم من بذرة سابقة: كان يسبق جدول businesses فيحجب أي تعديل
        Setting::create([
            'business_id' => $this->business->id,
            'key' => 'business_name', 'value' => 'اسم قديم عالق',
        ]);

        $this->save(['shop_name' => 'متجر الاختبار']);

        $this->actingAs($this->owner);
        $this->assertSame('متجر الاختبار', Demo::businessName());
    }

    public function test_other_settings_still_save_as_settings(): void
    {
        $this->save(['shop_name' => 'متجري', 'vat_rate' => '7', 'inv_prefix' => 'FT-'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', [
            'business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '7',
        ]);
        $this->assertDatabaseHas('settings', [
            'business_id' => $this->business->id, 'key' => 'inv_prefix', 'value' => 'FT-',
        ]);
    }

    public function test_an_empty_name_is_refused_instead_of_wiping_the_business(): void
    {
        $this->save(['shop_name' => ''])->assertSessionHasErrors('shop_name');

        $this->assertSame('متجري', $this->business->fresh()->name);
    }

    public function test_a_malformed_email_is_refused(): void
    {
        $this->save(['shop_name' => 'متجري', 'email' => 'ليس بريدًا'])
            ->assertSessionHasErrors('email');

        $this->assertSame('old@abaad.om', $this->business->fresh()->email);
    }

    public function test_saving_one_tab_does_not_blank_the_fields_of_another(): void
    {
        $this->save([
            'shop_name' => 'متجر الاختبار', 'phone' => '+96899887766',
            'email' => 'new@abaad.om', 'address' => 'مسقط',
        ]);

        // تبويب الضرائب يُرسل حقوله وحدها — لا يجوز أن يمسح بيانات النشاط
        $this->save(['vat_rate' => '9'])->assertSessionHasNoErrors();

        $fresh = $this->business->fresh();
        $this->assertSame('متجر الاختبار', $fresh->name);
        $this->assertSame('+96899887766', $fresh->phone);
        $this->assertSame('مسقط', $fresh->address);
    }
}
