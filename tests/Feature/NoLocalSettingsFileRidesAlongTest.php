<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * إعداداتُ الجهاز لا تخرج مع الكود — ولا نسخةٌ منها.
 *
 * ═══ ما وقع ═══
 *
 * `.gitignore` يستبعد `.claude/settings.local.json` — إعداداتُ جهازِ
 * المطوّر وحده. لكنّ الاستبعاد **يطابق الاسمَ حرفًا بحرف**، وسكربتُ الدفع
 * التلقائيّ كان ينسخ الملفَّ قبل أن يبدّله باسمٍ فيه لاحقةٌ زائدة:
 *
 *     .claude/settings.local.json.autopush-backup
 *
 * فلم تنطبق عليه القاعدة. ونسخةُ السكربت الأولى كانت تُودع بـ`git add -A`،
 * فالتقطته ودفعته إلى مستودعٍ عامّ في الإيداع 28b0a314 (2026-09-01) تحت
 * رسالةٍ آليّة: «تحديث تلقائي». ولم يكن فيه سرٌّ — خطّافُ دفعٍ فحسب.
 *
 * ═══ ولمَ حارسٌ على هذا ═══
 *
 * البابُ لم يُكسر: **بُني بجانبه بابٌ ثانٍ بلا قفل**. ولاحقةٌ أخرى —
 * `.bak`، `.orig`، `.save`، نسخةُ محرّرٍ — تفتحه ثانيةً. والمرّةُ القادمة
 * قد يكون في الملفّ مفتاحٌ لا خطّاف.
 *
 * وهذا أرخصُ موضعٍ للقفل: سطرٌ يقرأ ما يتتبّعه git فعلًا، لا ما نظنّ أنّنا
 * استبعدناه.
 */
class NoLocalSettingsFileRidesAlongTest extends TestCase
{
    /** ما يتتبّعه المستودعُ فعلًا — لا ما في `.gitignore` */
    private function trackedFiles(): array
    {
        $root = base_path();
        exec('git -C '.escapeshellarg($root).' ls-files 2>/dev/null', $out, $code);

        /*
         * ولا يُتخطّى إلّا حيث لا مستودعَ أصلًا (نسخةٌ تُفكّ من أرشيف).
         *
         * وحيث يوجد المستودعُ ويصمت الأمر، فالقياسُ هو المعطوب لا الشجرة —
         * فيُصرخ به ولا يُسكَت عنه. وفحصٌ يخرس عند عطبه لا يحرس شيئًا.
         *
         * و`.git` ملفٌّ لا مجلّد في شجرة عملٍ منفصلة — فيُسأل عن وجوده لا
         * عن نوعه.
         */
        if (! file_exists($root.'/.git')) {
            $this->markTestSkipped('لا مستودعَ هنا — لا شيء يُقاس');
        }

        $this->assertSame(0, $code, 'تعذّرت قراءةُ ما يتتبّعه المستودع');
        $this->assertNotSame([], $out, 'المستودعُ موجودٌ ولا يتتبّع شيئًا — القياسُ معطوب');

        return $out;
    }

    /** أهذا المسارُ إعداداتُ جهازٍ — هو أو نسخةٌ منه؟ */
    private function isLocalSettings(string $path): bool
    {
        return str_starts_with($path, '.claude/settings.local.json');
    }

    public function test_no_local_settings_file_is_tracked(): void
    {
        $tracked = $this->trackedFiles();

        /*
         * وقائمةٌ فارغةٌ ليست براءة.
         *
         * فحصٌ يُعطَّل وقائمةٌ نظيفة يقولان الشيءَ نفسَه: «لا شيء وُجد».
         * فيُشهَد على القائمة أنّها قُرئت فعلًا قبل أن يُشهَد لها بالنظافة.
         */
        $this->assertContains('.claude/auto-push.sh', $tracked,
            'لم تُقرأ قائمةُ المتتبَّع أصلًا — فنظافتُها لا تُصدَّق');

        $riders = array_values(array_filter($tracked, fn ($f) => $this->isLocalSettings($f)));

        $this->assertSame([], $riders,
            'إعداداتُ جهازٍ خرجت مع الكود: '.implode('، ', $riders));
    }

    /** والمقياسُ يمسك ما أفلت فعلًا — وإلّا كان فحصًا لا يفحص */
    public function test_the_rule_catches_the_name_that_got_through(): void
    {
        $this->assertTrue($this->isLocalSettings('.claude/settings.local.json.autopush-backup'),
            'الاسمُ الذي دخل المستودعَ فعلًا يمرّ من الفحص');
        $this->assertTrue($this->isLocalSettings('.claude/settings.local.json'));
        // ولا يمسك ما ليس منها: السكربتُ نفسُه يبقى متتبَّعًا
        $this->assertFalse($this->isLocalSettings('.claude/auto-push.sh'));
    }

    /** والاستبعادُ بنجمةٍ لا بالاسم وحده — وإلّا عادت اللاحقةُ تفتح البابَ */
    public function test_the_ignore_rule_covers_the_copies_too(): void
    {
        $this->assertStringContainsString(
            '.claude/settings.local.json*',
            (string) file_get_contents(base_path('.gitignore')),
            'الاستبعادُ يطابق الاسمَ حرفًا بحرف — ونسخةٌ بلاحقةٍ تمرّ من جانبه',
        );
    }
}
