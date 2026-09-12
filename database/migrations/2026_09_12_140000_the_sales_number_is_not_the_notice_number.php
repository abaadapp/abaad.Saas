<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رقمُ المبيعات ليس رقمَ الإشعارات — عمودٌ يقول ذلك، لا نيّة.
 *
 * ═══ لمَ لا يكفي رقمٌ واحد ═══
 *
 * رقمُ أبعادٍ المشترك يُرسل إشعاراتِ الطلبات **نيابةً عن المحلّات**، فيردّ
 * عليه زبائنُهم: «وصل؟»، «غيّر العنوان». وتلك رسائلُ زبونٍ لمحلِّه.
 *
 * وCRM يحتاج عكسَ ذلك تمامًا: أن يُقرأ الواردُ من **رقمٍ مجهول** — فالعميلُ
 * المحتمَل بتعريفه ليس مستخدمًا عندنا. فلو فُتح ذلك على رقم الإشعارات لَصارت
 * كلُّ زبونةِ محلِّ ورودٍ عميلًا محتملًا في دفتر مبيعاتنا، ونصُّ رسالتها
 * مقروءًا في لوحة المنصّة.
 *
 * والشرطان لا يجتمعان على رقمٍ واحد. فرقمان، و`purpose` عمودٌ يفصلهما.
 *
 * ═══ وما كان يكسر بلا هذا العمود ═══
 *
 * `WhatsAppConnections::platform()` تأخذ **أحدثَ** وصلةِ منصّةٍ نشطة. فربطُ
 * رقمِ مبيعاتٍ ثانٍ كان سيجعل إشعاراتِ طلبات كلّ المتاجر تخرج منه — يقرأ
 * الزبون رقمًا لا يعرفه، ويردّ عليه فلا يصل أحدًا.
 *
 * والافتراضُ `notifications`: الوصلةُ القائمة تبقى كما كانت بعد الترقية.
 *
 * ═══ ولمَ رسائلُ CRM جدولٌ مستقلّ ═══
 *
 * `support_conversations` تطلب `business_id` و`opened_by` — وللعميل المحتمَل
 * لا متجرَ ولا حساب. وإدخالُه هناك يعني تصفيةً بـ`whereNull` في **كلّ**
 * استعلامٍ في مركز المحادثات، وشرطٌ مكرَّرٌ في خمسة مواضع يُنسى في السادس —
 * والمنسيُّ هنا خيطُ مبيعاتٍ يظهر في صندوق الدعم، أو خيطُ دعمٍ يظهر في
 * المبيعات.
 *
 * والمشترَكُ الحقيقيّ ليس الجدول بل `MetaWhatsAppClient` و`WhatsAppPhone`
 * و`WebhookController` — وهي تُستعمل كما هي. لا محرّكَ واتسابٍ ثانٍ.
 *
 * والعميلُ المحتمَل **هو** الخيط: لا جدولَ محادثاتٍ بينهما. جدولٌ يحمل صفًّا
 * واحدًا لكلّ عميلٍ ولا شيء غيرَ ذلك هو وصلةٌ في كلّ استعلامٍ مقابل لا شيء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            // notifications | crm_sales — انظر App\Support\WhatsAppMode::PURPOSES
            $table->string('purpose', 20)->default('notifications')->after('owner_type');
        });

        Schema::table('crm_leads', function (Blueprint $table) {
            /*
             * ختمُ آخرِ واردٍ منه — ومنه وحدَه تُحسب نافذةُ ميتا.
             *
             * و`last_contact_at` يتحرّك بردّنا نحن أيضًا، فلو حُسبت منه
             * لَظنّت الشاشةُ البابَ مفتوحًا لأنّ موظّفَ المبيعات كتب — وميتا
             * تردّ الرسالة بالخطأ ١٣١٠٤٧.
             */
            $table->timestamp('whatsapp_window_at')->nullable()->after('last_contact_at');
        });

        Schema::create('crm_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();

            // in | out
            $table->string('direction', 4);
            $table->text('body')->nullable();

            /*
             * معرّفُ ميتا — فريدٌ في القاعدة لا مفحوصٌ في الكود.
             *
             * ميتا تُعيد الإشعارَ حين لا تصلها ٢٠٠ في الوقت، وتُعيده أحيانًا
             * وقد وصلتها. والفحصُ في PHP يقرأ ثمّ يكتب، وبينهما نافذةٌ يمرّ
             * منها الثاني فتُكتب رسالةُ العميل مرّتين في خيطه.
             */
            $table->string('external_message_id')->nullable()->unique();

            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_name', 100)->nullable();

            // sent | failed | blocked — وفارغٌ في الوارد: الوارد لا يُسلَّم
            $table->string('delivery', 12)->nullable();
            $table->string('delivery_error', 200)->nullable();

            /* نوعُ ما لا يُقرأ — صورةٌ أو صوت: يُكتب نوعُه لا يُسقَط الصفّ */
            $table->string('media_type', 20)->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_messages');

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn('whatsapp_window_at');
        });

        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
