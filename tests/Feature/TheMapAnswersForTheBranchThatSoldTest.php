<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Support\BranchGoogle;
use App\Support\GoogleReviews;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الخريطةُ تُجيب عن الفرع الذي باع — لا عن فرعٍ آخر، ولا بنصفِ خبر.
 *
 * ═══ أربعةُ عيوبٍ وُجدت في فحصٍ، وهذا حارسُها ═══
 *
 * **الأوّل — رمزٌ يُطبع لفرعٍ لم يبِع.** كان الإيصال يقرأ فرعَه، فإن لم
 * يُقرأ — حُذف الفرعُ وبقي رقمُه في طلباته، أو وصل رقمٌ ليس للمتجر — سقط
 * إلى **الفرع الأوّل** صامتًا. فيمسح زبونٌ اشترى من المعبيلة رمزًا فيكتب
 * تقييمًا يُحسب للخوض. وهو عطبٌ لا يراه صاحبُه أبدًا: لا يمسح إيصالاته
 * بنفسه، والعدّان يفسدان معًا.
 *
 * **الثاني — علامةُ تمامٍ على عملٍ لم يتمّ.** خطوةُ «فروعك مربوطة» كانت
 * تكتمل بربط **فرعٍ واحد**. فمتجرٌ بثلاثةٍ يربط أوّلَها فيرى الخطوةَ خضراء
 * ويُغلق الشاشة — وإيصالُ الفرعين الآخرين يخرج بلا رمزٍ إلى الأبد.
 *
 * **الثالث — سببٌ يُبتلع.** زرُّ «حدِّث الآن» كان يقول عن كلّ إخفاق «تعذّر
 * الاتّصال بـ Google». وGoogle تقول ما يُصلَح: فعِّل الواجهة، اربط الفوترة،
 * وسِّع قيدَ المفتاح. فكان التاجر يُعيد الضغط على رفضٍ لا يزول بالتكرار.
 *
 * **الرابع — رقمان لمحلٍّ واحد.** المعدّلُ يُعرض في بطاقة التقييمات من
 * ذاكرةِ ستِّ ساعات، وفي صفّ الفرع من عمودٍ يُزامَن كلَّ اثنتي عشرة. ومصدران
 * بساعتين يفترقان — فيقرأ التاجر رقمين مختلفين عن محلِّه في لحظةٍ واحدة.
 */
class TheMapAnswersForTheBranchThatSoldTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'ChIJN1t_tDeuEmsRUsoyG83frY4';

    private const B = 'ChIJP3Sa8ziYEmsRUKgyFmh9AQM';

    private Business $shop;

    private Branch $khoud;

    private Branch $mawaleh;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Cache::clear();
        Http::preventStrayRequests();

        $this->shop = Business::create([
            'name' => 'ورد أبعاد', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
        ]);
        $this->khoud = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع الخوض']);
        $this->mawaleh = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع المعبيلة']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'map@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /* ═══════════════════ أدوات ═══════════════════ */

    private function platformKey(string $key = 'platform-places-key'): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => GoogleReviews::PLATFORM_KEY],
            ['value' => Crypt::encryptString($key)],
        );
    }

    private function linked(Branch $branch, string $placeId = self::A, ?float $rating = 4.8, int $count = 127): BranchGooglePlace
    {
        return BranchGooglePlace::create([
            'branch_id' => $branch->id,
            'place_id' => $placeId,
            'place_name' => 'ورد أبعاد',
            'maps_url' => 'https://maps.google.com/?cid=111',
            'rating' => $rating,
            'review_count' => $count,
            'synced_at' => now(),
            'linked_at' => now(),
        ]);
    }

    private function onReceipt(): void
    {
        MarketingSettings::save($this->shop->id, 'google', ['google_review_on_receipt' => '1']);
    }

    private function fakeDetails(?float $rating = 4.2, int $count = 300): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => self::A,
            'displayName' => ['text' => 'ورد أبعاد — الخوض'],
            'rating' => $rating,
            'userRatingCount' => $count,
            'googleMapsUri' => 'https://maps.google.com/?cid=111',
        ], 200)]);
    }

    private function review(string $placeId): string
    {
        return 'https://search.google.com/local/writereview?placeid='.$placeId;
    }

    /* ═══════════════════ ١ · الرمز يتبع بائعَه ═══════════════════ */

    /**
     * فرعٌ حُذف وبقي رقمُه في طلباته — لا يُستعار له رمزُ فرعٍ آخر.
     *
     * والفروعُ تُغلق: موسمٌ ينتهي، أو عقدُ محلٍّ لا يُجدَّد. وطلباتُه تبقى،
     * وإيصالاتُها تُعاد طباعتُها للضمان وللمرتجعات.
     */
    public function test_a_receipt_of_a_removed_branch_borrows_no_other_code(): void
    {
        $this->onReceipt();
        $this->linked($this->khoud, self::A);

        $closed = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع أُغلق']);
        $id = $closed->id;
        $closed->delete();

        $this->assertNull(
            GoogleReviews::onReceipt($this->shop->id, $id),
            'طُبع على إيصال فرعٍ محذوفٍ غيرِ مربوطٍ رمزُ فرعٍ آخر',
        );
    }

    /**
     * ورقمُ فرعٍ لا يخصّ هذا المتجر لا يُطبع له شيء.
     *
     * وفرعُ الجارِ **مربوطٌ** في هذا الاختبار قصدًا: بلا ذلك يمرّ الحارسُ
     * ولو سقط حصرُ المتجر، لأنّ فرعًا غيرَ مربوطٍ يردُّ فراغًا من تلقائه.
     * ومع الربط يُقاس ما يُخشى فعلًا — أن يخرج على ورقتنا رمزُ محلٍّ آخر،
     * فيُحسب تقييمُ زبوننا لجارنا.
     */
    public function test_a_foreign_branch_id_prints_nothing(): void
    {
        $this->onReceipt();
        $this->linked($this->khoud, self::A);

        $other = Business::create(['name' => 'محل آخر', 'type' => 'بقالة', 'status' => 'نشط']);
        $theirs = Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);
        $this->linked($theirs, self::B);

        $this->assertNull(
            GoogleReviews::onReceipt($this->shop->id, $theirs->id),
            'خرج على إيصالنا رمزُ فرعِ متجرٍ آخر',
        );
    }

    /**
     * وفرعٌ أُغلق وهو مربوط يحمل رمزَ **نفسِه**.
     *
     * هو موضعُ البيع، وملفُّه على الخرائط قائم. ومنعُ الرمز عنه خسارةُ
     * تقييمٍ بلا سبب — والمقصودُ منعُ **الاستعارة** لا منعُ الحقّ.
     */
    public function test_a_closed_but_linked_branch_keeps_its_own_code(): void
    {
        $this->onReceipt();
        $this->linked($this->khoud, self::A);
        $this->linked($this->mawaleh, self::B);

        $id = $this->mawaleh->id;
        $this->mawaleh->delete();

        $this->assertSame(
            $this->review(self::B),
            GoogleReviews::onReceipt($this->shop->id, $id),
            'فرعٌ أُغلق وهو مربوطٌ فقد رمزَه هو',
        );
    }

    /** وورقةٌ بلا فرعٍ أصلًا تقرأ الفرعَ الأوّل — وهذا مقصود */
    public function test_a_paper_with_no_branch_reads_the_first_one(): void
    {
        $this->onReceipt();
        $this->linked($this->khoud, self::A);

        $this->assertSame(
            $this->review(self::A),
            GoogleReviews::onReceipt($this->shop->id, null),
        );
    }

    /* ═══════════════════ ٢ · لا علامةَ تمامٍ على ناقص ═══════════════════ */

    /** فرعٌ من فرعين مربوطٌ — والخطوةُ ليست تامّة */
    public function test_one_linked_branch_does_not_complete_the_step(): void
    {
        $this->platformKey();
        $this->linked($this->khoud, self::A);

        $step = $this->placeStep();

        $this->assertFalse($step['done'], 'اكتملت خطوةُ الفروع وفرعٌ منها بلا ربط');
    }

    /** وتُسمّي الناقصَ بالاسم — لا «ابحث عن فرعك» لمن له ثلاثة */
    public function test_the_step_names_the_unlinked_branch(): void
    {
        $this->platformKey();
        $this->linked($this->khoud, self::A);

        $this->assertStringContainsString('فرع المعبيلة', (string) $this->placeStep()['fix']);
        $this->assertStringNotContainsString('فرع الخوض', (string) $this->placeStep()['fix']);
    }

    /** وتكتمل حين تُربط كلُّها */
    public function test_the_step_completes_when_every_branch_is_linked(): void
    {
        $this->platformKey();
        $this->linked($this->khoud, self::A);
        $this->linked($this->mawaleh, self::B);

        $this->assertTrue($this->placeStep()['done']);
    }

    /**
     * ومتجرٌ بلا فروعٍ لا تكتمل خطوتُه — ويُقال له ماذا يفعل.
     *
     * «كلُّها مربوطة» عن صفرٍ من صفرٍ صحيحةٌ منطقًا وكاذبةٌ معنًى.
     */
    public function test_a_shop_with_no_branches_is_not_complete(): void
    {
        $this->platformKey();
        Branch::where('business_id', $this->shop->id)->delete();

        $step = $this->placeStep();

        $this->assertFalse($step['done'], 'اكتملت خطوةُ الفروع لمتجرٍ بلا فروع');
        $this->assertStringContainsString('أضِف فرعًا', (string) $step['fix']);
    }

    /** @return array<string, mixed> */
    private function placeStep(): array
    {
        $steps = GoogleReviews::readiness($this->shop->id, ['state' => 'ok', 'error' => null])['steps'];

        foreach ($steps as $step) {
            if ($step['key'] === 'place') {
                return $step;
            }
        }

        $this->fail('لا خطوةَ اسمُها place');
    }

    /* ═══════════════════ ٣ · السببُ يُقال بنصّه ═══════════════════ */

    /**
     * رفضُ Google يصل التاجرَ بحرفه — لا «تعذّر الاتّصال».
     *
     * «٤٠٣» وحدها لا تُصلح شيئًا؛ و«فعِّل Places API واربط الفوترة» تُصلح.
     */
    public function test_googles_own_reason_reaches_the_merchant(): void
    {
        $this->platformKey();
        $place = $this->linked($this->khoud, self::A);
        $place->forceFill(['synced_at' => now()->subDay()])->save();

        Http::fake(['places.googleapis.com/*' => Http::response([
            'error' => ['message' => 'Places API has not been used in project 123'],
        ], 403)]);

        $this->post(route('admin.integrations.google.branch.refresh', $this->khoud->id))
            ->assertRedirect()
            ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'danger'
                && str_contains((string) $toast['msg'], 'Places API'));
    }

    /** وغيابُ المفتاح يُقال غيابًا لا عطلَ شبكة */
    public function test_a_missing_key_is_not_reported_as_a_network_failure(): void
    {
        Http::fake();
        $this->linked($this->khoud, self::A);

        $out = BranchGoogle::sync(BranchGoogle::for($this->khoud), force: true);

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('غير مفعلة', (string) $out['error']);
        Http::assertNothingSent();
    }

    /**
     * ومزامنةُ ربطٍ حديثٍ ليست إخفاقًا.
     *
     * كانت تردُّ `false` كما يردُّها الرفض، فيقول الزرُّ «تعذّر الاتّصال» عن
     * صفٍّ سليمٍ لا شيء فيه.
     */
    public function test_a_fresh_row_is_a_success_that_wrote_nothing(): void
    {
        Http::fake();
        $this->platformKey();
        $this->linked($this->khoud, self::A);

        $out = BranchGoogle::sync(BranchGoogle::for($this->khoud));

        $this->assertTrue($out['ok'], 'عُدّ ربطٌ حديثٌ إخفاقًا');
        $this->assertFalse($out['wrote']);
        $this->assertNull($out['error']);
        Http::assertNothingSent();
    }

    /* ═══════════════════ ٤ · رقمٌ واحدٌ لا رقمان ═══════════════════ */

    /**
     * ما سحبته بطاقةُ التقييمات يُكتب على صفّ الفرع.
     *
     * فالرقمُ المعروض فوق هو الرقمُ المعروض تحت — ونداءٌ دُفع ثمنُه مرّةً
     * يُكتب مرّة.
     */
    public function test_the_pulled_rating_and_the_row_agree(): void
    {
        $this->platformKey();
        $place = $this->linked($this->khoud, self::A, rating: 4.8, count: 127);
        $this->fakeDetails(rating: 4.2, count: 300);

        $pulled = GoogleReviews::pull($this->shop->id);

        $this->assertSame('ok', $pulled['state']);
        $this->assertSame(4.2, $pulled['place']['rating']);
        $this->assertSame(4.2, (float) $place->fresh()->rating, 'بطاقةُ التقييمات تقول رقمًا وصفُّ الفرع يقول آخر');
        $this->assertSame(300, (int) $place->fresh()->review_count);
    }

    /**
     * ونداءٌ واحدٌ لا نداءان.
     *
     * السحبُ يجعل `synced_at` حديثة، فمزامنةُ الشاشة تتخطّاه — ولولا ذلك
     * لَخرج نداءان مدفوعان عن المحلّ نفسِه في فتحةٍ واحدة.
     */
    public function test_the_screen_does_not_call_google_twice_for_one_place(): void
    {
        $this->platformKey();
        $place = $this->linked($this->khoud, self::A);
        $place->forceFill(['synced_at' => now()->subDay()])->save();
        $this->fakeDetails();

        GoogleReviews::pull($this->shop->id);
        BranchGoogle::branches($this->shop->id);

        Http::assertSentCount(1);
    }
}
