<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * أربعةُ أبوابٍ تكتب في الموقع بلا حارسٍ يفحصها.
 *
 * ترتيبُ الصفحات، وإعداداتُ الموقع، ورفعُ الصور، وجدارُ المتجر بين متجرين
 * — كلُّها تُنادى من الشاشة ولا حالةَ تمرّ عليها. وبابٌ يكتب بلا حارسٍ
 * يُكسر في أوّل تعديلٍ لا ينتبه له كاتبُه.
 *
 * والأهمّ فيها الجدار: ترتيبٌ يمسّ صفحةَ جارٍ، أو إعدادٌ يُحفظ على موقعٍ
 * ليس لصاحب الطلب، لا يُكتشف إلا حين يرى تاجرٌ صفحاتِه وقد تبدّلت.
 */
class WebsiteWriteRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);

        $this->site = Builder::create($this->shop, Blueprints::STORE, 'modern', $this->owner->id);
    }

    /* --------------------------- ترتيب الصفحات --------------------------- */

    public function test_pages_take_the_order_they_are_given(): void
    {
        $ids = $this->site->pages()->orderBy('position')->pluck('id')->all();
        $this->assertGreaterThan(2, count($ids), 'القالب بلا صفحات');

        $flipped = array_reverse($ids);

        $this->post(route('admin.website.pages.reorder'), ['order' => $flipped])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $flipped,
            $this->site->pages()->orderBy('position')->pluck('id')->all(),
            'الترتيب لم يُحفظ',
        );
    }

    /**
     * وصفحةُ جارٍ في القائمة تُتخطّى ولا تُحرّك.
     *
     * الترتيب يصل مصفوفةَ معرّفات من المتصفّح، فمن أضاف إليها رقمًا ليس له
     * كان يحرّك صفحةً في موقع غيره — والجدار في المتحكّم لا في الشاشة.
     */
    public function test_a_neighbours_page_is_not_moved(): void
    {
        $other = Business::create(['name' => 'جاري', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'الجار', 'email' => 'n@abaadapp.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $theirs = Builder::create($other, Blueprints::STORE, 'modern', $stranger->id);

        $victim = $theirs->pages()->orderBy('position')->first();
        $before = $victim->position;

        $this->post(route('admin.website.pages.reorder'), [
            'order' => array_merge([$victim->id], $this->site->pages()->pluck('id')->all()),
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            $before,
            WebsitePage::find($victim->id)->position,
            'حُرّكت صفحةٌ في موقع الجار',
        );
    }

    public function test_an_empty_order_is_refused(): void
    {
        $this->post(route('admin.website.pages.reorder'), [])
            ->assertSessionHasErrors('order');
    }

    /* -------------------------- إعدادات الموقع -------------------------- */

    public function test_the_site_name_and_goal_are_saved(): void
    {
        $this->put(route('admin.website.settings.save'), [
            'name' => 'ورودُ مسقط للهدايا', 'goal' => Blueprints::CATALOG,
        ])->assertSessionHasNoErrors();

        $fresh = $this->site->fresh();
        $this->assertSame('ورودُ مسقط للهدايا', $fresh->name);
        $this->assertSame(Blueprints::CATALOG, $fresh->goal);
    }

    /** وتبديل الوجهة لا يهدم ما بُني: الصفحات والأقسام تبقى */
    public function test_changing_the_goal_keeps_the_work(): void
    {
        $pages = $this->site->pages()->count();
        $sections = $this->site->pages()->withCount('sections')->get()->sum('sections_count');

        $this->put(route('admin.website.settings.save'), [
            'name' => $this->site->name, 'goal' => Blueprints::CATALOG,
        ])->assertSessionHasNoErrors();

        $this->assertSame($pages, $this->site->fresh()->pages()->count(), 'ضاعت صفحات');
        $this->assertSame(
            $sections,
            $this->site->fresh()->pages()->withCount('sections')->get()->sum('sections_count'),
            'ضاعت أقسام',
        );
    }

    public function test_an_unknown_goal_is_refused(): void
    {
        $this->put(route('admin.website.settings.save'), ['name' => 'اسم', 'goal' => 'لا-وجهة'])
            ->assertSessionHasErrors('goal');
    }

    public function test_a_nameless_site_is_refused(): void
    {
        $this->put(route('admin.website.settings.save'), ['name' => '', 'goal' => Blueprints::STORE])
            ->assertSessionHasErrors('name');
    }

    /* ----------------------------- رفع الصور ----------------------------- */

    public function test_an_image_is_stored_under_its_own_shop(): void
    {
        Storage::fake('public');

        $this->post(route('admin.website.media'), [
            'image' => UploadedFile::fake()->image('banner.jpg', 800, 400),
        ])->assertSessionHasNoErrors();

        $files = Storage::disk('public')->allFiles('website/'.$this->shop->id);
        $this->assertNotEmpty($files, 'لم تُحفظ الصورة تحت مجلّد المتجر');
    }

    /** وما ليس صورةً يُردّ — لا يُحفظ ثمّ يُكتشف عند العرض */
    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        Storage::fake('public');

        $this->post(route('admin.website.media'), [
            'image' => UploadedFile::fake()->create('list.pdf', 40, 'application/pdf'),
        ])->assertSessionHasErrors('image');

        $this->assertSame([], Storage::disk('public')->allFiles('website/'.$this->shop->id));
    }

    /** والحدُّ أربعةُ ميغابايت — يُقال قبل الرفع لا بعده */
    public function test_a_huge_image_is_refused(): void
    {
        Storage::fake('public');

        $this->post(route('admin.website.media'), [
            'image' => UploadedFile::fake()->image('huge.jpg')->size(5000),
        ])->assertSessionHasErrors('image');
    }

    /* ------------------------------ والزائر ------------------------------ */

    public function test_a_guest_writes_nothing(): void
    {
        auth()->logout();

        $this->put(route('admin.website.settings.save'), ['name' => 'x', 'goal' => Blueprints::STORE])
            ->assertRedirect(route('login'));

        $this->post(route('admin.website.pages.reorder'), ['order' => [1]])
            ->assertRedirect(route('login'));
    }
}
