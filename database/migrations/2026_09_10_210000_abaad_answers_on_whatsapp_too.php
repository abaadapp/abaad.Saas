<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وأبعادُ تُجيب على واتساب أيضًا — بشرطٍ يُكتب في العمود لا في النيّة.
 *
 * ═══ ما كان ═══
 *
 * `channel` عمودٌ من أوّل يوم وقيمتُه `in_app` في كلّ صف. والباب الوارد من
 * ميتا يقرأ الحالات ويُسقط الرسائل: لا صندوقَ وارد في تلك النسخة.
 *
 * ═══ والرقمُ المشترك ليس رقمَ دعمٍ بطبعه ═══
 *
 * رقمُ أبعاد المشترك يُرسل إشعاراتِ الطلبات **نيابةً عن المحلّات**، فيردّ
 * عليه زبائنُهم: «وصل؟»، «غيّر العنوان». وتلك رسائلُ زبونٍ لمحلِّه لا
 * رسائلُ تاجرٍ لأبعاد، وقراءتُها هنا هي بعينها ما مُنع في مركز المحادثات.
 *
 * فالوارد لا يُقرأ إلّا حين يُشعَل `supports_inbox` صراحةً، ولا يُخزَّن منه
 * إلّا ما جاء من رقمٍ يُطابق **مستخدمًا واحدًا** في النظام له متجر. وما
 * عداه يُسقَط ولا يُكتب: ما لا يُخزَّن لا يُسرَّب.
 *
 * ═══ ونافذةُ الأربعِ والعشرين ═══
 *
 * ميتا لا تسمح بنصٍّ حرٍّ إلّا خلال أربعٍ وعشرين ساعةً من آخر رسالةٍ وصلت
 * منه. و`whatsapp_window_at` ختمُ الوارد وحده: التاجرُ قد يردّ من داخل
 * أبعادٍ على محادثةٍ بدأت بواتساب، وذلك لا يفتح نافذةَ ميتا. فحقلٌ يُختم
 * في الوارد وحده يقول الحقيقة، و`last_message_at` يكذب هنا.
 *
 * ═══ وحالُ التسليم تُكتب ═══
 *
 * ردٌّ كُتب في المركز ولم يخرج إلى واتساب هو ردٌّ لم يصل — والدعمُ ينتظر
 * جوابًا على كلامٍ لم يقرأه أحد. فالحالُ عمودٌ يُقرأ في الشاشة، لا صمتٌ
 * يُفسَّر نجاحًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            /*
             * هل يُقرأ الواردُ على هذا الرقم؟
             *
             * مطفأٌ افتراضًا — والوصلةُ القائمة تبقى كما كانت بعد الترقية:
             * ترقيةٌ تُشعل بابًا واردًا بلا أن يطلبه أحدٌ هي ترقيةٌ تُغيّر
             * ما يراه الناس بلا قرارٍ منهم.
             */
            $table->boolean('supports_inbox')->default(false)->after('status');
        });

        Schema::table('support_conversations', function (Blueprint $table) {
            /* رقمُ من يكلّمنا — إليه يخرج الردّ، ولا يُقرأ من الطلب */
            $table->string('contact_phone', 32)->nullable()->after('channel');

            /* ختمُ آخرِ واردٍ على واتساب — منه تُحسب النافذة ومنه وحده */
            $table->timestamp('whatsapp_window_at')->nullable()->after('last_message_at');
        });

        Schema::table('support_messages', function (Blueprint $table) {
            // sent | failed | blocked — وفارغٌ يعني أنّ الرسالة لا تُرسَل أصلًا
            $table->string('delivery', 12)->nullable()->after('external_meta');
            $table->string('delivery_error', 200)->nullable()->after('delivery');
        });

        /*
         * ومعرّفُ ميتا فريدٌ في القاعدة لا مفحوصٌ في الكود.
         *
         * ميتا تُعيد الإشعارَ نفسَه حين لا تصلها ٢٠٠ في الوقت، وتُعيده أحيانًا
         * وقد وصلتها. والفحصُ في PHP يقرأ ثمّ يكتب، وبينهما نافذةٌ يمرّ منها
         * الثاني فتُكتب رسالةُ التاجر مرّتين في خيطه.
         */
        Schema::table('support_messages', function (Blueprint $table) {
            $table->unique('external_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropUnique(['external_message_id']);
            $table->dropColumn(['delivery', 'delivery_error']);
        });

        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropColumn(['contact_phone', 'whatsapp_window_at']);
        });

        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropColumn('supports_inbox');
        });
    }
};
