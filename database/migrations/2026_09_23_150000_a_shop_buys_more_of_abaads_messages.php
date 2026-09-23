<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رقمُ المتجر لكلِّ متجر — ورسائلُ أبعاد تُشترى حين تنفد.
 *
 * ═══ الأوّل: إذنُ الرقم الخاصّ يُمنح بالولادة ═══
 *
 * كان `whatsapp_own_allowed` يبدأ `false`، فيُمنح متجرًا متجرًا بيد مدير
 * المنصّة. وهذا يعني أنّ ميزةً مبنيّةً كاملة — تسجيلٌ مدمج يربط التاجر رقمه
 * بنفسه في نافذةٍ من ميتا — لا يراها أحد: أربعةُ متاجر على الإنتاج، وفي
 * أربعتها الإذنُ مطفأ، فالزرُّ لا يظهر لأحد ولا أحدَ يعرف أنّه موجود.
 *
 * والمقبضُ يبقى: من أساء استعمالَ رقمه يُسحب منه، والسحبُ لا يُتلف وصلتَه
 * (انظر `WhatsAppFeature::effectiveMode`). لكنّ الافتراضَ ينقلب — يُمنح
 * فيُسحب، لا يُمنع فيُستأذن.
 *
 * ═══ والثاني: ما بعد الحصّة يُشترى ولا يُنتظر ═══
 *
 * حصّةُ الشهر من رقم أبعاد تنفد، فيقف الإرسال إلى أوّل الشهر القادم ويُقال
 * للتاجر «تعود مع الشهر الجديد» — في اليوم الثالث من الشهر. فمتجرٌ يبيع
 * لا يُخبر زبائنه ثمانيةً وعشرين يومًا، وأبعادُ لا تكسب من ذلك ريالًا.
 *
 * فعمودان وجدول:
 *
 *   `whatsapp_message_credits` رصيدٌ مشترًى — يُستهلَك بعد حصّة الشهر، **ولا
 *   ينتهي بانتهائها**. ومن اشترى خمسمئةً في الثامن والعشرين لا يخسر أربعمئةً
 *   في الأوّل: الحصّةُ عطيّةٌ شهريّة تُجدَّد، والرصيدُ مالٌ دُفع.
 *
 *   `whatsapp_messages.quota_source` من أيّ جيبٍ خرجت هذه الرسالة — لأنّ ما
 *   يرفضه المزوّد يُردّ إلى جيبه هو. ولولاه لَردّت رسالةٌ مشتراةٌ فاشلةٌ
 *   عدّادَ الشهر فيكسب التاجر رسالةً مجّانيّةً ويخسر ريالَه.
 *
 *   `whatsapp_message_packs` طلبُ الشراء وحياتُه: يطلبه التاجر، وتُصدَر له
 *   فاتورةُ منصّةٍ برقمها، وحين يُسجَّل سدادُها يُضاف الرصيد. ولا بوّابةَ دفعٍ
 *   في المستودع كلِّه — فالسدادُ تحويلٌ بنكيّ يُراجعه صاحب المنصّة، كما
 *   يجري فعلًا في تجديد الاشتراكات اليوم (انظر `Billing`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('whatsapp_own_allowed')->default(true)->change();

            /*
             * الرصيدُ المشترى — ولا يقبل السالب.
             *
             * `unsignedInteger` لا زينة: الخصمُ الذرّيّ مشروطٌ بـ`> 0`، ولو
             * أفلت شرطٌ يومًا لَالتفّ العمود الموقَّع إلى رقمٍ هائل فصار
             * النفادُ رصيدًا بلا حدّ.
             */
            $table->unsignedInteger('whatsapp_message_credits')->default(0);
        });

        /*
         * وكلُّ المتاجر القائمة تُمنح الإذن — لا القادمةُ وحدها.
         *
         * الافتراضُ أعلاه يخصّ من يُنشأ بعد هذه الهجرة. ومن سُجّل قبلها
         * يحمل `false` في صفّه، فلو تُرك لَكان «كلُّ متجر» تعني «كلَّ متجرٍ
         * جديد» — وهو غيرُ ما طُلب.
         */
        DB::table('businesses')->update(['whatsapp_own_allowed' => true]);

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // monthly | credit — و`null` لرسالةٍ لم تُحجَز أصلًا أو خرجت من رقم المحلّ
            $table->string('quota_source', 10)->nullable()->after('quota_consumed');
        });

        /*
         * ═══ وفاتورةُ المنصّة تقول ما بيعَ فيها ═══
         *
         * `invoices` كانت تحمل مبلغًا ورقمًا و`plan_id` — وكفى، لأنّ كلّ
         * فاتورةٍ فيها كانت دورةَ اشتراك. فأوّلُ فاتورةِ رسائلَ تدخلها تصير
         * سطرًا لا يُميَّز عن تجديدٍ في شاشةِ من يُحصّل.
         *
         * وأخطرُ من الشاشة: `Billing::markPaid` كانت تُعلّم **آخرَ اشتراكٍ
         * غيرِ مدفوع** مدفوعًا مع كلّ فاتورةٍ تُسدَّد. فمن اشترى رسائلَ
         * وسدّدها كان اشتراكُه السنويُّ غيرُ المدفوع يُقيَّد مدفوعًا بخمسة
         * ريالات. و`kind` هي ما يفصل الحالتين — انظر الدالّة نفسها.
         */
        Schema::table('invoices', function (Blueprint $table) {
            // اشتراك | رسائل
            $table->string('kind', 20)->default('اشتراك');
            /* ما بيعَ فيها بكلماتٍ تُقرأ: «٥٠٠ رسالة واتساب» */
            $table->string('note', 160)->nullable();
        });

        Schema::create('whatsapp_message_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            /* كم رسالةً تُضاف إلى الرصيد حين يُسجَّل السداد */
            $table->unsignedInteger('messages');

            /* ثمنُها كما كان يومَ الطلب — لقطةٌ لا تُقرأ من الإعداد بعد شهر */
            $table->decimal('amount', 10, 3)->default(0);

            // مطلوبة | مصدَّرة | مدفوعة | ملغاة
            $table->string('status', 20)->default('مطلوبة');

            /*
             * الفاتورةُ تُنشأ عند الاعتماد لا عند الطلب.
             *
             * وطلبٌ يُلغى قبل الاعتماد لا يترك فاتورةً في دفتر المنصّة —
             * ولو أُنشئت مع الطلب لَامتلأ الدفترُ بفواتيرَ لم يطلبها أحد.
             */
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            /* من طلبه من موظّفي المتجر — يُقرأ يوم يُسأل «من اشترى هذا؟» */
            $table->string('requested_by_name', 100)->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            /*
             * طلبٌ معلَّقٌ واحدٌ لكلّ متجر — والحارسُ في الكود لا في الفهرس.
             *
             * فهرسٌ فريدٌ على (business_id, status) كان سيمنع **طلبين
             * مدفوعين** أيضًا، وهما الحالةُ الطبيعيّة لمن اشترى مرّتين.
             */
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_packs');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['kind', 'note']);
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('quota_source');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('whatsapp_message_credits');
            $table->boolean('whatsapp_own_allowed')->default(false)->change();
        });
    }
};
