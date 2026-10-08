<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صلاحيةُ `order.edit` تقول في «صلاحيات الموظفين» ما تشمله.
 *
 * فاتورةُ نقطة البيع وطلبُ الموقع كلاهما `Order` ويُصحَّحان من المسار نفسِه،
 * والاسمُ القديم («تصحيح فاتورة مكتملة») لم يقل ذلك. والتغييرُ عرضٌ وحده:
 * المفتاحُ ومن يملكه بدوره كما كانا.
 */
class TheInvoiceEditPermissionSaysWhatItCoversTest extends TestCase
{
    use RefreshDatabase;

    private const LABEL = 'تعديل فواتير المبيعات وطلبات الموقع';

    private const HINT = 'يسمح بتعديل كمية البنود أو وسيلة الدفع في نفس يوم البيع، سواء كانت الفاتورة من نقطة البيع أو الموقع الإلكتروني. وحيث فُتحت الميزة للنشاط: إضافة صنف أو استبداله في يوم البيع، وتعديل ملاحظة المنتج، وتحصيل المتبقّي.';

    public function test_the_key_is_named_for_both_doors_and_explained_without_changing_who_holds_it(): void
    {
        $this->assertSame(self::LABEL, Permissions::ACTIONS['order.edit']);
        $this->assertSame(self::HINT, Permissions::ACTION_HINTS['order.edit']);
        $this->assertSame(['admin', 'manager'], Permissions::ACTION_ROLES['order.edit']);
    }

    public function test_the_employee_screen_shows_the_name_and_its_explanation(): void
    {
        $this->app->setLocale('ar');
        $shop = Business::create(['name' => 'متجر', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => 'owner@shop.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->get(route('admin.employees.create'))->assertOk()
            ->assertInertia(fn ($p) => $p
                // والمفتاحُ فيه نقطة — فيُقرأ بالمجموعة لا بمسار النقاط
                ->where('actions', fn ($a) => collect($a)->get('order.edit') === self::LABEL)
                ->where('actionHints', fn ($h) => collect($h)->get('order.edit') === self::HINT));
    }
}
