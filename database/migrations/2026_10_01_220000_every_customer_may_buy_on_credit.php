<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أيُّ عميلٍ مسجَّلٍ يُباع له آجلًا — لا إذنَ للعميل ولا حدَّ ائتمان.
 *
 * كان الآجلُ مغلقًا لكلّ عميلٍ حتّى يُفتح له (`allow_credit_sales`)، ومحدودًا
 * بسقفٍ يُتجاوَز بإذنٍ وسبب (`credit_limit`). فصار شرطُه على مستوى العميل
 * واحدًا: أن يكون هناك عميل — الذمّةُ تُكتب باسمه (انظر `CreditSales`).
 * ومقبضُ المتجر العامّ `pay_credit` باقٍ كما هو.
 *
 * ويبقى `payment_terms_days` (يحدّد الاستحقاق) و`monthly_billing`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['allow_credit_sales', 'credit_limit']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('allow_credit_sales')->default(false);
            $table->decimal('credit_limit', 12, 3)->nullable();
        });
    }
};
