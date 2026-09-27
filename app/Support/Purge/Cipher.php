<?php

namespace App\Support\Purge;

use RuntimeException;

/**
 * تشفيرُ أرشيفٍ وفكُّه — على دفعاتٍ لا في الذاكرة.
 *
 * ═══ ولمَ لا يُشفَّر الملفُّ دفعةً واحدة ═══
 *
 * أرشيفُ متجرٍ بألف فاتورةٍ ومرفقاتها يبلغ مئاتَ الميغابايتات. و`Crypt`
 * في لارافل تقرأ النصَّ كلَّه في الذاكرة ثمّ تُخرجه بـbase64 — أي ثلاثةَ
 * أضعافِ حجمِه في الذاكرة لحظةَ الذروة. فحدُّ الذاكرة يسقط، ويسقط معه
 * الحذفُ في منتصفه.
 *
 * فيُقرأ الملفُّ ميغابايتًا ميغابايتًا، وتُكتب كلُّ دفعةٍ رسالةَ CBC
 * قياسيّةً بمتّجهِ تهيئةٍ خاصٍّ بها. ولا قالبَ مبتكَرًا هنا: كلُّ دفعةٍ
 * رسالةٌ مستقلّةٌ بمعيارها، والملفُّ كلُّه يُوقَّع بـHMAC واحدٍ في ذيله.
 *
 * ═══ والتوقيعُ يُقرأ قبل الفكّ ═══
 *
 * «شفِّر ثمّ وقِّع» (encrypt-then-MAC) — والتحقّقُ يمرّ على الملفّ مرّةً
 * كاملةً **قبل** أن يُفكَّ حرفٌ منه. فملفٌّ نُقص أو قُطع أو أُعيد ترتيبُ
 * دفعاته يُردّ قبل أن يُكتب نصٌّ صريحٌ على القرص — ولو تأخّر التحقّقُ إلى
 * ما بعد الفكّ لكُتب النصُّ ثمّ قيل «لا تثق به».
 *
 * ═══ ومفتاحان من مفتاح ═══
 *
 * مفتاحُ التشفير ومفتاحُ التوقيع يُشتقّان بـHKDF من المفتاح الأمّ ومِلحٍ
 * عشوائيٍّ يُكتب في الترويسة — فلا يُستعمل مفتاحٌ واحدٌ لعملين، ولا
 * يتكرّر مفتاحُ ملفٍّ في ملفٍّ آخر ولو كان الأمُّ واحدًا.
 *
 * والمفتاحُ الأمّ من البيئة لا من الكود ولا من السجلّ — انظر `key()`.
 */
final class Cipher
{
    /** ترويسةٌ تقول ما هو وبأيّ نسخة — فقارئٌ بعد سنواتٍ يعرف ما بيده */
    private const MAGIC = "ABAAD-PURGE-1\n";

    private const SALT = 32;

    private const IV = 16;

    private const MAC = 32;

    /** دفعةُ النصّ الصريح — ميغابايتٌ واحد */
    private const CHUNK = 1048576;

    private const CIPHER = 'aes-256-cbc';

