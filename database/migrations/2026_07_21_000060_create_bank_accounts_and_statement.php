<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // حساب الشركة البنكي (حساب واحد لكل نشاط)
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('account_name')->nullable();
            $table->string('iban')->nullable();
            $table->decimal('opening_balance', 14, 3)->default(0);
            $table->date('opening_date')->nullable();
            $table->timestamps();
        });

        // أسطر كشف البنك المستوردة (للمطابقة مع معاملات النظام)
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->date('date');
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('amount', 14, 3);            // موجب = وارد · سالب = صادر
            $table->unsignedBigInteger('transaction_id')->nullable()->index(); // المعاملة المطابِقة
            $table->string('match_status')->default('غير مطابق'); // مطابق | غير مطابق
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_accounts');
    }
};
