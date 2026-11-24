<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ‏‏وصلةُ إنستقرام للمتجر — يضغط التاجر «تسجيل الدخول عبر Instagram» فيرجع مربوطًا.
 *
 * ثلاثةُ جداول جديدة وحدها، ولا تمسّ جدولًا قائمًا:
 *  - `instagram_connections`: حسابٌ احترافيٌّ واحد لكلّ متجر، ولا يُربط حسابٌ بمتجرين
 *    (`ig_user_id` فريد). والرمزُ مشفَّرٌ في عموده، ويُجدَّد قبل انتهاء أيّامه الستّين.
 *  - `instagram_events`: إشعاراتُ إنستقرام كما وصلت، بمفتاحٍ فريد يمنع معالجةَ المكرَّر — Meta تعيد
 *    الإرسال ستًّا وثلاثين ساعة. تُحفظ فورًا ويُجاب بـ200، وتُعالج بعدها، وتُحذف بعد ثلاثين يومًا.
 *  - `instagram_data_deletions`: طلباتُ حذف البيانات التي ترسلها Meta حين يطلبها صاحبُ الحساب —
 *    برمز تأكيدٍ وصفحةِ حال، كما تشترط Meta. سجلُّ منصّة يبقى ولو حُذف المتجر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('ig_user_id', 64)->unique();
            $table->string('ig_scoped_id', 64)->nullable()->index();
            $table->string('username', 100);
            $table->string('name', 150)->nullable();
            $table->string('account_type', 30)->nullable();
            $table->text('profile_picture_url')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('permissions')->nullable();
            $table->json('subscribed_fields')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->foreignId('connected_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('instagram_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instagram_connection_id')->nullable()->index()->constrained('instagram_connections')->nullOnDelete();
            $table->string('event_key', 191);
            $table->string('field', 40);
            $table->json('payload');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->unique(['business_id', 'event_key']);
            $table->index(['business_id', 'processed_at']);
            $table->index('received_at');
        });

        Schema::create('instagram_data_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('confirmation_code', 40)->unique();
            $table->string('meta_user_id', 64)->index();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->string('status', 20)->default('received');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // ‏‏ولا تُسقط وفيها وصلاتٌ ربطها التجّار أو طلباتُ حذفٍ وعدنا Meta بحالها
        foreach (['instagram_connections', 'instagram_data_deletions'] as $t) {
            if (Schema::hasTable($t) && DB::table($t)->exists()) {
                throw new RuntimeException("إنستقرام: في {$t} صفوفٌ قائمة — لا يُسقط الجدول. أرجِع الكود وحده.");
            }
        }

        Schema::dropIfExists('instagram_events');
        Schema::dropIfExists('instagram_data_deletions');
        Schema::dropIfExists('instagram_connections');
    }
};
