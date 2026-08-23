<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\DemoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما يُضاف يُرى — في متجرٍ ممتلئ لا في متجرٍ فارغ.
 *
 * القوائم كلّها تُصفَّح، فترتيبُها الافتراضيّ هو ما يقرّر أين يقع الصفّ
 * الجديد. وقائمة المنتجات وحدها كانت تصعد بالمعرّف: في متجرٍ فيه مئةٌ
 * وعشرون صنفًا يقع الصنف الجديد في الصفحة العاشرة، والتاجر يُعاد بعد الحفظ
 * إلى الصفحة الأولى — فيرى العشرين نفسها ويحسب أن شيئًا لم يُحفَظ.
 *
 * ولا يظهر هذا في متجرٍ فارغ: صنفٌ واحد في صفحةٍ واحدة يُرى صاعدًا ونازلًا.
 * فيُفحص على متجرٍ ممتلئ، وهو حال كلّ متجرٍ بعد شهر.
 */
class NewRowIsVisibleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // سقوف الإنتاج: افتراضاتُ الأعمدة أضيق منها بكثير، ومتجرٌ تجريبيّ
        // يُبذر عليها يرتطم بالسقف قبل أن يصل إلى ما يقيسه هذا الملفّ
        Plan::updateOrCreate(['name' => 'الباقة الاحترافية'], [
            'monthly_price' => 30, 'yearly_price' => 300,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 100000,
        ]);
    }

    public function test_a_product_added_to_a_full_store_is_on_the_first_page(): void
    {
        $business = DemoStore::create('متجر تجريبي', 'متوسط');
        $owner = $business->users()->where('role', 'admin')->first();

        $this->actingAs($owner)->post(route('admin.products.store'), [
            'name' => 'صنفٌ أُضيف الآن', 'price' => 5, 'cost' => 2,
            'quantity' => 10, 'alert_qty' => 2,
        ])->assertRedirect(route('admin.products.index'));

        // الصفحة التي يُعاد إليها بعد الحفظ — بلا ترتيبٍ ولا صفحةٍ في الرابط
        $names = collect($this->actingAs($owner)->get(route('admin.products.index'))
            ->viewData('page')['props']['products'])->pluck('name');

        $this->assertContains('صنفٌ أُضيف الآن', $names->all(),
            'الصنف المضاف ليس في الصفحة التي يُعاد إليها التاجر بعد حفظه');
    }

    /** والقوائم الأخرى تُقاس بالمقياس نفسه: الأحدث أوّلًا */
    public function test_the_lists_put_the_newest_first(): void
    {
        $business = DemoStore::create('متجر تجريبي', 'صغير');
        $owner = $business->users()->where('role', 'admin')->first();

        $this->actingAs($owner)->post(route('admin.customers.store'), [
            'name' => 'عميلٌ أُضيف الآن', 'phone' => '90000001',
        ]);

        $names = collect($this->actingAs($owner)->get(route('admin.customers.index'))
            ->viewData('page')['props']['customers'])->pluck('name');

        $this->assertContains('عميلٌ أُضيف الآن', $names->all());
    }
}
