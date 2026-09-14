<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * من كتب إلينا يُرى — لا يُعثر عليه بالصدفة.
 *
 * ═══ العطب ═══
 *
 * تاجرٌ يكتب إلى رقمنا تُضيء له شارةٌ في الشريط الجانبيّ — `supportBadge`
 * محسوبةٌ في كلّ طلب، وصفٌّ في `support_reads` لكلّ قارئ.
 *
 * وعميلٌ محتمَلٌ يكتب إلى الرقم نفسِه لا يُضيء شيئًا. تُكتب رسالتُه في
 * `crm_messages` وتنتظر — ولا يعرف بها أحدٌ حتّى يفتح «CRM ‹ المحادثات»
 * بمحض الصدفة. ومن جاء ليشتري وانتظر يومًا بلا ردّ يذهب إلى غيرنا.
 *
 * ═══ ولمَ جدولٌ لكلّ قارئ لا عمودٌ على العميل ═══
 *
 * مبيعاتُنا أكثرُ من واحد. وعمودُ `read_at` على الصفّ يعني أنّ فتحَ زميلٍ
 * للمحادثة يُطفئ الشارةَ عن الجميع — فتُنسى الرسالةُ لأنّ أحدَهم مرّ عليها
 * ولم يردّ. وهو الدرسُ نفسُه الذي بُني عليه `support_reads`، ويُبنى هنا
 * بالشكل نفسِه لا بشكلٍ ثانٍ يفترق عنه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /* آخرُ ما قرأه هذا القارئ — وما بعده غيرُ مقروء */
            $table->unsignedBigInteger('last_read_message_id')->default(0);

            $table->timestamps();

            $table->unique(['lead_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_reads');
    }
};
