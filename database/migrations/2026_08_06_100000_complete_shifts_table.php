<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إكمال جدول الورديات.
 *
 * الجدول موجود منذ أوّل هجرة، والنموذج موجود، ومستورَدٌ في PosController —
 * ولا سطر واحد يكتب فيه أو يقرأ منه. سقالةٌ تبدو ميزةً ولم تُبنَ قطّ.
 *
 * الناقص فيه ثلاثة يقوم عليها الغرض كلّه:
 * - الفرع: الدرج واحد في المحل، والوردية له لا للحساب المسجَّل.
 * - من أقفل: قد يفتحها موظف ويقفلها آخر، ومسؤولية الفرق للمُقفل.
 * - ملاحظة: سببُ الفرق يُكتب لحظتَه أو لا يُعرف أبدًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('business_id');
            $table->unsignedBigInteger('closed_by')->nullable()->after('user_id');
            $table->string('note')->nullable()->after('status');

            // السؤال الأكثر تكرارًا: هل لهذا الفرع وردية مفتوحة الآن؟
            $table->index(['business_id', 'branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'branch_id', 'status']);
            $table->dropColumn(['branch_id', 'closed_by', 'note']);
        });
    }
};
