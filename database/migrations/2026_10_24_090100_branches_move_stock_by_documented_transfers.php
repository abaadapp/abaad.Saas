<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نقلُ المخزون بين الفروع بسندٍ له مراحل — وطلبُ التزويد الذي يسبقه.
 *
 * ═══ ولمَ جداولُ جديدة لا `stock_transfers` ═══
 *
 * `stock_transfers` سِجلُّ نقلٍ قديم: صنفٌ واحد في الصفّ، يُخصم ويُضاف في
 * لحظته، ولا مرحلةَ بينهما. وشاشتُه حُذفت وبقي الجدولُ لأنّ حركاتِ مخزونٍ
 * تحمل أرقامَه. فلا يُكتب فيه ولا يُعاد تشكيله: يبقى كما هو يُقرأ.
 *
 * والسندُ هنا وثيقةٌ بأسطر: صنفٌ أو عشرة، وكلُّ سطرٍ بكميّاته الأربع —
 * المطلوبة، والمعتمدة، والمرسلة، والمستلمة — وما سُوّي من فرقها.
 *
 * ═══ والمخزونُ قيدَ النقل ═══
 *
 * يخرج من رفّ المصدر عند الإرسال (رصيدُ الفرع والإجماليّ معًا، فيبقى «مجموع
 * الفروع = كميّة المنتج»)، ويدخل رفَّ المستلم عند الاستلام. وما بينهما في
 * السطر نفسِه: المرسلُ ناقصَ المستلم ناقصَ ما سُوّي. فمخزونُ النشاط كلُّه =
 * ما على الرفوف + ما قيدَ النقل، ولا تُكتب القطعةُ في موضعين ولا تسقط من
 * الاثنين.
 *
 * ═══ والفرعُ والصنفُ يُنسخ اسمُهما ═══
 *
 * `nullOnDelete` كما في `stock_transfers`: حذفُ فرعٍ أو صنفٍ لا يمحو تاريخَ ما
 * مرّ به، والاسمُ يبقى في السطر فيُقرأ بعد الحذف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_supply_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('number', 30);
            // الفرعُ الذي يطلب البضاعة
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('branch_name');
            // pending | approved | rejected | cancelled
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            // ضغطتان على «إرسال» لا تكتبان طلبين
            $table->string('client_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->unique(['business_id', 'client_key']);
            $table->index(['business_id', 'status']);
            $table->index('branch_id');
            $table->index('requested_by');
            $table->index('decided_by');
        });

        Schema::create('branch_supply_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_supply_request_id')->constrained('branch_supply_requests')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->unsignedInteger('requested_qty');
            // تُكتب عند الاعتماد — والفرعُ الذي يزوّده
            $table->unsignedInteger('approved_qty')->nullable();
            $table->foreignId('source_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();

            $table->index('business_id');
            $table->index('branch_supply_request_id');
            $table->index('product_id');
            $table->index('source_branch_id');
        });

        Schema::create('branch_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('number', 30);
            /*
             * pending | approved | preparing | in_transit | discrepancy | received
             * | rejected | cancelled — والانتقالُ بينها في `BranchTransfers` وحده.
             */
            $table->string('status', 20)->default('pending');
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('from_branch_name');
            $table->string('to_branch_name');
            // وطلبُ التزويد الذي وُلد منه — إن وُلد من طلب
            $table->foreignId('branch_supply_request_id')->nullable()->constrained('branch_supply_requests')->nullOnDelete();
            $table->text('notes')->nullable();
            // الموظّفُ المسؤول عن تسليمه — اختياريّ
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();

            // من فعل كلَّ مرحلةٍ ومتى — والسجلُّ الكامل في `branch_operation_events`
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            // المالكُ اعتمد طلبَه بنفسه — بقرارٍ صريحٍ يُقرأ هنا وفي السجلّ
            $table->boolean('self_approved')->default(false);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('prepared_by_name')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dispatched_by_name')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by_name')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // سببُ الرفض أو الإلغاء
            $table->text('decision_reason')->nullable();
            $table->string('client_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->unique(['business_id', 'client_key']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'created_at']);
            $table->index('from_branch_id');
            $table->index('to_branch_id');
            $table->index('branch_supply_request_id');
            $table->index('responsible_user_id');
            $table->index('requested_by');
            $table->index('approved_by');
            $table->index('prepared_by');
            $table->index('dispatched_by');
            $table->index('received_by');
        });

        Schema::create('branch_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_transfer_id')->constrained('branch_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name');
            $table->string('sku')->nullable();
            // موجبةٌ كلُّها: الاتّجاهُ في الفرعين لا في إشارة الرقم
            $table->unsignedInteger('requested_qty');
            $table->unsignedInteger('approved_qty')->nullable();
            $table->unsignedInteger('sent_qty')->nullable();
            $table->unsignedInteger('received_qty')->nullable();
            /*
             * وما سُوّي من فرق الاستلام — ثلاثةُ أبوابٍ لا رقمٌ واحد:
             *   فُقد أو تلف ← يُكتب خسارةً (`StockLosses`) ولا يعود إلى رفّ
             *   أُعيد        ← يعود إلى رفّ المصدر
             *   وصل متأخّرًا ← يدخل رفَّ المستلم
             */
            $table->unsignedInteger('lost_qty')->default(0);
            $table->unsignedInteger('returned_qty')->default(0);
            $table->unsignedInteger('late_qty')->default(0);
            // تكلفةُ الوحدة يومَ الإرسال — قيمةُ ما قيدَ النقل وقيمةُ ما فُقد
            $table->decimal('unit_cost', 12, 3)->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('branch_transfer_id');
            $table->index('product_id');
        });

        /*
         * سجلُّ المراحل — يُضاف إليه ولا يُعدَّل.
         *
         * لكلّ انتقالٍ صفّ: من الحالة وإلى الحالة، ومن فعل، ولماذا. فلا يُخفي
         * سندٌ مكتمل ما جرى عليه: الرفضُ والإلغاءُ وفرقُ الاستلام وتسويتُه كلُّها
         * هنا بأسمائها.
         */
        Schema::create('branch_operation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            // transfer | supply
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->text('note')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['business_id', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_operation_events');
        Schema::dropIfExists('branch_transfer_lines');
        Schema::dropIfExists('branch_transfers');
        Schema::dropIfExists('branch_supply_request_lines');
        Schema::dropIfExists('branch_supply_requests');
    }
};
