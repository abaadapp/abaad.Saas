<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سندُ النقل بين الفروع — وثيقةٌ تربط الطرفين.
 *
 * لم يكن في النظام نقلٌ أصلًا: لا مسار ولا شاشة ولا سند. وطريقُ التاجر اليوم
 * حركتان يدويّتان — صرفٌ من فرع وإضافةٌ في آخر — لا شيء يربطهما. فإن نسي
 * الثانية نقص المخزون بلا سبب، وإن كتبها بكميّةٍ أخرى اختلّ الرصيدان، ولا
 * يُكتشف الفرق إلّا في جردٍ آخر السنة حين يكون سببُه قد نُسي.
 *
 * وأثرُه أوسع من شاشة: رسالةُ رفض حذف الفرع كانت تقول «انقلها إلى فرعٍ آخر»
 * وتُحيل إلى بابٍ لا وجود له، فيبحث التاجر عن زرٍّ ليس موجودًا ثمّ يظنّ
 * العطب في بصره.
 *
 * والسند لا يغيّر الإجمالي: الكميّة تنتقل بين رصيدَي فرعين، وكميّةُ المنتج
 * كما هي — البضاعة لم تدخل ولم تخرج، إنّما تحرّكت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            /*
             * الفرعان `nullOnDelete` كما في التعديلات: حذفُ فرعٍ لا يمحو
             * تاريخ ما مرّ به. والاسم يُنسخ لأنّ السجلّ يُقرأ بعد الحذف.
             */
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('from_branch_name');
            $table->string('to_branch_name');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('number', 30);
            // موجبةٌ دائمًا: الاتجاه في الفرعين لا في إشارة الرقم
            $table->unsignedInteger('quantity');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at');
            $table->timestamps();

            $table->index(['business_id', 'transferred_at']);
            $table->index('product_id');
        });

        /*
         * وحركتا المخزون تحملان رقم السند.
         *
         * الحركتان تظهران في سجلّ المخزون صرفًا وإضافةً، وبلا رقمٍ يجمعهما
         * تُقرآن حادثتين لا واحدة: من يراجع السجلّ يظنّ أنّ فرعًا صرف بلا
         * سببٍ وأنّ آخر استلم بلا مصدر.
         */
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('reference', 30)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn('reference');
        });

        Schema::dropIfExists('stock_transfers');
    }
};
