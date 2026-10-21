<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StorePaymentIntent;
use App\Models\StoreTermsVersion;
use App\Support\Store\StoreTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * الطلبُ يحمل الشروطَ التي وافق عليها صاحبُه — نسختَها ووقتَها ولغتَها وبصمتَها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - صاحبُ المتجر وحده يكتب الشروط، بلغتين، ولا تُشترط إلّا مكتوبتين.
 *   - لا طلبَ ولا صفحةَ دفعٍ بلا موافقةٍ صريحة — في الدفع عند الاستلام والبطاقة معًا،
 *     ولو أُرسل الطلبُ بيده. وموافقةٌ على نسخةٍ قديمة تُردّ ومعها النصُّ الجديد.
 *   - كلُّ تعديلٍ نسخةٌ جديدة، والقديمةُ لا تُعدَّل ولا تُحذف.
 *   - الصفحةُ تعرض شروطَ لغتها وحدها، وخانةُ الموافقة غيرُ محدّدة.
 *   - ومتجرٌ لم يفتحها لا يُسأل زبونُه عن شيء.
 *
 * وشروطٌ تبدّلت والزبونُ عند البنك: `APaymentStartedBeforeClosingBecomesItsOrderTest`.
 */
class AnOrderCarriesTheTermsItsBuyerAcceptedTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    private const AR = "١. يُسلَّم الطلبُ في موعده.\n٢. لا يُستردّ الوردُ بعد تسليمه.";

    private const EN = "1. Orders are delivered on time.\n2. Flowers are not refundable once delivered.";

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->openRibbon();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function requireTerms(string $ar = self::AR, string $en = self::EN): StoreTermsVersion
    {
        $this->settings([StoreTerms::ON => '1']);

        return StoreTerms::revise($this->shop->id, $ar, $en, null);
    }

    private function agreeing(StoreTermsVersion $v, array $over = []): array
    {
        return $over + [StoreTerms::ACCEPT => true, StoreTerms::VERSION => $v->id];
    }

    private function save(array $data)
    {
        return $this->actingAs($this->owner)->from(route('admin.website.shop'))->post(route('admin.marketing.store.save'), $data);
    }

    /* ═════════════ ما كان يبقى كما كان ═════════════ */

    public function test_a_shop_without_terms_asks_its_buyers_nothing(): void
    {
        $this->checkout()->assertOk();

        $order = Order::firstOrFail();
        $this->assertNull($order->terms_version_id);
        $this->assertNull($order->terms_accepted_at);
        $this->assertStringNotContainsString('data-testid="rb-terms"', $this->get('/s/ribbon/checkout')->getContent());
    }

    /* ═════════════ لا طلبَ بلا موافقة ═════════════ */

    public function test_no_order_is_written_without_an_explicit_agreement(): void
    {
        $v = $this->requireTerms();

        $this->checkout()->assertStatus(422)->assertJsonValidationErrors(StoreTerms::FIELD);
        $this->checkout([StoreTerms::ACCEPT => false, StoreTerms::VERSION => $v->id])->assertStatus(422);
        // و«yes» و«on» ليستا موافقة — الخانةُ ترسل `true`
        $this->checkout([StoreTerms::ACCEPT => 'yes', StoreTerms::VERSION => $v->id])->assertStatus(422);
        $this->checkout([StoreTerms::ACCEPT => 'on', StoreTerms::VERSION => $v->id])->assertStatus(422);

        $this->assertSame(0, Order::count(), 'كُتب طلبٌ بلا موافقةٍ على الشروط');
    }

    public function test_no_payment_page_opens_without_an_agreement(): void
    {
        $this->requireTerms();
        $this->bankOpens();

        $this->checkout(['pay' => 'card'])->assertStatus(422)->assertJsonValidationErrors(StoreTerms::FIELD);

        Http::assertNothingSent();
        $this->assertSame(0, StorePaymentIntent::count());
    }

    public function test_an_old_version_is_refused_and_the_new_text_handed_back_in_the_pages_language(): void
    {
        $old = $this->requireTerms();
        $new = StoreTerms::revise($this->shop->id, 'نصٌّ جديد.', 'New text.', null);

        $ar = $this->checkout($this->agreeing($old));
        $ar->assertStatus(422)->assertJsonValidationErrors(StoreTerms::FIELD);
        $this->assertSame(['id' => (int) $new->id, 'version' => 2, 'text' => 'نصٌّ جديد.'], $ar->json('terms'));

        $en = $this->checkout($this->agreeing($old), 'en');
        $this->assertSame('New text.', $en->json('terms.text'), 'أُعطيت الصفحةُ الإنجليزيّة نصًّا عربيًّا');

        // ونسخةُ متجرٍ آخر لا تُقبل موافقةً على هذا المتجر
        $this->checkout([StoreTerms::ACCEPT => true, StoreTerms::VERSION => $new->id + 999])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    /* ═════════════ وما يُحفظ مع الطلب ═════════════ */

    public function test_the_version_time_language_and_print_of_the_agreement_are_kept(): void
    {
        $v = $this->requireTerms();

        $this->checkout($this->agreeing($v))->assertOk();
        Carbon::setTestNow('2027-02-10 10:30:00');
        $this->checkout($this->agreeing($v, ['phone' => '96899220002', 'name' => 'Salem']), 'en')->assertOk();

        [$ar, $en] = Order::orderBy('id')->get()->all();
        $this->assertSame((int) $v->id, (int) $ar->terms_version_id);
        $this->assertSame('ar', $ar->terms_lang);
        $this->assertSame('2027-02-10 10:00:00', $ar->terms_accepted_at->toDateTimeString());
        $this->assertSame(hash('sha256', self::AR), $ar->terms_hash);

        $this->assertSame('en', $en->terms_lang);
        $this->assertSame(hash('sha256', self::EN), $en->terms_hash, 'بصمةُ الإنجليزيّة ليست بصمةَ ما رآه');
        $this->assertSame('2027-02-10 10:30:00', $en->terms_accepted_at->toDateTimeString());
    }

    public function test_a_stamp_sent_by_the_browser_is_never_written(): void
    {
        // متجرٌ بلا شروط، وحمولةٌ تدّعي موافقةً ولقطةَ خادم
        $other = StoreTerms::revise($this->shop->id, 'نصّ', 'Text', null);
        $this->checkout(['_terms' => ['id' => $other->id, 'at' => '2020-01-01T00:00:00Z', 'lang' => 'ar']])->assertOk();

        $this->assertNull(Order::firstOrFail()->terms_version_id, 'كُتبت على الطلب موافقةٌ أرسلها المتصفّح');
    }

    public function test_the_payment_intent_keeps_no_key_the_browser_wrote_for_the_server(): void
    {
        // ما يبدأ بـ`_` يكتبه الخادم في الحمولة قبل البنك، ويقرؤه حين تُسوّى الدفعةُ بلا فحصٍ ثانٍ
        $this->bankOpens();

        $this->checkout(['pay' => 'card', '_terms' => ['id' => 1], '_slot' => ['id' => 'x'], '_paid' => true])->assertOk();

        $payload = StorePaymentIntent::firstOrFail()->payload;
        $this->assertArrayNotHasKey('_paid', $payload, 'حُفظ في نيّة الدفع مفتاحٌ كتبه المتصفّح');
        $this->assertNull($payload[StoreTerms::STAMP] ?? null);
        $this->assertNull($payload['_slot'] ?? null);
    }

    /* ═════════════ والنسخ ═════════════ */

    public function test_rewriting_the_terms_writes_a_new_version_and_keeps_the_old_one_untouched(): void
    {
        $v1 = $this->requireTerms();
        $this->checkout($this->agreeing($v1))->assertOk();

        $this->save([StoreTerms::TEXT => self::AR."\n٣. بندٌ جديد.", StoreTerms::TEXT_EN => self::EN])->assertSessionHasNoErrors();
        // وحفظٌ لم يغيّر النصَّ لا يرفع الإصدار
        $this->save([StoreTerms::TEXT => self::AR."\n٣. بندٌ جديد.", StoreTerms::TEXT_EN => self::EN])->assertSessionHasNoErrors();

        $this->assertSame(2, StoreTermsVersion::where('business_id', $this->shop->id)->count());
        $this->assertSame(2, StoreTerms::current($this->shop->id)->version);
        $this->assertSame(self::AR, $v1->fresh()->body_ar, 'عُدّلت نسخةٌ وافق عليها زبون');
        $this->assertSame((int) $v1->id, (int) Order::firstOrFail()->terms_version_id);

        $this->expectException(\LogicException::class);
        $v1->fresh()->update(['body_ar' => 'محرَّف']);
    }

    public function test_terms_cannot_be_required_without_both_texts(): void
    {
        $this->save([StoreTerms::ON => true, StoreTerms::TEXT => self::AR, StoreTerms::TEXT_EN => ''])
            ->assertSessionHasErrors(StoreTerms::TEXT_EN);
        $this->save([StoreTerms::ON => true, StoreTerms::TEXT => '', StoreTerms::TEXT_EN => self::EN])
            ->assertSessionHasErrors(StoreTerms::TEXT);

        $this->assertFalse(StoreTerms::required($this->shop->id));
        $this->checkout()->assertOk();

        $this->save([StoreTerms::ON => true, StoreTerms::TEXT => self::AR, StoreTerms::TEXT_EN => self::EN])->assertSessionHasNoErrors();
        $this->assertTrue(StoreTerms::required($this->shop->id), 'الشروطُ تسري عند الحفظ لا عند النشر');
    }

    /* ═════════════ والصفحةُ بلغتها ═════════════ */

    public function test_the_checkout_shows_the_terms_of_its_language_only_and_leaves_the_box_unticked(): void
    {
        $v = $this->requireTerms();

        $ar = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('يُسلَّم الطلبُ في موعده', $ar);
        $this->assertStringNotContainsString('Orders are delivered on time', $ar, 'وقعت الشروطُ الإنجليزيّة على الصفحة العربيّة');
        $this->assertStringContainsString('قرأت الشروط والأحكام وأوافق عليها.', $ar);
        $this->assertStringContainsString('data-version="'.$v->id.'"', $ar);
        $this->assertSame(1, preg_match('/<input[^>]*data-rb-terms-accept[^>]*>/', $ar, $box), 'لا خانةَ موافقة');
        $this->assertStringNotContainsString('checked', $box[0], 'خانةُ الموافقة محدّدةٌ قبل أن يضغطها الزبون');

        $en = $this->get('/s/ribbon/checkout?lang=en')->assertOk()->getContent();
        $this->assertStringContainsString('Orders are delivered on time', $en);
        $this->assertStringNotContainsString('يُسلَّم الطلبُ في موعده', $en, 'وقعت الشروطُ العربيّة على الصفحة الإنجليزيّة');
        $this->assertStringContainsString('I have read and agree to the Terms &amp; Conditions.', $en);
    }

    public function test_the_order_page_shows_what_the_buyer_agreed_to(): void
    {
        $v = $this->requireTerms();
        $this->checkout($this->agreeing($v), 'en')->assertOk();

        $terms = $this->actingAs($this->owner)
            ->get(route('admin.orders.show', Order::firstOrFail()->number))
            ->assertOk()
            ->viewData('page')['props']['order']['terms'];

        $this->assertSame(1, $terms['version']);
        $this->assertSame('en', $terms['lang']);
        $this->assertSame(self::EN, $terms['text']);
        $this->assertSame(hash('sha256', self::EN), $terms['hash']);
    }
}
