<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تنبيهات يعرّفها صاحب النشاط بنفسه.
 *
 * التنبيهات كانت محصورة في ثلاثة أنواع مكتوبة في الكود (مخزون منخفض، طلب
 * بانتظار التجهيز، ملخّص اليوم)، فأي شيء آخر يهمّ التاجر لا سبيل لمراقبته
 * إلا بتعديل الكود.
 *
 * نوعان:
 * - rule: شرطٌ على مقياس من مقاييس النظام، يُفحص مع كل تحميل ويظهر متى تحقّق.
 * - reminder: نصٌّ بموعد، يظهر عند حلوله ولا يراقب شيئًا.
 *
 * المقاييس محصورة في قائمة معروفة لا صيغة حرة: شرطٌ يكتبه المستخدم بحرّية
 * يعني تنفيذ نصٍّ من إدخاله على قاعدة البيانات، وهذا بابٌ لا يُفتح لأجل
 * تنبيه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);            // rule | reminder
            $table->string('section', 40);         // القسم الذي يقود إليه التنبيه
            $table->string('metric', 40)->nullable();   // rule فقط
            $table->string('operator', 10)->nullable(); // rule فقط: > أو <
            $table->decimal('threshold', 12, 3)->nullable();
            $table->string('message', 255);
            $table->string('color', 20)->default('warning');
            $table->timestamp('due_at')->nullable(); // reminder فقط
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_alerts');
    }
};
