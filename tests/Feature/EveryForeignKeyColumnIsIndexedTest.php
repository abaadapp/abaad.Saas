<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * كلُّ عمود ربطٍ له فهرسٌ يبدأ به — وما يُضاف بعد اليوم كذلك.
 *
 * `foreignId()->constrained()` لا يُنشئ فهرسًا في PostgreSQL. فكان حذفُ طلبٍ
 * يمسح كلَّ جدولٍ يشير إليه لكلّ صفٍّ محذوف، وقراءةُ أصناف طلبٍ تمسح
 * `order_items` كلَّه — انظر الهجرة `every_foreign_key_column_has_an_index`.
 * وهجرةٌ جديدة بقيدٍ بلا فهرسٍ تُسقط هذا الاختبار باسم عمودها.
 */
class EveryForeignKeyColumnIsIndexedTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_foreign_key_column_leads_an_index(): void
    {
        $missing = match (DB::getDriverName()) {
            'pgsql' => $this->pgsql(),
            'sqlite' => $this->sqlite(),
            default => $this->markTestSkipped('يُقاس على PostgreSQL وSQLite'),
        };

        $this->assertSame([], $missing, "أعمدةُ ربطٍ بلا فهرس — أضف `->index()` في هجرتها:\n".implode("\n", $missing));
    }

    /** @return list<string> */
    private function pgsql(): array
    {
        $rows = DB::select(<<<'SQL'
            select c.conrelid::regclass::text as tbl, a.attname as col
            from pg_constraint c
            join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
            where c.contype = 'f' and array_length(c.conkey, 1) = 1
              and not exists (
                  select 1 from pg_index i
                  where i.indrelid = c.conrelid and i.indkey[0] = c.conkey[1]
              )
            order by 1, 2
        SQL);

        return array_map(fn ($r) => $r->tbl.'.'.$r->col, $rows);
    }

    /** @return list<string> */
    private function sqlite(): array
    {
        $missing = [];
        $tables = DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'");

        foreach ($tables as $t) {
            $leading = [];
            foreach (DB::select('pragma index_list("'.$t->name.'")') as $index) {
                $first = DB::select('pragma index_info("'.$index->name.'")')[0] ?? null;
                if ($first !== null) {
                    $leading[$first->name] = true;
                }
            }

            foreach (DB::select('pragma foreign_key_list("'.$t->name.'")') as $fk) {
                // قيدٌ على عمودٍ واحد — المركّبُ يُقاس بأوّل أعمدته في فهرسه
                if ((int) $fk->seq === 0 && ! isset($leading[$fk->from])) {
                    $missing[] = $t->name.'.'.$fk->from;
                }
            }
        }

        sort($missing);

        return array_values(array_unique($missing));
    }
}
