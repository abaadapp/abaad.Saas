<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * لا نصَّ عربيًّا يُعرض في الواجهة الإنجليزية.
 *
 * الترجمة تُضاف باليد مع كل شاشة، ونسيانُها لا يكسر شيئًا: `__()` تردّ المفتاح
 * نفسه، و`useTranslate` كذلك. فالشاشة تعمل والاختبارات خضراء، ولا يظهر النقص
 * إلا لمن بدّل لغته — وهو غالبًا ليس من كتب الشاشة.
 *
 * بلغ المتراكم مئةً وتسعةً وستّين نصًّا قبل هذا الاختبار: رسائل تحقّق، وتنبيهات
 * حفظ، ورسائل منعٍ في المحاسبة والرواتب. هذا الملفّ يُبقيه صفرًا.
 */
class TranslationCoverageTest extends TestCase
{
    /** ما يُترجَم في الواجهة: t('…') — وفي الخادم: __('…') */
    private const SOURCES = [
        ['resources/js', ['tsx', 'ts'], "/t\('((?:[^'\\\\]|\\\\.)+)'/"],
        ['app', ['php'], "/__\('((?:[^'\\\\]|\\\\.)+)'/"],
    ];

    /** @return array<string, string> */
    private function dictionary(): array
    {
        return json_decode(file_get_contents(base_path('lang/en.json')), true) ?: [];
    }

    /** @return list<string> */
    private function sourceStrings(): array
    {
        $out = [];

        foreach (self::SOURCES as [$dir, $exts, $pattern]) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! in_array($file->getExtension(), $exts, true)) {
                    continue;
                }

                preg_match_all($pattern, file_get_contents($file->getPathname()), $m);
                foreach ($m[1] as $raw) {
                    $key = str_replace("\\'", "'", $raw);
                    // العربيّ وحده يُترجَم — المفاتيح اللاتينية تمرّ كما هي
                    if (preg_match('/\p{Arabic}/u', $key)) {
                        $out[$key] = $file->getFilename();
                    }
                }
            }
        }

        return $out;
    }

    public function test_every_arabic_string_in_the_code_has_an_english_translation(): void
    {
        $dictionary = $this->dictionary();
        $missing = [];

        foreach ($this->sourceStrings() as $key => $file) {
            if (! array_key_exists($key, $dictionary)) {
                $missing[] = "{$file}: {$key}";
            }
        }

        $this->assertSame([], $missing, "نصوصٌ بلا ترجمة (" . count($missing) . ')');
    }

    /**
     * والمتغيّر لا يسقط في الترجمة.
     *
     * `:name` تُستبدَل عند العرض؛ فترجمةٌ تُسقطها تُظهر «Hello» بلا اسم، أو
     * «ends in days» بلا رقم — جملةٌ سليمة النحو تنقصها المعلومة كلّها. ولا
     * يكشفه شيء: لا خطأ ولا سطر في سجلّ.
     */
    public function test_no_placeholder_is_lost_in_translation(): void
    {
        $broken = [];

        foreach ($this->dictionary() as $arabic => $english) {
            preg_match_all('/:([a-zA-Z_]+)/', $arabic, $a);
            preg_match_all('/:([a-zA-Z_]+)/', (string) $english, $e);

            $lost = array_diff(array_unique($a[1]), array_unique($e[1]));
            if ($lost) {
                $broken[] = $arabic . ' → فقد: ' . implode('، ', $lost);
            }
        }

        $this->assertSame([], $broken);
    }
}
