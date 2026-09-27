<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعةٌ لا تصير طلبًا بالسعر الذي وافق عليه الزبون — تُردّ، وتُقرأ.
 *
 * ═══ ما كان ═══
 *
 * المالُ يُقبض، ثمّ يُسعَّر الطلبُ من القاعدة ثانيةً. فإن اختلف المبلغُ —
 * كوبونٌ انقضى حجزُه، أو سعرُ صنفٍ رُفع — لم يُكتب طلبٌ وبقيت الدفعةُ
 * معلَّقةً: يُوقَظ صاحبُ المحلّ في جرسه ليردّ بيده. وهو صادقٌ ولا يكفي:
 * الزبونُ دفع ولا طلبَ له، وردُّ المال يتأخّر بقدر ما يتأخّر صاحبُ المحلّ
 * عن جرسه.
 *
 * ═══ ولمَ أعمدةٌ لا سجلٌّ في ملفّ ═══
 *
 * الردُّ حدثٌ ماليٌّ يُسأل عنه بعد شهر: أُرسل؟ ومتى؟ وبأيّ معرّفٍ عند
 * البوّابة؟ فيُكتب على النيّة نفسِها — تُقرأ من شاشةٍ واحدةٍ مع الدفعة التي
 * تخصّها، لا من سجلٍّ يُفتَّش فيه.
 *
 * و`provider_refund_id` فريدٌ: هو حارسُ «لا ردَّ مرّتين» في القاعدة لا في
 * الكود. و`refund_status` يُطالَب به قبل الإرسال بتحديثٍ شرطيّ — فإشعاران
 * متقاربان لا يُرسل أحدُهما ردًّا والآخرُ ردًّا ثانيًا على المال نفسِه.
 *
 * ═══ ومهلةُ الجلسة تُكتب ولا تُحسب في موضعين ═══
 *
 * حجزُ فرصة الكوبون مربوطٌ بمهلة صفحة الدفع. وكانت المهلةُ ثابتًا يُقرأ في
 * موضعين — يُرسَل إلى Paymob ويُحسب منه الحجز. فتُكتب على النيّة مرّةً
 * واحدة: `expires_at` هي المهلةُ نفسُها التي أُعطيت للبوّابة، ومنها يُقرأ
 * الحجز. فلا يفترقان يوم تتبدّل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_payment_intents', function (Blueprint $table) {
            // مهلةُ صفحة الدفع كما أُعطيت للبوّابة — ومنها مدّةُ حجز الكوبون
            $table->timestamp('expires_at')->nullable()->after('status');

            // pending | sent | failed — والفراغُ يعني «لا ردَّ طُلب»
            $table->string('refund_status', 16)->nullable()->after('error');
            $table->string('provider_refund_id', 64)->nullable()->unique()->after('refund_status');
            $table->timestamp('refunded_at')->nullable()->after('provider_refund_id');
            $table->text('refund_error')->nullable()->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('store_payment_intents', function (Blueprint $table) {
            $table->dropUnique(['provider_refund_id']);
            $table->dropColumn(['expires_at', 'refund_status', 'provider_refund_id', 'refunded_at', 'refund_error']);
        });
    }
};
