<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حركات النقد داخل الوردية: سحبٌ من الدرج وإيداعٌ فيه.
 *
 * الدرج فيه أكثر من مبيعات نقدية. يُخرَج منه نقدٌ لدفع مورّد، ويأخذ صاحب
 * المحل مبلغًا، ويُضاف إليه فكّة. وحسابٌ يجهل ذلك يقول «نقص ٥٠» أوّل مرّة
 * يُسحب فيها خمسون لسائق.
 *
 * والنتيجة أسوأ من الخطأ نفسه: شاشةٌ تصرخ كل يوم بلا سبب يتوقّف الجميع عن
 * تصديقها — فيمرّ النقص الحقيقي وسط الضجيج.
 *
 * وكل حركة بسببها ومَن سجّلها: مبلغٌ بلا سبب لا يُراجَع، ولا يُسأل عنه أحد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('employee_name')->nullable();
            // in = إيداع في الدرج · out = سحب منه
            $table->string('type', 8);
            $table->decimal('amount', 12, 3);
            $table->string('reason');
            $table->timestamps();

            $table->index(['shift_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_movements');
    }
};
