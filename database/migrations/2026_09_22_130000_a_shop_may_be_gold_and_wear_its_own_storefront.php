<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * متجرٌ قد يكون ذهبيًّا ويلبس واجهتَه الخاصّة.
 *
 * ═══ الفئة ═══
 *
 * زبونٌ يُعامَل على أنّه نظامٌ مستقلٌّ داخل أبعاد — واجهتُه له، وبعد سنةٍ
 * يُفصل نظامًا قائمًا بذاته. فيُوسم «ذهبيًّا» ليُعرف في لوحة المنصّة
 * وفي لوحته بعلامته، ولا يُخمَّن من اسمه.
 *
 * ═══ والواجهة ═══
 *
 * مفتاحٌ يقول أيَّ واجهةٍ تُخدم على عنوان متجره بدل الصفحة البسيطة وبانِي
 * المواقع: `ribbon` أوّلُها. والواجهةُ الخاصّة تتقدّم على الاثنين حين
 * تُضبط، وتسقط إليهما حين تُمحى — لا شيءَ يُكسر بحذف قيمة.
 *
 * وكلاهما اختياريٌّ وفارغٌ لكلّ متجرٍ قائم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('tier', 20)->nullable()->after('status');
            $table->string('storefront_theme', 40)->nullable()->after('tier');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['tier', 'storefront_theme']);
        });
    }
};
