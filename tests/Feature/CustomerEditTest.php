<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تعديل العميل وحذفه — لم يكونا موجودين إطلاقًا.
 *
 * كان يُضاف ولا يُعدَّل ولا يُحذف: رقمٌ فيه خطأٌ واحد يبقى خطأً أبدًا. وهو
 * أشدّ ممّا يبدو — نقاط الولاء تتبع الهاتف، فالخطأ يعني عميلًا لا يجد نقاطه،
 * ولا سبيل إلى إصلاحه إلا بعميلٍ ثانٍ فيصير في القائمة اسمان لشخصٍ واحد.
 */
class CustomerEditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '90000001',
        ]);

        $this->actingAs($this->owner);
    }

    private function fields(array $over = []): array
    {
        return array_merge([
            'name' => 'سالم الحارثي', 'phone' => '90000002',
            'email' => 's@x.om', 'tax_number' => 'OM123', 'address' => 'مسقط',
        ], $over);
    }

    public function test_the_details_can_finally_be_corrected(): void
    {
        $this->put(route('admin.customers.update', $this->customer->id), $this->fields())
            ->assertSessionHasNoErrors();

        $this->customer->refresh();

        $this->assertSame('سالم الحارثي', $this->customer->name);
        $this->assertSame('90000002', $this->customer->phone);
        $this->assertSame('OM123', $this->customer->tax_number);
    }

    /** ولا يرفض حفظَ عميلٍ لم يُغيَّر رقمه — القيد يتجاوز صاحبه */
    public function test_saving_without_changing_the_phone_is_not_a_duplicate(): void
    {
        $this->put(route('admin.customers.update', $this->customer->id), $this->fields(['phone' => '90000001']))
            ->assertSessionHasNoErrors();
    }

    /** ويبقى الرقم فريدًا: نقاط الولاء تتبعه */
    public function test_it_still_refuses_a_phone_that_belongs_to_someone_else(): void
    {
        Customer::create(['business_id' => $this->business->id, 'name' => 'آخر', 'phone' => '90000009']);

        $this->put(route('admin.customers.update', $this->customer->id), $this->fields(['phone' => '90000009']))
            ->assertSessionHasErrors('phone');

        $this->assertSame('90000001', $this->customer->fresh()->phone);
    }

    /** ولا يُعدَّل عميل متجرٍ آخر */
    public function test_it_refuses_a_customer_of_another_business(): void
    {
        $other = Business::create(['name' => 'الجيران', 'status' => 'نشط']);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'جار', 'phone' => '99999999']);

        $this->put(route('admin.customers.update', $theirs->id), $this->fields())->assertNotFound();
        $this->delete(route('admin.customers.destroy', $theirs->id))->assertNotFound();
    }

    /* -------------------------------- الحذف -------------------------------- */

    public function test_deleting_hides_him_and_keeps_his_invoices(): void
    {
        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-000001',
            'customer_id' => $this->customer->id, 'customer_name' => 'سالم',
            'branch' => 'الرئيسي', 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'status' => 'مكتمل', 'subtotal' => 100, 'discount' => 0, 'tax' => 0,
            'total' => 100, 'is_held' => false, 'ordered_at' => now(),
        ]);

        $this->delete(route('admin.customers.destroy', $this->customer->id))
            ->assertRedirect(route('admin.customers.index'));

        $this->assertSoftDeleted('customers', ['id' => $this->customer->id]);
        $this->assertDatabaseHas('orders', ['number' => 'INV-000001', 'total' => 100]);

        $rows = $this->get(route('admin.customers.index'))
            ->assertOk()->viewData('page')['props']['customers'];

        $this->assertCount(0, $rows);
    }

    public function test_he_waits_in_the_trash_and_comes_back(): void
    {
        $this->delete(route('admin.customers.destroy', $this->customer->id));

        $trashed = $this->get(route('admin.settings.trash'))
            ->assertOk()->viewData('page')['props']['customers'];

        $this->assertCount(1, $trashed);
        $this->assertSame('سالم', $trashed[0]['name']);

        $this->post(route('admin.customers.restore', $this->customer->id))->assertRedirect();

        $this->assertNull($this->customer->fresh()->deleted_at);
    }

    public function test_purging_removes_him_for_good(): void
    {
        $this->delete(route('admin.customers.destroy', $this->customer->id));
        $this->delete(route('admin.customers.purge', $this->customer->id))->assertRedirect();

        $this->assertDatabaseMissing('customers', ['id' => $this->customer->id]);
    }
}
