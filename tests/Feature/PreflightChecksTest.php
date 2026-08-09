<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فحصان كانا يكذبان على خادم الإنتاج.
 *
 * الأول تحذيرٌ ثابت: «المجدول لا يعمل من نفسه» يُطبع دائمًا، والمجدول يعمل
 * فعلًا من /etc/cron.d/abaad. تحذيرٌ كاذب يتكرّر يُدرَّب الناظر على تخطّيه.
 *
 * والثاني أسوأ: «✓ عامل الطابور يسحب المهام» على خادمٍ لا عامل فيه إطلاقًا —
 * مرّ لأن الطابور فارغ. وطابورٌ فارغ ليس دليلًا على أن أحدًا يسحب منه، بل
 * على أن شيئًا لم يُصفَّ بعد. طمأنينةٌ كاذبة تُنيم، والتحذير الكاذب يُزعج
 * فقط.
 */
class PreflightChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_queue_check_no_longer_passes_on_an_empty_queue_alone(): void
    {
        /*
         * لا عامل يعمل في بيئة الاختبار، والطابور فارغ. الصياغة القديمة كانت
         * تطبع ✓ هنا؛ الجديدة يجب أن تفصل بين الأمرين.
         */
        config(['queue.default' => 'database']);

        $code = \Illuminate\Support\Facades\Artisan::call('abaad:preflight');
        $out = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('لا مهام عالقة في الطابور', $out);

        // إمّا نفيٌ صريح أو تعذّر فحص — لكن ليس ✓ صامتة
        $this->assertStringContainsString('عاملٌ دائم', $out);
        $this->assertStringNotContainsString('✓ عاملٌ دائم', $out);
    }

    public function test_the_scheduler_line_is_looked_for_not_assumed_missing(): void
    {
        // لا cron في بيئة الاختبار، فالمتوقّع تحذيرٌ يقول «لم أجد» لا جزمٌ بالغياب
        \Illuminate\Support\Facades\Artisan::call('abaad:preflight');
        $out = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('لم أجد سطر المجدول', $out);
        $this->assertStringNotContainsString('المجدول لا يعمل من نفسه', $out);
    }

    public function test_a_blocker_still_fails_the_command(): void
    {
        // الغرض من الأمر أن يصلح لخطّ نشر آليّ: مانعٌ واحد ⇒ خروجٌ بـ1
        config(['app.env' => 'local']);

        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('abaad:preflight'));
    }
}
