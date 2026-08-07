<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عناوين العميل — القواعد التي لا تُرى في الواجهة:
 * افتراضي واحد لا أكثر، ولا عميل بعناوين بلا افتراضي، ولا وصول عبر
 * حدود المستأجرين (لا بالعميل ولا بترقيم العنوان في الرابط).
 */
class CustomerAddressTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Business $business): User
    {
        return User::create([
            'business_id' => $business->id,
            'name' => 'المالك',
            'email' => 'owner' . $business->id . '@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);
    }

    private function scenario(): array
    {
        $business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $customer = Customer::create(['business_id' => $business->id, 'name' => 'أحمد الشامسي']);

        return [$this->owner($business), $customer];
    }

    public function test_the_first_address_becomes_the_default_on_its_own(): void
    {
        [$owner, $customer] = $this->scenario();

        $this->actingAs($owner)
            ->post(route('admin.customers.addresses.save', $customer->id), [
                'label' => 'المنزل', 'city' => 'مسقط', 'area' => 'الخوير',
            ])->assertRedirect();

        $this->assertTrue($customer->addresses()->sole()->is_default);
    }

    public function test_only_one_address_can_be_the_default(): void
    {
        [$owner, $customer] = $this->scenario();
        $home = $customer->addresses()->create(['label' => 'المنزل', 'city' => 'مسقط', 'is_default' => true]);
        $work = $customer->addresses()->create(['label' => 'العمل', 'city' => 'مسقط']);

        $this->actingAs($owner)
            ->post(route('admin.customers.addresses.default', [$customer->id, $work->id]))
            ->assertRedirect();

        $this->assertFalse($home->refresh()->is_default);
        $this->assertTrue($work->refresh()->is_default);
        $this->assertSame(1, $customer->addresses()->where('is_default', true)->count());
    }

    public function test_deleting_the_default_hands_the_flag_to_the_oldest_survivor(): void
    {
        [$owner, $customer] = $this->scenario();
        $first = $customer->addresses()->create(['label' => 'المنزل', 'city' => 'مسقط']);
        $default = $customer->addresses()->create(['label' => 'العمل', 'city' => 'مسقط', 'is_default' => true]);

        $this->actingAs($owner)
            ->delete(route('admin.customers.addresses.delete', [$customer->id, $default->id]))
            ->assertRedirect();

        $this->assertTrue($first->refresh()->is_default, 'العميل لا يجوز أن يبقى بعناوين بلا افتراضي');
    }

    public function test_a_label_and_a_city_are_required(): void
    {
        [$owner, $customer] = $this->scenario();

        $this->actingAs($owner)
            ->post(route('admin.customers.addresses.save', $customer->id), ['label' => '', 'city' => ''])
            ->assertSessionHasErrors(['label', 'city']);

        $this->assertSame(0, $customer->addresses()->count());
    }

    public function test_an_owner_cannot_touch_another_businesss_customer(): void
    {
        [$owner] = $this->scenario();

        $other = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);
        $victim = Customer::create(['business_id' => $other->id, 'name' => 'عميل الغير']);
        $address = $victim->addresses()->create(['label' => 'المنزل', 'city' => 'صلالة', 'is_default' => true]);

        $this->actingAs($owner)
            ->post(route('admin.customers.addresses.save', $victim->id), ['label' => 'x', 'city' => 'y'])
            ->assertNotFound();

        $this->actingAs($owner)
            ->post(route('admin.customers.addresses.default', [$victim->id, $address->id]))
            ->assertNotFound();

        $this->actingAs($owner)
            ->delete(route('admin.customers.addresses.delete', [$victim->id, $address->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('customer_addresses', ['id' => $address->id]);
    }

    public function test_an_address_id_from_another_customer_cannot_be_smuggled_in(): void
    {
        [$owner, $mine] = $this->scenario();

        $other = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);
        $victim = Customer::create(['business_id' => $other->id, 'name' => 'عميل الغير']);
        $stranger = $victim->addresses()->create(['label' => 'المنزل', 'city' => 'صلالة']);

        // عميلي أنا في الرابط، لكن رقم العنوان يخصّ عميل غيري
        $this->actingAs($owner)
            ->delete(route('admin.customers.addresses.delete', [$mine->id, $stranger->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('customer_addresses', ['id' => $stranger->id]);
    }

    /**
     * الحذف الناعم يُبقي العناوين — والاستعادة تعيدها معه.
     *
     * كان الاختبار يفحص العكس: أن الحذف يمحو العناوين بالتسلسل. وذلك صحيحٌ
     * حين يكون الحذف نهائيًّا، لكن العميل صار يُخفى لا يُمحى — وعميلٌ يعود
     * بلا عناوينه يعود ناقصًا، فيُعاد إدخالها يدويًّا وقد ضاع أصلها.
     */
    public function test_hiding_a_customer_keeps_their_addresses_for_the_return(): void
    {
        [, $customer] = $this->scenario();
        $customer->addresses()->create(['label' => 'المنزل', 'city' => 'مسقط']);

        $customer->delete();
        $this->assertSame(1, CustomerAddress::count());

        $customer->restore();
        $this->assertSame(1, $customer->fresh()->addresses()->count());
    }

    public function test_wiping_a_customer_still_takes_their_addresses(): void
    {
        [, $customer] = $this->scenario();
        $customer->addresses()->create(['label' => 'المنزل', 'city' => 'مسقط']);

        // المحو النهائي (مسح المتجر قبل استعادة نسخة) يتسلسل كما كان
        $customer->forceDelete();

        $this->assertSame(0, CustomerAddress::count());
    }
}
