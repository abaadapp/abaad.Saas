<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * التحصيلُ يُكتب مرّةً واحدة — مفتاحُ المحاولة على صفّه، فريدٌ في متجره.
 *
 * نافذةُ التحصيل تولّد مفتاحًا عند فتحها وترسله مع كلّ إرسال. فضغطةٌ ثانية، أو
 * إعادةُ إرسالٍ بعد ردٍّ ضاع، تحمل المفتاحَ نفسَه: يجده الخادمُ مكتوبًا فيردّ
 * التحصيلَ الأوّل ولا يكتب غيرَه (`CustomerPayments::record`). وإرسالان في
 * اللحظة نفسها يصطدم ثانيهما بالفهرس الفريد — في القاعدة لا في الذاكرة.
 *
 * عمودٌ يقبل الفراغ، ولا يُملأ لماضٍ: التحصيلاتُ القائمة تبقى كما هي، والفراغُ
 * لا يصطدم بالفراغ في الفهرس الفريد. وما يُكتب من غير النافذة (الصندوق وروابط
 * Paymob) له حارسُه القائم ويبقى بلا مفتاح.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->string('request_key', 64)->nullable();
            $table->unique(['business_id', 'request_key']);
        });
    }

    /** ولا يُتراجع عنه وفيه مفاتيح — نزولُه يمحو ما يمنع إعادةَ التحصيل نفسِه */
    public function down(): void
    {
        $held = DB::table('customer_payments')->whereNotNull('request_key')->count();

        if ($held > 0) {
            throw new RuntimeException('لا يُتراجع عن مفتاح التحصيل وفي القاعدة '.$held.' تحصيلًا يحمله — نزولُه يسمح بكتابة التحصيل نفسِه مرّتين. ارجع بالكود وحده فيبقى العمود.');
        }

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'request_key']);
            $table->dropColumn('request_key');
        });
    }
};
