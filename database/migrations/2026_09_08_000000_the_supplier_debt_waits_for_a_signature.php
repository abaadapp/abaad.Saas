<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ذمّةُ المورّد تنتظر توقيعًا.
 *
 * كان سندُ المورّد يُرحَّل لحظةَ كتابته: يُدخله المحاسب فيصير على المتجر
 * دَينٌ في الدفتر قبل أن يراه أحد. ومن يكتب هو من يعتمد — وهو البابُ نفسُه
 * الذي أُغلق في استلام البضاعة.
 *
 * وأخطرُ ما فيه أنّ السند يُطابَق بثلاثة: بأمر الشراء، وبما استُلم فعلًا،
 * وبنفسه. فسندٌ بمئةٍ على أمرٍ بثمانين، أو سندٌ بالكامل على شحنةٍ وصل
 * تسعون بالمئة منها — يمرّ بلا سؤال، ويُدفع.
 *
 * ═══ وحالان لا حالٌ واحدة ═══
 *
 * `status` القائم يقول حالَ السداد (غير مدفوع | جزئي | مدفوع) ويبقى كما هو.
 * والاعتمادُ عمودٌ ثانٍ: خلطُهما يجعل «معتمد» و«مدفوع» في خانةٍ واحدة —
 * فلا يُعرف سندٌ اعتُمد ولم يُدفع من سندٍ لم يُعتمد أصلًا.
 *
 * ═══ وما مضى معتمَد ═══
 *
 * كلُّ سندٍ قائمٍ اليوم رُحّل قيدُه لحظةَ كتابته. فلو بدأ منتظرًا لظهر في
 * الطابور، ولأعاد اعتمادُه ترحيلَ الذمّة مرّةً ثانية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            // بانتظار الاعتماد | معتمد | مرفوض | ملغاة
            $table->string('approval_status', 20)->default('بانتظار الاعتماد');

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();

            /*
             * حصيلةُ المطابقة الثلاثيّة — مخزَّنةٌ لأنّها لقطةُ لحظة الاعتماد.
             *
             * ولا تُشتقّ عند القراءة: أمرُ الشراء قد يُستلم بعد الاعتماد
             * فتتغيّر الحصيلة، ومن يفتح السندَ بعد شهر يقرأ مطابقةً غير التي
             * وقّع عليها. والسؤالُ «على أيّ حالٍ وقّعتَ؟» لا «ما حالُه اليوم؟».
             */
            $table->string('match_status', 20)->nullable();
            $table->text('match_notes')->nullable();

            // تجاوزُ عدم المطابقة — بسببٍ يُكتب ويُنسب، ولا يقع صامتًا
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('override_at')->nullable();
            $table->string('override_reason')->nullable();

            // واسمُ المرفق كما رفعه صاحبه — الاسمُ المخزَّن عشوائيّ
            $table->string('attachment_name')->nullable();

            $table->index(['business_id', 'approval_status']);
        });

        // وما مضى معتمَد — قيدُه في الدفتر منذ كُتب
        DB::table('supplier_invoices')->update([
            'approval_status' => 'معتمد',
            'approved_at' => DB::raw('created_at'),
            'match_status' => 'مطابق',
        ]);
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('override_by');
            $table->dropColumn([
                'approval_status', 'approved_at', 'rejected_at', 'rejection_reason',
                'match_status', 'match_notes', 'override_at', 'override_reason', 'attachment_name',
            ]);
        });
    }
};
