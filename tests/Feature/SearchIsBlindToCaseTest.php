<?php

namespace Tests\Feature;

use App\Support\Search;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * لا بحثَ في النظام يكتب `like` بيده على نصٍّ يكتبه إنسان.
 *
 * `like` في SQLite — وعليها تجري الاختبارات — لا تفرّق بين الحرف الكبير
 * والصغير، وفي PostgreSQL — وعليها يجري الإنتاج — تفرّق. فالحارس أخضرُ
 * عندنا والبحث أعمى عند التاجر، ولا يظهر ذلك في أيّ اختبارٍ يجري على
 * محرّكٍ لا يفرّق.
 *
 * وقد وقع فعلًا: بحث لوحة المنصّة عن مستخدمٍ ببريده كان يفشل إن كُتب فيه
 * حرفٌ كبيرٌ واحد — وهي أوّل شاشةٍ تُفتح حين يتّصل التاجر.
 *
 * والمسحُ هنا لا يمنع `like` مطلقًا: أرقامُ النظام المولَّدة (`INV-%` و`JV-%`
 * و`TRX-%`) تُطابَق ببادئةٍ ثابتة كتبها النظام لا إنسان، فلا حرفَ فيها
 * يُكتب بحالتين.
 */
class SearchIsBlindToCaseTest extends TestCase
{
    public function test_postgres_gets_a_case_blind_operator(): void
    {
        $this->assertSame('ilike', Search::operatorFor('pgsql'));
        $this->assertSame('like', Search::operatorFor('sqlite'));
        $this->assertSame('like', Search::operatorFor('mysql'));
    }

    /**
     * ولا متحكّمَ يطابق نصًّا محاطًا بـ`%` بمعامِلٍ مكتوبٍ بيده.
     *
     * `"%{$s}%"` أو `$like` علامةُ بحثٍ عن نصٍّ كتبه إنسان — وهي وحدها ما
     * يُمنع. والبادئة `'ABC-%'` تمرّ.
     */
    public function test_no_controller_hand_writes_like_for_a_human_query(): void
    {
        $hand = [];

        foreach ($this->php(app_path()) as $file) {
            if (str_ends_with($file, 'Search.php')) {
                continue;
            }

            foreach (file($file) as $n => $line) {
                // 'like', "%...%"  أو  'like', $like  أو  'like', '%'.$x
                if (preg_match('/[\'"]like[\'"]\s*,\s*(?:"%|\$like\b|\'%\'\s*\.)/i', $line)) {
                    $hand[] = str_replace(app_path().'/', '', $file).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $hand, 'بحثٌ يكتب المعامل بيده — استعمل Search::like()');
    }

    /** والبحثان الموحّدان قائمان: مسحٌ لا يجد شيئًا ليس دليلَ سلامة */
    public function test_both_unified_search_routes_exist(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('admin.search'));
        $this->assertNotNull(Route::getRoutes()->getByName('super-admin.search'));
    }

    /** @return array<int, string> */
    private function php(string $dir): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
