<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بطاقةُ القسم في «تسوّق حسب الفئة» — تُخفى بيد صاحبها، وتلبس صورتَه.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * القسمُ كان يظهر في الصفحة الرئيسيّة متى كان فيه صنفٌ معروض، ولا سبيلَ
 * إلى إخفائه إلّا بإخفاء أصنافه من المتجر كلِّه — و«الإضافات» تُباع مع
 * الطلب ولا تُريد بطاقةً كبيرةً في الرئيسيّة. والبطاقةُ خطوطٌ ملوّنةٌ
 * ثابتة: لا مكانَ لصورة.
 *
 * ═══ والعمودان للبطاقة وحدها ═══
 *
 * `card_hidden` لا يمسّ الأصناف ولا رفَّ المتجر ولا مرشِّحَه: القسمُ يبقى
 * يُختار في صفحة المتجر وتُباع أصنافُه. و`card_image` رابطٌ مرفوعٌ في
 * مجلّد النشاط (`website/{id}`) كسائر صور المتجر — وفراغُه يُبقي الخطوط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('card_hidden')->default(false);
            $table->string('card_image', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['card_hidden', 'card_image']);
        });
    }
};
