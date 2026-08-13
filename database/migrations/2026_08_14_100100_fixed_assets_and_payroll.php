<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأصول الثابتة ومسيرة الرواتب.
 *
 * كلاهما يولّد قيودًا في الدفتر: الإهلاك الشهري، ومستحقّات الرواتب ثم صرفها.
 * ولذلك يحمل كلٌّ منهما حالةً صريحة — فمسيرةٌ صُرفت لا تُعدَّل، وأصلٌ أُهلك
 * شهرُه لا يُهلَك مرّتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->string('category')->nullable();      // أثاث، أجهزة، سيارات…
            $table->date('purchased_at');
            $table->decimal('cost', 14, 3);
            $table->decimal('salvage_value', 14, 3)->default(0);
            // العمر الإنتاجي بالأشهر — الإهلاك شهريّ فالوحدة شهر لا سنة
            $table->unsignedSmallInteger('life_months');
            $table->string('method', 20)->default('قسط ثابت');
            // ما أُهلك فعلًا حتى الآن — يُزاد عند كل ترحيل إهلاك
            $table->decimal('accumulated', 14, 3)->default(0);
            $table->date('depreciated_through')->nullable();
            $table->string('status', 20)->default('نشط');  // نشط | مستبعد | مباع
            $table->date('disposed_at')->nullable();
            $table->decimal('disposal_amount', 14, 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('number', 30);
            // الشهر المستحقّ — أوّل يومٍ منه، فمسيرتان لشهرٍ واحد تُمنعان
            $table->date('period');
            $table->string('status', 20)->default('مسودة'); // مسودة | معتمدة | مصروفة
            $table->decimal('gross', 14, 3)->default(0);
            $table->decimal('deductions', 14, 3)->default(0);
            $table->decimal('net', 14, 3)->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'period']);
            $table->unique(['business_id', 'number']);
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // الاسم يُنسخ: موظّفٌ يُحذف حسابه لا يُفرّغ مسيرةً مضت
            $table->string('employee_name');
            $table->decimal('basic', 14, 3)->default(0);
            $table->decimal('allowances', 14, 3)->default(0);
            $table->decimal('overtime', 14, 3)->default(0);
            $table->decimal('deductions', 14, 3)->default(0);
            $table->decimal('net', 14, 3)->default(0);
            $table->string('payment_method', 30)->nullable();
            $table->boolean('paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'user_id']);
        });

        // الراتب الأساسي وبدلاته على حساب الموظّف — مصدر مسيرة الشهر
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('basic_salary', 14, 3)->default(0)->after('status');
            $table->decimal('allowances', 14, 3)->default(0)->after('basic_salary');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['basic_salary', 'allowances']);
        });
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('fixed_assets');
    }
};
