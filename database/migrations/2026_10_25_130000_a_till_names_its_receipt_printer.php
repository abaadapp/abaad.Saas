<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الصندوقُ يسمّي طابعةَ إيصاله — اختيارٌ صريحٌ واحد، غيرُ «الطباعة التلقائية».
 *
 * ═══ ولمَ ═══
 *
 * كانت طابعةُ الإيصال «أوّلَ طابعةٍ» في قائمةٍ لا ترتيبَ لها. فصندوقٌ عليه
 * طابعتان يطبع على أيّهما قرأ PostgreSQL أوّلًا، ولا يقول ذلك لأحد. والآن:
 * عمودٌ يُختار من الإعدادات، وفهرسٌ فريدٌ جزئيّ يمنع طابعتَي إيصالٍ على
 * صندوقٍ واحد — في القاعدة نفسِها لا في الكود وحده.
 *
 * ═══ والترحيلُ لا يبدّل طابعةَ تاجرٍ بصمت ═══
 *
 * - صندوقٌ عليه طابعةٌ مفعّلةٌ واحدة: تُعلَّم — هي ما كان يطبع عليه، بلا لبس.
 * - صندوقٌ عليه طابعتان مفعّلتان أو أكثر: **لا تُعلَّم أيٌّ منها.** الالتباسُ
 *   يُعرض في الإعدادات ليختار التاجر (`TillHealth`)، ويبقى الطبعُ حتى يختار على
 *   الأقدم — أقربِ ما يكون إلى «أوّل صفّ» القديم (`PosPeripheral::receiptAmong`).
 * - وما لا طابعةَ مفعّلةً عليه لا يُمسّ.
 *
 * ولا يمسّ شيئًا غيرَ هذا العمود: لا مبيعات ولا ورديات ولا قيود.
 */
return new class extends Migration
{
    private const INDEX = 'pos_peripherals_one_receipt_printer';

    public function up(): void
    {
        Schema::table('pos_peripherals', function (Blueprint $table) {
            $table->boolean('is_receipt_printer')->default(false)->after('type');
        });

        $single = DB::table('pos_peripherals')
            ->where('type', 'طابعة')
            ->where('active', true)
            ->groupBy('pos_device_id')
            ->havingRaw('count(*) = 1')
            ->pluck('pos_device_id');

        foreach ($single->chunk(500) as $devices) {
            DB::table('pos_peripherals')
                ->whereIn('pos_device_id', $devices->all())
                ->where('type', 'طابعة')
                ->where('active', true)
                ->update(['is_receipt_printer' => true]);
        }

        // طابعةُ إيصالٍ واحدةٌ لكلّ صندوق — والقاعدةُ تقولها لا الكودُ وحده
        DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON pos_peripherals (pos_device_id) WHERE is_receipt_printer');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        Schema::table('pos_peripherals', function (Blueprint $table) {
            $table->dropColumn('is_receipt_printer');
        });
    }
};
