<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نقطة الصحّة — ما يقرؤه المراقب الخارجي.
 *
 * وأهمّ ما فيها ليس أنها تردّ 200 وهي بخير، بل أنها **لا تردّ 200 وهي ليست
 * كذلك**: مراقبٌ يُطمئن دائمًا لا يختلف عن غياب المراقبة، بل أسوأ — يُنيم.
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_healthy_system_answers_200(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertJson(['ok' => true, 'db' => true, 'storage' => true, 'cache' => true]);
    }

    public function test_it_needs_no_login(): void
    {
        // من يراقب لا يملك حسابًا، والعطب المقصود يمنع الدخول أصلًا
        $this->get('/health')->assertOk();
    }

    public function test_a_broken_component_answers_503_not_200(): void
    {
        /*
         * هذا هو سبب وجود النقطة: nginx وphp قد يعملان وجزءٌ يقف عليه البيع
         * ساقطًا — قرصٌ ممتلئ، قاعدةٌ لا تستجيب — فتردّ الصفحة 200 ويبقى
         * المتجر معطّلًا بلا أن يعرف أحد.
         *
         * ويُكسر التخزين لا القاعدة: قطعُ الاتصال الافتراضي يكسر معاملة
         * RefreshDatabase نفسها، فيصير الاختبار يقيس الإطار لا النقطة.
         */
        $this->app->useStoragePath('/dev/null/لا-يوجد');

        $res = $this->get('/health');

        $this->assertSame(503, $res->status());
        $res->assertJson(['ok' => false, 'storage' => false, 'db' => true]);
    }

    public function test_it_leaks_nothing_about_the_system(): void
    {
        // تُقرأ من الإنترنت بلا مصادقة: تقول حيّ أو ميت، ولا تقول بماذا يعمل
        $body = $this->get('/health')->getContent();

        foreach (['laravel', 'php', base_path()] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase((string) $secret, $body);
        }
    }
}
