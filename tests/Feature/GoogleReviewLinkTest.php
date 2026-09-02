<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Support\GoogleReviews;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ربط خرائط Google — صفحةٌ تربط، لا زرٌّ يخرج من النظام.
 *
 * وكان زرًّا في شاشة التقييمات يفتح `business.google.com` في تبويبٍ خارجيّ:
 * اسمُه «ربط» ولا يربط شيئًا — يُخرج التاجر من لوحته ولا يعود بمعرّفٍ ولا
 * يُحفظ شيء.
 *
 * وأخطرُ ما يحرسه هذا الملفّ أنّ **معرّفًا خاطئًا لا يُخطئ أحدًا في الشاشة**:
 * الحفظ ينجح، والرمز يُطبع، ويمسحه الزبون فيفتح ملفَّ محلٍّ آخر — ولا يرى
 * صاحبُه ذلك أبدًا لأنّه لا يمسح إيصاله بنفسه.
 */
class GoogleReviewLinkTest extends TestCase
{
    use RefreshDatabase;

    private const PLACE = 'ChIJN1t_tDeuEmsRUsoyG83frY4';

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function save(array $data)
    {
        return $this->post(route('admin.marketing.google.save'), $data);
    }

    /* ======================= قراءة المعرّف ======================= */

    public function test_it_reads_the_id_written_plainly(): void
    {
        $this->assertSame(self::PLACE, GoogleReviews::placeId(self::PLACE));
    }

    public function test_it_reads_the_id_out_of_a_link_that_carries_it(): void
    {
        foreach ([
            'https://www.google.com/maps/place/?q=place_id:'.self::PLACE,
            'https://search.google.com/local/writereview?placeid='.self::PLACE,
        ] as $url) {
            $this->assertSame(self::PLACE, GoogleReviews::placeId($url), $url);
        }
    }

    public function test_it_refuses_a_link_that_does_not_carry_the_id(): void
    {
        /*
         * رابطُ الخرائط العاديّ يحمل رقم CID لا معرّف المكان، واستخراجُ
         * المعرّف منه تخمين. والتخمينُ هنا يرسل الزبائن إلى محلٍّ آخر.
         */
        foreach ([
            'https://www.google.com/maps/place/My+Shop/@23.58,58.38,17z',
            'https://maps.app.goo.gl/abc123',
            'ليس رابطًا',
            '',
        ] as $bad) {
            $this->assertNull(GoogleReviews::placeId($bad), $bad);
        }
    }

    /* ========================= الحفظ ========================= */

    public function test_saving_a_readable_link_stores_the_id_and_builds_the_urls(): void
    {
        $this->save(['google_maps_url' => 'https://www.google.com/maps/place/?q=place_id:'.self::PLACE])
            ->assertSessionHasNoErrors();

        $link = GoogleReviews::forBusiness($this->business->id);

        $this->assertSame(self::PLACE, $link['place_id']);
        $this->assertSame('https://search.google.com/local/writereview?placeid='.self::PLACE, $link['review_url']);
        $this->assertStringContainsString(self::PLACE, $link['place_url']);
    }

    public function test_an_unreadable_link_is_refused_not_stored_half_way(): void
    {
        /*
         * ولا يُحفظ نصفُه: لو حُفظ الرابط وتُرك المعرّف فارغًا لبدت الشاشة
         * مربوطةً — فيها رابط التاجر — ولا رمزَ يُطبع ولا رابطَ يُرسل.
         */
        $this->save(['google_maps_url' => 'https://www.google.com/maps/place/My+Shop/@23.58,58.38,17z'])
            ->assertSessionHasErrors('google_maps_url');

        $this->assertSame('', MarketingSettings::group($this->business->id, 'google')['google_maps_url']);
        $this->assertNull(GoogleReviews::forBusiness($this->business->id)['place_id']);
    }

    public function test_clearing_the_field_unlinks_it(): void
    {
        $this->save(['google_maps_url' => self::PLACE])->assertSessionHasNoErrors();
        $this->save(['google_maps_url' => ''])->assertSessionHasNoErrors();

        $this->assertNull(GoogleReviews::forBusiness($this->business->id)['place_id']);
    }

    /* ===================== الرمز على الإيصال ===================== */

    public function test_the_receipt_code_needs_the_switch_and_the_id_together(): void
    {
        // مقبضٌ يعمل بلا معرّف يطبع مربّعًا أسود يمسحه الزبون فلا يجد
        $this->save(['google_maps_url' => '', 'google_review_on_receipt' => true]);
        $this->assertNull(GoogleReviews::onReceipt($this->business->id), 'طُبع رمزٌ بلا معرّف');

        $this->save(['google_maps_url' => self::PLACE, 'google_review_on_receipt' => false]);
        $this->assertNull(GoogleReviews::onReceipt($this->business->id), 'طُبع رمزٌ والمقبض مُطفأ');

        $this->save(['google_maps_url' => self::PLACE, 'google_review_on_receipt' => true]);
        $this->assertSame(
            'https://search.google.com/local/writereview?placeid='.self::PLACE,
            GoogleReviews::onReceipt($this->business->id),
        );
    }

    public function test_one_shops_link_never_reaches_another(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Setting::create(['business_id' => $neighbour->id, 'key' => 'google_place_id', 'value' => 'ChIJneighbourneighbour']);

        $this->save(['google_maps_url' => self::PLACE, 'google_review_on_receipt' => true]);

        $this->assertSame(self::PLACE, GoogleReviews::forBusiness($this->business->id)['place_id']);
        $this->assertSame('ChIJneighbourneighbour', GoogleReviews::forBusiness($neighbour->id)['place_id']);
    }

    /* ======================== الشاشة ======================== */

    public function test_the_page_opens_and_carries_its_links(): void
    {
        $this->save(['google_maps_url' => self::PLACE]);

        $this->get(route('admin.marketing.google'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('link.place_id', self::PLACE)
                ->has('link.review_url')
                ->has('internal')
                ->etc());
    }

    public function test_the_reviews_screen_now_leads_into_the_system(): void
    {
        /*
         * زرٌّ اسمُه «ربط» يفتح موقعًا خارجيًّا لا يربط شيئًا: يُخرج التاجر
         * من لوحته ويتركه هناك.
         */
        $source = file_get_contents(resource_path('js/Pages/Admin/Marketing/Reviews.tsx'));

        // رابطًا لا ذِكرًا: الكلمة تَرِد في تعليقٍ يشرح ما كان
        $this->assertStringNotContainsString('href="https://business.google.com', $source, 'الزرّ ما زال يخرج من النظام');
        $this->assertStringContainsString('admin.marketing.google', $source, 'الزرّ لا يقود إلى صفحة الربط');
    }

    public function test_it_is_measured_by_the_marketing_section(): void
    {
        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'موظف', 'email' => 's@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط',
            'permissions' => ['reports'],
        ]);

        $this->actingAs($staff)->get(route('admin.marketing.google'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.marketing.google.save'), ['google_maps_url' => self::PLACE])
            ->assertForbidden();
    }
}
