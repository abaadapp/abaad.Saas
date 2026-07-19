<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('code');
            $table->string('type')->default('نسبة'); // نسبة | مبلغ
            $table->decimal('value', 12, 3)->default(0);
            $table->decimal('min_order', 12, 3)->default(0);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['business_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
