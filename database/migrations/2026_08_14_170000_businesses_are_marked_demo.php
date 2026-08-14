<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المتجر التجريبيّ يُعرَف بعلامةٍ في صفّه لا ببريد مالكه.
 *
 * كان يُعرَف بـ`Demo@abaadapp.om`: نصٌّ يُبدَّل من شاشة الإعدادات كأي بريد،
 * فمتى بدّله أحدٌ صار المتجر تاجرًا حقيقيًّا في عين النظام — تدخل بياناته
 * الوهميّة إحصاءاتِ المنصّة، ولا يجد `--drop` ما يحذفه.
 *
 * والعلامة هي ما تُبنى عليه قاعدة العزل: البذر لا يمسّ متجرًا إلا إن كانت
 * فيه، فلا تقع بيانات وهميّة في حساب تاجرٍ ولو بالخطأ.
 */
return new class extends Migration
{
    /** بريد مالك المتجر التجريبيّ القديم — يُوسَم به ما كان قائمًا */
    private const LEGACY_OWNER = 'Demo@abaadapp.om';

    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
        });

        /*
         * وسمُ ما كان قائمًا قبل هذه الهجرة.
         *
         * بدونه يصير المتجر التجريبيّ الموجود على أي خادمٍ تاجرًا عاديًّا:
         * يبقى في الإحصاءات، ويرفض قسمُ الديمو إعادةَ بنائه لأنه لا يراه.
         */
        $legacy = DB::table('users')->where('email', self::LEGACY_OWNER)->value('business_id');

        if ($legacy) {
            DB::table('businesses')->where('id', $legacy)->update(['is_demo' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
