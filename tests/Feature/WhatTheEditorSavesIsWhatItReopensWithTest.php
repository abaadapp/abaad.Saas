<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Store\StoreCustomPages;
use App\Support\Store\StoreNav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ما يقول المحرّرُ إنّه حفظه هو ما يُفتح به ثانيةً — في القاعدة لا في الشاشة.
 *
 * ═══ الحادثة (RIBBON، ٢٦ أكتوبر ٢٠٢٦) ═══
 *
 * صورةٌ رُفعت (على القرص 17:40:37) ولا تذكرها صفحة، وصفحةٌ عادت بخمس عشرة وحدةً
 * بعد عشرين، وقائمةٌ لم يُكتب فيها ما أُضيف. والخادمُ لم يُسقط شيئًا صامتًا: يردّ
 * برسالة أو يحفظ ما وصله — والضياعُ كان في المحرّر (انظر
 * `the-editor-never-loses-what-it-says-it-saved.test.tsx`). وهنا ما يحفظه الخادم:
 *
 *  • قائمةٌ بصفحتي الخدمات وخدمات الشركات تُحفظ وتُفتح وتُرسم.
 *  • عشرون وحدةً تُحفظ كلُّها، والحادية والعشرون تُردّ باسم صفحتها ولا تمسّ المحفوظ.
 *  • الرفعُ يجيب المحرّرَ JSON برابطٍ من مجلّد متجره، والصورةُ تُحفظ في وحدتها.
 *  • رفعٌ فاشل يقول لماذا ولا يكتب شيئًا، وصورةُ متجرٍ آخر تُردّ.
 *  • ولا يمسّ حفظُ متجرٍ متجرًا آخر.
 */
