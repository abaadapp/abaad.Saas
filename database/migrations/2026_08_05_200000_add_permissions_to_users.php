<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صلاحيات يدوية لكل موظف على حدة.
 *
 * كانت الصلاحية تُشتقّ من الدور وحده (Permissions::MAP)، فلا سبيل لإعطاء
 * كاشيرٍ بعينه صلاحية المخزون دون ترقيته دورًا كاملًا. وشاشة «صلاحيات
 * الموظفين» في الإعدادات كانت تحفظ مفاتيح perm_* لا يقرؤها أي كود — مربّعات
 * تُبدَّل ولا تغيّر شيئًا.
 *
 * NULL تعني «اتبع الدور» — وهو الوضع الافتراضي لكل الموظفين الحاليين، فلا
 * تتبدّل صلاحية أحد بمجرّد تشغيل هذه الهجرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
