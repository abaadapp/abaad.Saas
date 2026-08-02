<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لغة الواجهة تفضيل شخصي لا إعداد متجر.
 *
 * كانت تُحفظ في settings[business_id, 'locale']: فالكاشير الذي يبدّل إلى
 * الإنجليزية يغيّرها على المالك وعلى كل زملائه، والمالك حين يعيدها عربية
 * يسلبها منهم. متجر فيه موظفون لا يقرأون العربية ومالك لا يقرأ الإنجليزية
 * لا يمكن أن يعمل بإعداد واحد مشترك.
 *
 * إعداد النشاط يبقى كما هو: افتراضي المتجر لمن لم يختر لنفسه بعد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
