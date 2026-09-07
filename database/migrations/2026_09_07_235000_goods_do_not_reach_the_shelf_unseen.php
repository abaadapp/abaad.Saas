<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * البضاعةُ لا تصل الرفَّ قبل أن يراها من يملكه.
 *
 * كان الاستلامُ يزيد المخزونَ لحظةَ كتابته: يفتح الموظّف أمرَ الشراء، يكتب
 * ما وصله، فيرتفع الرصيد ويُعاد حسابُ متوسّط التكلفة على الفور. ولا خطوةَ
 * بينهما — من يكتب هو من يعتمد.
 *
 * وفي متجرٍ فيه موظّفان هذا بابٌ مفتوح: كميّةٌ تُكتب أكبر ممّا وصل فتدخل
 * الرفَّ ورقيًّا ولا توجد فيه، ومتوسّطُ التكلفة يُرجَّح بها فيُفسد تسعيرَ
 * كلّ بيعةٍ بعدها. ولا يكشفه إلّا الجرد، بعد شهر.
 *
 * فصار للإشعار حالٌ: يُكتب «بانتظار الاعتماد» ولا يمسّ شيئًا، ويُعتمد
 * فيتحرّك المخزون، أو يُرفض فلا يتحرّك — ولا يُمحى في الحالين.
 *
 * ═══ وما مضى معتمَد ═══
 *
 * كلُّ إشعارٍ قائمٍ اليوم دخلت بضاعتُه الرفَّ فعلًا — الكودُ القديم أدخلها
 * قبل أن يكتب الورقة. فلو بدأ «بانتظار الاعتماد» لظهرت شحناتُ الأمس في
 * طابور الاعتماد، ولأدخل اعتمادُها البضاعةَ **مرّةً ثانية**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            // بانتظار الاعتماد | معتمد | مرفوض — عربيّةٌ كحالات المسيرة والسند
            $table->string('status', 20)->default('بانتظار الاعتماد');

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();

            /*
             * ورقةُ المورّد التي جاءت مع الشحنة — بوليصةٌ أو إشعارُ تسليم.
             *
             * وهي غيرُ فاتورته: هذه تقول «وصل»، وتلك تقول «عليك». ومرفقٌ
             * واحدٌ يحمل الاثنين يجعل المحاسب يفتح ورقةً يبحث فيها عن مبلغٍ
             * ليس فيها.
             */
            $table->string('attachment')->nullable();
            $table->string('attachment_name')->nullable();

            $table->index(['business_id', 'status']);
        });

        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            /*
             * وسطرُ الورقة يشير إلى بند الأمر الذي جاء منه.
             *
             * كان يشير إلى المنتج وحده — والمنتجُ الواحد قد يتكرّر في بندين
             * من أمرٍ واحد بسعرين. فبلا هذه الإشارة لا يُعرف أيَّ بندٍ
             * يُزاد `received_quantity` له عند الاعتماد، ولا كم بقي منه.
             */
            $table->foreignId('purchase_order_item_id')->nullable()
                ->constrained('purchase_order_items')->nullOnDelete();
        });

        // وما مضى معتمَد — بضاعتُه على الرفّ منذ كُتبت ورقتُه
        DB::table('goods_receipt_notes')->update([
            'status' => 'معتمد',
            'approved_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_item_id');
        });

        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn([
                'status', 'approved_at', 'rejected_at', 'rejection_reason',
                'attachment', 'attachment_name',
            ]);
        });
    }
};
