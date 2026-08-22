<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تعديل الفاتورة يترك أثرًا — وإلّا كان محوًا لا تصحيحًا.
 *
 * الكاشير يُخطئ: يُدخل ثلاثةً بدل واحد، أو يمسح صنفًا سُجّل مرّتين. وبلا بابٍ
 * للتصحيح يبقى الخطأ في الفاتورة أبدًا: مخزونٌ ناقص لم يخرج، وضريبةٌ على ما
 * لم يُبَع، ورقمٌ في المالية لا يقابله شيء.
 *
 * والباب وحده لا يكفي. فاتورةٌ تُعدَّل بصمت أدقُّ في الشاشة وأخطرُ في الواقع:
 * الزبون يحمل إيصالًا مطبوعًا يخالف ما في النظام، ومن أخذ نقدًا ثمّ أنقص
 * الفاتورة لا يظهر في أيّ تقرير. فيُقيَّد كلُّ تعديل: ما كان، وما صار، ومن
 * غيّره، ومتى، ولماذا. والسبب مطلوبٌ لا اختياريّ — «تصحيح» بلا سببٍ سطرٌ
 * لا يُدقَّق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // البند قد يُحذف، فيبقى اسمه محفوظًا هنا ولا يضيع معه
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('item_name');
            $table->unsignedInteger('qty_before');
            $table->unsignedInteger('qty_after');
            $table->decimal('order_total_before', 12, 3);
            $table->decimal('order_total_after', 12, 3);
            $table->string('reason');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('employee_name')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_edits');
    }
};
