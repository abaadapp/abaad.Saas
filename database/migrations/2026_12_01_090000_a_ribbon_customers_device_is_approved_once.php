<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * بوابةُ عملاء RIBBON — جهازٌ كُتب فيه رقمُ هاتفٍ وينتظر أن يعتمده موظّف. جدولٌ جديد، ولا يُمسّ قائم.
 *
 * والصفُّ ليس جلسةً ولا يُقرأ منه شيء: الجلسةُ تُكتب عند الاعتماد في جدولها. ولا يُحفظ الرقمُ ولا
 * رمزُ الجهاز: بصمةُ الأوّل بمفتاح الخادم وبصمةُ الثاني (sha256). ويُكتب لكلّ رقمٍ كُتب، مسجَّلٍ أو
 * غيره — فلا يقول الردُّ ولا زمنُه أيُّهما هو.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_portal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->char('phone_hash', 64);
            $table->char('token_hash', 64)->unique();
            $table->string('device', 40)->nullable();
            $table->unsignedTinyInteger('tries')->default(0);
            $table->timestamp('expires_at');
            // من اعتمده ولمن — أو لا شيء وهو ينتظر
            $table->foreignId('customer_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'phone_hash']);
            $table->index(['business_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        // من اعتمد جهازًا ولمن — لا يُمحى بالرجوع بالكود
        if (Schema::hasTable('customer_portal_requests') && DB::table('customer_portal_requests')->exists()) {
            throw new RuntimeException('customer_portal_requests holds rows — roll the code back, not the data.');
        }

        Schema::dropIfExists('customer_portal_requests');
    }
};
