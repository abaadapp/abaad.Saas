<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * - ويبدأ مغلقًا لكلّ نشاطٍ بلا استثناء — قرارُ المالك. ما رفعه تاجرٌ بنفسه
 *   من `store_gift_checkout` لا يُنقل ولا يُقرأ لشيء؛ صفُّه يبقى في الجدول بلا
 *   أثر. ومديرُ المنصّة يفتحه بعد النشر لكلّ نشاطٍ يُراد له، واحدًا واحدًا.
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
