<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * العميلُ يحمل تنبيهَه وعيدَ ميلاده.
 *
 * ═══ الميلادُ ثلاثةُ أعمدةٍ لا تاريخ ═══
 *
 * كثيرٌ من الأنشطة يعرف يومَ الزبون وشهرَه ولا يعرف سنتَه — ولا يسأل عنها.
 * وعمودُ `date` يوجب سنةً، فتُخترع «١٩٠٠» لإرضائه ثمّ تُطبع في الشاشة عمرًا
 * لا يصدّقه أحد. فاليومُ والشهرُ إلزاميّان معًا والسنةُ تُترك فارغةً بصدق.
 *
 * ═══ والتنبيهُ نوعٌ وسبب ═══
 *
 * `alert_type` فارغٌ يعني لا تنبيه؛ و`warning` يُقال للكاشير ويمضي؛
 * و`block` يُردّ به البيعُ في الخادم. والسببُ نصٌّ داخليٌّ لا يخرج في ورقة.
 *
 * و`notes` القائمة كانت «ملاحظات داخلية عن العميل» بنصّ الشاشة — فهي
 * الملاحظةُ الداخليّة نفسُها ولا يُنشأ لها توأم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedTinyInteger('birth_day')->nullable()->after('language');
            $table->unsignedTinyInteger('birth_month')->nullable()->after('birth_day');
            $table->unsignedSmallInteger('birth_year')->nullable()->after('birth_month');
            $table->string('alert_type', 10)->nullable()->after('birth_year');
            $table->text('alert_reason')->nullable()->after('alert_type');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['birth_day', 'birth_month', 'birth_year', 'alert_type', 'alert_reason']);
        });
    }
};
