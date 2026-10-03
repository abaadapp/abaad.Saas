<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الطلبُ هديّةٌ لغير مشتريه — حالةٌ تُكتب، لا تُستنتج.
 *
 * كان يُظنّ هديّةً من اسمٍ في خانة المستلِم أو نصٍّ على الكرت، وكلاهما يقع
 * في طلبٍ يشتريه صاحبُه لنفسه. فصار للهديّة عمودُها (`is_gift`)، ولها
 * طريقةُ تحديد موقع المستلِم (`recipient_location_mode`: يكتبه المشتري الآن،
 * أو يتواصل المتجرُ مع المستلِم)، ونصُّ المناسبة حين يختار الزبونُ «أخرى»
 * ويكتبها بيده (`occasion_text`) — فلا يُكتب نصٌّ حرٌّ في `occasion_type`
 * الذي تتحقّق منه شاشةُ الطلب بقائمته.
 *
 * والطلباتُ القائمة لا تُمسّ: ليست هدايا، ولا طريقةَ موقعٍ لها، ولا نصّ.
 * انظر `Store\GiftOrders`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_gift')->default(false);
            $table->string('recipient_location_mode', 20)->nullable();
            $table->string('occasion_text', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_gift', 'recipient_location_mode', 'occasion_text']);
        });
    }
};
