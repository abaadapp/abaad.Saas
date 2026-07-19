<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('returned_quantity')->default(0)->after('quantity');
        });

        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('order_number');
            $table->string('type');                 // كلي | جزئي
            $table->decimal('amount', 12, 3)->default(0);
            $table->integer('items_count')->default(0);
            $table->string('reason')->nullable();
            $table->string('employee_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_returns');
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });
    }
};
