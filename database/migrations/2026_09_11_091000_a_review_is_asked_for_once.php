<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلبُ التقييم يُسأل مرّةً — والعمودُ هو ما يمنع الثانية.
 *
 * زبونٌ يصله «قيّمنا على Google» مرّتين عن طلبٍ واحد يقرؤها إلحاحًا. وبلا
 * ختمٍ في الصفّ لا شيء يعرف أنّها أُرسلت: التاجر يضغط الزرَّ فيُفتح واتساب،
 * ثمّ يعود إلى الصفحة بعد أسبوع فيضغطه ثانية وهو لا يذكر.
 *
 * والختمُ **إعدادُ الرسالة** لا تسليمُها: ما يقع هو أن يُفتح واتساب بنصٍّ
 * مكتوب، وأن يضغط التاجر «إرسال» بيده. فلا يُقال في الشاشة «أُرسل» — يُقال
 * «طُلب في يوم كذا»، وهو ما جرى فعلًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('review_request_sent_at')->nullable()->after('internal_notes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('review_request_sent_at');
        });
    }
};
