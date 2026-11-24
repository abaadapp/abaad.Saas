<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ‏‏رقمٌ رُبط — متى تُحقِّق من جاهزيّته، ومتى صار رقمَ إرسال المتجر، وما أصابه بعدها (`WhatsAppSender`).
 *
 * جدولٌ واحدٌ جديد، ولا يمسّ جدولًا قائمًا ولا وصلةً ولا وضعَ إرسال: الوصلاتُ التي رُبطت قبله لا صفَّ لها
 * فيه، فلا يبدّل إرسالَها شيء. وكلُّ ربطٍ بعده صفٌّ — وهو السجلّ: من أيّ رقمٍ إلى أيّ رقم ومتى ولماذا انتظر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_sender_adoptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_connection_id')->nullable()->index()->constrained('whatsapp_connections')->nullOnDelete();
            $table->string('phone_number_id', 64);
            $table->string('phone', 40)->nullable();
            // embedded · client_link · manual · registered
            $table->string('via', 20);
            $table->foreignId('started_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('started_by_name', 120)->nullable();
            // checking · adopted · closed
            $table->string('state', 20);
            $table->string('from_mode', 20)->nullable();
            // ‏‏أوّلُ ما يمنع الاعتماد الآن، ومراحلُ آخر فحصٍ كلٌّ بحاله
            $table->string('code', 60)->nullable();
            $table->json('checks')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('adopted_at')->nullable();
            $table->string('closed_why', 40)->nullable();
            $table->timestamp('closed_at')->nullable();
            // ‏‏وبعد الاعتماد: ما وجده الفحصُ الدوريّ في رقمٍ يُرسل منه
            $table->string('health_code', 60)->nullable();
            $table->json('health_params')->nullable();
            $table->unsignedSmallInteger('health_seen')->default(0);
            $table->timestamp('health_since')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'id']);
            $table->index(['state', 'next_check_at']);
            $table->index('adopted_at');
        });
    }

    public function down(): void
    {
        // ‏‏وفيها سجلُّ من أيّ رقمٍ خرجت رسائلُ المتجر ومتى — لا تُمحى بالرجوع بالكود
        if (Schema::hasTable('whatsapp_sender_adoptions') && DB::table('whatsapp_sender_adoptions')->exists()) {
            throw new RuntimeException('whatsapp_sender_adoptions holds rows — roll the code back, not the data.');
        }

        Schema::dropIfExists('whatsapp_sender_adoptions');
    }
};
