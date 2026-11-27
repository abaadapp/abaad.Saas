<?php

namespace Tests\Feature;

use App\Support\Document\Pdf\MpdfDriver;
use App\Support\Pdf;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * mpdf يكتب ملفّاتٍ مؤقّتة مع كلّ ورقة. والافتراضيُّ مجلّدٌ داخل vendor —
 * والكودُ على الإنتاج يملكه حسابُ النشر لا www-data منذ #205، فسقط كلُّ PDF
 * بخطأ 500 (فاتورةٌ وإيصالٌ وسندٌ وتقرير). فالمجلّدُ في storage التي يكتبها
 * التطبيق.
 */
class APdfKeepsItsTempFilesInStorageTest extends TestCase
{
    private const DIR = 'framework/cache/mpdf';

    public function test_the_temp_dir_is_inside_storage_not_vendor(): void
    {
        $base = new ReflectionMethod(MpdfDriver::class, 'base');
        $config = $base->invoke(null);

        $this->assertSame(storage_path(self::DIR), $config['tempDir'] ?? null, 'mpdf بلا tempDir يكتب في vendor');
        $this->assertStringNotContainsString('vendor', $config['tempDir']);
    }

    public function test_a_pdf_writes_its_temp_files_into_storage(): void
    {
        File::deleteDirectory(storage_path(self::DIR));

        $response = Pdf::a4('<p>فاتورة</p>', 'probe.pdf');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertDirectoryExists(storage_path(self::DIR), 'لم يكتب mpdf في storage');
    }

    /**
     * وما كان على الإنتاج بعينه: مجلّدُ mpdf في vendor لا يُكتب فيه.
     *
     * بلا الإصلاح يسقط هذا بـ«Temporary files directory … is not writable» —
     * الخطأُ نفسُه في سجلّ الإنتاج. وroot يكتب حيث شاء، فلا يُحاكى به.
     */
    public function test_a_pdf_renders_while_vendor_mpdf_tmp_is_read_only(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root يكتب في المجلّد مهما كانت صلاحيّاتُه');
        }

        $dirs = array_values(array_filter(
            [base_path('vendor/mpdf/mpdf/tmp'), base_path('vendor/mpdf/mpdf/tmp/mpdf')],
            'is_dir',
        ));
        $modes = array_map(fn ($d) => fileperms($d) & 0777, $dirs);

        try {
            foreach ($dirs as $d) {
                chmod($d, 0555);
            }
            clearstatcache();

            $response = Pdf::a4('<p>فاتورة</p>', 'probe.pdf');

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        } finally {
            foreach ($dirs as $i => $d) {
                chmod($d, $modes[$i]);
            }
            clearstatcache();
        }
    }

    public function test_git_ignores_the_temp_dir_so_the_server_tree_stays_clean(): void
    {
        $ignore = (string) file_get_contents(storage_path('framework/cache/.gitignore'));

        $this->assertStringContainsString('*', $ignore);
        $this->assertStringNotContainsString('!mpdf', $ignore);
    }
}
