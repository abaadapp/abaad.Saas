<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التسجيل المدمج — التاجر يربط رقمه بضغطة، ولا يلصق رمزًا قطّ.
 *
 * ═══ ما يُضاف ولمَ ═══
 *
 * كان الربط لصقَ ثلاثة أسطرٍ من لوحة مطوّري ميتا: معرّف الرقم ومعرّف حساب
 * الأعمال والرمز. وهذا يعني أنّ التاجر يفتح حسابَ مطوّرين، ويُنشئ رمزًا
 * دائمًا بيده، ويُرسله إلينا في حقلٍ نصّيّ — ثلاثةُ أبوابٍ يمرّ منها الخطأ
 * والسرّ معًا. والتسجيل المدمج يُغني عنها: ميتا تُعيد **كودًا** عمرُه ثلاثون
 * ثانية، والخادمُ وحده يبدّله برمز، فلا يمرّ السرُّ بمتصفّحٍ ولا بيد.
 *
 * والأعمدةُ تُضاف ولا يُمسّ عمودٌ قائم: على الإنتاج وصلةٌ حيّة تُرسل اليوم،
 * وإعادةُ تسميةِ عمودٍ تحتها تقطع الإرسال في لحظة النشر.
 *
 *   `meta_business_id`      — حساب الأعمال في ميتا الذي يملك الـWABA.
 *   `coexistence`           — هل رُبط الرقمُ وهو باقٍ في تطبيق واتساب للأعمال.
 *   `connected_by_user_id`  — من ربط، فيُسأل يوم ينقطع.
 *   `last_webhook_at`       — آخر إشعارٍ وصل على هذا الرقم؛ صمتُ الإشعارات
 *                             عطبٌ لا يُكتشف إلّا بسؤال «متى آخرُ ما وصل؟».
 *   `last_error_*`          — سببُ آخر فشلٍ كما قالته ميتا، يُعرض للتاجر
 *                             مختصرًا ويُقيَّد كاملًا في السجلّ.
 *
 * ولا عمودَ لحالةٍ جديدة: `status` نصٌّ، و«بانتظار ميتا» و«يحتاج إعادة
 * تفويض» قيمتان فيه — انظر `WhatsAppConnection::PENDING` و`REAUTH`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->string('meta_business_id')->nullable()->after('waba_id');
            $table->boolean('coexistence')->default(false)->after('display_phone_number');
            $table->foreignId('connected_by_user_id')->nullable()->after('connected_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('last_webhook_at')->nullable()->after('disconnected_at');
            $table->string('last_error_code', 60)->nullable()->after('last_webhook_at');
            $table->text('last_error_message')->nullable()->after('last_error_code');
            $table->timestamp('last_error_at')->nullable()->after('last_error_message');
        });

        /*
         * الوارد على رقم المحلّ — صفٌّ لكلّ رسالةٍ وصلت، ومعرّفها فريد.
         *
         * ═══ ولمَ جدولٌ وقد كان الواردُ يُسقَط ═══
         *
         * رقمُ الإشعارات المشترك يقرأ واردَه في `support_messages`، ورقمُ
         * المبيعات في `crm_messages`. أمّا رقمُ المحلّ فكان واردُه يُسقَط —
         * ولا بأسَ ما دام لا أحد يردّ عليه. فلمّا صار للمحلّ ردٌّ تلقائيّ
         * وجب موضعان: موضعٌ يقول «هذه الرسالة عولجت» فلا تُعالج مرّتين حين
         * تُعيد ميتا الإشعار، وموضعٌ يقول «رددنا على هذا الرقم قبل ساعة»
         * فلا يُقصف الزبون بردٍّ على كلّ سطرٍ يكتبه.
         *
         * والفريدُ في القاعدة لا فحصٌ في الكود: الفحصُ يقرأ ثمّ يكتب، وبين
         * القراءة والكتابة نافذةٌ يمرّ منها إشعارٌ ثانٍ وصل في اللحظة نفسها.
         *
         * ولا يُخزَّن نصُّ الرسالة: هذا دفترُ معالجةٍ لا صندوقُ واردٍ —
         * ومحتوى محادثات زبائن المحلّ ليس ممّا نحفظه بلا حاجة.
         */
        Schema::create('whatsapp_inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('whatsapp_connection_id')->constrained('whatsapp_connections')->cascadeOnDelete();

            /** معرّف الرسالة عند ميتا (wamid) — الحارسُ الأخير ضدّ التكرار */
            $table->string('wamid')->unique();
            $table->string('from_phone', 32);
            $table->string('message_type', 20)->nullable();
            $table->timestamp('received_at');

            // حالُ الردّ التلقائيّ عليها: skipped | sent | failed
            $table->string('reply_status', 20)->nullable();
            $table->string('reply_reason', 60)->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            /* «هل رُدَّ على هذا الرقم قريبًا؟» — سؤالُ كلّ رسالةٍ واردة */
            $table->index(['business_id', 'from_phone', 'replied_at'], 'wa_inbound_recent_reply');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbound_messages');

        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('connected_by_user_id');
            $table->dropColumn([
                'meta_business_id', 'coexistence', 'last_webhook_at',
                'last_error_code', 'last_error_message', 'last_error_at',
            ]);
        });
    }
};
