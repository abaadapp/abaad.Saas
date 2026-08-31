<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * للمورّد اسمٌ ثانٍ كما للعميل.
 *
 * العميل يحمل `name_en` منذ زمن، فتُقرأ قائمته بالإنجليزية لمن يختارها.
 * والمورّد لا — فبقيت شاشة المشتريات عربيّةً وحدها مهما كانت لغة الواجهة.
 *
 * والعمود nullable: كلّ مورّدٍ قائمٍ يبقى باسمه الواحد، ويُقرأ به في
 * اللغتين حتى يُكتب له ثانٍ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', fn (Blueprint $t) => $t->string('name_en')->nullable());
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('name_en'));
    }
};
