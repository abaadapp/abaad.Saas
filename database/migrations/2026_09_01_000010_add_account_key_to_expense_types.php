<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نوع المصروف يقول إلى أيّ حسابٍ يُرحَّل.
 *
 * كان الترحيل يقرأ اسم النوع ويطابقه بقائمةٍ فيها سطرٌ واحد: «إيجار». وكلّ
 * ما عداه — الكهرباء والتسويق والصيانة والنقل — يسقط في «مصروفات أخرى».
 * فدفترُ الأستاذ يعرف أنّ المحلّ أنفق، ولا يعرف على ماذا؛ وقائمةُ الدخل تصير
 * سطرًا واحدًا كبيرًا لا يُقرأ منه شيء.
 *
 * والنوع يكتبه التاجر بيده — فلا تكفي قائمةٌ في الكود مهما طالت: من كتب
 * «كهرباء» بدل «كهرباء وماء» يسقط منها. فيُحفظ الحساب على النوع نفسه،
 * ويبقى الاسم دليلًا افتراضيًّا لمن لم يختر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_types', function (Blueprint $table) {
            $table->string('account_key')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('expense_types', function (Blueprint $table) {
            $table->dropColumn('account_key');
        });
    }
};
