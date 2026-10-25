<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * منفذُ الطابعة يتّسع لكلّ منافذ TCP — حتى 65535.
 *
 * ═══ ولمَ ═══
 *
 * كُتب العمودُ `unsignedSmallInteger`، وPostgreSQL لا يعرف «بلا إشارة»: صار
 * `smallint` حدُّه 32767. والتحقّقُ يقبل حتى 65535 كما يقبل التطبيقان
 * (`mobile/PROTOCOL.md` §4). فمنفذٌ مثل 40000 كان يمرّ من التحقّق ثمّ يسقط
 * الحفظُ بخطأ خادمٍ (500) لا يقول شيئًا — وعلى SQLite يمرّ صامتًا، فلا يراه
 * إلّا الإنتاج. حارسُه `APrinterAddressIsRefusedWhereTheAppWouldRefuseItTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_peripherals', function (Blueprint $table) {
            $table->unsignedInteger('port')->nullable()->change();
        });
    }

    /**
     * والتراجعُ لا يقصّ منفذًا ولا يمحوه.
     *
     * منفذٌ فوق 32767 حُفظ بعد الترقية لا يتّسع له `smallint`: يتوقّف التراجعُ
     * هنا ويُسمّي الصفوف، ولا يُكمل ليسقط في منتصف `ALTER` أو يقصّ رقمًا. ومن
     * أراد التراجع فعلًا يصحّح تلك المنافذ بيده أوّلًا — قرارٌ لا يُتّخذ عنه.
     */
    public function down(): void
    {
        $wide = DB::table('pos_peripherals')->where('port', '>', 32767)->pluck('id');

        if ($wide->isNotEmpty()) {
            throw new RuntimeException('لا يُتراجع: منافذ فوق 32767 في pos_peripherals (المعرّفات: '.$wide->implode(', ').'). صحّحها يدويًّا أوّلًا.');
        }

        Schema::table('pos_peripherals', function (Blueprint $table) {
            $table->unsignedSmallInteger('port')->nullable()->change();
        });
    }
};
