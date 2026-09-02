<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Storefront;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * السؤالُ الأوّل: من أين يأتي عنوان متجرك؟
 *
 * وكانت الصفحة تعرض بطاقتين معًا وفيهما كلمةُ «نطاق» بمعنيين متضادّين —
 * «موقعي عندكم» و«موقعي عند غيركم». فيكتب التاجر عنوان متجره في حقل الموقع
 * الخارجيّ، ويصير زرُّ «فتح الموقع» يشير إلى عنوانٍ لا وجود له.
 *
 * وثلاثةٌ تُفحص هنا: أنّ السؤال يُطرح **مرّةً** ولا يُعاد على من سبقه، وأنّ
 * تبديل الرأي **لا يمحو** عنوانًا محجوزًا ولا نطاقًا مكتوبًا، وأنّ السعر
 * يخرج من **موضعٍ واحد** — إذ سعرٌ يُكتب في الشاشة يبقى بعد أن يتغيّر في
 * الخادم، فيرى التاجر رقمًا ويُفوتر بآخر.
 */
class DomainChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /** الطريق كما تراه الشاشة */
    private function path(): string
    {
        return $this->get(route('admin.settings.index', ['section' => 'website']))
            ->viewData('page')['props']['store']['path'];
    }

    /* ---------------------------- السؤال يُطرح مرّةً ---------------------------- */

    public function test_a_shop_that_never_chose_is_asked(): void
    {
        // '' هو ما تقرؤه الشاشة فتعرض البطاقات الثلاث
        $this->assertSame('', $this->path());
    }

    public function test_a_shop_that_already_reserved_an_address_is_not_asked_again(): void
    {
        /*
         * ومن ضبط موقعه قبل وجود هذا السؤال يُستنتج طريقُه ولا يُسأل: سؤالُه
         * «هل عندك نطاق؟» بعد أن حجز عنوانه يجعله يظنّ أنّ ما ضبطه ضاع.
         */
        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();

        $this->assertSame('sub', $this->path());
    }

    public function test_a_shop_that_already_wrote_its_own_domain_is_not_asked_again(): void
    {
        MarketingSettings::save($this->business->id, 'website', ['site_domain' => 'mystore.om']);

        $this->assertSame('own', $this->path());
    }

    public function test_the_saved_choice_beats_the_guess(): void
    {
        /*
         * الاستنتاج لمن لم يُسأل وحده. ومن حجز عنوانًا ثمّ قال صراحةً «عندي
         * نطاق» يجب أن يبقى على قوله — وإلّا صار اختيارُه لا أثر له.
         */
        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();

        $this->post(route('admin.marketing.domain.path'), ['site_path' => 'own'])
            ->assertSessionHasNoErrors();

        $this->assertSame('own', $this->path());
    }

    /* ----------------------------- ما يُقبل ويُردّ ----------------------------- */

    public function test_each_of_the_three_routes_is_accepted(): void
    {
        foreach (Storefront::PATHS as $path) {
            $this->post(route('admin.marketing.domain.path'), ['site_path' => $path])
                ->assertSessionHasNoErrors();

            $this->assertSame($path, $this->path());
        }
    }

    public function test_a_route_that_does_not_exist_is_refused(): void
    {
        $this->post(route('admin.marketing.domain.path'), ['site_path' => 'free-forever'])
            ->assertSessionHasErrors('site_path');

        $this->assertSame('', $this->path());
    }

    public function test_an_empty_choice_is_refused(): void
    {
        $this->post(route('admin.marketing.domain.path'), [])
            ->assertSessionHasErrors('site_path');
    }

    /* --------------------- التبديل لا يمحو ما ضُبط --------------------- */

    public function test_changing_the_route_keeps_the_reserved_address_and_the_written_domain(): void
    {
        /*
         * وهذا أخطر ما في السؤال: من جرّب «عندي نطاق» ثمّ عاد يجب أن يجد
         * عنوانه كما تركه. ومحوُه يعني متجرًا يسقط من الإنترنت لأنّ صاحبه
         * ضغط بطاقةً ليقرأها.
         */
        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();
        MarketingSettings::save($this->business->id, 'website', [
            'site_domain' => 'mystore.om',
            'store_on' => '1',
            'store_theme' => 'sea',
        ]);

        $this->post(route('admin.marketing.domain.path'), ['site_path' => 'own']);
        $this->post(route('admin.marketing.domain.path'), ['site_path' => 'new']);
        $this->post(route('admin.marketing.domain.path'), ['site_path' => 'sub']);

        $site = MarketingSettings::group($this->business->id, 'website');

        $this->assertSame('ward-alkhuwair', $this->business->fresh()->site_slug);
        $this->assertSame('mystore.om', $site['site_domain']);
        $this->assertSame('1', $site['store_on'], 'تبديلُ الطريق أنزل متجرًا منشورًا');
        $this->assertSame('sea', $site['store_theme']);
    }

    public function test_a_published_shop_stays_open_to_its_customers_after_the_route_changes(): void
    {
        /*
         * ولا يُقاس النشرُ بمفتاحٍ في القاعدة وحده: الصفحة نفسها يجب أن تبقى
         * تُفتح. فمن اختار «عندي نطاق» لا يفقد زبائنه الذين معهم عنوانُه.
         */
        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();
        MarketingSettings::save($this->business->id, 'website', ['store_on' => '1']);

        $this->post(route('admin.marketing.domain.path'), ['site_path' => 'own']);

        $this->assertNotNull(Storefront::find('ward-alkhuwair'));
    }

    /* ------------------------------- السعر ------------------------------- */

    public function test_the_price_reaches_the_screen_from_the_configuration(): void
    {
        config([
            'storefront.pricing.subdomain.monthly' => 2,
            'storefront.pricing.subdomain.yearly' => 20,
            'storefront.currency' => 'ر.ع',
        ]);

        $pricing = $this->get(route('admin.settings.index', ['section' => 'website']))
            ->viewData('page')['props']['store']['pricing'];

        $this->assertSame(2.0, $pricing['subdomain']['monthly']);
        $this->assertSame(20.0, $pricing['subdomain']['yearly']);
        $this->assertFalse($pricing['subdomain']['free']);
        $this->assertSame('ر.ع', $pricing['currency']);
    }

    public function test_no_price_set_reads_as_included_not_as_missing(): void
    {
        /*
         * وصفرٌ ليس «سعرًا مفقودًا»: الشاشة تقول «مشمول في باقتك». ولو حُسب
         * ذلك في الشاشة لا في الخادم لافترقا يومًا — تقول واحدةٌ «٠٫٠٠٠ ر.ع»
         * ويقول الآخر «مجّانًا».
         */
        config(['storefront.pricing.subdomain.monthly' => 0, 'storefront.pricing.subdomain.yearly' => 0]);

        $this->assertTrue(Storefront::pricing()['subdomain']['free']);
    }

    public function test_a_broken_price_is_read_as_zero_not_as_a_negative_charge(): void
    {
        // قيمةٌ خاطئة في البيئة تُقرأ صفرًا: سعرٌ سالب يُعرض «خصمًا» لا رسمًا
        config(['storefront.pricing.subdomain.monthly' => 'مجانا', 'storefront.pricing.subdomain.yearly' => -5]);

        $pricing = Storefront::pricing();

        $this->assertSame(0.0, $pricing['subdomain']['monthly']);
        $this->assertSame(0.0, $pricing['subdomain']['yearly']);
        $this->assertTrue($pricing['subdomain']['free']);
    }

    /* ------------------------------ العزل ------------------------------ */

    public function test_a_neighbours_choice_is_not_read_as_mine(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        MarketingSettings::save($neighbour->id, 'website', ['site_path' => 'own']);

        $this->assertSame('', $this->path());
    }
}
