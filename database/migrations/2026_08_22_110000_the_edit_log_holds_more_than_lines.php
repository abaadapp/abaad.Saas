<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ التصحيحات يحمل أكثر من بند.
 *
 * وُلد الجدول لتصحيح الكميّات، ثم لزم تصحيح وسيلة الدفع — وهي خطأٌ شائع
 * مثلها: يضغط الكاشير «نقدي» والزبون دفع بالبطاقة، فيُنتظر في الدرج مالٌ
 * لم يدخله، ويظهر النقص عند الإقفال بلا سبب.
 *
 * وسجلٌّ ثانٍ لتصحيحٍ ثانٍ كان يعني قائمتين تحت الفاتورة الواحدة، وقارئًا
 * يجمعهما بعينه ليعرف ما جرى. فوُسّع هذا: `subject` ما تغيّر، و`kind` نوعه،
 * والكميّات تبقى للبنود وتُترك فارغةً لغيرها، وقيمتان نصّيّتان لما ليس عددًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_edits', function (Blueprint $table) {
            $table->renameColumn('item_name', 'subject');
        });

        Schema::table('order_edits', function (Blueprint $table) {
            $table->string('kind')->default('بند')->after('order_item_id');
            $table->string('value_before')->nullable()->after('qty_after');
            $table->string('value_after')->nullable()->after('value_before');
        });

        // الكميّات للبنود وحدها — تصحيحُ وسيلة دفعٍ لا كميّة له
        Schema::table('order_edits', function (Blueprint $table) {
            $table->unsignedInteger('qty_before')->nullable()->change();
            $table->unsignedInteger('qty_after')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_edits', function (Blueprint $table) {
            $table->dropColumn(['kind', 'value_before', 'value_after']);
            $table->renameColumn('subject', 'item_name');
        });
    }
};
