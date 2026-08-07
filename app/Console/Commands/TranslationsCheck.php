<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * يتحقق أن كل نص عربي مغلَّف بـ __() في الخادم أو t() في الواجهة له ترجمة
 * في lang/en.json.
 *
 *   php artisan translations:check          # يعرض المفاتيح الناقصة (يفشل إن وُجدت)
 *   php artisan translations:check --stub   # يضيف الناقصة إلى en.json بقيمة «TODO» لترجمتها
 *
 * التصميم: النص العربي نفسه هو المفتاح، فالنص الناقص يظهر عربيًا (تراجع آمن)،
 * وهذا الأمر يكشفه حتى لا يبقى بلا ترجمة إنجليزية.
 *
 * وكان يقرأ ملفات php وحدها فيقول «الترجمة مكتملة» وسبعُ مئة مفتاحٍ في
 * الواجهة لم تُفحص — تطمينٌ بلا فحص، وهو أسوأ من لا فحص: من يقرأ «مكتملة»
 * يكفّ عن النظر. وموظفٌ لا يقرأ العربية يقف أمام النص الذي فات.
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

        $this->line("مفاتيح __() و t() في الكود : " . count($keys));
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

    /** يستخرج مفاتيح __() العربية من php ومفاتيح t() من tsx/ts */
    private function extractKeys(): array
    {
        $keys = [];

        foreach ([resource_path(), app_path()] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if (! $file->isFile()) { continue; }
                $name = $file->getFilename();
                $src = null;

                if (preg_match('/\.php$/', $name)) {
                    $src = file_get_contents($file->getPathname());
                    $fn = '__';
                } elseif (preg_match('/\.tsx?$/', $name)) {
                    // التعليقات تُنزع أولًا: مثالُ الاستعمال في توثيق useTranslate
                    // كان يُقرأ مفتاحًا ناقصًا فيُبلَّغ عن عطبٍ لا وجود له
                    $src = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file->getPathname()));
                    $fn = 't';
                } else {
                    continue;
                }

                $patterns = [
                    "/\b{$fn}\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
                    "/\b{$fn}\(\s*\"((?:[^\"\\\\]|\\\\.)*)\"/",
                ];

                /*
                 * ونصوصٌ تُمرَّر خصائصَ ثم يترجمها المكوّن نفسه.
                 *
                 * كان الفاحص يقول «الترجمة مكتملة» و٢٣ نصًّا عربيًّا يظهر على
                 * لوحةٍ إنجليزية: عناوين أعمدة الجداول وتلميحات الحقول تصل
                 * إلى t() داخل DataTable وField لا عند موضع الكتابة، فلا يراها
                 * فحصٌ يبحث عن t('…') وحدها. وفحصٌ يطمئن على ما لم يفحصه أسوأ
                 * من لا فحص.
                 */
                if ($fn === 't') {
                    foreach (['header', 'label', 'hint', 'title', 'subtitle', 'empty', 'message', 'placeholder'] as $prop) {
                        $patterns[] = "/\b{$prop}\s*[:=]\s*'((?:[^'\\\\]|\\\\.)*)'/";
                        $patterns[] = "/\b{$prop}\s*[:=]\s*\"((?:[^\"\\\\]|\\\\.)*)\"/";
                    }
                }

                foreach ($patterns as $re) {
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
