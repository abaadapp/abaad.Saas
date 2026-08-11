<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            // مسار التحويل («مسقط ← صلالة») — الحركة وحدها لا تقول من أين إلى أين
            $table->string('note')->nullable()->after('quantity');
        });

        Schema::table('order_items', function (Blueprint $table) {
            /*
             * تكلفة القطعة يوم بيعها.
             *
             * كان الربح يُحسب بتكلفة المنتج اليوم: `receive` تكتب آخر سعر شراء
             * فوق القديم، فترفع لك المورّد السعر من ٤ إلى ٦ فينقص ربحُ الشهر
             * الماضي. تقريرٌ ماليّ يتغيّر بأثرٍ رجعيّ كلّما اشتريتَ — ولا يُرى،
             * لأن الأرقام تبقى معقولة دائمًا.
             *
             * والصفر يعني «بيعةٌ قديمة بلا لقطة»: الحساب يعود فيها إلى تكلفة
             * المنتج كما كان، فلا تنقلب أرقام ما مضى بهجرةٍ واحدة.
             */
            $table->decimal('cost', 12, 3)->default(0)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropColumn('note'));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('cost'));
    }
};
