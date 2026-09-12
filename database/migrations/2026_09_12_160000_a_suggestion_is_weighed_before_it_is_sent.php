<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اقتراحُ المساعد يُوزن قبل أن يُرسَل — ويُقيَّد من كتبه ومن غيّره.
 *
 * ═══ ولمَ يُسجَّل أصلًا ═══
 *
 * رسالةٌ خرجت إلى عميلٍ لا يُعرف بعد شهرٍ أكتبها إنسانٌ أم نموذج. وإن وعدت
 * بشيء، فالفرقُ بين الحالين هو الفرقُ بين خطأِ موظّفٍ وخطأِ نظام.
 *
 * فعمودان على الرسالة: `ai_model` يقول أيُّ نموذجٍ اقترحها، و`ai_edited`
 * يقول هل عدّلها الإنسان قبل الإرسال. وفارغان معًا يعنيان أنّ إنسانًا
 * كتبها من رأسه.
 *
 * ═══ والتغذيةُ الراجعة تُخزَّن لأنّها تُقرأ ═══
 *
 * «مناسب / غير مناسب» بلا جدولٍ يُقرأ زرٌّ لا يُدير شيئًا. وهي تُقرأ لتحسين
 * التعليمات والمعرفة — لا لتدريب نموذج: لا تدريبَ يجري هنا، ولا يُدّعى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            /* أيُّ نموذجٍ اقترحها — وفارغٌ يعني أنّ إنسانًا كتبها */
            $table->string('ai_model', 60)->nullable()->after('media_type');
            /* وهل عدّلها الإنسانُ قبل الإرسال — فرقٌ يُقرأ في المراجعة */
            $table->boolean('ai_edited')->nullable()->after('ai_model');
        });

        Schema::create('crm_ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 100);

            // up | down
            $table->string('verdict', 8);
            // wrong_info | wrong_tone | too_long | too_formal | wrong_price | missed_intent | other
            $table->string('reason', 20)->nullable();

            /*
             * ونصُّ الاقتراح يُحفظ معه.
             *
             * «غير مناسب» بلا النصّ الذي حُكم عليه لا يُصلح شيئًا: لا يُعرف
             * ما قاله النموذج ولا لمَ كان خطأ. وهو نصٌّ ولّدناه نحن لا كلامُ
             * العميل — فحفظُه لا يُفشي شيئًا عنه.
             */
            $table->text('suggestion');
            $table->string('model', 60)->nullable();
            $table->timestamps();

            $table->index(['verdict', 'created_at']);
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_ai_feedback');

        Schema::table('crm_messages', function (Blueprint $table) {
            $table->dropColumn(['ai_model', 'ai_edited']);
        });
    }
};