    /**
     * المفتاحُ الأمّ — اثنان وثلاثون بايتًا بـbase64 في البيئة.
     *
     * ولا قيمةَ افتراضيّة: مفتاحٌ يُخترع في الكود يعني أرشيفًا يفكُّه كلُّ من
     * قرأ المستودع. وغيابُه يوقف الحذفَ بكلمةٍ تُقرأ لا بخطأٍ غامض.
     *
     * ═══ وهو مفتاحٌ آخرُ غيرُ `APP_KEY` ═══
     *
     * `APP_KEY` يشفّر الجلساتَ والذاكرةَ المؤقّتةَ والروابطَ الموقّعة، وهو
     * حاضرٌ في `.env` الخادم ويُدوَّر عند الحاجة. وأرشيفُ عشرِ سنينَ يُفكّ
     * بمفتاحٍ يجب أن يبقى عشرًا، ونسخةٌ منه تُحفظ خارج الخادم.
     *
     * وضبطُ الاثنين على قيمةٍ واحدةٍ يجمع عيبين: تدويرُ `APP_KEY` يُفقد كلَّ
     * أرشيف، ومن قرأ `.env` — في نسخةٍ احتياطيّةٍ أو سجلٍّ أو خادمٍ مخترَق —
     * صار يفكُّ الأرشيفَ البعيدَ أيضًا. فالنسختان تسقطان معًا، وتلك هي
     * الحالةُ التي جاء الأرشيفُ البعيدُ ليمنعَها.
     *
     * فيُردّ صراحةً، ولا يُترك تحذيرًا يُقرأ ولا يُعمل به.
     */
    public static function key(): string
    {
        $raw = (string) config('purge.key');

        if ($raw === '') {
            throw new RuntimeException(__('لا مفتاح تشفير للأرشيف على هذا الخادم — أُلغي الحذف ولم يُمسّ شيء.'));
        }

        /*
         * والبادئةُ `base64:` تُنزع إن وُجدت.
         *
         * `APP_KEY` يُكتب بها في `.env`، فمن يضبط هذا المفتاحَ يحتذي شكلَه
         * — أو يلصق قيمتَه كما هي. ولو رُدَّ الشكلُ بـ«مفتاحٌ غيرُ صالح»
         * لقيل له الخطأُ الأصغر: أنّ الترميزَ معطوب، لا أنّ المفتاحَ هو
         * `APP_KEY` نفسُه وأنّ استقلالَه هو المقصود.
         */
        $body = str_starts_with($raw, 'base64:') ? substr($raw, 7) : $raw;

        $key = base64_decode($body, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(__('مفتاح تشفير الأرشيف غير صالح — يجب أن يكون ٣٢ بايتًا بترميز base64.'));
        }

        if (self::isAppKey($key)) {
            throw new RuntimeException(__('مفتاح تشفير الأرشيف هو APP_KEY نفسه — ولا بدّ من مفتاح مستقلّ. أُلغي الحذف ولم يُمسّ شيء.'));
        }

        return $key;
    }

    /**
     * أهو `APP_KEY` بثوبٍ آخر؟ — والمقارنةُ على البايتات لا على النصّ.
     *
     * `APP_KEY` يُكتب عادةً بالبادئة `base64:`، وقد يُكتب بلا بادئة، وقد
     * يُلصق مفتاحُ الأرشيف بالبادئة. فمقارنةُ النصّين تُمرّر الصورةَ
     * المختلفةَ للقيمة نفسِها — وهي ما يُراد ردُّه.
     */
    private static function isAppKey(string $key): bool
    {
        $app = (string) config('app.key');

        if ($app === '') {
            return false;
        }

        $raw = str_starts_with($app, 'base64:') ? base64_decode(substr($app, 7), true) : $app;

        return $raw !== false && hash_equals($raw, $key);
    }

