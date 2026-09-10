<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Content;
use App\Support\Website\Nav;
use App\Support\Website\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * روابطُ القائمة تعرف إلى أين تشير — ولا تُخمَّن من شكلها.
 *
 * ═══ ما كان يقع ═══
 *
 * كانت المزامنة تفرز الروابط بشكلها: `str_starts_with($href, '/')` تعني
 * «رابطُ صفحةٍ يبنيه النظام»، وما سواه «رابطٌ زاده التاجر». ثمّ تُمحى
 * الأولى وتُبنى من الصفحات، ويُلحق بها الثاني.
 *
 * فكلُّ رابطٍ داخليٍّ يكتبه التاجر بيده كان يُحذف: يضيف «العروض» يشير إلى
 * `/shop`، ثمّ يبدّل عنوان صفحةٍ **أخرى** بعد يومين فتُنادى المزامنة ويسقط
 * رابطُه بلا خبر. وهو لا يربط بين الأمرين أبدًا.
 */
class AMenuLinkKnowsWhereItPointsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function build(): Website
    {
        return Builder::create($this->business, Blueprints::STORE, 'modern', $this->owner->id);
    }

    /** @return list<array<string, mixed>> */
    private function links(Website $site): array
    {
        return (array) ($site->fresh()->header()->data['links'] ?? []);
    }

    private function addCustom(Website $site, string $label, string $href): void
    {
        $header = $site->header();
        $data = $header->data;
        $data['links'][] = ['label' => $label, 'href' => $href, 'type' => Nav::EXTERNAL, 'page_id' => 0];

        $header->update(['data' => Content::clean(Sections::HEADER, $data, $site->goal)]);
    }

    /* ═════════════════════ ما يبنيه النظام ═════════════════════ */

    public function test_every_published_page_has_a_link(): void
    {
        $site = $this->build();

        $slugs = collect($this->links($site))->pluck('href')->all();

        foreach ($site->pages()->where('status', WebsitePage::PUBLISHED)->pluck('slug') as $slug) {
            $this->assertContains($slug, $slugs);
        }
    }

    /** ورابطُ الصفحة يحمل معرّفَها — لا شكلَها وحده */
    public function test_a_page_link_carries_its_page(): void
    {
        $site = $this->build();
        $home = $site->homePage();

        $link = collect($this->links($site))->firstWhere('page_id', $home->id);

        $this->assertNotNull($link, 'رابطُ الرئيسية بلا معرّفٍ لصفحته');
        $this->assertSame(Nav::PAGE, $link['type']);
    }

    /* ═════════════════════ ما يكتبه التاجر ═════════════════════ */

    /**
     * رابطٌ داخليٌّ يكتبه التاجر يبقى — وهذا هو العطبُ الذي كان.
     *
     * `/shop#sale` يبدأ بشرطة، فكان يُقرأ رابطَ صفحةٍ يبنيه النظام ويُمحى
     * في أوّل مزامنة.
     */
    public function test_an_internal_link_the_merchant_wrote_survives_a_sync(): void
    {
        $site = $this->build();
        $this->addCustom($site, 'العروض', '/offers-2025');

        Nav::sync($site->fresh());

        $labels = collect($this->links($site))->pluck('label')->all();

        $this->assertContains('العروض', $labels, 'حُذف رابطٌ كتبه التاجر بيده');
    }

    /**
     * ورابطٌ كتبه التاجر يشير إلى صفحةٍ عنده يبقى باسمه هو.
     *
     * «تسوّق» يشير إلى `/shop` ليس رابطَ الصفحة الذي يبنيه النظام — هو اسمٌ
     * اختاره صاحبُ الموقع. وإبدالُه بعنوان الصفحة في كلّ مزامنة يجعل التاجر
     * يعيد تسميته ثمّ يجده عاد، فيتركه.
     */
    public function test_a_written_link_to_an_existing_page_keeps_its_own_name(): void
    {
        $site = $this->build();
        $this->addCustom($site, 'تسوّق الآن', '/shop');

        Nav::sync($site->fresh());

        $this->assertContains('تسوّق الآن', collect($this->links($site))->pluck('label')->all());
    }

    /** ويبقى بعد تبديل عنوان صفحةٍ أخرى — وهو ما كان يُسقطه */
    public function test_it_survives_renaming_another_page(): void
    {
        $site = $this->build();
        $this->addCustom($site, 'العروض', '/offers-2025');

        $about = $site->pages()->where('key', 'about')->first();

        $this->actingAs($this->owner)->put(route('admin.website.pages.update', $about->id), [
            'title' => 'قصّتنا', 'slug' => 'our-story', 'status' => WebsitePage::PUBLISHED,
        ]);

        $this->assertContains('العروض', collect($this->links($site))->pluck('label')->all());
    }

    /** والرابطُ الخارجيّ كذلك */
    public function test_an_external_link_survives(): void
    {
        $site = $this->build();
        $this->addCustom($site, 'مدوّنتنا', 'https://blog.example.om');

        Nav::sync($site->fresh());

        $this->assertContains('https://blog.example.om', collect($this->links($site))->pluck('href')->all());
    }

    /* ═════════════════════ العنوانُ يتبع صفحته ═════════════════════ */

    /**
     * بدّل التاجر عنوان صفحةٍ فتبعه رابطُها.
     *
     * ورابطٌ يبقى على العنوان القديم يقود الزائر إلى «غير موجود» — والتاجر
     * لا يفتح قائمةَ موقعه ليتفقّدها بعد كلّ تعديل.
     */
    public function test_a_page_link_follows_its_page_when_the_slug_changes(): void
    {
        $site = $this->build();
        $about = $site->pages()->where('key', 'about')->first();

        $this->actingAs($this->owner)->put(route('admin.website.pages.update', $about->id), [
            'title' => 'من نحن', 'slug' => 'who-we-are', 'status' => WebsitePage::PUBLISHED,
        ]);

        $link = collect($this->links($site))->firstWhere('page_id', $about->id);

        $this->assertSame('/who-we-are', $link['href'] ?? null);
        $this->assertNotContains('/about', collect($this->links($site))->pluck('href')->all());
    }

    /** وصفحةٌ حُذفت يسقط رابطُها — لا يبقى بابًا على «غير موجود» */
    public function test_a_deleted_page_loses_its_link(): void
    {
        $site = $this->build();
        $about = $site->pages()->where('key', 'about')->first();
        $slug = $about->slug;

        $this->actingAs($this->owner)->delete(route('admin.website.pages.destroy', $about->id));

        $this->assertNotContains($slug, collect($this->links($site))->pluck('href')->all());
    }

    /** وصفحةٌ أُخفيت من القائمة كذلك — تعمل ولا يُدلّ عليها */
    public function test_a_hidden_page_leaves_the_menu_but_keeps_working(): void
    {
        $site = $this->build();
        $about = $site->pages()->where('key', 'about')->first();

        $this->actingAs($this->owner)->put(route('admin.website.pages.update', $about->id), [
            'title' => 'من نحن', 'slug' => 'about', 'status' => WebsitePage::HIDDEN,
        ]);

        $this->assertNotContains('/about', collect($this->links($site))->pluck('href')->all());
        $this->assertSame(WebsitePage::HIDDEN, $about->fresh()->status);
    }

    /* ═════════════════════ الشاشةُ لا تُضيّع النوع ═════════════════════ */

    /**
     * وما يعود من المحرّر بلا معرّفٍ لا يضيع.
     *
     * الشاشةُ لا تعرض `page_id` للتاجر — وهو صواب: بنيةُ النظام لا تُعرض في
     * الشاشة. فما يعود منها بلا معرّف، ويُعاد الاشتقاقُ بمطابقة العنوان.
     * ولولا ذلك لَصارت روابطُ الصفحات كلُّها «كتبها التاجر» فتتكرّر في
     * القائمة عند أوّل مزامنة.
     */
    public function test_a_link_that_comes_back_from_the_editor_keeps_its_page(): void
    {
        $site = $this->build();
        $header = $site->header();

        // كما يعود من النموذج: اسمٌ ووجهةٌ لا غير
        $bare = collect($header->data['links'])
            ->map(fn ($l) => ['label' => $l['label'], 'href' => $l['href']])->all();

        $this->actingAs($this->owner)->put(route('admin.website.sections.update', $header->id), [
            'data' => ['links' => $bare] + $header->data,
        ]);

        Nav::sync($site->fresh());

        $hrefs = collect($this->links($site))->pluck('href')->all();

        $this->assertSame(count($hrefs), count(array_unique($hrefs)), 'تكرّرت روابط الصفحات');
    }
}
