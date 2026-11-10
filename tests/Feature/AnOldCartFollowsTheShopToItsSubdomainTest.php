<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سلّةُ الزبون على العنوان القديم تلحق المتجرَ إلى نطاقه — مرّةً، وبلا ثغرة.
 *
 * السلّةُ في المتصفّح وتخصّ عنوانَها، فتُقرأ من `app.abaadapp.om` بصفحةٍ مخفيّة
 * يؤطّرها نطاقُ المتجر (`StoreOldCartBridgeController`). وما يُحرَس:
 *
 *   - الصفحةُ لمتجرٍ نُقل وحده، وعلى عنوان النظام وحده — وما سوى ذلك 404.
 *   - يؤطّرها نطاقُ ذلك المتجر وحده (`frame-ancestors`)، وترسل إليه وحده.
 *   - وكلُّ صفحةٍ أخرى تبقى محميّةً من التأطير كما كانت (`X-Frame-Options`).
 *   - ولا يطلبها إلّا نطاقُ المتجر المنقول؛ متجرٌ آخر لا يرى منها شيئًا.
 *
 * وسلوكُ المتصفّح نفسُه (القراءةُ والإرسالُ والدمجُ) يُجرَّب في متصفّحٍ حقيقيّ
 * خارج هذه الاختبارات — هنا ما يقوله الخادم.
 */
class AnOldCartFollowsTheShopToItsSubdomainTest extends TestCase
{
    use RefreshDatabase;

    private Business $ribbon;

    private Business $other;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        config(['storefront.subdomains' => false, 'storefront.custom_domains' => false, 'app.url' => 'https://app.abaadapp.om']);

        // معرّفاتٌ صريحة: تسلسلُ PostgreSQL لا يرجع بين الاختبارات فيقع أحدُها على ٥
        $this->ribbon = $this->shop('RIBBON', 'ribbon', id: 301);
        $this->other = $this->shop('BLOOM', 'bloom', id: 302);

