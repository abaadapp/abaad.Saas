<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ترتيبُ الفئات في الموقع — بيد صاحبها لا بالأبجديّة.
 *
 * كانت بطاقاتُ «تسوّق حسب الفئة» وروابطُ التذييل مرتّبةً بالاسم: «الإضافات»
 * قبل «منتجات» لأنّ الألف قبل الميم، ولا سبيلَ إلى غير ذلك إلّا بتبديل
 * الاسم نفسِه.
 *
 * و`sort_order` فارغٌ لكلّ فئةٍ قائمة: فالترتيبُ يبقى أبجديًّا كما كان حتّى
 * يرتّب صاحبُ المحلّ بيده (`Category::scopeInStoreOrder`). والفئةُ الجديدة
 * تأتي فارغةً فتقع آخرًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
