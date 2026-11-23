<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ‏‏جهاتُ اتّصال التاجر من تطبيق واتساب للأعمال — حين يبقى رقمُه في التطبيق ويصل أبعاد معًا (التعايش).
 *
 * تُرسلها Meta بعد طلب المزامنة، **مرّةً واحدة** ولا يُعاد طلبُها إلّا بإعادة الربط من أوّله.
 * فما لم يُحفظ يوم وصوله ضاع. ولا يُنشأ منها عميلٌ في المتجر: تبقى جهاتِ اتّصالٍ حتّى يقرّر
 * التاجر (`WhatsAppCoexistence::contacts`).
 *
 * والمفتاحُ معرّفُ المستخدم عند Meta (`user_id`، خاصٌّ بالنشاط) — فالرقمُ قد لا يُرسَل.
 * جدولٌ جديد وحده، ولا يمسّ جدولًا قائمًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_connection_id')->nullable()->index()->constrained('whatsapp_connections')->nullOnDelete();
            $table->string('user_id', 100);
            $table->string('phone', 32)->nullable();
            $table->string('full_name', 150)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
            $table->index(['business_id', 'phone']);
        });
    }

    public function down(): void
    {
        // ‏‏ولا يُسقط وفيه جهاتٌ وصلت: Meta لا تُرسلها ثانيةً إلّا بإعادة الربط
        if (Schema::hasTable('whatsapp_contacts') && DB::table('whatsapp_contacts')->exists()) {
            throw new RuntimeException('جهات اتّصال واتساب: في القاعدة جهاتٌ وصلت من Meta — لا يُسقط الجدول. أرجِع الكود وحده.');
        }

        Schema::dropIfExists('whatsapp_contacts');
    }
};
