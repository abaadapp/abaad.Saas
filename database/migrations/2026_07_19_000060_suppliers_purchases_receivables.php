<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // المورّدون
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // أوامر الشراء
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('number')->index();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('status')->default('مسودة'); // مسودة | مُرسل | مستلم جزئيًا | مستلم | ملغي
            $table->decimal('total', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name');
            $table->decimal('cost', 12, 3)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('received_quantity')->default(0);
            $table->timestamps();
        });

        // حدّ الائتمان للعميل (البيع الآجل)
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('credit_limit', 12, 3)->default(0)->after('points');
        });

        // دفتر ذمم العملاء (دين/سداد)
        Schema::create('customer_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('order_number')->nullable();
            $table->string('type'); // دين | سداد
            $table->decimal('amount', 12, 3)->default(0);
            $table->string('method')->nullable(); // نقدي | تحويل | بطاقة (للسداد)
            $table->string('note')->nullable();
            $table->timestamp('due_at')->nullable(); // تاريخ استحقاق الدين
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ledger');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('credit_limit'));
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
