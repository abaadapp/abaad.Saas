<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('reference')->nullable()->after('business_id');   // الرقم المرجعي
            $table->date('due_date')->nullable()->after('spent_at');          // تاريخ الاستحقاق
            $table->string('status')->default('مدفوع')->after('method');      // الحالة
        });

        Schema::table('expense_types', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');         // الوصف
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['reference', 'due_date', 'status']);
        });

        Schema::table('expense_types', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
