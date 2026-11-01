<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * طلبٌ يُجمع داخل محادثة واتساب — سلّتُه وتفاصيلُ تسليمه وحالُه حتى يُدفع.
 *
 * لا فاتورةَ هنا ولا دفعة: الفاتورةُ فاتورةُ عميلٍ كأيّ فاتورة
 * (`customer_invoices`)، والرابطُ رابطُ دفعٍ كأيّ رابط (`customer_payment_links`)،
 * والتجهيزُ مهمّةُ اللوحة نفسُها. هذا الصفُّ يحفظ ما لا مكانَ له قبل أن تُكتب
 * الفاتورة: ما اختاره الزبونُ خطوةً خطوة، وأيّ سؤالٍ يُنتظر جوابُه.
 *
 * - `open_key`: معرّفُ المحادثة ما دام الطلبُ مفتوحًا، وفراغٌ بعده — ففهرسُه
 *   الفريدُ يمنع طلبين مفتوحين في محادثةٍ واحدة، ولو وصلت رسالتان معًا.
 * - `seq`: رقمُ آخر سؤال. زرٌّ من رسالةٍ أقدم يُعرف به فلا يُطبَّق على سؤالٍ غيره.
 * - `total`: ما رآه الزبونُ في الملخّص ووافق عليه — والفاتورةُ لا تُكتب بغيره.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_order_sessions')) {
            return;
        }

        Schema::create('whatsapp_order_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('chat_id')->constrained('whatsapp_chats')->cascadeOnDelete();
            $table->string('customer_phone', 32);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('language', 2)->default('ar');
            $table->string('state', 20);
            $table->string('step', 40)->nullable();
            $table->unsignedInteger('seq')->default(0);
            $table->unsignedBigInteger('open_key')->nullable()->unique();
            $table->json('cart')->nullable();
            $table->json('details')->nullable();
            $table->json('context')->nullable();
            $table->decimal('total', 12, 3)->nullable();
            $table->foreignId('customer_invoice_id')->nullable()->constrained('customer_invoices')->nullOnDelete();
            $table->foreignId('payment_link_id')->nullable()->constrained('customer_payment_links')->nullOnDelete();
            $table->string('handoff_reason', 40)->nullable();
            $table->string('error', 60)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'state']);
            $table->index('chat_id');
            $table->index('customer_id');
            $table->index('customer_invoice_id');
            $table->index('payment_link_id');
        });
    }

    public function down(): void
    {
        // وطلبٌ بانتظار الدفع لا يُمحى بالرجوع — زبونٌ يدفع ولا يُعرف لأيّ محادثةٍ دفع
        if (Schema::hasTable('whatsapp_order_sessions') && DB::table('whatsapp_order_sessions')->exists()) {
            throw new RuntimeException('a_customer_orders_through_whatsapp: down() refused while whatsapp_order_sessions holds rows.');
        }

        Schema::dropIfExists('whatsapp_order_sessions');
    }
};
