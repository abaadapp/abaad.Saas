<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الصيانةُ تقع في اللحظة — فليست تغييرًا ينتظر النشر.
 *
 * ═══ ما كان يقع ═══
 *
 * مفتاح الصيانة يُقرأ حيًّا من عمود الموقع: العارضُ الخارجيّ يفحص
 * `websites.maintenance` قبل أن ينظر في اللقطة المنشورة، فرفعُ المفتاح يُغلق
 * الموقع في اللحظة ولا ينتظر «انشر».
 *
 * ومع ذلك كان يستدعي `touchDraft` — عدّادَ «فيه تغييراتٌ لم تُنشر». فالتاجر
 * يُشغّل الصيانة ثمّ يُطفئها، فتقول لوحته من بعدها **«فيه تغييرات لم
 * تُنشر»** وليس فيه تغييرٌ واحد. فيضغط «انشر» فتُكتب نشرةٌ جديدة برقمٍ
 * جديد في سجلّ النشرات — لا تحمل شيئًا.
 *
 * وتقريرُ حالٍ كاذب أسوأ من غياب التقرير: من رآها مرّةً كاذبة لم يصدّقها
 * حين تصدق.
 */
class MaintenanceIsNotAnUnpublishedChangeTest extends TestCase
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
        Publisher::publish($this->site, $this->owner->id);
        $this->site->refresh();
    }

    private function toggle(bool $on, ?string $message = null)
    {
        return $this->post(route('admin.website.maintenance'), array_filter([
            'maintenance' => $on,
            'maintenance_message' => $message,
        ], fn ($v) => $v !== null));
    }

    public function test_a_published_site_starts_with_nothing_unpublished(): void
    {
        $this->assertFalse($this->site->hasUnpublishedChanges());
    }

    public function test_turning_maintenance_on_is_not_an_unpublished_change(): void
    {
        $this->toggle(true)->assertSessionHasNoErrors();

        $this->assertFalse($this->site->fresh()->hasUnpublishedChanges(),
            'الصيانةُ عُدّت تغييرًا ينتظر النشر — وهي تقع في اللحظة');
    }

    public function test_and_turning_it_off_leaves_the_site_published(): void
    {
        $this->toggle(true);
        $this->toggle(false)->assertSessionHasNoErrors();

        $site = $this->site->fresh();

        $this->assertFalse($site->maintenance);
        $this->assertSame(Website::PUBLISHED, $site->state(),
            'اللوحة تقول «فيه تغييرات» بعد صيانةٍ شُغّلت وأُطفئت');
    }

    /** ورسالتُها تُحفظ — هي ما يقرؤه الزائر */
    public function test_the_message_is_kept(): void
    {
        $this->toggle(true, 'نعود بعد ساعة');

        $this->assertSame('نعود بعد ساعة', $this->site->fresh()->maintenance_message);
    }

    /** والزائرُ يُردّ في اللحظة بلا نشرةٍ جديدة */
    public function test_the_visitor_is_turned_away_at_once(): void
    {
        Setting::create([
            'business_id' => $this->business->id, 'key' => 'site_domain', 'value' => 'wrood.om',
        ]);

        $this->get(route('site.published', 'wrood.om'))->assertSuccessful();

        $this->toggle(true);

        $this->get(route('site.published', 'wrood.om'))
            ->assertStatus(503)
            ->assertJson(['maintenance' => true]);
    }

    /** وتعديلٌ حقيقيّ يبقى تغييرًا ينتظر النشر — الحارس لا يُسكت الصادق */
    public function test_a_real_edit_is_still_an_unpublished_change(): void
    {
        $page = $this->site->pages()->where('is_home', true)->firstOrFail();

        $this->put(route('admin.website.pages.update', $page->id), [
            'title' => 'الرئيسية الجديدة', 'status' => WebsitePage::PUBLISHED,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->site->fresh()->hasUnpublishedChanges());
    }
}