    /** أمعرَّفٌ مفتاحٌ صالح؟ — سؤالٌ لا يُلقي، تقرؤه البوّابة */
    public static function keyed(): bool
    {
        try {
            self::key();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * يُشفَّر `$src` إلى `$dst`، ويُعاد بصمةُ المُشفَّر وحجمُه.
     *
     * @return array{sha256:string, bytes:int}
     */
    public static function encrypt(string $src, string $dst): array
    {
        $key = self::key();
        $salt = random_bytes(self::SALT);
        [$enc, $mac] = self::derive($key, $salt);

        $in = self::open($src, 'rb');
        $out = self::open($dst, 'wb', $in);

        try {
            $hmac = hash_init('sha256', HASH_HMAC, $mac);
            $head = self::MAGIC.$salt;
            self::write($out, $head);
            hash_update($hmac, $head);

            while (! feof($in)) {
                $plain = fread($in, self::CHUNK);

                if ($plain === false) {
                    throw new RuntimeException(__('تعذّر قراءة الأرشيف للتشفير — أُلغي الحذف ولم يُمسّ شيء.'));
                }

                if ($plain === '') {
                    continue;
                }

                $iv = random_bytes(self::IV);
                $ct = openssl_encrypt($plain, self::CIPHER, $enc, OPENSSL_RAW_DATA, $iv);

                if ($ct === false) {
                    throw new RuntimeException(__('تعذّر تشفير الأرشيف — أُلغي الحذف ولم يُمسّ شيء.'));
                }

                $block = $iv.pack('N', strlen($ct)).$ct;
                self::write($out, $block);
                hash_update($hmac, $block);
            }

            self::write($out, hash_final($hmac, true));
        } finally {
            fclose($in);
            fclose($out);
        }

        return ['sha256' => (string) hash_file('sha256', $dst), 'bytes' => (int) filesize($dst)];
    }

    /**
     * يُتحقّق من التوقيع ثمّ يُفكّ `$src` إلى `$dst`.
     *
     * ولو سقط التحقّقُ لم يُكتب `$dst` أصلًا.
     */
    public static function decrypt(string $src, string $dst): void
    {
        $key = self::key();

        if (! is_file($src)) {
            throw new RuntimeException(__('الأرشيف المشفَّر غير موجود.'));
        }

        $size = (int) filesize($src);
        $least = strlen(self::MAGIC) + self::SALT + self::MAC;

        if ($size < $least) {
            throw new RuntimeException(__('الأرشيف المشفَّر ناقص — لا يمكن فكّه.'));
        }

        $in = self::open($src, 'rb');

        try {
            $magic = (string) fread($in, strlen(self::MAGIC));

            if ($magic !== self::MAGIC) {
                throw new RuntimeException(__('الأرشيف المشفَّر ليس بالصيغة المعروفة — لا يمكن فكّه.'));
            }

            $salt = (string) fread($in, self::SALT);
            [$enc, $mac] = self::derive($key, $salt);

            /* المرورُ الأوّل: التوقيعُ وحدَه — ولا يُكتب شيءٌ بعد */
            self::assertSigned($in, $size, $mac, $magic.$salt);

            /* والمرورُ الثاني: الفكّ، وقد صحّ التوقيع */
            fseek($in, strlen(self::MAGIC) + self::SALT);
            $body = $size - self::MAC;
            $out = self::open($dst, 'wb', $in);

            try {
                while (ftell($in) < $body) {
                    $iv = (string) fread($in, self::IV);
                    $len = (string) fread($in, 4);

                    if (strlen($iv) !== self::IV || strlen($len) !== 4) {
                        throw new RuntimeException(__('الأرشيف المشفَّر مقطوع — لا يمكن فكّه.'));
                    }

                    $n = (int) (unpack('N', $len)[1] ?? 0);
                    $ct = $n > 0 ? (string) fread($in, $n) : '';

                    if (strlen($ct) !== $n) {
                        throw new RuntimeException(__('الأرشيف المشفَّر مقطوع — لا يمكن فكّه.'));
                    }

                    $plain = openssl_decrypt($ct, self::CIPHER, $enc, OPENSSL_RAW_DATA, $iv);

                    if ($plain === false) {
                        throw new RuntimeException(__('تعذّر فكّ تشفير الأرشيف.'));
                    }

                    self::write($out, $plain);
                }
            } finally {
                fclose($out);
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * التوقيعُ يُقرأ على كلّ بايتٍ قبله — ويُقارَن بمقارنةٍ ثابتةِ الزمن.
     *
     * @param  resource  $in
     */
    private static function assertSigned($in, int $size, string $mac, string $head): void
    {
        $body = $size - self::MAC;
        $hmac = hash_init('sha256', HASH_HMAC, $mac);
        hash_update($hmac, $head);

        $at = strlen($head);

        while ($at < $body) {
            $take = min(self::CHUNK, $body - $at);
            $part = (string) fread($in, $take);

            if ($part === '') {
                break;
            }

            hash_update($hmac, $part);
            $at += strlen($part);
        }

        $want = (string) fread($in, self::MAC);

        if (! hash_equals(hash_final($hmac, true), $want)) {
            throw new RuntimeException(__('توقيع الأرشيف لا يطابق محتواه — الأرشيف تالف أو مُبدَّل.'));
        }
    }

    /**
     * مفتاحان من مفتاحٍ وملح — لا يُستعمل مفتاحٌ واحدٌ لعملين.
     *
     * @return array{0:string,1:string}
     */
    private static function derive(string $key, string $salt): array
    {
        return [
            hash_hkdf('sha256', $key, 32, 'abaad-purge-v1-enc', $salt),
            hash_hkdf('sha256', $key, 32, 'abaad-purge-v1-mac', $salt),
        ];
    }

    /**
     * @param  resource|null  $also  يُغلق إن تعذّر الفتحُ الثاني
     * @return resource
     */
    private static function open(string $path, string $mode, $also = null)
    {
        $fh = @fopen($path, $mode);

        if ($fh === false) {
            if (is_resource($also)) {
                fclose($also);
            }

            throw new RuntimeException(__('تعذّر فتح ملفّ الأرشيف: :path', ['path' => basename($path)]));
        }

        return $fh;
    }

    /** @param  resource  $fh */
    private static function write($fh, string $bytes): void
    {
        if (fwrite($fh, $bytes) !== strlen($bytes)) {
            throw new RuntimeException(__('تعذّر كتابة الأرشيف المشفَّر — القرص ممتلئ أو غير قابل للكتابة.'));
        }
    }
}
