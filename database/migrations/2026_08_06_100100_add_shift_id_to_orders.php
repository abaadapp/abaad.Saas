<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط البيعة بورديتها.
 *
 * الاعتماد على التاريخ وحده لا يكفي: وردية تمتدّ بعد منتصف الليل تُقسم
 * يومين، ووردية تُقفل ظهرًا تختلط بالتي بعدها. والمعرّف يُثبّت الحدّ عند
 * لحظة الفتح والإقفال لا عند تغيّر التاريخ.
 *
 * NULL للطلبات السابقة لهذه الميزة — لا تُنسب إلى وردية لم تكن موجودة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable()->after('branch_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shift_id');
        });
    }
};
