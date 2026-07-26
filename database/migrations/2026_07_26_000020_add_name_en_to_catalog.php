<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // اسم إنجليزي اختياري يظهر تلقائيًا عند تشغيل الواجهة بالإنجليزية
        Schema::table('categories', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
        });
        Schema::table('addons', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('name_en'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('name_en'));
        Schema::table('addons', fn (Blueprint $t) => $t->dropColumn('name_en'));
    }
};
