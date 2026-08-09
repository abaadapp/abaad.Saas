<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأجهزة الملحقة بصندوق البيع: طابعة، ماسح باركود، درج نقدي، شاشة عميل، ميزان.
 *
 * مرتبطة بجهاز نقطة البيع لا بالمتجر: الطابعة في فرع الخوير ليست طابعة فرع
 * السيب، والصندوق هو من يعرف ملحقاته.
 *
 * وbusiness_id مكتوبٌ رغم إمكان اشتقاقه من الجهاز: كل استعلامات هذا النظام
 * تُقيَّد بالمستأجر مباشرةً، وقفزةٌ عبر جدول لتعرف صاحب الصفّ هي الموضع الذي
 * يُنسى فيه القيد يومًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_peripherals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_device_id')->constrained('pos_devices')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 30);
            $table->string('connection', 20)->default('usb');
            $table->string('model', 60)->nullable();
            // الشبكة وحدها تحتاجهما، ويبقيان فارغين لغيرها
            $table->string('address', 100)->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            // خاصّ بالطابعة: عرض الورق بالمليمتر، والطباعة التلقائية بعد البيع
            $table->unsignedSmallInteger('paper_width')->nullable();
            $table->boolean('auto_print')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'pos_device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_peripherals');
    }
};
