<?php

namespace App\Support\Purge;

use App\Support\BusinessPurge;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * نسخةُ الأرشيف بعيدًا عن الخادم — مشفَّرةً، ومُستعادةً قبل أن يُمحى شيء.
 *
 * ═══ ولمَ نسخةٌ خارج الخادم ═══
 *
 * الأرشيفُ الأوّلُ يُكتب على قرص الخادم نفسِه الذي تُمحى منه الشركة. وقرصٌ
 * واحدٌ يحمل الأصلَ والنسخةَ ليس نسخةً احتياطيّة: عطبُ القرص، أو خادمٌ
 * يُعاد بناؤه، أو خطأٌ في مسارٍ — كلُّها تأخذ الاثنين معًا. ودفاترُ عشرِ
 * سنينَ لا تُترك على قرصٍ واحد.
 *
 * ═══ والرفعُ لا يكفي ═══
 *
 * «نجحت الكتابة» جوابُ شبكةٍ لا جوابُ قرص. فبعد الرفع **يُنزَّل الأرشيفُ
 * ثانيةً، ويُفكُّ تشفيرُه، ويُفتح، ويُقرأ بيانُه، وتُقارَن بصمتُه بالأصل**
 * — استعادةٌ حقيقيّةٌ لا وعدٌ بها. فما لم يُستعَد الأرشيفُ بالفعل لم يُثبت
 * أنّه أرشيف، ولا يُمحى صفٌّ واحد.
 *
 * وبايتٌ تبدّل في التخزين يُسقط توقيعَ HMAC قبل أن يُفكّ حرف — فالاستعادةُ
 * تُغني عن مقارنةِ بصمةٍ منفصلةٍ تُنزّل الجسمَ مرّةً أخرى لجوابٍ أضعف.
 *
 * ═══ ولا بديلَ صامت ═══
 *
 * إن لم يكن قرصٌ مستقلٌّ مهيَّأً، أو غاب مفتاحُ التشفير، أو سقط الفحصُ —
 * يتوقّف الحذفُ بكلمةٍ تُقرأ. ولا يُكتفى بالقرص المحلّيّ في صمتٍ: من ظنّ
 * أنّ له نسخةً بعيدةً وليست له، يكتشف ذلك يومَ يحتاجها وحدَه.
 *
 * ولا يُكتب مفتاحٌ ولا سرٌّ في رسالةٍ ولا في سجلّ — انظر `fault`.
 */
final class Offsite
{
    /** بادئةُ اسمِ ملفِّ الفحص — يُكتب ويُقرأ ويُحذف، ولا يبقى */
    private const PROBE = 'health/probe-';

    public static function disk(): ?string
    {
        $name = trim((string) config('purge.offsite.disk'));

        return $name === '' ? null : $name;
    }

    /** أمهيَّأٌ قرصٌ مستقلٌّ ومفتاحٌ صالح؟ — سؤالٌ لا يلمس الشبكة */
    public static function configured(): bool
    {
        return self::disk() !== null && Cipher::keyed();
    }

    /**
     * فحصٌ حقيقيّ: يُكتب جسمٌ صغير، ويُقرأ، ويُقارَن، ويُحذف.
     *
     * ولا يُستنتج من الإعدادات: قرصٌ معرَّفٌ بمفتاحٍ خاطئ أو دلوٍ غير موجود
     * يمرّ في القراءة ويسقط عند الرفع. ومحوُ شركةٍ ليس موضعَ استنتاج.
     *
     * @throws RuntimeException إن لم يكن القرصُ صالحًا للكتابة والقراءة
     */
    public static function assertReady(): void
    {
        $name = self::disk();

        if ($name === null) {
            throw new RuntimeException(__('لا تخزين مستقلّ مهيَّأ لنسخ الأرشيف — أُلغي الحذف ولم يُمسّ شيء.'));
        }

        self::assertIndependent($name);

        Cipher::key();

        $path = self::prefix().'/'.self::PROBE.bin2hex(random_bytes(8));
        $body = 'abaad-purge-probe-'.now()->toIso8601String();

        try {
            $disk = Storage::disk($name);

            if ($disk->put($path, $body) === false) {
                throw new RuntimeException('put');
            }

            $back = $disk->get($path);

            if ($back !== $body) {
                throw new RuntimeException('read-back');
            }
        } catch (Throwable $e) {
            throw new RuntimeException(self::fault($e), 0, $e);
        } finally {
            try {
                Storage::disk($name)->delete($path);
            } catch (Throwable) {
                /* ملفُّ فحصٍ بقي لا يمنع شيئًا — ولا يُخفي سببَ السقوط الأوّل */
            }
        }
    }

