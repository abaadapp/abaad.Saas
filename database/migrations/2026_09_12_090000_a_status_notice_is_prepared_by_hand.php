<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إشعارُ الحالة يُعَدُّ باليد — والعمودان يقولان لأيّ حالةٍ ومتى.
 *
 * ═══ ولمَ عمودان لا واحد ═══
 *
 * ختمُ الوقت وحدَه يكذب: طلبٌ أُبلغ زبونُه بـ«جاهز» أمسِ ثمّ صار «في الطريق»
 * اليوم يبقى ختمُه قائمًا، فتقول الشاشة «أُبلغ» عن حالةٍ لم يُبلَّغ بها.
 * والحدثُ هو هويّةُ الإشعار: يُقارَن بحدث الحالة الراهنة، فإن اختلفا فالطلب
 * لم يُبلَّغ بحالته هذه — ولو أُبلغ بسابقتها عشر مرّات.
 *
 * ═══ وما يُختَم هو الإعداد لا التسليم ═══
 *
 * ما يقع أنّ واتساب يُفتح بنصٍّ مكتوب، وأنّ التاجر يضغط «إرسال» بيده. ولا
 * شيء عندنا يعرف أوصلت أم لا. فلا يُقال في الشاشة «أُرسل» — يُقال «أُعِدّ»،
 * وهو ما جرى فعلًا. وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('status_notice_at')->nullable()->after('review_request_sent_at');
            $table->string('status_notice_event', 40)->nullable()->after('status_notice_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['status_notice_at', 'status_notice_event']);
        });
    }
};
