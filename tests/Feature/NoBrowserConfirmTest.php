<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * لا نافذةَ من المتصفّح في لوحة التاجر.
 *
 * و`confirm()` نافذةٌ يرسمها نظام التشغيل: تخرج داكنةً في أعلى الشاشة بخطٍّ
 * غريب، وتتجاهل اتجاه الواجهة فينعكس ترتيب أزرارها، ولا تُترجَم إلى لغة
 * الشاشة، وتُجمّد الصفحة حتى يُجاب. وهي أوّلُ ما يشكّ فيه من يراها — تبدو
 * تحذيرًا من المتصفّح لا سؤالًا من البرنامج.
 *
 * وهذا الاختبار يحرس القاعدة لا الموضع: خمسةَ عشرَ موضعًا صُحّحت بيدٍ واحدة،
 * وسادسَ عشرَ يُكتب غدًا في شاشةٍ جديدة يعيد العطب كلَّه. فيُمنع عند الباب.
 */
class NoBrowserConfirmTest extends TestCase
{
    /** الملفّات التي يُسمح لها بذكر الاسم — التوثيق يشرح لماذا رُفع */
    private const ALLOWED = [
        'Components/ConfirmDialog.tsx',
        'Components/DeleteButton.tsx',
        'Components/RowActions.tsx',
    ];

    public function test_no_screen_asks_with_the_browsers_own_dialog(): void
    {
        $found = [];

        foreach ($this->screens() as $path) {
            $relative = str_replace(resource_path('js').'/', '', $path);

            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $code = (string) file_get_contents($path);

            /*
             * والبحث عن النداء لا عن الكلمة: `useConfirm` و«نافذة تأكيد»
             * تحملان الحروف نفسها، ومطابقةُ الكلمة وحدها تصطاد اسم الخطّاف
             * الذي وُضع ليحلّ محلّها.
             */
            foreach (['confirm(', 'alert(', 'prompt('] as $call) {
                if (preg_match('/(?<![A-Za-z_.])'.preg_quote($call, '/').'/', $code)) {
                    $found[] = $relative.' → '.$call;
                }
            }
        }

        $this->assertSame([], $found, "نوافذُ من المتصفّح:\n".implode("\n", $found));
    }

    /** @return list<string> */
    private function screens(): array
    {
        $out = [];

        foreach ([resource_path('js/Pages/Admin'), resource_path('js/Components')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx')) {
                    $out[] = $file->getPathname();
                }
            }
        }

        return $out;
    }
}
