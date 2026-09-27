<?php

namespace App\Support\Purge;

use App\Models\PurgeRun;
use App\Support\BusinessPurge;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * فتحُ أرشيفٍ لمن خُوِّل — نسخةٌ مؤقّتةٌ تُسلَّم ثمّ تُمحى.
 *
 * ═══ ولمَ لا يُسلَّم الملفُّ من مكانه ═══
 *
 * الأرشيفُ المحلّيُّ خارج `public` عن قصد، والبعيدُ مشفَّرٌ في دلوٍ خاصّ.
 * وتسليمُ أيٍّ منهما برابطٍ مباشرٍ يُخرج الملفَّ من حراسةِ النظام: رابطُ
 * S3 الموقَّع يُنسخ فيُقرأ بعد ذلك بلا تخويلٍ ولا قيدٍ في السجلّ.
 *
 * فيُنسخ إلى ملفٍّ مؤقّتٍ ويُسلَّم من المتحكّم، ويُمحى المؤقّتُ بعد
 * التسليم (`deleteFileAfterSend`).
 *
 * ═══ والبصمةُ تُقرأ قبل التسليم ═══
 *
 * الأرشيفُ المحلّيُّ يُقدَّم إن كان موجودًا **وبصمتُه كما سُجّلت** — فلا
 * تُنزَّل نسخةٌ بعيدةٌ بلا داعٍ ولا تُدفع كلفةُ تصدير. وملفٌّ محلّيٌّ
 * تبدّلت بصمتُه لا يُسلَّم: يُستعاد البعيدُ مكانَه، فالمشكوكُ فيه لا
 * يُعطى لمن سيقرؤه بعد سنوات على أنّه الأصل.
 */
final class Vault
{
    /**
     * يُعاد مسارُ ملفٍّ مؤقّتٍ صريحٍ جاهزٍ للتسليم.
     *
     * @throws RuntimeException إن لم يكن ثمّ أرشيفٌ يُقرأ
     */
    public static function open(PurgeRun $run): string
    {
        $sha = (string) $run->archive_sha256;

        /* المحلّيُّ أوّلًا — إن كان موجودًا وبصمتُه كما سُجّلت */
        $rel = (string) $run->archive_path;

        if ($rel !== '' && $sha !== '') {
            $disk = Storage::disk(BusinessPurge::DISK);

            if ($disk->exists($rel)) {
                $abs = $disk->path($rel);

                if (hash_equals($sha, (string) hash_file('sha256', $abs))) {
                    return self::copy($abs);
                }
            }
        }

        /* وإلّا فالبعيدُ: يُنزَّل ويُفكّ ويُقارَن بما سُجّل */
        $path = (string) $run->offsite_path;

        if ($path === '') {
            throw new RuntimeException(__('لا توجد نسخة من هذا الأرشيف — لا محلّية ولا بعيدة.'));
        }

        $tmp = self::temp();

        try {
            $out = Offsite::restore($path, $tmp);
        } catch (RuntimeException $e) {
            @unlink($tmp);

            throw $e;
        }

        if ($sha !== '' && ! hash_equals($sha, $out['sha256'])) {
            @unlink($tmp);

            throw new RuntimeException(__('النسخة المستعادة لا تطابق بصمة الأرشيف المسجَّلة.'));
        }

        return $tmp;
    }

    /** أثمّ نسخةٌ تُقرأ؟ — سؤالٌ للشاشة، لا يُنزّل شيئًا */
    public static function available(PurgeRun $run): bool
    {
        if ((string) $run->archive_path !== '' && Storage::disk(BusinessPurge::DISK)->exists((string) $run->archive_path)) {
            return true;
        }

        return (string) $run->offsite_path !== '' && Offsite::has((string) $run->offsite_path);
    }

    private static function copy(string $abs): string
    {
        $tmp = self::temp();

        if (! @copy($abs, $tmp)) {
            @unlink($tmp);

            throw new RuntimeException(__('تعذّر تهيئة نسخة من الأرشيف للتنزيل.'));
        }

        return $tmp;
    }

    private static function temp(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'abaad-purge-dl-');

        if ($tmp === false) {
            throw new RuntimeException(__('تعذّر تهيئة ملفّ مؤقّت للتنزيل.'));
        }

        return $tmp;
    }
}
