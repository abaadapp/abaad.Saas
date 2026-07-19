<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code', 8);              // OMR, AED, USD ...
            $table->string('name');
            $table->string('symbol', 12)->nullable();
            $table->decimal('rate', 14, 6)->default(1); // 1 وحدة أساسية = rate من هذه العملة
            $table->boolean('is_base')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
