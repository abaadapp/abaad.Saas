<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الإضافةُ تختار أين تُباع: نقطةُ البيع، والمتجرُ على الإنترنت، أو كلاهما، أو لا شيء.
 *
 * ═══ ما كان ═══
 *
 * الإضافةُ تُباع في الصندوق وحدَه: الموقعُ لا يقرأ إضافاتِ المنتج أصلًا
 * (`WebCheckout::items` كانت تطرحها، والبندُ يُكتب بـ`addons_total = 0`).
 * و`active` وحدَه يقول أتُباع أم لا.
 *
 * ═══ وما يُضاف ═══
 *
 * `pos_enabled` و`web_enabled` — نظيرا `products.pos_enabled` و`published`.
 * و`active` يبقى الحارسَ العامّ فوقهما: الموقوفةُ لا تُباع في أيّ قناة.
 *
 * ═══ والافتراضان مختلفان عمدًا ═══
 *
 *   - `pos_enabled = true`: كلُّ إضافةٍ تُباع في الصندوق اليوم تبقى تُباع فيه.
 *     وافتراضُ `false` يُفرغ شاشةَ الكاشير من إضافاته لحظةَ الهجرة.
 *   - `web_enabled = false`: لم تُعرض إضافةٌ في الموقع قطّ. وافتراضُ `true`
 *     يفتح على زبائن كلّ متجرٍ إضافاتٍ لم يقرّر صاحبُها بيعَها على الشبكة —
 *     «تغليف عند الكاشير» أو رسمُ خدمةٍ تُعرض لزبونٍ لا يعرف معناها.
 *
 * فلا تتغيّر حالُ إضافةٍ ولا سعرُها ولا ما تأكله من الرفّ، ولا فاتورةٌ مضت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            if (! Schema::hasColumn('addons', 'pos_enabled')) {
                $table->boolean('pos_enabled')->default(true)->after('active');
            }
            if (! Schema::hasColumn('addons', 'web_enabled')) {
                $table->boolean('web_enabled')->default(false)->after('pos_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            foreach (['web_enabled', 'pos_enabled'] as $column) {
                if (Schema::hasColumn('addons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
