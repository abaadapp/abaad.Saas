<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ‏‏أتمتةُ القنوات — ردٌّ يكتبه التاجر مرّةً فيخرج وحده حين يكتب زبونٌ كلمةً بعينها.
 *
 * جدولان جديدان وحدهما، ولا يمسّان جدولًا قائمًا:
 *  - `channel_automations`: ما كتبه التاجر — الكلماتُ والردّ وأزرارُه وحالُه. والقناةُ عمودٌ
 *    (`whatsapp` اليوم، وإنستقرام بعده) فمحرّكٌ واحدٌ يخدمهما.
 *  - `channel_automation_runs`: سطرٌ لكلّ رسالةٍ طابقت أتمتة — خرج الردّ أو فشل أو مُنع وبأيّ
 *    سبب. ومفتاحُه الفريد (`source_key`) يمنع ردّين على رسالةٍ واحدة، ومنه عدّاداتُ الشاشة.
 *
 * والردُّ الترحيبيّ القائم لا يُنقل إلى هنا: يبقى في إعداداته كما هو، فلا يتغيّر سلوكُ متجرٍ يومَ النشر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('name', 80);
            $table->string('template', 40)->nullable();
            $table->string('trigger', 30)->default('keyword');
            $table->string('match', 10)->default('contains');
            $table->json('keywords');
            $table->json('excluded')->nullable();
            $table->text('reply_ar')->nullable();
            $table->text('reply_en')->nullable();
            $table->json('buttons')->nullable();
            $table->foreignId('product_id')->nullable()->index()->constrained('products')->nullOnDelete();
            $table->string('status', 12)->default('draft');
            $table->unsignedInteger('cooldown_minutes')->default(60);
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'channel', 'status']);
        });

        Schema::create('channel_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->nullable()->index()->constrained('channel_automations')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('source_key', 191);
            $table->string('recipient', 64);
            $table->string('status', 12);
            $table->string('reason', 60)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['business_id', 'source_key']);
            $table->index(['business_id', 'recipient', 'automation_id']);
        });
    }

    public function down(): void
    {
        // ‏‏ولا يُسقطان وفيهما ما كتبه التاجر بيده: أرجِع الكود وحده، والجدولان يبقيان بلا قارئ
        if (Schema::hasTable('channel_automations') && DB::table('channel_automations')->exists()) {
            throw new RuntimeException('أتمتة القنوات: في القاعدة أتمتاتٌ كتبها التجّار — لا يُسقط الجدول. أرجِع الكود وحده.');
        }

        Schema::dropIfExists('channel_automation_runs');
        Schema::dropIfExists('channel_automations');
    }
};
