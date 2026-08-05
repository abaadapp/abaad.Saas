<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حذف حقل «القسم الأب» من النموذج لا يعني إفراغ العمود.
 *
 * الحقل كان يُحفَظ في parent_id ولا تعرضه أي شاشة، فحُذف. لكن update كان
 * يقرأه بـ`?? null`: حقلٌ لا يصل يساوي عنده «اجعله فارغًا». فكان أول حفظ
 * لأي قسم قديم يقطع صلته بأبيه بلا أن يطلب أحدٌ ذلك — وحمايةُ الحذف نفسها
 * مبنيّة على وجود الأبناء، فتنكسر معه.
 */
class CategoryParentTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    public function test_editing_a_child_category_keeps_its_parent(): void
    {
        $parent = Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);
        $child = Category::create([
            'business_id' => $this->business->id, 'name' => 'ورد جوري', 'parent_id' => $parent->id,
        ]);

        $this->actingAs($this->owner)
            ->put(route('admin.categories.update', $child->id), ['name' => 'ورد جوري أحمر'])
            ->assertRedirect();

        $this->assertSame('ورد جوري أحمر', $child->fresh()->name);
        $this->assertSame($parent->id, $child->fresh()->parent_id, 'فقد القسم أباه عند حفظٍ لم يمسّه');
    }

    /** والحماية المبنيّة عليه تبقى قائمة بعد الحفظ */
    public function test_the_parent_still_cannot_be_deleted_while_it_has_children(): void
    {
        $parent = Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);
        $child = Category::create([
            'business_id' => $this->business->id, 'name' => 'ورد جوري', 'parent_id' => $parent->id,
        ]);

        $this->actingAs($this->owner)->put(route('admin.categories.update', $child->id), ['name' => 'ورد']);
        $this->actingAs($this->owner)->delete(route('admin.categories.destroy', $parent->id));

        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
    }

    /** ومن يرسل parent صراحةً (طلبٌ قديم أو API) ما زال يُغيّره */
    public function test_an_explicit_parent_is_still_honoured(): void
    {
        $a = Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);
        $b = Category::create(['business_id' => $this->business->id, 'name' => 'هدايا']);
        $child = Category::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'parent_id' => $a->id,
        ]);

        $this->actingAs($this->owner)
            ->put(route('admin.categories.update', $child->id), ['name' => 'باقة', 'parent' => $b->id]);

        $this->assertSame($b->id, $child->fresh()->parent_id);
    }
}
