<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * يدٌ واحدة تكتب في الحافظة.
 *
 * كان النمطُ مكتوبًا بستّ أيدٍ: كلمةُ مرور تاجرٍ في لوحة المنصّة، وكلمةُ
 * مرور موظّف، وحسابات متجرٍ تجريبيّ، ورابطان في التسويق، ورقمُ فاتورة.
 * وستُّ نسخٍ من عشرة أسطرٍ تفترق — وقد افترقت: ثلاثٌ منها كانت
 * `navigator.clipboard?.writeText(x)` ثمّ `setCopied(true)` في السطر الذي
 * يليه، فتقول «نُسخ» ولو لم يقع نسخ.
 *
 * وهذا حارسٌ يقرأ مصدرًا — ولا يقوم مقام اختبار المتصفّح (انظر DEC-002،
 * و`tests/js/copy-button`). عملُه واحد: أن يمنع كتابةَ يدٍ سابعة.
 */
class OneHandWritesToTheClipboardTest extends TestCase
{
    /** الموضعُ الوحيد المأذون له بمسّ `navigator.clipboard` */
    private const HOME = 'resources/js/lib/copy.ts';

    public function test_only_one_file_touches_the_clipboard(): void
    {
        $offenders = [];

        foreach ($this->screens() as $path => $source) {
            if ($path === self::HOME) {
                continue;
            }

            /*
             * والتعليقاتُ لا تُحسب: صفحةٌ تشرح لماذا لا تمسّ الحافظة بنفسها
             * تذكر اسمَها. فيُقرأ الاستدعاء وحده — `writeText` بعدها.
             */
            if (preg_match('/navigator\s*\.\s*clipboard\s*\??\.\s*writeText/', $source)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'يدٌ ثانية تكتب في الحافظة — استعمل CopyButton أو useCopy');
    }

    /**
     * وموضعُ الحافظة موجودٌ ولا يُعلن نجاحًا في مسار الفشل.
     *
     * فحارسٌ يقول «لا أحد يمسّ الحافظة» يمرّ خضراءَ لو حُذف الملفُّ كلُّه.
     */
    public function test_the_one_hand_exists_and_does_not_lie(): void
    {
        $source = file_get_contents(base_path(self::HOME));

        $this->assertStringContainsString('await navigator.clipboard.writeText(text);', $source);

        /*
         * ولا `?.` على الاستدعاء — فهي تُسكت غياب الحافظة وتمضي.
         *
         * والاستدعاءُ وحده يُفحص لا النصُّ كلُّه: الشرحُ فوقه يذكرها ليقول
         * لمَ رُفعت، فحارسٌ يقرأ الشرح يسقط على ملفٍّ صحيح.
         */
        $this->assertStringNotContainsString('await navigator.clipboard?.writeText', $source);
    }

    /** وكلُّ زرِّ نسخٍ في النظام يقرأ من هناك */
    public function test_every_copy_button_reads_from_the_one_hand(): void
    {
        $users = [];

        foreach ($this->screens() as $path => $source) {
            if (str_contains($source, 'CopyButton') || str_contains($source, 'useCopy')) {
                $users[] = $path;
            }
        }

        // الموضع، والزرّ، وستُّ شاشاتٍ كانت تكتبه بيدها
        $this->assertGreaterThanOrEqual(7, count($users), 'شاشةٌ فقدت طريقها إلى موضع النسخ');
    }

    /** @return array<string, string> */
    private function screens(): array
    {
        $out = [];
        $root = base_path('resources/js');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }

            $out[str_replace(base_path().'/', '', $file->getPathname())] = file_get_contents($file->getPathname());
        }

        return $out;
    }
}
