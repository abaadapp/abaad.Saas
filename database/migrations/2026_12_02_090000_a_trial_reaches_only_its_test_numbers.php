<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ‏‏تجربةُ الفوترة تُسمّي أرقامَ الاختبار التي تصلها رسائلُها — ولا تخرج رسالةٌ منها إلى غيرها
 * (`WhatsAppBillingTrials::recipient`).
 *
 * والمتجرُ الاختباريّ متجرٌ تجريبيّ: عملاؤه بأرقام جوّالٍ عُمانيّةٍ عشوائيّة زرعها `DemoStore`، وقد تكون
 * لأناسٍ حقيقيّين. فرقمٌ لا يُكتب هنا لا تصله رسالة، أيًّا كان الطلبُ الذي تحرّك.
 *
 * عمودٌ فارغٌ في الجدول اليوم (لم تُفتح تجربةٌ قطّ)، وتجربةٌ بلا أرقام لا تُرسل شيئًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_billing_trials', function (Blueprint $table) {
            $table->json('allowed_phones')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_billing_trials', function (Blueprint $table) {
            $table->dropColumn('allowed_phones');
        });
    }
};
