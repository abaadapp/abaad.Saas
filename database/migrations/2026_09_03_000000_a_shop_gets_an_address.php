<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عنوانُ متجرٍ على الإنترنت — `ward-alkhuwair.abaadapp.om`.
 *
 * وموضعُه عمودٌ في `businesses` لا مفتاحٌ في `settings` مع بقيّة إعدادات
 * الموقع، لسببٍ واحد: **التفرّد يُفرَض في القاعدة لا في الشيفرة**. جدولُ
 * الإعدادات صفوفٌ حرّة (متجر/مفتاح/قيمة)، ولا فهرس فيه يمنع متجرين من
 * حجز الاسم نفسه — وطلبان يصلان في اللحظة نفسها يفلتان من أيّ فحصٍ يسبق
 * الكتابة. وحين يقع ذلك يفتح زبونُ الأوّل متجر الثاني.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('site_slug', 63)->nullable()->unique()->after('logo');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['site_slug']);
            $table->dropColumn('site_slug');
        });
    }
};
