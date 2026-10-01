<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رقمُ تكامل Apple Pay — اختياريٌّ، ولكلّ محلٍّ رقمُه من حسابه في Paymob.
 *
 * ═══ ولمَ عمودٌ لا حقلٌ في إعدادٍ عامّ ═══
 *
 * هو رقمُ تكاملٍ كرقم البطاقة بجانبه (`card_integration_id`)، يُرسَل معه في
 * `payment_methods` عند فتح الدفعة — فمكانُه صفُّ البوّابة نفسُه، وحجمُه
 * حجمُه. وليس سرًّا: لا يُقبض به مالٌ ولا يُصدَّق به إشعار.
 *
 * ═══ ولا قيمةَ تُكتب لأحد ═══
 *
 * Apple Pay عند Paymob له أرقامُ تكاملٍ حيّةٌ فقط، ولا رقمَ تجريبيّ. فلا
 * يُملأ من رقم البطاقة ولا يُخترع ولا يُنسخ من متجرٍ إلى آخر: كلُّ صفٍّ
 * قائمٍ يبقى كما هو والعمودُ فارغ، حتى يكتب صاحبُه رقمَه بيده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->string('apple_pay_integration_id', 32)->nullable()->after('card_integration_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropColumn('apple_pay_integration_id');
        });
    }
};
