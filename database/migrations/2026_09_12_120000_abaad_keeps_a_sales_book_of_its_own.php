<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفترُ مبيعاتِ أبعادٍ نفسِها — وهو غيرُ دفاترِ التجّار.
 *
 * ═══ ثلاثةُ نطاقاتٍ لا تختلط ═══
 *
 * (أ) أبعاد ↔ تاجرٌ محتمَل — هذا الجدول.
 * (ب) أبعاد ↔ تاجرٌ قائم — `support_conversations`، ولها بابُها.
 * (ج) تاجرٌ ↔ زبائنُه — `whatsapp_messages` تحت `business_id`، ولا سبيلَ
 *     من هنا إليها بحال.
 *
 * وخلطُ (أ) بـ(ج) هو الخطرُ الحقيقيّ: رقمُ أبعادٍ المشترك يُرسل إشعاراتِ
 * الطلبات نيابةً عن المحلّات، فيردّ عليه زبائنُهم. ولو صار كلُّ رقمٍ مجهولٍ
 * يكتب إليه «عميلًا محتملًا» لَصارت زبونةُ محلِّ ورودٍ سألت عن هديّتها صفًّا
 * في دفتر مبيعاتنا، ونصُّ رسالتها مقروءًا في لوحة المنصّة.
 *
 * فلا شيء يُكتب هنا من ذلك الرقم. والوارد لا يصنع عميلًا محتملًا إلّا على
 * وصلةٍ غرضُها `crm_sales` صراحةً — وتلك مرحلةٌ تالية، وهذا الجدولُ يُملأ
 * اليوم باليد.
 *
 * ═══ ولمَ جدولٌ مستقلٌّ لا عمودٌ على `businesses` ═══
 *
 * العميلُ المحتمَل **ليس متجرًا**: لا اشتراكَ له ولا باقةَ ولا مستخدمَ ولا
 * فرع. وصفٌّ في `businesses` باسمه يعني متجرًا يظهر في العدّادات وفي
 * الفواتير وفي حدود الباقات قبل أن يشتري شيئًا — ويعني أنّ «تحويله» لاحقًا
 * يصنع نسخةً ثانية.
 *
 * فهو صفٌّ هنا، وحين يشتري يُربَط بـ`businesses` برابطٍ واحد: عمودُ
 * `converted_business_id`. لا نسخَ ولا تكرار.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();

            /*
             * الرقمُ المطبَّع — هويّةُ العميل المحتمَل، وفريدٌ في القاعدة.
             *
             * ولمَ عمودان للرقم؟ لأنّ من يكتب «+968 9123 4567» يريد أن يجده
             * كما كتبه، و«96891234567» هو ما يُقارَن به. وعمودٌ واحد يعني
             * إمّا تصحيحَ ما كتبه الناس بلا طلبهم، وإمّا مطابقةً لا تنجح.
             *
             * والتفرّدُ في القاعدة لا في الكود: الفحصُ يقرأ ثمّ يكتب، وبينهما
             * نافذةٌ يمرّ منها إشعارٌ ثانٍ من ميتا فيصير للرقم الواحد صفّان
             * — ثمّ يردّ موظّفان على نصفَي محادثةٍ واحدة.
             */
            $table->string('phone', 20)->unique();
            $table->string('phone_raw', 40)->nullable();

            /* الاسمُ كما قاله صاحبُه — ولا يُخترع: بلا اسمٍ يُعرض الرقم */
            $table->string('name', 150)->nullable();
            $table->string('business_name', 150)->nullable();
            $table->string('wilayat', 80)->nullable();
            $table->unsignedSmallInteger('branches_count')->nullable();
            $table->string('current_system', 120)->nullable();

            // whatsapp | phone | manual | website | referral | instagram — انظر Crm::SOURCES
            $table->string('source', 20)->default('manual');

            /*
             * الحالُ والمرحلةُ عمودان لا عمود.
             *
             * الحالُ يقول إن كان الصفُّ حيًّا (`active`) أو انتهى (`converted`
             * أو `lost`). والمرحلةُ تقول أين هو من المسار. وواحدٌ يحمل
             * الاثنين يفقد الفرقَ بين «مهتمٌّ ولم يُتابَع منذ شهر» و«خسرناه»:
             * الأوّل يُتابَع والثاني يُقرأ في التقرير.
             */
            $table->string('status', 12)->default('active');
            $table->string('stage', 20)->default('new');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamp('first_contact_at')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();

            $table->foreignId('interested_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->decimal('expected_value', 12, 3)->nullable();

            /*
             * سببُ الخسارة — نصٌّ من قائمةٍ مغلقة، ومعه سطرٌ حرّ.
             *
             * وبلا قائمةٍ مغلقة لا يُجمع التقرير: «السعر» و«غالي» و«الأسعار
             * مرتفعة» ثلاثةُ صفوفٍ في تقريرٍ يُفترض أن يقول رقمًا واحدًا.
             */
            $table->string('lost_reason', 30)->nullable();
            $table->string('lost_note', 300)->nullable();

            /* الرابطُ الوحيد إلى عالم المتاجر — ولا نسخةَ لبياناتها هنا */
            $table->foreignId('converted_business_id')->nullable()
                ->constrained('businesses')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->json('tags')->nullable();
            $table->string('notes_summary', 300)->nullable();
            $table->timestamps();

            $table->index(['status', 'stage']);
            $table->index(['assigned_to', 'status']);
            $table->index('last_contact_at');
            $table->index('next_follow_up_at');
        });

        /*
         * الملاحظاتُ الداخليّة — تُقرأ في المنصّة ولا تخرج منها أبدًا.
         *
         * واسمُ كاتبها يُنسخ مع معرّفه: موظّفٌ يُحذف حسابُه بعد سنةٍ لا يجوز
         * أن يُمحى معه من الدفتر أنّ أحدًا كتب هذا.
         */
        Schema::create('crm_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 100);
            $table->text('body');
            $table->timestamps();

            $table->index(['lead_id', 'id']);
        });

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->string('title', 200);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * موعدٌ مطلوب — لا اختياريّ.
             *
             * مهمّةٌ بلا موعدٍ لا تتأخّر أبدًا، فلا تظهر في «المتأخّرة» ولا
             * تُنبّه أحدًا. وهي بذلك سطرٌ يُكتب ليُنسى.
             */
            $table->timestamp('due_at');
            // open | done | cancelled
            $table->string('status', 12)->default('open');
            // low | normal | high
            $table->string('priority', 10)->default('normal');
            $table->string('notes', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['assigned_to', 'status', 'due_at']);
            $table->index(['lead_id', 'status']);
        });

        /*
         * تاريخُ المراحل — سطرٌ لكلّ انتقال، ولا يُمحى.
         *
         * وبلا هذا لا يُحسب شيء: «كم يومًا يقضي العميل بين (مهتمّ) و(عرض
         * سعر)؟» سؤالٌ لا يجيبه عمودُ `stage` الحاليّ، لأنّه يحمل الآن
         * وحدَه. ومسارُ التحويل في التقارير يُقرأ من هنا لا من الحال.
         */
        Schema::create('crm_stage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 100);
            $table->string('reason', 300)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['lead_id', 'id']);
            $table->index(['to_stage', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_stage_events');
        Schema::dropIfExists('crm_tasks');
        Schema::dropIfExists('crm_notes');
        Schema::dropIfExists('crm_leads');
    }
};
