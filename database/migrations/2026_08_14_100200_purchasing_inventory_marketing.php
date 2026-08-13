<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سندات المورّدين، وإشعارات تسليم الشحنات، وتعديلات المخزون، وتقييمات العملاء.
 *
 * السند فاتورة المورّد كما وصلت: رقمُه رقمُ المورّد لا رقمُنا، وعليه يُبنى
 * ما له علينا. وإشعار التسليم مستندُ حركةٍ لا مستندُ مال: يخرج البضاعة من
 * المخزون ولا يُنشئ ذمّة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            // رقم المورّد على سنده — لا يُولَّد عندنا، وقد يتكرّر بين موردين
            $table->string('supplier_ref', 60);
            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->decimal('subtotal', 14, 3)->default(0);
            $table->decimal('tax', 14, 3)->default(0);
            $table->decimal('total', 14, 3)->default(0);
            $table->decimal('paid', 14, 3)->default(0);
            $table->string('status', 20)->default('غير مدفوع'); // غير مدفوع | جزئي | مدفوع
            $table->text('notes')->nullable();
            $table->string('attachment')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'supplier_id', 'supplier_ref']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('number', 30);
            $table->date('delivered_at');
            $table->string('recipient')->nullable();
            $table->string('driver')->nullable();
            $table->text('address')->nullable();
            $table->string('status', 20)->default('مسودة'); // مسودة | مُسلَّم | ملغى
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('delivery_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained('delivery_notes')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('name');
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('number', 30);
            // فرقٌ موجبٌ زيادة وسالبٌ نقص — لا حقلَ اتجاهٍ منفصل يناقضه
            $table->decimal('quantity_delta', 12, 3);
            $table->decimal('cost_at_time', 14, 3)->default(0);
            $table->string('reason');   // تلف | فقد | جرد | إهداء | تصحيح
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('adjusted_at');
            $table->timestamps();

            $table->index(['business_id', 'adjusted_at']);
            $table->index('product_id');
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('author_name')->nullable();
            $table->unsignedTinyInteger('rating');   // ١..٥
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('معلّق'); // معلّق | منشور | مرفوض
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('delivery_note_items');
        Schema::dropIfExists('delivery_notes');
        Schema::dropIfExists('supplier_invoices');
    }
};
