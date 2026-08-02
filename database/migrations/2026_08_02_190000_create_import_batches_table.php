<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ عمليات الاستيراد — ليصير التراجع ممكنًا.
 *
 * استيرادٌ لا يُتراجَع عنه يجعل كل خطأ في ملفٍ نهائيًّا: أسعارٌ شاملة الضريبة
 * دخلت صافيةً لا تُكتشف إلا في تقرير الأرباح بعد شهر، وقد بِيعت ألف فاتورة
 * بهامش خاطئ. الحالة السابقة تُحفظ هنا قبل الكتابة، لا تُستنتج بعدها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 32)->default('products');
            $table->string('file');
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('updated')->default(0);
            // لقطة ما قبل الاستيراد: المُنشأ يُحذف، والمُحدَّث يُعاد إلى قيمه
            $table->json('payload');
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'type', 'undone_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
