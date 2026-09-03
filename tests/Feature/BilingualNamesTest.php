<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اسمٌ يُكتب مرّةً ويُقرأ بلغتين — للعميل وللمورّد.
 *
 * الشاشة تُقرأ بلغةٍ يختارها من يقف أمامها، والاسم يُكتب بلغةٍ يختارها من
 * سجّله. وكان الطرفان يفترقان: كاشيرٌ لا يقرأ العربية يرى قائمةً كلّها
 * حروفٌ لا يفكّها.
 */
class BilingualNamesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'email' => 'n@test.local', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@n.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* ------------------------------- العملاء ------------------------------- */

    public function test_a_latin_customer_name_is_transliterated_and_the_original_kept(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customers.store'), [
            'name' => 'Mohammed Salem', 'phone' => '91110001',
        ])->assertSessionHasNoErrors();

        $customer = Customer::firstOrFail();

        $this->assertSame('Mohammed Salem', $customer->name_en);
        $this->assertNotSame('Mohammed Salem', $customer->name, 'لم يُنقل الاسم إلى العربية');
    }

    /**
     * والمكتوب بيدٍ يعلو على النقل الآليّ.
     *
     * كان يُكتب فوقه بلا استئذان — فلا سبيل إلى تصحيح «Muhammed» إلى
     * «Mohammed» أبدًا. والنقل تخمينٌ يصيب ويخطئ، ومن كتب اسمه بنفسه
     * أعلمُ بكتابته.
     */
    public function test_a_hand_written_english_name_is_not_overwritten(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customers.store'), [
            'name' => 'Mohammed Salem', 'name_en' => 'Mo Salem', 'phone' => '91110002',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Mo Salem', Customer::firstOrFail()->name_en);
    }

    /**
     * واسمٌ عربيّ لا صورة لاتينية له تُخمَّن — فيُكتب بيدٍ أو يبقى فارغًا.
     */
    public function test_an_arabic_customer_can_be_given_an_english_name_by_hand(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customers.store'), [
            'name' => 'محمد سالم', 'name_en' => 'Mohammed Salem', 'phone' => '91110003',
        ])->assertSessionHasNoErrors();

        $customer = Customer::firstOrFail();

        $this->assertSame('محمد سالم', $customer->name);
        $this->assertSame('Mohammed Salem', $customer->name_en);
    }

    /** وبلا اسمٍ ثانٍ يبقى العربيّ وحده — لا فراغ */
    public function test_an_arabic_customer_without_a_second_name_keeps_only_one(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customers.store'), [
            'name' => 'محمد سالم', 'phone' => '91110004',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Customer::firstOrFail()->name_en);
    }

    /* ------------------------------ الموردون ------------------------------ */

    public function test_a_supplier_now_has_a_second_name_at_all(): void
    {
        $this->actingAs($this->owner)->post(route('admin.suppliers.store'), [
            'name' => 'محمد للورود', 'name_en' => 'Mohammed Flowers',
        ])->assertSessionHasNoErrors();

        $supplier = Supplier::firstOrFail();

        $this->assertSame('محمد للورود', $supplier->name);
        $this->assertSame('Mohammed Flowers', $supplier->name_en);
    }

    public function test_a_latin_supplier_name_is_transliterated_like_a_customer(): void
    {
        $this->actingAs($this->owner)->post(route('admin.suppliers.store'), [
            'name' => 'Salem Trading',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Salem Trading', Supplier::firstOrFail()->name_en);
    }

    public function test_a_supplier_second_name_can_be_edited_later(): void
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد قديم']);

        $this->actingAs($this->owner)->put(route('admin.suppliers.update', $supplier->id), [
            'name' => 'مورّد قديم', 'name_en' => 'Old Supplier',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Old Supplier', $supplier->fresh()->name_en);
    }

    /* -------------------------------- العرض ------------------------------- */

    public function test_the_displayed_name_follows_the_interface_language(): void
    {
        Supplier::create([
            'business_id' => $this->business->id, 'name' => 'محمد للورود', 'name_en' => 'Mohammed Flowers',
        ]);

        $this->actingAs($this->owner);

        app()->setLocale('ar');
        $this->assertSame('محمد للورود', Demo::suppliers()[0]['label']);

        app()->setLocale('en');
        $this->assertSame('Mohammed Flowers', Demo::suppliers()[0]['label']);
    }

    /** وبلا اسمٍ ثانٍ يُعرض الوحيد في اللغتين — لا فراغ في شاشةٍ إنجليزية */
    public function test_a_supplier_without_a_second_name_still_reads_in_english(): void
    {
        Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد بلا مقابل']);

        $this->actingAs($this->owner);
        app()->setLocale('en');

        $this->assertSame('مورّد بلا مقابل', Demo::suppliers()[0]['label']);
    }
}
