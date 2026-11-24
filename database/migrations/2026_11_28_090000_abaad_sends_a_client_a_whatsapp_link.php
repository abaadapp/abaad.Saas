<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ‏‏روابطُ ربط واتساب — يرسلها فريقُ أبعاد إلى صاحب المتجر فيربط رقمَه بلا حسابٍ ولا دخول.
 *
 * جدولٌ واحدٌ جديد، ولا يمسّ جدولًا قائمًا. والرمزُ نفسُه لا يُحفظ: بصمتُه وحدها (sha256)، فمن قرأ
 * القاعدة أو نسخةً منها لا يملك رابطًا يعمل. وكلُّ صفٍّ يقول من أنشأه ولمن، ومتى فُتح واستُعمل وأُلغي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_client_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('opened_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_problem', 255)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->foreignId('whatsapp_connection_id')->nullable()->index()->constrained('whatsapp_connections')->nullOnDelete();
            $table->string('connected_phone', 40)->nullable();
            $table->string('connected_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name', 120)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'id']);
            $table->index('connected_at');
        });
    }

    public function down(): void
    {
        // ‏‏وفيها سجلُّ من ربط ومتى — لا تُمحى بالرجوع بالكود
        if (Schema::hasTable('whatsapp_client_links') && DB::table('whatsapp_client_links')->exists()) {
            throw new RuntimeException('whatsapp_client_links holds rows — roll the code back, not the data.');
        }

        Schema::dropIfExists('whatsapp_client_links');
    }
};
