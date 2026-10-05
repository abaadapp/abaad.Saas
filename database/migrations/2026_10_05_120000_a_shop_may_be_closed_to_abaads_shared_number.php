<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رقمُ أبعاد المشترك يُغلق عن متجرٍ بعينه — ولا يُغلق عن المنصّة.
 *
 * ═══ ما كان ═══
 *
 * كلُّ متجرٍ لم يربط رقمه يُرسل من رقم أبعاد: الوضعُ الافتراضيّ
 * `abaad_shared`، و`WhatsAppConnections::resolve` تُرجع وصلةَ المنصّة له.
 * وإطفاءُ المشترك مفتاحٌ واحدٌ للمنصّة كلِّها (`whatsapp_shared_enabled`)
 * — لا يُفصل به متجرٌ واحدٌ دون غيره.
 *
 * ═══ وما تفعله هذه ═══
 *
 * - عمودٌ على المتجر (`businesses.whatsapp_shared_allowed`)، مفتوحٌ افتراضًا،
 *   فلا يتغيّر شيءٌ لمتجرٍ قائم.
 * - لا يكتبه إلّا مديرُ المنصّة من شاشة المتجر. وإغلاقُه يجعل المتجر على
 *   رقمه وحده: إن ربطه أرسل منه، وإلّا فلا رسالة — ولا رجوعَ إلى رقم أبعاد
 *   من أيّ باب (`WhatsAppFeature::effectiveMode`).
 * - ولا يُسمّى متجرٌ هنا: يُغلق بعد النشر من شاشته، كما يُفتح الإهداء.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('businesses', 'whatsapp_shared_allowed')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->boolean('whatsapp_shared_allowed')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('businesses', 'whatsapp_shared_allowed')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->dropColumn('whatsapp_shared_allowed');
            });
        }
    }
};
