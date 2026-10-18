<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ورقةٌ تنتظر صندوقَها ليطبعها — طابورُ طباعةٍ في الخادم.
 *
 * إشعارُ Paymob يصل الخادم، والطابعةُ على شبكة المحلّ (192.168.x.x) لا
 * يبلغها الخادمُ أبدًا. فالورقةُ تُكتب هنا **مهمّةً** تنتظر، والصندوقُ
 * المعيَّن يسألها حين يكون مفتوحًا، ويحجزها، ويطبعها، ويقول ما جرى.
 *
 * - `dedupe_key` فريد: مهمّةٌ منطقيّةٌ واحدةٌ لكلّ ورقة — ولو تكرّر الإشعار.
 * - `status`: `pending` ← `claimed` (حجزها صندوق) ← `printed` أو `failed`.
 *   والحجزُ شرطٌ في UPDATE واحد: صندوقان يسألان معًا فيأخذها أحدُهما.
 * - `claim_expires_at`: حجزٌ لم يُردّ عليه في مهلته لا يُعاد وحده (قد تكون
 *   خرجت)، بل يصير `failed` ويُعاد بيدٍ بعد أن يُنظر في الورق.
 * - `via`: كيف خرجت — `native` (طابعةُ الشبكة في تطبيق أبعاد) أو `page`/
 *   `share`/`browser` (حوارُ المتصفّح: طُلب، ولا يُعرف أطُبع).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('print_jobs')) {
            return;
        }

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // الصندوقُ المعيَّن حين كُتبت — وفارغٌ: أيُّ صندوقٍ معيَّنٍ في فرعها
            $table->foreignId('pos_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();

            $table->string('kind', 40);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('dedupe_key', 120)->unique();

            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('claimed_by_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('via', 20)->nullable();
            $table->string('last_error', 255)->nullable();
            // من أعادها إلى الطابور بيده — إن أُعيدت
            $table->foreignId('requeued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status'], 'print_jobs_business_status_idx');
            $table->index(['subject_type', 'subject_id'], 'print_jobs_subject_idx');
            $table->index('branch_id', 'print_jobs_branch_idx');
            $table->index('pos_device_id', 'print_jobs_device_idx');
            $table->index('claimed_by_device_id', 'print_jobs_claimed_by_idx');
            $table->index('requeued_by', 'print_jobs_requeued_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
