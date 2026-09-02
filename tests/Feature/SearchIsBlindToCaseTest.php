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

    /**
     * ولا صندوقَ بحثٍ يقرأ ما كُتب فيه خامًا.
     *
     * `%` تعني «أيّ شيء» في `LIKE`، وتصل من الصندوق كما يكتبها صاحبها: فمن
     * كتبها وحدها رأى كلّ ما يُؤذن له به دفعةً واحدة — بحثٌ لا يبحث. وأسوأ
     * منه أنّ ما يُطلب لا يُوجد: صنفٌ اسمه «خصم 50%» يُكتب اسمه كاملًا فتُرَدّ
     * الشاشة بكلّ شيءٍ إلّاه.
     */
    public function test_no_search_box_reads_its_query_raw(): void
    {
        $raw = [];

        foreach ($this->php(app_path()) as $file) {
            foreach (file($file) as $n => $line) {
                if (str_contains($line, "\$request->query('q')") && ! str_contains($line, 'Search::term')) {
                    $raw[] = str_replace(app_path().'/', '', $file).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $raw, 'صندوق بحثٍ يقرأ نصّه خامًا — استعمل Search::term()');
    }

    /** والنزعُ يقع على `%` وحدها */
    public function test_the_wildcard_is_stripped_but_the_rest_survives(): void
    {
        $term = fn (string $q) => Search::term(\Illuminate\Http\Request::create('/?q='.urlencode($q)));

        $this->assertSame('', $term('%'));
        $this->assertSame('', $term('%%'));
        $this->assertSame('50', $term('50%'));
        $this->assertSame('عدي', $term('  عدي  '));
        // و`_` تبقى: نزعُها يمنع إيجاد ما فيه شرطةٌ سفليّة أصلًا
        $this->assertSame('SKU_12', $term('SKU_12'));
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
