<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الجهاز يُسجَّل على البيعة والوردية.
 *
 * كانت الفاتورة تعرف متجرها وفرعها وموظفها — ولا تعرف من أيّ صندوقٍ خرجت.
 * وحين ينقص الدرج عشرين ريالًا في محلٍّ فيه ثلاثة صناديق، لا يقول السجلّ
 * أيّها. الآن يقول.
 *
 * والعمود اختياري: كل ما بيع قبل اليوم لا جهاز له، وملؤه بقيمةٍ مخترعة
 * كذبٌ في سجلٍّ يُراجَع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('pos_device_id')->nullable()->after('shift_id')
                ->constrained('pos_devices')->nullOnDelete();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('pos_device_id')->nullable()->after('branch_id')
                ->constrained('pos_devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_device_id');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_device_id');
        });
    }
};
