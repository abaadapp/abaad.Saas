<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * لا يُنشر رأسٌ لم تفحصه البوّابة.
 *
 * ═══ العطب ═══
 *
 * «Deploy Main» تنطلق حين تكتمل CI، وتسحب رأسَ main كما هو ساعتَها. فدفعتان
 * متتاليتان: تكتمل CI للأولى، تنطلق النشرةُ، فتسحب **الثانية** — التي لم
 * تُفحص بعد — وتضعها على الإنتاج. وقع فعلًا (36f7b6f2 نُشرت قبل CI).
 *
 * ═══ وما يُحرس هنا ═══
 *
 * الملفُّ YAML على GitHub ولا يُشغَّل محلّيًّا، فيُقرأ نصًّا: خطوةُ
 * المقارنة موجودةٌ وتقارن رأسَ main بما فحصته CI وترفع `SKIP` عند
 * الاختلاف، وكلُّ خطوةٍ بعدها — وأوّلُها مفتاحُ النشر — مشروطةٌ بألّا يكون
 * `SKIP` مرفوعًا. خطوةٌ واحدة بلا الشرط تنشر ما لم يُفحص.
 */
class TheGateDeploysOnlyWhatItCheckedTest extends TestCase
{
    private function workflow(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/deploy-main.yml'));
    }

    public function test_the_gate_compares_the_head_of_main_with_what_ci_checked(): void
    {
        $yml = $this->workflow();

        $gate = strpos($yml, 'رأسُ main هو ما فحصته CI؟');
        $this->assertNotFalse($gate, 'خطوةُ المقارنة غائبة');

        $body = substr($yml, $gate, strpos($yml, "\n      - name:", $gate + 1) - $gate);
        $this->assertStringContainsString('github.event.workflow_run.head_sha', $body, 'لا تقرأ ما فحصته CI');
        $this->assertStringContainsString('git rev-parse origin/main', $body, 'لا تقرأ رأسَ main');
        $this->assertMatchesRegularExpression('/if \[ "\$HEAD" != "\$CHECKED" \]/', $body, 'لا تقارنهما');
        $this->assertStringContainsString('echo "SKIP=1" >> "$GITHUB_ENV"', $body, 'لا ترفع SKIP عند الاختلاف');
    }

    public function test_every_step_after_the_gate_is_skipped_when_the_head_differs(): void
    {
        $yml = $this->workflow();
        // ما بعد خطوة المقارنة — سطرُها نفسُه يُقصّ فلا يُعدّ خطوة
        $after = substr($yml, strpos($yml, 'رأسُ main هو ما فحصته CI؟'));

        preg_match_all('/^      - name: (.+)\n((?:        .*\n)*)/m', $after, $steps, PREG_SET_ORDER);

        $this->assertGreaterThanOrEqual(4, count($steps), 'خطواتُ النشر لم تُقرأ');

        foreach ($steps as [$all, $name, $body]) {
            // والشرطُ SKIP وحده، أو بعد دالّة حالٍ بـ&& (ملخّصُ السقوط يجري بعد خطوةٍ سقطت) — لا بـ||
            $this->assertMatchesRegularExpression(
                "/^        if: (?:(?:failure|always)\\(\\) && )?env\\.SKIP != '1'\$/m", $body,
                "الخطوة «{$name}» تعمل ولو اختلف الرأسُ عمّا فُحص",
            );
        }

        $names = array_column($steps, 1);
        $this->assertContains('تهيئة مفتاح النشر', $names);
        $this->assertContains('النشر', $names);
        $this->assertContains('الوسم', $names);
        // وإن سقط شيء: حالُ الخادم في ملخّص التشغيل — من العلامتين اللتين يطبعهما deploy.sh
        $this->assertContains('حال الخادم بعد السقوط', $names);
        // ولا يجري إلّا بعد سقوط — ولا يجري إن اختلف الرأس
        $summary = array_values(array_filter($steps, fn ($s) => $s[1] === 'حال الخادم بعد السقوط'))[0][2];
        $this->assertMatchesRegularExpression("/^        if: failure\\(\\) && env\\.SKIP != '1'\$/m", $summary);
        $deploy = (string) file_get_contents(base_path('scripts/deploy.sh'));
        foreach (['=== حال الخادم بعد سقوط النشر ===', '=== نهاية حال الخادم ==='] as $marker) {
            $this->assertStringContainsString($marker, $yml, "ملخّصُ السقوط لا يقرأ العلامة: $marker");
            $this->assertStringContainsString("printf '".$marker."\\n'", $deploy, "deploy.sh لا يطبع العلامة: $marker");
        }
        $this->assertStringContainsString('GITHUB_STEP_SUMMARY', $yml);
    }
}
