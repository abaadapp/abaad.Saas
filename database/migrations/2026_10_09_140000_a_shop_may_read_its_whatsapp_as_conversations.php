<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «محادثات واتساب» — ما أرسله النظام من رقم المحلّ، مقروءًا محادثاتٍ.
 *
 * ═══ ما يُضاف — وكلُّه إضافة ═══
 *
 * - `businesses.whatsapp_conversations_enabled`: مفتاحٌ على النشاط، مغلقٌ
 *   افتراضًا (`WhatsAppConversations::enabled`). ويُفتح هنا مرّةً لمن في
 *   `whatsapp.conversations_businesses` — ولا اسمَ ولا رقمَ في الكود.
 * - `whatsapp_messages.body_snapshot`: النصُّ الذي حاول النظامُ إرسالَه
 *   وقتَ الرسالة — نصُّ القالب المعتمَد بمتغيّراتها الفعليّة. والقديمةُ
 *   تبقى فارغةً: لا يُولَّد لها نصٌّ من قالبٍ قد تغيّر بعدها.
 * - `whatsapp_messages.sender_phone_number_id`: الرقمُ الذي خرجت منه. وصلةُ
 *   المحلّ صفٌّ واحدٌ يُحدَّث عند كلّ ربط — ولو بِرقمٍ آخر — فمعرّفُ الوصلة
 *   وحده لا يفصل رسائلَ الرقم الحاليّ عن رسائل رقمٍ سبقه على الصفّ نفسِه.
 * - `whatsapp_template_mappings.body_by_language`: نصُّ BODY المعتمَد عند
 *   ميتا لكلّ لغة، تكتبه مزامنةُ القوالب (`WhatsAppTemplates::sync`).
 *
 * ولا يُحذف ولا يُعاد تسميةُ شيء.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('businesses', 'whatsapp_conversations_enabled')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->boolean('whatsapp_conversations_enabled')->default(false);
            });
        }

        if (! Schema::hasColumn('whatsapp_messages', 'body_snapshot')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->text('body_snapshot')->nullable();
            });
        }

        if (! Schema::hasColumn('whatsapp_messages', 'sender_phone_number_id')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->string('sender_phone_number_id', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('whatsapp_template_mappings', 'body_by_language')) {
            Schema::table('whatsapp_template_mappings', function (Blueprint $table) {
                $table->json('body_by_language')->nullable();
            });
        }

        $ids = array_values(array_filter(array_map('intval', (array) config('whatsapp.conversations_businesses', []))));

        if ($ids !== []) {
            DB::table('businesses')->whereIn('id', $ids)->update(['whatsapp_conversations_enabled' => true]);
        }
    }

    public function down(): void
    {
        foreach ([
            ['whatsapp_template_mappings', 'body_by_language'],
            ['whatsapp_messages', 'sender_phone_number_id'],
            ['whatsapp_messages', 'body_snapshot'],
            ['businesses', 'whatsapp_conversations_enabled'],
        ] as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