    /**
     * وقرصُ النسخة يجب أن يكون **مستقلًّا** — لا القرصَ نفسَه بثوبٍ آخر.
     *
     * ═══ ولمَ يُسأل هذا ═══
     *
     * المفتاحُ يقبل اسمَ أيّ قرص. ومن ضبطه على `local` — أو على قرصٍ محلّيٍّ
     * جذرُه جذرُ الأرشيف — حصل على «نسخةٍ احتياطيّة» على القرص الذي تُمحى
     * منه الشركة. وقرصٌ واحدٌ يحمل الأصلَ والنسخةَ ليس نسخةً: عطبُه يأخذ
     * الاثنين.
     *
     * وهذا هو «البديلُ الصامت» الذي لا يُقبل: لا يظهر خطأٌ، ولا يُكتشف
     * الأمرُ إلّا يومَ تُطلب النسخةُ ولا تكون.
     *
     * وفي الإنتاج يُشترط أكثرُ من ذلك: ألّا يكون المحوّلُ محلّيًّا بحال —
     * فمسارٌ على هذا الخادم ليس مستقلًّا عنه ولو كان مجلّدًا آخر. وتُترك
     * الأقراصُ المحلّيّة لبيئات التطوير والاختبار وحدَها.
     */
    private static function assertIndependent(string $name): void
    {
        if ($name === BusinessPurge::DISK) {
            throw new RuntimeException(__('قرص النسخة الاحتياطية هو قرص الأرشيف نفسه — هذه ليست نسخة مستقلة. أُلغي الحذف.'));
        }

        $driver = (string) config("filesystems.disks.{$name}.driver");

        if ($driver !== 'local') {
            return;
        }

        if (app()->isProduction()) {
            throw new RuntimeException(__('قرص النسخة الاحتياطية محلّي على هذا الخادم — ليس تخزينًا مستقلًّا. أُلغي الحذف.'));
        }

        /* وخارجَ الإنتاج يُقبل المحلّيُّ للتجربة، ما لم يكن جذرُه جذرَ الأرشيف */
        $mine = rtrim((string) config('filesystems.disks.'.BusinessPurge::DISK.'.root'), '/');
        $theirs = rtrim((string) config("filesystems.disks.{$name}.root"), '/');

        if ($mine !== '' && $mine === $theirs) {
            throw new RuntimeException(__('قرص النسخة الاحتياطية يشترك في مجلّد الأرشيف نفسه — هذه ليست نسخة مستقلة. أُلغي الحذف.'));
        }
    }

