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

    /**
     * عربيٌّ خارج t() عن قصد — يُستثنى بعلّته لا بالسكوت عنه.
     *
     * قائمةٌ مغلقة يقرؤها من يفتح الملف: تقريرٌ يكرّر ستّة أسطر صحيحة كل
     * مرّة يصير ضجيجًا يُتجاهَل، فيمرّ السابعُ الخاطئ معها.
     */
    private const DELIBERATE = [
        // زرّ اللغة يكتب الاسم بلغته: 'Change language' في الإنجليزية و«ع» شارةً
        'تغيير اللغة',
        'ع',
        // بديل الأحرف الأولى لاسمٍ فارغ (lib/format.ts::initials)
        '؟',
        // قيمة افتراضية في القاعدة لا نصّ واجهة
        'عُمان',
        // تذييل الإيصال الافتراضي: يُطبع للزبون العُماني، ويعدّله التاجر من «قوالب»
        "شكرًا لزيارتكم 🌹\nنتشرف بخدمتكم دائمًا",
        // فاصلة العطف العربية في join()
        '، ',
    ];

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

        $this->reportLoose($en);

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

    /**
     * يعرض النصوص العربية التي لم تمرّ بـt().
     *
     * ما كان منها في en.json يُسكت عنه: مرّ بالترجمة من موضعٍ آخر. والباقي
     * إمّا نصُّ واجهةٍ نسيه أحدهم، أو قيمةٌ في القاعدة تُقارَن — والحكم
     * للإنسان، لكن لا يجوز أن يبقى غير مرئي.
     */
    private function reportLoose(array $en): void
    {
        $suspects = array_values(array_filter(
            $this->loose,
            fn ($s) => ! array_key_exists($s, $en) && ! in_array($s, self::DELIBERATE, true),
        ));

        if (! $suspects) {
            return;
        }

        $this->warn("\n⚠ نصوص عربية في tsx لم تمرّ بـt() (" . count($suspects) . ') — راجعها:');
        foreach ($suspects as $s) {
            $this->line('   · ' . mb_substr($s, 0, 70));
        }
    }

    /** نصوص عربية في tsx لم تلتقطها الأنماط — تُعرض للمراجعة لا للإفشال */
    private array $seen = [];

    private array $loose = [];

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
                    // searchPlaceholder مستقلّة عن placeholder: لا حدَّ كلمةٍ
                    // بينهما، فنمطُ الثانية لا يلتقط الأولى — وستّ خانات بحثٍ
                    // كانت تُترجَم داخل DataTable ولا تُفحص، فبقيت عربية
                    foreach (['header', 'label', 'hint', 'title', 'subtitle', 'empty', 'message', 'placeholder', 'searchPlaceholder'] as $prop) {
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

                /*
                 * وما لم يُطابقه شيء ممّا سبق.
                 *
                 * الأنماط أعلاه تقرأ ما يلي t( مباشرةً وخصائصَ معدودة، وهذا
                 * يفوته كلُّ شكلٍ آخر: نصٌّ في مصفوفةٍ يُمرّ عليها، أو عاملٌ
                 * ثلاثي داخل t()، أو ثابتٌ يُعلَن فوق المكوّن. وقعت الثلاثة
                 * فعلًا، وفي كل مرّة قال الفاحص «الترجمة مكتملة» وبقي النصّ
                 * عربيًّا على شاشة إنجليزية.
                 *
                 * فيُجمع كلُّ نصٍّ عربيٍّ في الملف ويُعرض للإنسان ليحكم: قد
                 * يكون قيمةً في القاعدة تُقارَن ('نشط') لا نصًّا يُعرض. لا
                 * يُفشل الفحص، لكنه يُرى — وفحصٌ يصمت عمّا لم ينظر فيه أسوأ
                 * من لا فحص.
                 */
                if ($fn === 't') {
                    $quoted = "/'((?:[^'\\\\\\n]|\\\\.)*)'|\"((?:[^\"\\\\\\n]|\\\\.)*)\"/";
                    if (preg_match_all($quoted, $src, $m)) {
                        foreach (array_merge($m[1], $m[2]) as $s) {
                            $s = stripcslashes($s);
                            if ($s !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $s)) {
                                $this->seen[$s] = true;
                            }
                        }
                    }
                }
            }
        }

        // ما وُجد نصًّا عربيًّا ولم تلتقطه الأنماط — يُعرض ولا يُفشل
        $this->loose = array_values(array_diff(array_keys($this->seen), array_keys($keys)));

        return array_keys($keys);
    }
}
