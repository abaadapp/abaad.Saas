<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // سجل النشاط (Audit Log)
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->string('action');            // created | updated | deleted | login | logout | checkout | status | settings
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description');
            $table->string('icon')->default('activity');
            $table->string('color')->default('primary');
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        // ربط الطلبات بالفرع
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('business_id')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('branch_id');
        });
    }
};
