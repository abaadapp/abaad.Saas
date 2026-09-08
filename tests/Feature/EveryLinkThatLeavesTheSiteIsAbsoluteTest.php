<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Models\Website;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Preview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كلُّ رابطٍ يخرج في مستند الموقع مطلق — وصورةُ المشاركة منه.
 *
 * ═══ لماذا ═══
 *
 * الصور في النظام تُخزَّن مساراتٍ وتُقرأ روابطَ نسبيّة (`/storage/…`). وهذا
 * يعمل ما دام القارئ في نطاق أبعاد. لكنّ الموقع المنشور يُقرأ من نطاق
 * التاجر: رابطٌ نسبيّ هناك يعني `https://متجره.om/storage/…` — عنوانٌ لا شيء
 * عليه. ولذلك بُني `Media::url`، وتُمرَّر عليه بياناتُ كلّ قسمٍ وشعارُ المتجر.
 *
 * ═══ وما كان يفوته ═══
 *
 * `seo.image` — «صورة المشاركة» التي تظهر حين يُشارَك رابط الموقع في واتساب
 * أو غيره — كانت تخرج كما كُتبت. فمن كتب فيها مسارًا خرجت في `og:image`
 * برابطٍ لا يفتح، ولا يراه صاحبُه أبدًا: هو لا يشارك رابط موقعه بنفسه.
 * وسيوُ الصفحات مثلُها.
 *
 * والحقلُ اليوم يُلصق فيه رابطٌ كامل غالبًا (حقلُه يقول `https://…`)، فالعطب
 * نادرُ الوقوع لا مستحيلُه — والقاعدةُ أنّ ما يخرج يخرج مطلقًا، في موضعٍ
 * واحد، لا في المواضع التي تُذكَر.
 */
class EveryLinkThatLeavesTheSiteIsAbsoluteTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);

        $this->site = Builder::create($this->business, Blueprints::STORE, 'modern', $this->owner->id);
    }

    private function host(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function test_the_share_image_leaves_absolute(): void
    {
        $this->site->update(['seo' => [
            'title' => 'ورود مسقط', 'description' => 'باقات', 'image' => 'website/1/share.jpg', 'index' => true,
        ]]);

        $document = Preview::document($this->site->fresh());

        $this->assertSame($this->host().'/storage/website/1/share.jpg', $document['seo']['image']);
    }

    /** ورابطٌ كامل يمرّ كما هو — الحقل يقبل الأمرين منذ بُني */
    public function test_an_external_share_image_passes_through(): void
    {
        $this->site->update(['seo' => ['image' => 'https://cdn.example.om/s.jpg']]);

        $this->assertSame(
            'https://cdn.example.om/s.jpg',
            Preview::document($this->site->fresh())['seo']['image'],
        );
    }

    /** ولا صورةَ تعني لا صورة — لا رابطًا إلى جذر الموقع */
    public function test_no_share_image_stays_empty(): void
    {
        $this->site->update(['seo' => ['title' => 'ورود', 'image' => '']]);

        $this->assertSame('', Preview::document($this->site->fresh())['seo']['image']);
    }

    /** وسيوُ الصفحة مثلُ سيو الموقع */
    public function test_a_page_share_image_leaves_absolute_too(): void
    {
        $page = $this->site->pages()->where('is_home', true)->firstOrFail();
        $page->update(['seo' => ['title' => 'الرئيسية', 'description' => '', 'image' => 'website/1/home.png']]);

        $document = Preview::document($this->site->fresh());
        $home = collect($document['pages'])->firstWhere('is_home', true);

        $this->assertSame($this->host().'/storage/website/1/home.png', $home['seo']['image']);
    }

    /** وصفحةٌ بلا سيو لا تكسر المستند */
    public function test_a_page_without_seo_is_left_alone(): void
    {
        $page = $this->site->pages()->where('is_home', true)->firstOrFail();
        $page->update(['seo' => null]);

        $home = collect(Preview::document($this->site->fresh())['pages'])->firstWhere('is_home', true);

        $this->assertNull($home['seo'] ?? null);
    }

    /** والشعارُ كان يخرج مطلقًا ويبقى — الحارس لا يكسر ما كان يعمل */
    public function test_the_logo_still_leaves_absolute(): void
    {
        $this->business->forceFill(['logo' => 'logos/shop.png'])->save();

        $this->assertSame(
            $this->host().'/storage/logos/shop.png',
            Preview::document($this->site->fresh())['brand']['logo'],
        );
    }
}