class WhatTheEditorSavesIsWhatItReopensWithTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private User $owner;

    private User $neighbourOwner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'owner@reopen.test');
        [$this->neighbour, $this->neighbourOwner] = $this->ribbon('jaar', 'jaar@reopen.test');
    }

    /** @return array{0: Business, 1: User} */
    private function ribbon(string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'site_slug' => $slug,
            'tier' => 'gold', 'storefront_theme' => 'ribbon', 'phone' => '+96890000000',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        return [$shop, $owner];
    }

    private function page(string $slug, string $ar, string $en, array $blocks): array
    {
        return ['slug' => $slug, 'kind' => 'mixed', 'title_ar' => $ar, 'title_en' => $en, 'intro_ar' => '', 'intro_en' => '', 'blocks' => $blocks, 'visible' => true];
    }

    private function heading(string $en, string $ar = ''): array
    {
        return ['type' => 'heading', 'text_ar' => $ar, 'text_en' => $en];
    }

    private function link(string $id, string $ar, string $en, string $page): array
    {
        return ['type' => 'link', 'id' => $id, 'title_ar' => $ar, 'title_en' => $en, 'target' => ['type' => 'page', 'value' => $page], 'visible' => true];
    }

    private function save(User $as, array $menu, array $pages)
    {
        return $this->actingAs($as)->post(route('admin.marketing.store.save'), [
            StoreNav::MENU => json_encode($menu, JSON_UNESCAPED_UNICODE),
            StoreCustomPages::KEY => json_encode($pages, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** ما يُفتح به المحرّرُ — كما يقرؤه صاحبُ المتجر بعد إعادة التحميل */
    private function reopened(User $as): array
    {
        $values = $this->actingAs($as)->get(route('admin.website.editor'))->assertOk()->viewData('page')['props']['values'];

        return [json_decode($values[StoreNav::MENU] ?: '[]', true), json_decode($values[StoreCustomPages::KEY] ?: '[]', true)];
    }

    private function upload(User $as, UploadedFile $file)
    {
        return $this->actingAs($as)->postJson(route('admin.marketing.store.image'), ['image' => $file]);
    }

    private function stored(Business $shop): array
    {
        return Setting::where('business_id', $shop->id)->orderBy('key')->pluck('value', 'key')->all();
    }

    public function test_a_menu_with_a_services_page_and_a_separate_corporate_page_is_saved_and_reopened(): void
    {
        $menu = [
            ['type' => 'page', 'key' => 'home'], ['type' => 'page', 'key' => 'shop'],
            $this->link('l1', 'خدماتنا', 'Services', 'services'),
            $this->link('l2', 'خدمات الشركات', 'Corporate', 'corporate'),
            ['type' => 'page', 'key' => 'about'], ['type' => 'page', 'key' => 'contact'],
        ];
        $pages = [
            $this->page('services', 'خدماتنا', 'Services', [$this->heading('Monthly Flower Subscriptions')]),
            $this->page('corporate', 'خدمات الشركات', 'Corporate', [
                $this->heading('Strategic Business Collaborations'),
                ['type' => 'text', 'body_ar' => '', 'body_en' => 'Partnerships for offices and events.'],
                ['type' => 'button', 'label_ar' => '', 'label_en' => 'Collaborate With Us', 'target' => ['type' => 'contact', 'value' => '']],
            ]),
        ];

        $this->save($this->owner, $menu, $pages)->assertSessionHasNoErrors();

        [$menuBack, $pagesBack] = $this->reopened($this->owner);
        $this->assertSame(['services', 'corporate'], array_column($pagesBack, 'slug'));
        $this->assertSame('Strategic Business Collaborations', $pagesBack[1]['blocks'][0]['text_en']);
        $this->assertCount(3, $pagesBack[1]['blocks']);
        $links = array_values(array_filter($menuBack, fn ($i) => $i['type'] === 'link'));
        $this->assertSame(['services', 'corporate'], array_map(fn ($l) => $l['target']['value'], $links));

        // وتُرسم في المتجر بعنوانها
        $this->get('/s/ribbon/page/corporate?lang=en')->assertOk()->assertSee('Strategic Business Collaborations');
    }

    public function test_twenty_blocks_are_kept_and_a_twenty_first_is_refused_by_name_without_touching_them(): void
    {
        $twenty = array_map(fn ($i) => $this->heading('Section '.$i), range(1, StoreCustomPages::MAX_BLOCKS));
        $menu = [['type' => 'page', 'key' => 'home'], $this->link('l1', 'خدماتنا', 'Services', 'services')];

        $this->save($this->owner, $menu, [$this->page('services', 'خدماتنا', 'Services', $twenty)])->assertSessionHasNoErrors();
        $this->assertCount(20, $this->reopened($this->owner)[1][0]['blocks']);

        $more = [...$twenty, $this->heading('Section 21')];
        $this->save($this->owner, $menu, [$this->page('services', 'خدماتنا', 'Services', $more)])
            ->assertSessionHasErrors([StoreCustomPages::KEY => 'أضف حتى 20 وحدة في صفحة «خدماتنا».']);

        $blocks = $this->reopened($this->owner)[1][0]['blocks'];
        $this->assertCount(20, $blocks, 'ردُّ الحادية والعشرين مسّ ما حُفظ');
        $this->assertSame('Section 20', $blocks[19]['text_en']);
    }

    public function test_an_uploaded_image_answers_the_editor_and_is_kept_with_its_block(): void
    {
        $res = $this->upload($this->owner, UploadedFile::fake()->image('واجهة.jpg', 1600, 900))->assertOk();
        $url = $res->json('url');

        $this->assertStringStartsWith('/storage/website/'.$this->shop->id.'/', $url);
        $this->assertSame([1600, 900], [$res->json('meta.w'), $res->json('meta.h')]);
        Storage::disk('public')->assertExists('website/'.$this->shop->id.'/'.basename($url));

        $menu = [['type' => 'page', 'key' => 'home'], $this->link('l1', 'خدماتنا', 'Services', 'services')];
        $this->save($this->owner, $menu, [$this->page('services', 'خدماتنا', 'Services', [
            ['type' => 'image', 'image' => $url, 'fit' => 'contain'],
            $this->heading('Strategic Business Collaborations'),
        ])])->assertSessionHasNoErrors();

        $blocks = $this->reopened($this->owner)[1][0]['blocks'];
        $this->assertSame(['type' => 'image', 'image' => $url, 'fit' => 'contain'], $blocks[0]);
        $this->get('/s/ribbon/page/services')->assertOk()->assertSee(basename($url));
    }

    public function test_a_failed_upload_says_why_and_writes_nothing(): void
    {
        $this->upload($this->owner, UploadedFile::fake()->create('عقد.pdf', 20, 'application/pdf'))
            ->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['image']]);
        $this->actingAs($this->owner)->postJson(route('admin.marketing.store.image'), [])
            ->assertStatus(422)->assertJsonPath('errors.image.0', 'اختر صورة لترفعها.');

        $this->assertSame([], Storage::disk('public')->allFiles('website/'.$this->shop->id));
    }

    public function test_an_image_from_another_shop_is_refused_and_nothing_is_saved(): void
    {
        $theirs = $this->upload($this->neighbourOwner, UploadedFile::fake()->image('جار.jpg'))->assertOk()->json('url');
        $menu = [['type' => 'page', 'key' => 'home'], $this->link('l1', 'خدماتنا', 'Services', 'services')];
        $before = $this->stored($this->shop);

        $this->save($this->owner, $menu, [$this->page('services', 'خدماتنا', 'Services', [['type' => 'image', 'image' => $theirs, 'fit' => 'contain']])])
            ->assertSessionHasErrors(StoreCustomPages::KEY);

        $this->assertSame($before, $this->stored($this->shop));
    }

    public function test_one_shops_save_and_upload_never_reach_another(): void
    {
        $this->save($this->neighbourOwner, [['type' => 'page', 'key' => 'home'], $this->link('l1', 'عروضنا', 'Offers', 'offers')], [
            $this->page('offers', 'عروضنا', 'Offers', [$this->heading('Neighbour offers')]),
        ])->assertSessionHasNoErrors();
        $neighbour = $this->stored($this->neighbour);

        $this->upload($this->owner, UploadedFile::fake()->image('a.jpg'))->assertOk();
        $this->save($this->owner, [['type' => 'page', 'key' => 'home'], $this->link('l1', 'خدماتنا', 'Services', 'services')], [
            $this->page('services', 'خدماتنا', 'Services', [$this->heading('Ours')]),
        ])->assertSessionHasNoErrors();

        $this->assertSame($neighbour, $this->stored($this->neighbour));
        $this->assertSame(['offers'], array_column($this->reopened($this->neighbourOwner)[1], 'slug'));
        $this->assertSame([], Storage::disk('public')->allFiles('website/'.$this->neighbour->id));
    }
}
