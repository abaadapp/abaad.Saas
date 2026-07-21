<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // الفرع الذي ستُستلم فيه البضاعة — nullable حتى لا تنكسر الأوامر السابقة
            $table->foreignId('branch_id')->nullable()->after('business_id')->constrained('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
