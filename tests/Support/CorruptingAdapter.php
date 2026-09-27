<?php

namespace Tests\Support;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;

/**
 * قرصٌ يقبل ما يُكتب ويردّ غيرَه — لمحاكاة عطبٍ صامتٍ في التخزين.
 *
 * ═══ ولمَ يُحتاج مثلُ هذا ═══
 *
 * «نجح الرفع» جوابُ شبكةٍ لا جوابُ قرص. والعطبُ الذي يُخشى ليس الذي يرفع
 * راسًا — ذاك يُقال — بل الذي يقول «تمّ» ويحفظ بايتًا مبدَّلًا: نسخةٌ
 * احتياطيّةٌ تبدو سليمةً سنواتٍ حتّى تُطلب.
 *
 * ولا يُحاكى هذا بقرصٍ مُزيَّفٍ عاديّ: يردّ ما كُتب حرفًا بحرف. فهذا
 * المحوّلُ يكتب كما يُطلب منه ويقلب بايتًا واحدًا فيما يُقرأ — فيُسأل
 * الكودُ: أتلحظ؟
 *
 * وهو أداةُ اختبارٍ لا شيءَ غيرُها: يُسجَّل قرصًا في الاختبار وحدَه.
 */
class CorruptingAdapter implements FilesystemAdapter
{
    /**
     * @param  int  $above  لا يُفسد ما كان أصغرَ من هذا الحجم
     *
     * والعتبةُ تُفرّق بين عطبين: قرصٌ يفسد كلَّ شيءٍ يُكشف في فحص البوّابة
     * (جسمٌ صغيرٌ يُكتب ويُقرأ)، وقرصٌ يفسد الكبيرَ وحدَه يمرّ بالفحص ثمّ
     * يُكشف عند رفع الأرشيف. ولكلٍّ حارسُه.
     */
    public function __construct(private FilesystemAdapter $inner, private int $above = 0) {}

    /** يُقلب بايتٌ في المنتصف — فالطولُ كما هو والمحتوى ليس هو */
    private function spoil(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) < $this->above) {
            return $bytes;
        }

        $at = intdiv(strlen($bytes), 2);
        $bytes[$at] = chr(ord($bytes[$at]) ^ 0xFF);

        return $bytes;
    }

    public function read(string $path): string
    {
        return $this->spoil($this->inner->read($path));
    }

    public function readStream(string $path)
    {
        $spoiled = $this->spoil($this->inner->read($path));
        $fh = fopen('php://temp', 'w+b');
        fwrite($fh, $spoiled);
        rewind($fh);

        return $fh;
    }

    /* ═══ وما بقي يمرّ كما هو ═══ */

    public function fileExists(string $path): bool
    {
        return $this->inner->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->inner->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->inner->write($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->inner->writeStream($path, $contents, $config);
    }

    public function delete(string $path): void
    {
        $this->inner->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->inner->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->inner->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->inner->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->inner->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->inner->fileSize($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->inner->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->inner->copy($source, $destination, $config);
    }
}
