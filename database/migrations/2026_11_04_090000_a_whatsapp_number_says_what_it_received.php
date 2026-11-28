<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ما وصل الرقمَ فعلًا — حالةُ تسليمٍ شيءٌ، ورسالةُ زبونٍ شيءٌ آخر.
 *
 * `last_webhook_at` يُختم بأيّ إشعارٍ موقَّع، وأكثرُ الإشعارات حالاتُ تسليمٍ
 * لما أرسله المتجر. فرقمٌ يصله «وصلت» و«قُرئت» قد لا يصله ما يكتبه زبونٌ
 * من نفسه — ولا يقول ذلك العمودُ الواحد.
 *
 * - `last_status_at`: آخرُ حالةِ تسليمٍ وصلت لرسالةٍ خرجت من هذا الرقم.
 * - `last_inbound_at`: آخرُ رسالةٍ كتبها زبونٌ إلى هذا الرقم **ووصلت** الويب هوك على
 *   هذه الوصلة — وصولٌ لا حفظ.
 * - `last_inbound_saved_at`: آخرُ رسالةِ زبونٍ **حُفظت** في صندوق محادثات المتجر —
 *   وهي وحدها دليلُ «تم استقبال رسالة عميل».
 * - `inbound_problem`: لماذا لم تُحفظ آخرُ رسالةٍ وصلت (`WhatsAppInbox::*`) — بلا نصّها
 *   ولا رقم مُرسِلها. يُمحى بأوّل رسالةٍ تُحفظ بعدها.
 * - `inbound_problem_at`: متى.
 * - `routing_state`: توجيهُ المحادثات عند ميتا (Conversation Routing) لهذا الرقم —
 *   أيمرّ الواردُ بأبعاد؟ (`WhatsAppLinkCheck::routing`). يُقرأ ولا يُضبط من هنا.
 *
 * أعمدةٌ فارغةٌ على الوصلات القائمة: لا تُملأ من الماضي، ولا يُمسّ رمزٌ ولا رقم.
 * ووصلةٌ قائمةٌ بلا ختمٍ تُقرأ «لم يصل شيءٌ بعد» حتى أوّل إشعار — لا يُدّعى عنها نجاح.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_connections', 'last_status_at')) {
                $table->timestamp('last_status_at')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'last_inbound_at')) {
                $table->timestamp('last_inbound_at')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'last_inbound_saved_at')) {
                $table->timestamp('last_inbound_saved_at')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'inbound_problem')) {
                $table->string('inbound_problem', 40)->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'inbound_problem_at')) {
                $table->timestamp('inbound_problem_at')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_connections', 'routing_state')) {
                $table->string('routing_state', 20)->nullable();
            }
        });
    }

    /** أختامُ وصولٍ تُملأ بأوّل إشعار — حذفُها لا يُضيّع رسالةً ولا رمزًا */
    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            foreach (['last_status_at', 'last_inbound_at', 'last_inbound_saved_at', 'inbound_problem', 'inbound_problem_at', 'routing_state'] as $column) {
                if (Schema::hasColumn('whatsapp_connections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
