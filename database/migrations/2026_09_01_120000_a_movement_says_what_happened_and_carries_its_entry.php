<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الحركة المالية تقول ماذا حدث، وتحمل قيدها معها.
 *
 * كان في `transactions` عمودان يصفان الحركة: `type` («دخل» أو «مصروف»)
 * و`method`. وهما لا يكفيان: تحويلٌ من الصندوق إلى البنك ليس دخلًا ولا
 * مصروفًا، وسحبُ المالك ليس مصروفًا، و«دخل» واحدةٌ تجمع بيعةَ نقطة البيع
 * وتعويضَ شركة التأمين في خانةٍ واحدة — فتُقرأ الثانية مبيعاتٍ في كل تقرير.
 *
 * فـ`kind` تقول ما الذي حدث فعلًا، ومنها تُشتقّ لغةُ الشاشة والقيدُ في دفتر
 * الأستاذ معًا. و`journal_entry_id` تربط الصفَّ التشغيلي بقيده المحاسبي:
 * بها يُعرف أنّ الحركة رُحّلت فلا تُرحّل مرّتين، وبها يتبع القيدُ حركتَه
 * حين تُحذف. و`client_uuid` تمنع تكرار الحركة حين يُضغط «حفظ» مرّتين أو
 * يُعاد إرسال الطلب بعد انقطاع — كما في الطلبات.
 *
 * والاستدراك يكتب `kind` لكل صفٍّ قائم: صفٌّ بلا نوعٍ لا يُقرأ في شاشةٍ
 * تصنّف بالنوع، فيختفي دفترُ المتجر القديم يوم تُفتح الشاشة الجديدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('kind', 30)->nullable()->after('type');
            $table->foreignId('branch_id')->nullable()->after('business_id')
                ->constrained('branches')->nullOnDelete();
            /*
             * القيد يُمحى فيبقى الصفّ التشغيلي — لا العكس.
             *
             * `nullOnDelete` لا `cascade`: حذفُ قيدٍ خاطئ يجب ألّا يمحو
             * المصروفَ نفسه من شاشته، وإلا صار تصحيحُ الدفتر يمحو المستند.
             */
            $table->foreignId('journal_entry_id')->nullable()->after('kind')
                ->constrained('journal_entries')->nullOnDelete();
            $table->string('client_uuid', 64)->nullable()->after('reference');

            $table->index(['business_id', 'kind']);
            $table->index(['business_id', 'client_uuid']);
        });

        // بيعةٌ لها طلب، وما عداها يُقرأ من `type` كما كان يُقرأ
        DB::table('transactions')->whereNotNull('order_id')->update(['kind' => 'sale']);
        DB::table('transactions')->whereNull('kind')->where('type', 'مصروف')->update(['kind' => 'expense']);
        DB::table('transactions')->whereNull('kind')->update(['kind' => 'other_income']);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'kind']);
            $table->dropIndex(['business_id', 'client_uuid']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn(['kind', 'client_uuid']);
        });
    }
};
