<?php

namespace Tests\Feature;

use App\Http\Controllers\SuperAdmin\PageController;
use App\Models\Business;
use App\Models\User;
use App\Support\MerchantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * شعار الشركة في لوحة المنصّة — يُرفع ويبقى.
 *
 * الشاشة ترسل حقل الملفّ في كلّ حفظ، فارغًا حين لا يُختار ملف: النموذج
 * يُجبَر على FormData (فيه ملفّ)، وقيمةُ null تُكتب `''` في FormData ثم
 * تعود null بـ`ConvertEmptyStringsToNull`. فالمفتاح **حاضرٌ فارغ** لا
 * غائب — والفرق بينهما هو الفرق بين «لا تمسّ الشعار» و«امسح الشعار».
 *
 * فكان المشغّل يرفع الشعار فيُحفظ، ثم يعدّل الاسم أو الهاتف بعد يومٍ
 * فيختفي الشعار بلا رسالةٍ ولا زرٍّ ضُغط — ويبقى ملفُّه على القرص يتيمًا.
 * وهذا ما رأيناه في الإنتاج: عشرةُ ملفّاتٍ في `logos/` وصفٌّ واحدٌ يشير
 * إلى أحدها.
 *
 * والاختبارات هنا تُرسل ما ترسله الشاشة لا ما يُكتب في PHP: المفتاح
 * موجودٌ دائمًا، والقيمة هي التي تتغيّر.
 */
class ALogoOutlivesTheNextSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private int $seq = 0;

    /** متجرٌ بحسابِ دخولٍ قائم — وإلا طالبت الشاشةُ باسمٍ وكلمةِ مرور مع كلّ حفظ */
    private function shop(array $over = []): Business
    {
        $b = Business::create($over + ['name' => 'متجر الشعار', 'type' => 'عام', 'status' => 'نشط']);
        MerchantAccount::create($b, 'sahib'.(++$this->seq), 'secret12345');

        return $b;
    }

    /** ما ترسله الشاشة: كلّ حقولها في كلّ حفظ، والملفّ من بينها */
    private function save(Business $b, array $over = [])
    {
        return $this->actingAs($this->super)->put(route('super-admin.businesses.update', $b->id), $over + [
            'name' => $b->name,
            'type' => $b->type,
            'status' => $b->status,
            // الفارغ كما يصل من المتصفّح — قبل أن يقلبه الوسيط إلى null
            'logo' => '',
            'remove_logo' => '0',
        ]);
    }

    /**
     * حفظُ حقلٍ آخر لا يمسّ الشعار — ولو وصل حقلُ الملفّ فارغًا.
     *
     * هذا هو العطب نفسه: `nullable` تقبل الفراغ فيدخل المصفوفة المُتحقَّقة
     * بقيمة null، ثم يذهب إلى `update` فيمسح العمود.
     */
    public function test_saving_another_field_does_not_wipe_the_logo(): void
    {
        $b = $this->shop(['logo' => 'logos/keep.png']);

        $this->save($b, ['name' => 'اسمٌ جديد'])->assertSessionHasNoErrors();

        $this->assertSame('logos/keep.png', $b->fresh()->getRawOriginal('logo'));
        $this->assertSame('اسمٌ جديد', $b->fresh()->name);
    }

    /** ويبقى بعد حفظين متتاليين: العطب كان يظهر في الثاني لا الأول */
    public function test_it_survives_a_second_save(): void
    {
        Storage::fake('public');
        $b = $this->shop();

        $this->save($b, ['logo' => UploadedFile::fake()->image('shop.png', 200, 200)])
            ->assertSessionHasNoErrors();
        $path = $b->fresh()->getRawOriginal('logo');
        $this->assertNotNull($path);

        $this->save($b, ['name' => 'بعد يومين'])->assertSessionHasNoErrors();

        $this->assertSame($path, $b->fresh()->getRawOriginal('logo'));
    }

    /** ورفعُ ملفٍّ جديد يحلّ محلّ القديم */
    public function test_a_new_file_replaces_the_old_one(): void
    {
        Storage::fake('public');
        $b = $this->shop(['logo' => 'logos/old.png']);

        $this->save($b, ['logo' => UploadedFile::fake()->image('new.png', 200, 200)])
            ->assertSessionHasNoErrors();

        $fresh = $b->fresh()->getRawOriginal('logo');
        $this->assertNotSame('logos/old.png', $fresh);
        $this->assertStringStartsWith('logos/', (string) $fresh);
        Storage::disk('public')->assertExists($fresh);
    }

    /** و«حذف الشعار» يمسحه فعلًا — الطريق الوحيد إلى المسح */
    public function test_the_delete_button_still_clears_it(): void
    {
        $b = $this->shop(['logo' => 'logos/old.png']);

        $this->save($b, ['remove_logo' => '1'])->assertSessionHasNoErrors();

        $this->assertNull($b->fresh()->getRawOriginal('logo'));
    }

    /** والإنشاء يحفظ الملفّ المرفوع لا اسمَه ولا فراغًا */
    public function test_creating_a_shop_keeps_the_uploaded_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->super)->post(route('super-admin.businesses.store'), [
            'name' => 'شركةٌ بشعار', 'type' => 'عام', 'status' => 'نشط',
            'login_username' => 'shiar', 'login_password' => 'secret12345',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'remove_logo' => '0',
        ])->assertSessionHasNoErrors();

        // العمود الخام: `getLogoAttribute` يردّ رابط العرض لا المسار المخزَّن
        $path = Business::where('name', 'شركةٌ بشعار')->first()->getRawOriginal('logo');
        $this->assertStringStartsWith('logos/', (string) $path);
        Storage::disk('public')->assertExists($path);
    }

    /** وإنشاءٌ بلا شعار يبدأ بلا شعار — لا بنصٍّ فارغ في العمود */
    public function test_creating_a_shop_without_a_logo_leaves_it_null(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.businesses.store'), [
            'name' => 'شركةٌ بلا شعار', 'type' => 'عام', 'status' => 'نشط',
            'login_username' => 'bidoon', 'login_password' => 'secret12345',
            'logo' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Business::where('name', 'شركةٌ بلا شعار')->first()->getRawOriginal('logo'));
    }

    /* ─────────────── ورابطُ عرضه: بادئةٌ واحدة لا اثنتان ─────────────── */

    /**
     * قائمةُ الشركات تعرض شعارًا يُحمَّل.
     *
     * `Business::getLogoAttribute` يردّ `/storage/logos/…` جاهزًا، ثمّ كانت
     * `PageController::logoUrl` تُضيف بادئتها فوقه: `/storage/storage/…` —
     * ٤٠٤ في كلّ موضعٍ يُعرض فيه شعارُ شركة. الملفُّ على القرص ولا يُرى.
     */
    public function test_the_list_shows_a_logo_that_loads(): void
    {
        $this->shop(['logo' => 'logos/shop.png']);

        $this->actingAs($this->super)->get(route('super-admin.businesses.index'))
            ->assertInertia(fn ($page) => $page->where(
                'businesses.0.logo',
                fn ($url) => is_string($url) && ! str_contains($url, '/storage/storage/') && str_ends_with($url, '/storage/logos/shop.png'),
            ));
    }

    /** وملفُّ الشركة مثلها */
    public function test_the_profile_shows_a_logo_that_loads(): void
    {
        $b = $this->shop(['logo' => 'logos/shop.png']);

        $this->actingAs($this->super)->get(route('super-admin.businesses.show', $b->id))
            ->assertInertia(fn ($page) => $page->where(
                'business.logo',
                fn ($url) => is_string($url) && ! str_contains($url, '/storage/storage/'),
            ));
    }

    /** ومعاينةُ شاشة التعديل — وهي التي يراها المشغّل لحظة الرفع */
    public function test_the_edit_screen_previews_a_logo_that_loads(): void
    {
        $b = $this->shop(['logo' => 'logos/shop.png']);

        $this->actingAs($this->super)->get(route('super-admin.businesses.edit', $b->id))
            ->assertInertia(fn ($page) => $page->where(
                'business.logo_url',
                fn ($url) => is_string($url) && ! str_contains($url, '/storage/storage/'),
            ));
    }

    /** وشركةٌ بلا شعار لا تُرسل رابطًا مكسورًا بل لا شيء */
    public function test_a_shop_without_a_logo_ships_nothing(): void
    {
        $b = $this->shop();

        $this->actingAs($this->super)->get(route('super-admin.businesses.edit', $b->id))
            ->assertInertia(fn ($page) => $page->where('business.logo_url', null));
    }

    /** ورابطٌ مطلقٌ في سجلٍّ قديم يُترك كما هو */
    public function test_an_absolute_link_is_left_alone(): void
    {
        $this->assertSame(
            'https://cdn.example.com/a.png',
            PageController::logoUrl('https://cdn.example.com/a.png'),
        );
    }

    /** ومسارٌ خامٌ يُحوَّل — الدالّة لا تفقد عملها الأصلي */
    public function test_a_bare_path_is_still_turned_into_a_url(): void
    {
        $this->assertSame(
            rtrim(config('app.url'), '/').'/storage/logos/bare.png',
            PageController::logoUrl('logos/bare.png'),
        );
    }

    /** وملفٌّ ليس صورةً يُردّ برسالةٍ لا يُحفظ بصمت */
    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        Storage::fake('public');
        $b = $this->shop(['logo' => 'logos/keep.png']);

        $this->save($b, ['logo' => UploadedFile::fake()->create('shop.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('logo');

        $this->assertSame('logos/keep.png', $b->fresh()->getRawOriginal('logo'));
    }
}
