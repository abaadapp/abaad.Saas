<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * بوابةُ عملاء RIBBON — روابطُ الدخول الشخصيّة وجلساتُها. جدولان جديدان، ولا يُمسّ جدولٌ قائم.
 *
 * الرمزُ نفسُه لا يُحفظ في أيٍّ منهما: بصمتُه وحدها (sha256)، فمن قرأ القاعدة أو نسخةً منها لا
 * يملك رابطًا يعمل ولا جلسة. و`nonce` عددٌ عشوائيٌّ يُشتقّ منه الرمزُ بمفتاح الخادم ليُنسخ الرابطُ
 * نفسُه ثانيةً بلا إبطاله — ولا يُشتقّ منه وحدَه شيء. و`phone_hash` بصمةُ الرقم يومَ صدر: تغيّرُه
 * يُبطل الرابط. ولا مالَ ولا نقاطَ هنا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_portal_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->char('nonce', 64);
            $table->char('phone_hash', 64);
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_why', 30)->nullable();
            $table->foreignId('revoked_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'customer_id']);
        });

        // رابطٌ حيٌّ واحدٌ لكلّ عميل — فموظّفان يُصدران معًا لا يكتبان رابطين
        DB::statement('CREATE UNIQUE INDEX customer_portal_links_one_live ON customer_portal_links (customer_id) WHERE revoked_at IS NULL');

        Schema::create('customer_portal_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('link_id')->constrained('customer_portal_links')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['link_id', 'revoked_at']);
            $table->index(['business_id', 'expires_at']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        // من شُورك رابطُه ومتى أُلغي — لا يُمحى بالرجوع بالكود
        foreach (['customer_portal_sessions', 'customer_portal_links'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException("{$name} holds rows — roll the code back, not the data.");
            }
        }

        Schema::dropIfExists('customer_portal_sessions');
        Schema::dropIfExists('customer_portal_links');
    }
};
