<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جميع جداول نظام Abad POS.
 * قاعدة بيانات واحدة متعددة المستأجرين عبر business_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // الباقات
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('monthly_price', 10, 3)->default(0);
            $table->decimal('yearly_price', 10, 3)->default(0);
            $table->unsignedInteger('max_branches')->default(1);
            $table->unsignedInteger('max_employees')->default(3);
            $table->unsignedInteger('max_products')->default(100);
            $table->json('features')->nullable();
            $table->string('color')->default('primary');
            $table->boolean('is_popular')->default(false);
            $table->timestamps();
        });

        // الشركات (المستأجرون)
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('محل ورود');
            $table->string('owner_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('country')->default('عُمان');
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('logo')->nullable();
            $table->string('status')->default('نشط'); // نشط | منتهي | معطل
            $table->unsignedInteger('branches_count')->default(1);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();
        });

        // الفروع
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        // التصنيفات
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->string('icon')->default('tag');
            $table->string('color')->default('primary');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
        });

        // المنتجات
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('price', 12, 3)->default(0);
            $table->decimal('cost', 12, 3)->default(0);
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('alert_qty')->default(10);
            $table->decimal('tax', 5, 2)->default(0);
            $table->decimal('discount', 5, 2)->default(0);
            $table->string('image')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // العملاء
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->timestamps();
        });

        // الطلبات
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('number')->index();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable(); // الموظف/الكاشير
            $table->string('customer_name')->nullable();
            $table->string('employee_name')->nullable();
            $table->string('branch')->default('الفرع الرئيسي');
            $table->string('status')->default('جديد'); // جديد | قيد التجهيز | جاهز | خرج للتوصيل | مكتمل | ملغي | معلّق
            $table->string('payment_method')->default('نقدي');
            $table->string('payment_status')->default('مدفوع'); // مدفوع | غير مدفوع | جزئي
            $table->decimal('subtotal', 12, 3)->default(0);
            $table->decimal('discount', 12, 3)->default(0);
            $table->decimal('tax', 12, 3)->default(0);
            $table->decimal('delivery_fee', 12, 3)->default(0);
            $table->decimal('total', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_held')->default(false);
            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();
        });

        // عناصر الطلب
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name');
            $table->decimal('price', 12, 3)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('note')->nullable();
            $table->decimal('total', 12, 3)->default(0);
            $table->timestamps();
        });

        // الاشتراكات
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->decimal('amount', 10, 3)->default(0);
            $table->string('payment_status')->default('مدفوع');
            $table->string('status')->default('نشط');
            $table->timestamps();
        });

        // فواتير المنصة
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->index();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->decimal('amount', 10, 3)->default(0);
            $table->date('issued_at')->nullable();
            $table->string('status')->default('مدفوعة');
            $table->timestamps();
        });

        // حركات المخزون
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->string('type'); // إضافة كمية | خصم كمية | مرتجع | تلف | تعديل يدوي
            $table->string('quantity'); // نص لإظهار +/-
            $table->string('employee_name')->nullable();
            $table->timestamps();
        });

        // المصروفات
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('type');
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 3)->default(0);
            $table->string('method')->default('نقدي');
            $table->string('employee_name')->nullable();
            $table->date('spent_at')->nullable();
            $table->timestamps();
        });

        // المعاملات المالية
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('reference')->index();
            $table->string('description')->nullable();
            $table->string('method')->default('نقدي'); // نقدي | تحويل بنكي | بطاقة
            $table->string('type')->default('دخل'); // دخل | مصروف
            $table->decimal('amount', 12, 3)->default(0);
            $table->string('employee_name')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });

        // الورديات
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('employee_name')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_balance', 12, 3)->default(0);
            $table->decimal('cash_sales', 12, 3)->default(0);
            $table->decimal('card_sales', 12, 3)->default(0);
            $table->decimal('returns', 12, 3)->default(0);
            $table->decimal('expenses', 12, 3)->default(0);
            $table->decimal('expected_balance', 12, 3)->default(0);
            $table->decimal('actual_balance', 12, 3)->default(0);
            $table->decimal('difference', 12, 3)->default(0);
            $table->string('status')->default('مفتوحة'); // مفتوحة | مغلقة
            $table->timestamps();
        });

        // الإعدادات (منصة أو نشاط)
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable()->index(); // null = إعدادات المنصة
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'settings', 'shifts', 'transactions', 'expenses', 'inventory_movements',
            'invoices', 'subscriptions', 'order_items', 'orders', 'customers',
            'products', 'categories', 'branches', 'businesses', 'plans',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
