<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * وجهة الحفظ تفترق بين الإضافة والتعديل.
 *
 * الإضافة عملٌ ينتهي: يُخرَج منها إلى القائمة حيث يظهر المنتج الجديد صفًّا
 * يُرى، فالإشعار يقول «تمّ» والعين تصدّقه.
 *
 * والتعديل عملٌ يستمرّ: من يصحّح سعرًا يريد أن يرى أنه ثبت في مكانه، وغالبًا
 * يتبعه بتعديل الكمية أو الصورة في القسم المجاور — فالقذف إلى القائمة بعد كل
 * حقلٍ رحلةُ عودةٍ كاملة.
 */
class ProductFormStaysTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->actingAs(User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 'salem@abaad.om',
            'role' => 'admin', 'job_title' => 'مدير', 'status' => 'نشط', 'password' => 'x',
        ]));
    }

    private function payload(array $over = []): array
    {
        return array_merge(['name' => 'باقة ورد', 'price' => '5.500'], $over);
    }

    public function test_adding_a_product_leaves_for_the_list(): void
    {
        // الإضافة تنتهي، والقائمة شاهدها: الإشعار يقول «تمّ» والعين ترى الصفّ
        $this->post(route('admin.products.store'), $this->payload())
            ->assertRedirect(route('admin.products.index'));

        $this->assertDatabaseHas('products', ['name' => 'باقة ورد']);
    }

    public function test_editing_a_product_returns_to_its_own_edit_page(): void
    {
        $user = auth()->user();
        $product = Product::create([
            'business_id' => $user->business_id, 'name' => 'شمعة', 'price' => 2,
            'cost' => 1, 'quantity' => 5, 'alert_qty' => 2, 'tax' => 0, 'discount' => 0,
            'sku' => 'SKU-1', 'barcode' => 'B-1', 'active' => true,
        ]);

        // لا إلى قائمةٍ عامّة: من يصحّح سعرًا يريد أن يرى أنه ثبت في مكانه
        $this->put(route('admin.products.update', $product->id), $this->payload(['name' => 'شمعة معطّرة']))
            ->assertRedirect(route('admin.products.edit', $product->id));

        $this->assertSame('شمعة معطّرة', $product->fresh()->name);
    }

    public function test_the_success_notice_still_arrives(): void
    {
        /*
         * البقاء في الصفحة يجعل الإشعار الدليل الوحيد على أن الحفظ تمّ —
         * لا انتقال يشهد له. فلو سقط، بقي المستخدم أمام صفحةٍ لا تقول شيئًا.
         */
        $this->post(route('admin.products.store'), $this->payload())
            ->assertSessionHas('toast');
    }

    public function test_a_rejected_product_stays_on_the_form(): void
    {
        /*
         * النجاح وحده يُخرج. أمّا الخطأ فيرجع بالمدخلات إلى النموذج، والواجهة
         * تقفز إلى قسمه — ولولا ذلك لخرج المستخدم إلى القائمة ولم يجد منتجه
         * ولا سببًا مكتوبًا.
         */
        $this->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->payload(['name' => '']))
            ->assertRedirect(route('admin.products.create'))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('products', 0);
    }
}
