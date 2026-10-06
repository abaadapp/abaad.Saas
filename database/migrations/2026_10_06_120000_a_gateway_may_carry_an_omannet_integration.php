<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رقمُ تكامل OmanNet — اختياريٌّ، ولكلّ محلٍّ رقمُه من حسابه في Paymob.
 *
 * ═══ ولمَ عمودٌ ثالثٌ لا خانةُ Apple Pay ═══
 *
 * OmanNet شبكةُ بطاقات الخصم العُمانيّة، ولها عند Paymob تكاملٌ مستقلٌّ برقمه
 * (Payment Integrations). وهو رقمٌ كرقم البطاقة ورقم Apple Pay بجانبه، يُرسَل
 * معهما في `payment_methods` عند فتح الدفعة — فمكانُه صفُّ البوّابة نفسُه،
 * وحجمُه حجمُهما. وليس سرًّا.
 *
 * ═══ ولا قيمةَ تُكتب لأحد ═══
 *
 * لا يُملأ من رقم البطاقة ولا من رقم Apple Pay ولا يُنسخ من متجرٍ إلى آخر،
 * ولا رقمَ عامًّا لأبعاد. كلُّ صفٍّ قائمٍ يبقى كما هو والعمودُ فارغ — فيبقى
 * كلُّ متجرٍ على ما كان حتى يكتب صاحبُه رقمَه بيده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->string('omannet_integration_id', 32)->nullable()->after('card_integration_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropColumn('omannet_integration_id');
        });
    }
};
