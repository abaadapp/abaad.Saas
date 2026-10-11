<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رابطُ الدفع — تحصيلٌ من عميل، لا طلبُ متجر.
 *
 * ═══ ولمَ جدولٌ مستقلّ لا `store_payment_intents` ═══
 *
 * نيّةُ دفع الموقع تنتهي بطلبٍ (`WebCheckout::place`): تُخرج بضاعةً وتكتب
 * إيرادًا وضريبةً وتكلفة. ورابطُ الدفع يطلب مالًا على ذمّةٍ قائمة — فاتورةٌ
 * رُحّل إيرادُها يومَ صدرت، أو رصيدٌ للعميل. فلو مرّ بنيّة الموقع لَبيع
 * الشيءُ مرّتين. فينتهي هنا بتحصيلٍ واحد (`CustomerPayments::record`).
 *
 * ═══ والبوّابةُ تعرف بيئتَها ومفتاحَ الروابط ═══
 *
 * روابطُ Paymob (QuickLink) تُفتح برمزٍ من «API Key» — مفتاحٌ غيرُ المفتاح
 * السرّيّ الذي تُفتح به صفحةُ الموقع. ولكلّ متجرٍ مفتاحُه، مشفَّرًا لا يخرج.
 * والبيئةُ (تجربةٌ أو حقيقة) يكتبها التاجر صراحةً ولا تُستنتج من شكل مفتاح:
 * تُرسَل `is_live` مع كلّ رابط، ورقمُ تكاملٍ من بيئةٍ غيرِها يُردّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->text('api_key')->nullable();
            $table->string('mode', 8)->nullable();
        });

        Schema::create('customer_payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->nullable()->constrained()->nullOnDelete();

            // مرجعُنا — عشوائيٌّ لا يُخمَّن، ويُرسَل إلى Paymob `reference_id`
            $table->string('reference', 64)->unique();

            // ما تردّه Paymob: رقمُ الرابط، وطلبُها الذي يُدفع عليه، وعمليّةُ الدفع
            $table->string('provider_link_id', 64)->nullable()->index();
            $table->string('provider_order_id', 64)->nullable()->index();
            // عمليّةٌ واحدةٌ لرابطٍ واحد — والفهرسُ الفريدُ حارسُ الإشعار المكرَّر
            $table->string('provider_transaction_id', 64)->nullable()->unique();
            $table->string('short_url', 500)->nullable();

            $table->decimal('amount', 12, 3);
            $table->string('currency', 8);
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('pending');
            $table->boolean('is_live')->default(false);

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->index()->constrained('users')->nullOnDelete();

            // التحصيلُ الذي أنشأه الدفع — واحدٌ لا يتعدّد
            $table->foreignId('customer_payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();

            // للتشخيص وحده: سببُ الرفض، وما لا يُحفظ فيه سرٌّ ولا بطاقة
            $table->string('error', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'customer_id']);
            $table->index('customer_id');
            $table->index(['business_id', 'customer_invoice_id']);
            $table->index('customer_invoice_id');
        });

        /*
         * تسويةُ Paymob — المالُ يصل البنكَ بعد أيّام، ناقصًا رسومَها.
         *
         * نجاحُ الدفع لا يعني أنّ المالَ في بنك التاجر: يجلس في «Paymob تحت
         * التسوية» حتّى تُحوّله Paymob. والتسويةُ يكتبها التاجر من كشفها:
         * الإجماليُّ والرسومُ والصافي، ولا تُخمَّن هنا.
         */
        Schema::create('paymob_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->decimal('gross', 12, 3);
            $table->decimal('fee', 12, 3)->default(0);
            $table->decimal('net', 12, 3);
            $table->date('settled_on');
            $table->string('reference', 80)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'settled_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paymob_settlements');
        Schema::dropIfExists('customer_payment_links');

        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropColumn(['api_key', 'mode']);
        });
    }
};
