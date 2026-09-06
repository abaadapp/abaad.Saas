<?php

namespace Tests\Feature;

use App\Support\DomainOptions;
use App\Support\Storefront;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قائمتان لسؤالٍ واحد: من أين يأتي عنوان المتجر؟
 *
 * كانت `DomainOptions::MODES` تقول `['own', 'subdomain', 'new']` وتقرأ
 * `site_domain_mode`، و`Storefront::PATHS` تقول `['sub', 'own', 'new']`
 * وتقرأ `site_path`. الطريق نفسه باسمين — `subdomain` و`sub` — ومفتاحين.
 *
 * ولم يكن الفرقُ نظريًّا: شاشةُ الإعدادات تكتب `site_path`، ولا شيء يكتب
 * `site_domain_mode` إلا هجرةٌ قديمة. فما تقرؤه `DomainOptions::mode` ليس
 * ما اختاره التاجر اليوم.
 *
 * فبقيت واحدة. وهذا الحارس يمنع عودةَ الثانية: `Storefront::PATHS` وحدها
 * تسمّي الطرق، و`DomainOptions` لا تحمل قائمةً منافسة.
 */
class OneListForTheAddressPathsTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_owns_the_only_list_of_paths(): void
    {
        $this->assertSame(['sub', 'own', 'new'], Storefront::PATHS);
    }

    /** ولا قائمةَ ثانية تسمّي الطرق في موضعٍ آخر */
    public function test_domain_options_carries_no_rival_list(): void
    {
        foreach (['MODES', 'OWN', 'SUBDOMAIN', 'SERVICE'] as $name) {
            $this->assertFalse(
                defined(DomainOptions::class.'::'.$name),
                "DomainOptions::{$name} قائمةٌ ثانية لطرق العنوان — الطرق في Storefront::PATHS",
            );
        }
    }

    /** ولا تقرأ `DomainOptions` المفتاحَ الميّت — الطريق يُقرأ من `site_path` وحده */
    public function test_the_dead_key_is_not_read_here(): void
    {
        $code = file_get_contents(app_path('Support/DomainOptions.php'));

        // ما بعد التعليق وحده: ذِكرُ المفتاح في شرحٍ ليس قراءةً له
        $body = substr($code, strpos($code, 'class DomainOptions'));

        $this->assertStringNotContainsString('site_domain_mode', $body);
        $this->assertStringNotContainsString('site_path', $body);
    }

    /** وما بقي من `DomainOptions` يُقرأ فعلًا */
    public function test_what_survives_is_read(): void
    {
        $this->assertSame('abaadapp.om', DomainOptions::DEFAULT_SUFFIX);
        $this->assertSame('my-store.abaadapp.om', DomainOptions::host('my-store'));
    }
}
