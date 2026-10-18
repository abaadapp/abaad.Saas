<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اسمٌ يُكتب مرّةً ويُقرأ بلغتين — للمورّد.
 *
 * الشاشة تُقرأ بلغةٍ يختارها من يقف أمامها، والاسم يُكتب بلغةٍ يختارها من
 * سجّله. وكان الطرفان يفترقان: كاشيرٌ لا يقرأ العربية يرى قائمةً كلّها
 * حروفٌ لا يفكّها.
 *
 * وكانت القاعدةُ للعميل أيضًا، ثمّ صار اسمُه يُحفظ ويُعرض كما كُتب — انظر
 * `ACustomersNameIsKeptAsWrittenTest`.
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

    public function test_a_latin_supplier_name_is_still_transliterated(): void
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