    /**
     * يُشفَّر الأرشيفُ ويُرفع، ثمّ يُستعاد ويُقرأ — والنتيجةُ بصمتان.
     *
     * @param  string  $localAbs  مسارُ الأرشيف الصريح على القرص المحلّيّ
     * @param  string  $sha256  بصمةُ الأرشيف الصريح كما قُرئت محلّيًّا
     * @return array{path:string, sha256:string, bytes:int}
     */
    public static function store(string $localAbs, string $sha256, int $bid): array
    {
        $name = self::disk();

        if ($name === null) {
            throw new RuntimeException(__('لا تخزين مستقلّ مهيَّأ لنسخ الأرشيف — أُلغي الحذف ولم يُمسّ شيء.'));
        }

        $enc = self::temp('enc');
        $path = self::prefix().'/'.$bid.'-'.now()->format('Ymd-His').'.zip.enc';

        try {
            $sealed = Cipher::encrypt($localAbs, $enc);

            $stream = fopen($enc, 'rb');

            if ($stream === false) {
                throw new RuntimeException(__('تعذّر قراءة الأرشيف المشفَّر قبل رفعه — أُلغي الحذف ولم يُمسّ شيء.'));
            }

            try {
                $put = Storage::disk($name)->writeStream($path, $stream);
            } catch (Throwable $e) {
                throw new RuntimeException(self::fault($e), 0, $e);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($put === false) {
                throw new RuntimeException(__('تعذّر رفع النسخة الاحتياطية إلى التخزين المستقلّ — أُلغي الحذف ولم يُمسّ شيء.'));
            }

            /*
             * ولا تُقرأ البصمةُ من التخزين هنا.
             *
             * كانت تُنزَّل النسخةُ فتُقارَن بصمتُها، ثمّ تُنزَّل ثانيةً
             * فتُستعاد — تنزيلان لجوابٍ واحد. والاستعادةُ أقوى: أيُّ بايتٍ
             * تبدّل في التخزين يُسقط توقيعَ HMAC قبل أن يُفكّ حرف، ثمّ
             * تُقارَن بصمةُ ما استُعيد بالأرشيف الأصليّ، ثمّ يُفتح الملفُّ
             * ويُقرأ بيانُه. فمقارنةُ بصمةٍ وحدَها لا تُثبت أنّ النسخةَ
             * تُستعاد، والاستعادةُ تُثبت البصمةَ ومعها ما بعدها.
             *
             * ويبقى `sha256` المسجَّلُ بصمةَ المشفَّر كما كُتب — بها يُتحقّق
             * من الجسم في التخزين لاحقًا بلا فكّ تشفير.
             *
             * والاستعادةُ تُطلب من `BusinessPurge` في مرحلتها كي تُقرأ في
             * الشاشة، ولا يُمحى شيءٌ قبل أن تُسجَّل لحظتُها.
             */
            return ['path' => $path, 'sha256' => $sealed['sha256'], 'bytes' => $sealed['bytes']];
        } finally {
            @unlink($enc);
        }
    }

    /**
     * استعادةٌ حقيقيّة: تُنزَّل النسخةُ وتُفكّ ويُفتح ملفُّها ويُقرأ بيانُه.
     *
     * وتُقارَن بصمةُ ما استُعيد ببصمة الأرشيف الأصليّ — فنسخةٌ تُفكّ إلى
     * ملفٍّ آخرَ ليست نسخةً عنه.
     */
    public static function assertRestorable(string $disk, string $path, string $sha256): void
    {
        $down = self::temp('down');
        $open = self::temp('open');

        try {
            self::download($disk, $path, $down);

            Cipher::decrypt($down, $open);

            if (! hash_equals($sha256, (string) hash_file('sha256', $open))) {
                throw new RuntimeException(__('النسخة المستعادة لا تطابق الأرشيف الأصلي — أُلغي الحذف ولم يُمسّ شيء.'));
            }

            $zip = new ZipArchive;

            if ($zip->open($open, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException(__('النسخة المستعادة لا تُفتح — أُلغي الحذف ولم يُمسّ شيء.'));
            }

            $manifest = $zip->getFromName('manifest.json');
            $bad = $manifest === false || json_decode((string) $manifest, true) === null;
            $zip->close();

            if ($bad) {
                throw new RuntimeException(__('النسخة المستعادة بلا بيانٍ يُقرأ — أُلغي الحذف ولم يُمسّ شيء.'));
            }
        } finally {
            @unlink($down);
            @unlink($open);
        }
    }

    /**
     * تُنزَّل نسخةٌ وتُفكّ إلى مسارٍ صريح — لبابِ التنزيل في لوحة المنصّة.
     *
     * @return array{bytes:int, sha256:string}
     */
    public static function restore(string $path, string $dstAbs): array
    {
        $name = self::disk();

        if ($name === null) {
            throw new RuntimeException(__('لا تخزين مستقلّ مهيَّأ — لا يمكن استرجاع الأرشيف.'));
        }

        $down = self::temp('down');

        try {
            self::download($name, $path, $down);
            Cipher::decrypt($down, $dstAbs);
        } finally {
            @unlink($down);
        }

        return ['bytes' => (int) filesize($dstAbs), 'sha256' => (string) hash_file('sha256', $dstAbs)];
    }

    /** أموجودٌ جسمٌ بهذا المسار؟ — ولا يُنزَّل لمعرفة ذلك */
    public static function has(string $path): bool
    {
        $name = self::disk();

        if ($name === null) {
            return false;
        }

        try {
            return Storage::disk($name)->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    public static function prefix(): string
    {
        return trim((string) config('purge.offsite.prefix', 'business-purges'), '/') ?: 'business-purges';
    }

    /* ═══════════════════ الأدوات ═══════════════════ */

    private static function download(string $disk, string $path, string $dstAbs): void
    {
        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (Throwable $e) {
            throw new RuntimeException(self::fault($e), 0, $e);
        }

        if (! is_resource($stream)) {
            throw new RuntimeException(__('النسخة الاحتياطية غير موجودة في التخزين المستقلّ.'));
        }

        $out = fopen($dstAbs, 'wb');

        if ($out === false) {
            fclose($stream);

            throw new RuntimeException(__('تعذّر كتابة النسخة المنزَّلة على القرص.'));
        }

        try {
            if (stream_copy_to_stream($stream, $out) === false) {
                throw new RuntimeException(__('تعذّر تنزيل النسخة الاحتياطية.'));
            }
        } finally {
            fclose($stream);
            fclose($out);
        }
    }

    private static function temp(string $tag): string
    {
        $path = tempnam(sys_get_temp_dir(), 'abaad-purge-'.$tag.'-');

        if ($path === false) {
            throw new RuntimeException(__('تعذّر تهيئة ملفّ مؤقّت للنسخة الاحتياطية.'));
        }

        return $path;
    }

    /**
     * رسالةُ عطبٍ بلا سرّ.
     *
     * ═══ ولمَ لا يُقال نصُّ الاستثناء ═══
     *
     * رسائلُ عميل S3 تحمل أحيانًا الرابطَ الموقَّع، وفيه المفتاحُ العامّ
     * ومعاملاتُ التوقيع. وهذه الرسالةُ تُعرض على الشاشة وتُكتب في السجلّ،
     * فلو حملت ذلك لانتشر السرُّ في مكانين لا يُمسحان.
     *
     * فيُقال **نوعُ** الاستثناء وحدَه — يكفي لتمييز «لا محوّل مثبَّت» من
     * «دلوٌ غير موجود» عند القراءة في السجلّ، ولا يحمل سرًّا.
     */
    private static function fault(Throwable $e): string
    {
        return __('تعذّر الوصول إلى التخزين المستقلّ (:type) — أُلغي الحذف ولم يُمسّ شيء.', [
            'type' => class_basename($e),
        ]);
    }
}
