<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أسطرٌ يقولها الخادم مرّةً فتُغلق أبوابًا لا يُغلقها الكود.
 *
 * وفحصُ الإنتاج أظهر الردود بلا واحدةٍ منها.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_says_do_not_guess_the_content_type(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /** ولا تُوضع شاشة التاجر في إطارٍ في موقعٍ آخر */
    public function test_the_screen_cannot_be_framed_elsewhere(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    /** ولا يُسرَّب مسارُ صفحةٍ داخليّة إلى موقعٍ خارجيّ */
    public function test_the_internal_path_does_not_leak_to_other_sites(): void
    {
        $this->get('/login')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * وHSTS على HTTPS وحده.
     *
     * قولُها على اتصالٍ غير آمن يتجاهله المتصفّح، وفي التطوير المحلّي
     * تحبس المطوّر على https لا خادم له.
     */
    public function test_hsts_is_said_only_over_https(): void
    {
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /**
     * وكلّ صفحات النظام تحملها — لا شاشةٌ خارج الحماية.
     *
     * و`/up` وحدها خارجها: مسارُ صحّةٍ يسجّله الإطار خارج مجموعة `web`
     * فلا يمرّ بوسائطها أصلًا. لا جلسةَ فيه ولا محتوى يُؤطَّر — سطرٌ يقول
     * إنّ التطبيق يعمل.
     */
    public function test_the_signed_in_screens_carry_them_as_well(): void
    {
        $business = \App\Models\Business::create(['name' => 'محل', 'email' => 'h@t.local', 'status' => 'نشط']);
        $owner = \App\Models\User::create([
            'business_id' => $business->id, 'name' => 'مالك', 'email' => 'o@h.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->get(route('admin.products.index'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
