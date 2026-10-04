<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بندُ الطلب يحمل رسالةَ كرت الهدية كلَّها.
 *
 * ═══ ما كان ═══
 *
 * `order_items.note` نصٌّ قصير (255) منذ أوّل جداول الصندوق، ورسالةُ الكرت
 * تُقبل حتّى `FlowerOrder::CARD_MAX` (500). فرسالةٌ بين الحدَّين تمرّ على
 * SQLite (لا تحدّ الطول) وتسقط على PostgreSQL عند الكتابة — والإتمامُ يقول
 * للزبون «تعذّر فتحُ صفحة الدفع» وهو لم يخطئ في شيء.
 *
 * ═══ وما تفعله هذه ═══
 *
 * يتّسع العمودُ نصًّا طويلًا، كـ`orders.card_message`. لا صفَّ يتغيّر.
 *
 * ═══ ولا رجوع ═══
 *
 * `down` لا يُضيّقه: تضييقُه يقصّ رسائلَ كُتبت أو يُسقط الهجرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->text('note')->nullable()->change();
        });
    }

    public function down(): void
    {
        // لا يُضيَّق عمودٌ فيه رسائلُ — انظر أعلاه
    }
};
