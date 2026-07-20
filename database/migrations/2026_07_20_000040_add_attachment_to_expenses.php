<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('attachment')->nullable()->after('status');       // مسار الملف المرفق
            $table->string('attachment_name')->nullable()->after('attachment'); // الاسم الأصلي للملف
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['attachment', 'attachment_name']);
        });
    }
};
