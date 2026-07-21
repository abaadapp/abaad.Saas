<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');                 // الاسم الظاهر (حر) مثل: منسّق زهور
            $table->string('role');                 // الصلاحية المكافئة من أدوار النظام
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // الاسم الظاهر لوظيفة الموظف (حقل role يبقى للصلاحيات فقط)
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_title')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('job_title');
        });
        Schema::dropIfExists('job_titles');
    }
};
