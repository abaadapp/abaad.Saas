<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ما يُكتب على مفتاح الإدخال هو ما يفعله.
 *
 * لوحةُ iOS تختار الكلمةَ بنفسها حين لا نقول: «اذهب» لحقلٍ وحيدٍ في نموذج،
 * و«التالي» حين بعده حقل. والضغطةُ عندنا تُنهي الكتابة وتُغلق اللوحة لا غير
 * — فيقرأ التاجر «اذهب» ويظنّ أنّه حفظ، أو «التالي» وينتظر قفزةً لا تأتي.
 *
 * فصارت «تم» افتراضًا في مكانٍ واحد، و«اذهب» حيث تُرسل فعلًا. وهذا الحارس
 * يمنع افتراقهما: نموذجٌ سادسٌ يُضاف غدًا بـ`data-enter-submits` وينسى
 * كاتبُه المفتاح، فيقول حقلُه «تم» ويُرسل.
 *
 * والسلوكُ نفسُه — أنّ اللوحة تنغلق — مقيسٌ في متصفّح لا مقروءٌ من مصدر:
 * انظر `tests/js/enter-closes-the-keyboard.test.ts`.
 */
class TheKeyboardClosesWhenTypingEndsTest extends TestCase
{
    private function read(string $path): string
    {
        return (string) file_get_contents(resource_path($path));
    }

    /** ملفّات الواجهة كلُّها */
    private function screens(): array
    {
        $out = [];
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($dir as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    public function test_the_shared_field_says_done_on_the_key(): void
    {
        $input = $this->read('js/Components/ui/input.tsx');

        $this->assertStringContainsString(
            "enterKeyHint={enterKeyHint ?? 'done'}",
            $input,
            'المفتاحُ يُترك للوحة تسمّيه — فتقول «اذهب» عن ضغطةٍ لا تحفظ',
        );
    }

    public function test_the_forms_that_really_submit_say_go(): void
    {
        $missing = [];

        foreach ($this->screens() as $path) {
            $src = file_get_contents($path);

            if (! str_contains($src, 'data-enter-submits')) {
                continue;
            }

            // كلُّ حقلٍ في هذه الشاشة يُرسل بالمفتاح — فليقل ذلك
            $fields = preg_match_all('/<(?:Input|PasswordInput)\b/', $src);
            $said = substr_count($src, 'enterKeyHint="go"');

            if ($fields !== $said) {
                $missing[] = basename($path)." — حقولٌ: {$fields} · قالت «اذهب»: {$said}";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "نموذجٌ يُرسَل بالمفتاح وحقلُه يقول «تم»:\n".implode("\n", $missing),
        );
    }

    public function test_it_is_wired_at_startup(): void
    {
        $app = $this->read('js/app.tsx');

        $this->assertStringContainsString('enterEndsTypingOnTouch()', $app, 'قاعدةٌ لا تُركَّب لا تعمل');
    }
}
