<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ختمُ التاجر على هويّة متجره.
 *
 * اسمُ المتجر يُطبع في رأس كلِّ مستند — الفاتورةُ الضريبيّة والإيصالُ
 * والتقرير — عبر `Paper::brand()`. والنظامُ كان يكتب اسمًا من عنده حين لا
 * اسمَ للمتجر: «متجري» في الشاشات، و«نظام Abad POS» على الورق. فتخرج
 * فاتورةٌ ضريبيّةٌ باسمٍ لم يختره صاحبُها — وهي بذلك ليست مستندًا تجاريًّا.
 *
 * والعمودُ يقول شيئًا واحدًا: **رأى التاجرُ هويّةَ متجره وأقرّها**. ولا
 * يُشتقّ من الاسم وحده، لأنّ من يسمّي متجره «متجري» فعلًا له أن يفعل —
 * يُقرّه مرّةً فلا يُسأل بعدها.
 *
 * ولا تعبئةَ رجعيّة: من كان اسمُه اسمًا حقيقيًّا يُعدّ مُقرًّا باشتقاقٍ في
 * `ShopIdentity::confirmed()`، فلا يُزعج أحدٌ يعمل اليوم. والعمودُ لمن اسمُه
 * اسمٌ يكتبه النظام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('identity_confirmed_at')->nullable()->after('logo');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('identity_confirmed_at');
        });
    }
};
