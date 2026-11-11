<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * بيعةُ صندوقٍ اكتملت بلا اتصال وخصمُ نقاطها لا يغطّيه رصيدُ العميل حين وصلت.
 *
 * ═══ ولمَ عمودٌ على الطلب ═══
 *
 * البيعةُ لا تُردّ ولا يُغيَّر مبلغُها: الزبونُ دفع ما قيل له وانصرف. فيُكتب
 * عليها ما طُلب وما صُرف والفرقُ (`PosController::reviewRedemption`)، ومنه
 * يُشتقّ جرسُ صاحب المحلّ (`loyalty-review-`) — التنبيهاتُ هنا مشتقّةٌ من
 * القاعدة لا مخزَّنة (`Notifications`). وفارغٌ لكلّ طلبٍ قائم: لم يقع له ذلك.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('loyalty_review')->nullable();
        });
    }

    public function down(): void
    {
        // ولا يُسقط عمودٌ فيه مراجعاتٌ مكتوبة — محوُه يُخفي عن صاحب المحلّ خصمًا لم تغطّه نقاط
        if (DB::table('orders')->whereNotNull('loyalty_review')->exists()) {
            throw new RuntimeException('مراجعات نقاط الصندوق: في القاعدة بيعاتٌ تنتظر المراجعة — لا يُسقط العمود. أرجِع الكود وحده.');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('loyalty_review');
        });
    }
};
