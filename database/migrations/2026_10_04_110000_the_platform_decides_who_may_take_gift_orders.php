<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الإهداءُ في المتجر الإلكترونيّ يفتحه مديرُ المنصّة — لا التاجر.
 *
 * ═══ ما كان ═══
 *
 * مفتاحُ الإهداء كان في إعدادات الموقع عند التاجر (`store_gift_checkout`)،
 * يرفعه بنفسه. وأبعادُ منصّةٌ عامّة: ليس كلُّ نشاطٍ يُهدى منه، ومن يُهدى منه
 * يُقرَّر له كما يُقرَّر إيواءُ البوتيكات (`boutiques_enabled`).
 *
 * ═══ وما تفعله هذه ═══
 *
 * - عمودٌ على النشاط نفسِه (`businesses.gift_orders_enabled`)، مغلقٌ افتراضًا،
 *   لا يكتبه إلّا مديرُ المنصّة من شاشة النشاط.
 * - ومن كان قد رفع المفتاحَ بنفسه قبل اليوم يُنقل إليه مرّةً واحدة — فلا
 *   يختفي «هذا الطلب هدية» من متجرٍ حيٍّ يومَ النشر. ومديرُ المنصّة يُطفئه
 *   إن شاء. وبعدها لا يُقرأ `store_gift_checkout` لشيء؛ صفُّه يبقى كما هو.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('businesses', 'gift_orders_enabled')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->boolean('gift_orders_enabled')->default(false);
            });
        }

        $carried = DB::table('settings')
            ->whereNotNull('business_id')
            ->where('key', 'store_gift_checkout')
            ->where('value', '1')
            ->pluck('business_id');

        if ($carried->isNotEmpty()) {
            DB::table('businesses')->whereIn('id', $carried)->update(['gift_orders_enabled' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('businesses', 'gift_orders_enabled')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->dropColumn('gift_orders_enabled');
            });
        }
    }
};