        config(['storefront.subdomain_businesses' => [$this->ribbon->id => 'ribbon']]);
    }

    private function shop(string $name, string $slug, int $id): Business
    {
        $shop = (new Business)->forceFill([
            'id' => $id, 'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96890000000',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        $shop->save();
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        return $shop;
    }

    public function test_the_bridge_reads_only_for_the_moved_shop_and_posts_only_to_its_domain(): void
    {
        $res = $this->get('https://app.abaadapp.om/storefront/cart-bridge/'.$this->ribbon->id)->assertOk();

        $this->assertSame('frame-ancestors https://ribbon.abaadapp.om', $res->headers->get('Content-Security-Policy'));
        $this->assertFalse($res->headers->has('X-Frame-Options'), 'SAMEORIGIN يمنع النطاقَ الذي أُذن له');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $res->headers->get('X-Robots-Tag'));

        $html = (string) $res->getContent();
        $this->assertStringContainsString('"ribbon-cart:'.$this->ribbon->id.'"', $html);
        $this->assertStringContainsString('postMessage', $html);
        $this->assertStringContainsString(json_encode('https://ribbon.abaadapp.om'), $html);
        $this->assertStringNotContainsString("'*'", $html);
        $this->assertStringNotContainsString('"*"', $html);
        // تقرأ ولا تكتب ولا تمحو
        $this->assertStringNotContainsString('setItem', $html);
        $this->assertStringNotContainsString('removeItem', $html);
    }

    public function test_the_bridge_is_not_found_for_anyone_else_or_anywhere_else(): void
    {
        // متجرٌ لم يُنقل، ومعرّفٌ لا وجود له
        $this->get('https://app.abaadapp.om/storefront/cart-bridge/'.$this->other->id)->assertNotFound();
        $this->get('https://app.abaadapp.om/storefront/cart-bridge/'.($this->other->id + 1000))->assertNotFound();

        // وعلى غير عنوان النظام
        $this->get('https://ribbon.abaadapp.om/storefront/cart-bridge/'.$this->ribbon->id)->assertNotFound();

        // والقائمةُ الفارغةُ تُطفئه
        config(['storefront.subdomain_businesses' => []]);
        $this->get('https://app.abaadapp.om/storefront/cart-bridge/'.$this->ribbon->id)->assertNotFound();
    }

    public function test_every_other_page_keeps_its_frame_guard(): void
    {
        foreach ([
            'https://app.abaadapp.om/login',
            'https://app.abaadapp.om/s/bloom',
            'https://ribbon.abaadapp.om/',
            'https://ribbon.abaadapp.om/cart',
        ] as $url) {
            $res = $this->get($url)->assertOk();
            $this->assertSame('SAMEORIGIN', $res->headers->get('X-Frame-Options'), $url);
            $this->assertFalse($res->headers->has('Content-Security-Policy'), $url);
        }
    }

    public function test_only_the_moved_shops_domain_asks_for_the_old_cart(): void
    {
        $html = (string) $this->get('https://ribbon.abaadapp.om/cart')->assertOk()->getContent();
        $this->assertStringContainsString(json_encode('https://app.abaadapp.om/storefront/cart-bridge/'.$this->ribbon->id), $html);
        $this->assertStringContainsString(json_encode('https://app.abaadapp.om'), $html);
        $this->assertStringContainsString('ribbon-cart:'.$this->ribbon->id.':moved', $html);

        // متجرٌ آخر — على بابه البديل ولا على نطاقه — لا يطلب شيئًا
        $this->assertStringNotContainsString('cart-bridge', (string) $this->get('https://app.abaadapp.om/s/bloom/cart')->getContent());
        $this->assertStringNotContainsString('cart-bridge', (string) $this->get('https://bloom.abaadapp.om/cart')->getContent());

        // وبلا نقل: متجرُ سعود على بابه كما كان
        config(['storefront.subdomain_businesses' => []]);
        $this->assertStringNotContainsString('cart-bridge', (string) $this->get('https://app.abaadapp.om/s/ribbon/cart')->assertOk()->getContent());
    }

    /** ورقمٌ لا يصلح معرّفًا يُردّ 404 — لا خطأَ خادمٍ ولا سطرَ خطأ في السجلّ */
    public function test_a_number_that_is_not_an_id_is_not_found_never_a_server_error(): void
    {
        foreach (['99999999999999999999', '9223372036854775808', '12345678901', '0', '007', '-5', '5.0', 'abc', '5%00'] as $id) {
            $this->get('https://app.abaadapp.om/storefront/cart-bridge/'.$id)->assertNotFound();
        }
    }

    /**
     * ولعدّاد الجسر اسمُه: زياراتُه لا تُحسب على إتمام الطلب لبقية المتاجر.
     *
     * زبائنُ كثيرون خلف عنوان شبكةٍ واحد (شبكةُ الجوّال) يفتحون نطاقَ سعود فيطلب
     * كلٌّ منهم الجسرَ مرّة — ولا يقترب أحدُهم بذلك من حدّ إتمام الطلب (عشرٌ في
     * الدقيقة) في متجرٍ آخر.
     */
    public function test_the_bridge_counts_on_its_own_and_never_on_other_shops_checkout(): void
    {
        foreach (range(1, 15) as $_) {
            $this->get('https://app.abaadapp.om/storefront/cart-bridge/'.$this->ribbon->id)->assertOk();
        }

        $this->assertNotSame(429, $this->postJson('https://app.abaadapp.om/s/bloom/checkout', [])->getStatusCode());
        $this->assertNotSame(429, $this->postJson('https://app.abaadapp.om/s/bloom/quote', [])->getStatusCode());

        // وحدُّه هو باقٍ: ستّون في الدقيقة
        foreach (range(16, 60) as $_) {
            $this->get('https://app.abaadapp.om/storefront/cart-bridge/'.$this->ribbon->id)->assertOk();
        }
        $this->get('https://app.abaadapp.om/storefront/cart-bridge/'.$this->ribbon->id)->assertStatus(429);

        // وعدّادُه لا يأخذ من إتمام الطلب شيئًا ولو امتلأ
        $this->assertNotSame(429, $this->postJson('https://app.abaadapp.om/s/bloom/checkout', [])->getStatusCode());
    }

    /** وغيّر سعودٌ اسمَه: يبقى نطاقُه وجسرُه — والسلّةُ القديمة تلحقه كما كانت */
    public function test_a_renamed_shop_keeps_its_domain_and_its_bridge(): void
    {
        $this->ribbon->forceFill(['site_slug' => 'ribbon-flowers'])->save();

        $res = $this->get('https://app.abaadapp.om/storefront/cart-bridge/'.$this->ribbon->id)->assertOk();
        $this->assertSame('frame-ancestors https://ribbon.abaadapp.om', $res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString(
            json_encode('https://app.abaadapp.om/storefront/cart-bridge/'.$this->ribbon->id),
            (string) $this->get('https://ribbon.abaadapp.om/cart')->assertOk()->getContent(),
        );
    }
}
