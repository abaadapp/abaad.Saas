<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ‏‏حملاتُ واتساب — رسالةُ عرضٍ بقالبٍ اعتمدته Meta، من رقم المتجر إلى عملائه.
 *
 * أربعةُ جداول جديدة وحدها، ولا تمسّ جدولًا قائمًا:
 *  - `whatsapp_campaign_templates`: قوالبُ كتبها التاجر وأُرسلت إلى Meta، وحالُها عندها.
 *  - `whatsapp_campaigns`: حملةٌ — قالبُها كما كان يومَها، ولمن، ومن أقرّ بالموافقة والكلفة ومتى.
 *  - `whatsapp_campaign_recipients`: سطرٌ لكلّ رقم — خرجت أو وصلت أو فشلت وبأيّ سبب. ومفتاحُه الفريد
 *    يمنع رسالتين من حملةٍ واحدة إلى رقمٍ واحد. وليست في `whatsapp_messages`: تلك إشعاراتُ الطلبات،
 *    ومنها يُقرأ «أتصل رسائلُك؟» — وفشلُ حملةٍ ليس عطبَ ربط.
 *  - `whatsapp_optouts`: من طلب ألّا تصله العروض — لا تُرسل إليه حملةٌ بعدها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_campaign_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('waba_id', 64);
            $table->string('meta_name', 120);
            $table->string('meta_id', 64)->nullable();
            $table->string('title', 80);
            $table->string('language', 10);
            $table->text('body');
            $table->json('variables')->nullable();
            $table->string('footer', 60)->nullable();
            $table->json('button')->nullable();
            $table->string('category', 20)->default('MARKETING');
            $table->string('status', 24)->default('PENDING');
            $table->string('rejected_reason', 60)->nullable();
            $table->string('quality', 12)->nullable();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'waba_id', 'meta_name', 'language'], 'whatsapp_campaign_templates_name_unique');
            $table->index(['waba_id', 'meta_name']);
        });

        Schema::create('whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->index()->constrained('whatsapp_campaign_templates')->nullOnDelete();
            $table->foreignId('whatsapp_connection_id')->nullable()->index()->constrained('whatsapp_connections')->nullOnDelete();
            $table->string('name', 80);
            $table->json('message');
            $table->json('audience');
            $table->string('status', 12);
            $table->string('stop_reason', 60)->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->json('stats')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('consent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['business_id', 'created_at']);
        });

        Schema::create('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('whatsapp_campaigns')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->index()->constrained('customers')->nullOnDelete();
            $table->string('phone', 20);
            $table->string('name', 80)->nullable();
            $table->string('status', 12)->default('pending');
            $table->string('wamid', 191)->nullable()->index();
            $table->string('error_code', 60)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'phone']);
            $table->index(['campaign_id', 'status']);
            $table->index(['business_id', 'phone', 'sent_at']);
        });

        Schema::create('whatsapp_optouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('source', 12);
            $table->timestamp('created_at')->nullable();

            $table->unique(['business_id', 'phone']);
        });
    }

    public function down(): void
    {
        // ‏‏ومن طلب الإيقاف لا تُنسى رغبتُه، وما خرج لا يُنسى أنّه خرج: أرجِع الكود وحده
        foreach (['whatsapp_optouts', 'whatsapp_campaigns', 'whatsapp_campaign_templates'] as $t) {
            if (Schema::hasTable($t) && DB::table($t)->exists()) {
                throw new RuntimeException("حملات واتساب: في {$t} صفوفٌ قائمة — لا يُسقط الجدول. أرجِع الكود وحده.");
            }
        }

        Schema::dropIfExists('whatsapp_optouts');
        Schema::dropIfExists('whatsapp_campaign_recipients');
        Schema::dropIfExists('whatsapp_campaigns');
        Schema::dropIfExists('whatsapp_campaign_templates');
    }
};
