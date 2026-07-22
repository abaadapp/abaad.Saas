<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * يتحقق أن كل نص عربي مغلَّف بـ __() في الكود له ترجمة في lang/en.json.
 *
 *   php artisan translations:check          # يعرض المفاتيح الناقصة (يفشل إن وُجدت)
 *   php artisan translations:check --stub   # يضيف الناقصة إلى en.json بقيمة «TODO» لترجمتها
 *
 * التصميم: النص العربي نفسه هو المفتاح، فالنص الناقص يظهر عربيًا (تراجع آمن)،
 * وهذا الأمر يكشفه حتى لا يبقى بلا ترجمة إنجليزية.
 */
class TranslationsCheck extends Command
{
    protected $signature = 'translations:check {--stub : أضف المفاتيح الناقصة إلى en.json بقيمة مؤقتة}';

    protected $description = 'التأكد أن كل نص __() له ترجمة إنجليزية في lang/en.json';

    public function handle(): int
    {
        $keys = $this->extractKeys();
        $enPath = lang_path('en.json');
        $en = is_file($enPath) ? (json_decode(file_get_contents($enPath), true) ?: []) : [];

        $missing = array_values(array_filter($keys, fn ($k) => ! array_key_exists($k, $en)));

        // فحص إضافي: تطابق عناصر :placeholder بين المفتاح والترجمة
        $mismatched = [];
        foreach ($en as $k => $v) {
            if (! is_string($v)) { continue; }
            preg_match_all('/:[a-zA-Z_]+/', $k, $kp);
            preg_match_all('/:[a-zA-Z_]+/', $v, $vp);
            if (array_diff($kp[0], $vp[0]) || array_diff($vp[0], $kp[0])) {
                $mismatched[] = $k;
            }
        }

        $this->line("مفاتيح __() في الكود : " . count($keys));
        $this->line("مترجَمة في en.json    : " . (count($keys) - count($missing)));

        if ($mismatched) {
            $this->warn("\n⚠ تعارض في :placeholders (" . count($mismatched) . '):');
            foreach ($mismatched as $k) {
                $this->line("   {$k}  →  " . $en[$k]);
            }
        }

        if (empty($missing)) {
            $this->info("\n✔ لا مفاتيح ناقصة — الترجمة الإنجليزية مكتملة.");

            return $mismatched ? self::FAILURE : self::SUCCESS;
        }

        $this->error("\n✘ مفاتيح بلا ترجمة إنجليزية (" . count($missing) . '):');
        foreach ($missing as $k) {
            $this->line('   - ' . $k);
        }

        if ($this->option('stub')) {
            foreach ($missing as $k) {
                $en[$k] = 'TODO: ' . $k;
            }
            ksort($en);
            file_put_contents(
                $enPath,
                json_encode($en, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );
            $this->warn("\nأُضيفت المفاتيح الناقصة إلى en.json بقيمة «TODO» — ترجِمها ثم أعِد الفحص.");
        } else {
            $this->line("\nنصيحة: شغّل php artisan translations:check --stub لإضافتها تلقائيًا للترجمة.");
        }

        return self::FAILURE;
    }

    /** يستخرج كل مفاتيح __() العربية الحرفية من resources/ و app/ */
    private function extractKeys(): array
    {
        $keys = [];
        foreach ([resource_path(), app_path()] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if (! $file->isFile() || ! preg_match('/\.php$/', $file->getFilename())) { continue; }
                $src = file_get_contents($file->getPathname());
                foreach (["/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", '/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/'] as $re) {
                    if (preg_match_all($re, $src, $m)) {
                        foreach ($m[1] as $k) {
                            $k = stripcslashes($k);
                            if (preg_match('/[\x{0600}-\x{06FF}]/u', $k)) {
                                $keys[$k] = true;
                            }
                        }
                    }
                }
            }
        }

        return array_keys($keys);
    }
}
