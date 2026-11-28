<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ‏‏قفلُ الفوترة — لا تخرج رسالةٌ من رقمٍ رُبط عبر تطبيق أبعاد قبل أن تثبت جهةُ رسومه (`WhatsAppBillingLock`).
 *
 * ═══ وما رُبط قبل هذا لا يقف ═══
 *
 * `billing_exempt` عمودٌ افتراضُه «لا»: كلُّ وصلةٍ تُكتب بعد اليوم مقفلةٌ حتى تثبت، من أيّ بابٍ جاءت. وما كان
 * قائمًا يُختم هنا مرّةً واحدة: **كلُّ وصلةٍ تعمل اليوم تبقى كما هي**، ومنها ما رُبط أو أُعيد ربطُه عبر الاعتماد
 * الآليّ (`whatsapp_sender_adoptions`) — متجرٌ قديم جدّد تفويضَه أمس له فيه صفّ، ولا يُوقَف إلّا بقرارٍ مستقلّ.
 * ويُقفل ممّا رُبط به ما لا يعمل أصلًا: لا شيءَ عنده يتوقّف. وكلُّها تظهر لمدير المنصّة «لم يتم التحقق».
 * ووصلةُ تطبيق التاجر (RIBBON) لا يقرؤها القفلُ أصلًا.
 *
 * ولا رقمَ بطاقةٍ ولا وسيلةَ دفع في أيّ عمودٍ هنا، ولا مبلغَ لم تقله Meta.
 */
return new class extends Migration
{
    private const TABLES = ['whatsapp_message_prices', 'whatsapp_billing_events', 'whatsapp_billing_trials', 'whatsapp_billings'];

    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->boolean('billing_exempt')->default(false);
        });

        $adopting = DB::table('whatsapp_sender_adoptions')->whereNotNull('whatsapp_connection_id')->select('whatsapp_connection_id');

        DB::table('whatsapp_connections')->where('owner_type', 'business')
            ->where(fn ($q) => $q->whereNotIn('id', $adopting)->orWhere(fn ($works) => $works
                ->whereIn('status', ['active', 'reauthorization_required'])
                ->where('phone_number_id', '<>', '')->where('access_token', '<>', '')
                ->where(fn ($live) => $live->whereNull('token_expires_at')->orWhere('token_expires_at', '>', now()))))
            ->update(['billing_exempt' => true]);

        // ‏‏حالُ فوترة حسابِ رسائلٍ بعينه (WABA) لمتجرٍ بعينه — يبقى وإن أُعيد الربط أو استُعيدت نسخة
        Schema::create('whatsapp_billings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('waba_id', 64);
            // unverified · pending · proven · problem · recheck
            $table->string('state', 20)->default('unverified')->index();
            $table->string('code', 60)->nullable();
            $table->string('owner_id', 64)->nullable();
            $table->string('owner_name', 150)->nullable();
            // ‏‏ما قالته Meta عند آخر قراءة — وما لم تقله يُكتب «لم يُقرأ»
            $table->json('facts')->nullable();
            // ‏‏ما رُوجع: نوعُ الدليل ومرجعُه ومن راجعه ومتى
            $table->json('evidence')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->string('requested_by', 120)->nullable();
            $table->timestamp('proven_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'waba_id']);
            $table->index('waba_id');
        });

        // ‏‏تجربةٌ واحدة مفتوحة في النظام كلِّه — يحرسها الفهرسُ الفريد لا الكود وحده
        Schema::create('whatsapp_billing_trials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('waba_id', 64);
            $table->string('phone_number_id', 64);
            // open · closed · passed
            $table->string('state', 20)->default('open')->index();
            $table->unsignedTinyInteger('open_slot')->nullable()->unique();
            $table->unsignedSmallInteger('message_limit');
            $table->unsignedSmallInteger('messages_used')->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('expires_at');
            $table->foreignId('opened_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('opened_by_name', 120);
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_why', 40)->nullable();
            $table->json('proof')->nullable();
            $table->timestamp('passed_at')->nullable();
            // ‏‏أُعيد القفلُ بعد نجاحها
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name', 120)->nullable();
            $table->string('revoked_why', 200)->nullable();
            $table->timestamps();

            $table->index('business_id');
        });

        // ‏‏سجلٌّ لا يُعدَّل: من طلب ومن راجع وماذا رُفض ولماذا وقف
        Schema::create('whatsapp_billing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('waba_id', 64)->nullable();
            $table->foreignId('trial_id')->nullable()->index()->constrained('whatsapp_billing_trials')->nullOnDelete();
            $table->string('event', 30);
            $table->string('code', 60)->nullable();
            $table->foreignId('by_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('by_name', 120);
            $table->json('data')->nullable();
            $table->timestamp('created_at');

            $table->index(['business_id', 'id']);
        });

        // ‏‏تصنيفُ Meta لكلّ رسالة (أتُحتسب، وبأيّ فئة) — ولا مبلغَ فيه: إشعارُها لا يحمله
        Schema::create('whatsapp_message_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_connection_id')->nullable()->index()->constrained('whatsapp_connections')->nullOnDelete();
            // business · platform — من أيّ رقمٍ خرجت، فعلى أيّ حسابٍ تُحتسب
            $table->string('owner_type', 20);
            $table->string('waba_id', 64)->nullable();
            $table->string('phone_number_id', 64)->nullable();
            $table->string('wamid', 191)->unique();
            // notice · campaign · chat · crm · other
            $table->string('kind', 20)->default('other');
            $table->boolean('billable')->nullable();
            $table->string('pricing_model', 20)->nullable();
            $table->string('pricing_type', 40)->nullable();
            $table->string('category', 40)->nullable();
            $table->string('subtype', 40)->nullable();
            $table->string('last_status', 20)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'created_at']);
            $table->index(['business_id', 'created_at']);
            $table->index(['waba_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        // ‏‏وفيها من أثبت الفوترة ومتى، وما قالته Meta عن كلّ رسالة — لا تُمحى بالرجوع بالكود
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException($name.' holds rows — roll the code back, not the data.');
            }
        }

        foreach (self::TABLES as $name) {
            Schema::dropIfExists($name);
        }

        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropColumn('billing_exempt');
        });
    }
};
