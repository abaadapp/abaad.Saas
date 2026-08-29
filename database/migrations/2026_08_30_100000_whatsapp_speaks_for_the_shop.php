<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * واتساب: وصلةٌ واحدة لأبعاد، وأخرى اختيارية لكلّ محلّ.
 *
 * الرقم المشترك ملكُ المنصّة لا ملكُ متجر، فوصلتُه صفٌّ واحدٌ لا نسخةٌ في كلّ
 * متجر: نسخةُ رمزٍ في كلّ صفّ تعني أنّ تجديد الرمز يمرّ على مئة صفّ، وأنّ
 * تسريب صفٍّ واحد يُسرّب المفتاح كلّه.
 *
 * و`owner_type` يفرّق: «platform» وصلةُ أبعاد، و«business» وصلةُ محلٍّ ربط
 * رقمه بنفسه. جدولٌ واحد لأنّ الإرسال واحد — ولو فُصلا لَصار لكلٍّ منهما
 * مسار إرسالٍ يفترق عن أخيه عند أوّل تعديل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->id();
            // «platform» أو «business» — انظر App\Support\WhatsAppMode::OWNERS
            $table->string('owner_type', 20);
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('provider', 40)->default('meta_cloud');

            $table->string('waba_id')->nullable();
            /*
             * معرّف الرقم عند المزوّد — به وحده يُعرف صاحبُ الإشعار الوارد.
             *
             * الإشعار يصل من ميتا بلا معرّف متجرٍ ولا شيء يخصّنا، فلا يُوثق
             * بشيءٍ فيه إلا هذا. وهو فريدٌ عالميًّا فلا يصلح لمتجرين.
             */
            $table->string('phone_number_id')->nullable()->unique();
            $table->string('display_phone_number')->nullable();

            // مشفَّرٌ في النموذج (encrypted cast) — لا يُقرأ من القاعدة نصًّا
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            // active | inactive | expired | revoked | error
            $table->string('status', 20)->default('inactive');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'status']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('whatsapp_connection_id')->nullable()
                ->constrained('whatsapp_connections')->nullOnDelete();

            // من أيّ رقمٍ خرجت: abaad_shared أو business_own
            $table->string('source_mode', 20);
            $table->string('event_type', 40);
            $table->string('direction', 10)->default('outbound');

            $table->string('recipient_phone', 32)->nullable();
            $table->string('template_name')->nullable();
            $table->string('language_code', 10)->nullable();
            $table->string('provider_message_id')->nullable();

            /*
             * مفتاح منع التكرار.
             *
             * إعادةُ محاولةٍ من الطابور، أو حفظٌ مرّتين للحالة نفسها، أو ضغطةٌ
             * مزدوجة — كلّها تصل إلى هنا. وفريدٌ في القاعدة لا فحصٌ في الكود:
             * الفحص يقرأ ثمّ يكتب، وبينهما نافذةٌ يمرّ منها الثاني.
             */
            $table->string('dedupe_key')->unique();

            // queued | sent | delivered | read | failed | skipped | quota_exceeded
            $table->string('status', 20)->default('queued');
            // هل استهلكت من حصّة الشهر — الرسائل الممنوعة لا تستهلك
            $table->boolean('quota_consumed')->default(false);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'quota_consumed', 'created_at']);
            $table->index('provider_message_id');
            $table->index(['order_id', 'event_type']);
        });

        /*
         * عدّاد الشهر — صفٌّ لكلّ متجرٍ في كلّ شهر.
         *
         * ولمَ عدّادٌ وجدولُ الرسائل موجود؟ لأنّ `count(*)` ثمّ `send` يسمح
         * لرسالتين متزامنتين بقراءة «بقيت واحدة» معًا فتخرجان معًا. والزيادة
         * الشرطية `used = used + 1 where used < limit` تُنفَّذ في المحرّك ذرّةً
         * واحدة، فلا تنجح إلا واحدة.
         *
         * وجدول الرسائل يبقى دفتر الحقيقة الذي يُدقَّق؛ هذا عدّادٌ لا سجلّ.
         */
        Schema::create('whatsapp_usage_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'period_year', 'period_month'], 'wa_usage_period_unique');
        });

        /*
         * القوالب: قالبُ أبعاد المعتمَد للرقم المشترك، وقالبُ المحلّ لرقمه.
         *
         * ميتا لا تُرسل نصًّا حرًّا في رسالةٍ يبدؤها العمل — بل قالبًا معتمَدًا
         * باسمه ولغته ومتغيّراته. فاسمُ القالب بيانٌ لا نصّ، ويختلف بين حساب
         * أبعاد وحساب المحلّ. و`business_id = null` يعني قالب المنصّة.
         */
        Schema::create('whatsapp_template_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 20);
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->string('template_name');
            $table->string('language_code', 10)->default('ar');
            $table->boolean('enabled')->default(true);
            $table->json('variable_mapping')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'business_id', 'event_type'], 'wa_template_scope_unique');
        });

        /*
         * ما يخصّ كلّ متجر — أعمدةٌ على صفّه لا جدولٌ خامس.
         *
         * أربع قيمٍ تُقرأ مع كلّ رسالة، وثلاثٌ منها يكتبها مدير المنصّة وحده.
         * وجدولٌ منفصل لها يعني وصلةً في كلّ استعلام مقابل لا شيء.
         */
        Schema::table('businesses', function (Blueprint $table) {
            /*
             * مفعَّلٌ افتراضيًّا — والحارس فوقه لا تحته.
             *
             * لا شيء يخرج قبل أن يُفعّل مدير المنصّة واتساب عالميًّا ويربط
             * الرقم؛ فإطفاؤه هنا أيضًا يعني مقبضًا ثانيًا يُنسى، ثمّ سؤالًا:
             * «فعّلتُ واتساب فلماذا لا يُرسل لأحد؟».
             */
            $table->boolean('whatsapp_enabled')->default(true);
            // abaad_shared | business_own
            $table->string('whatsapp_mode', 20)->default('abaad_shared');
            /*
             * حدُّ هذا المتجر — و`null` تعني «حدّ المنصّة الافتراضي».
             *
             * ولا يُنسخ الافتراضيّ في كلّ صفّ: لو نُسخ لَما غيّره تعديلُ
             * الافتراضيّ في مئة متجر، ولَصار لكلّ متجرٍ تخصيصٌ لم يطلبه أحد.
             * و‎-1 تعني بلا حدّ.
             */
            $table->integer('whatsapp_monthly_limit')->nullable();
            // صلاحية ربط رقمٍ خاصّ — يمنحها مدير المنصّة وحده
            $table->boolean('whatsapp_own_allowed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_enabled', 'whatsapp_mode', 'whatsapp_monthly_limit', 'whatsapp_own_allowed',
            ]);
        });

        Schema::dropIfExists('whatsapp_template_mappings');
        Schema::dropIfExists('whatsapp_usage_periods');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_connections');
    }
};
