<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طابعةُ الصندوق قد تطبع طلبَ الموقع ساعةَ يُؤكَّد — خيارٌ مستقلّ لكلّ طابعة.
 *
 * ═══ ولمَ عمودٌ لا إعداد ═══
 *
 * الخيارُ صفةُ طابعةٍ بعينها، كأخيه `auto_print` («طباعة تلقائية بعد البيع»)
 * الذي يجاوره في الصفّ نفسه. وجدولُ `settings` مفاتيحُ متجرٍ لا مفاتيحُ
 * طابعة: حملُه هناك يعني مفتاحًا يحمل رقمَ الطابعة في اسمه، يبقى بعد حذفها،
 * ولا يُنسخ ولا يُستعاد معها. والعمودُ يُحذف مع صفّه ويُنسخ معه.
 *
 * والافتراضُ مُطفأ: لا تاجرَ اليوم يفتح نافذةَ طباعةٍ لم يطلبها بعد النشر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_peripherals', function (Blueprint $table) {
            $table->boolean('auto_print_website_confirm')->default(false)->after('auto_print');
        });
    }

    public function down(): void
    {
        Schema::table('pos_peripherals', function (Blueprint $table) {
            $table->dropColumn('auto_print_website_confirm');
        });
    }
};
