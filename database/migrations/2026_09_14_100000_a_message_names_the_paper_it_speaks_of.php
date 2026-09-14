<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الرسالةُ تُسمّي الورقةَ التي تتحدّث عنها.
 *
 * تذكيرُ السداد يخرج بقالبٍ نصُّه «رقم الفاتورة: {{2}}» — وكان الصفُّ لا يحمل
 * الفاتورة أصلًا: `order_id` فارغٌ لأنّها ليست طلبًا، ولا عمودَ للفاتورة. فما
 * يُرسَل في المتغيّر الثاني نصٌّ **فارغ**، وميتا تردّ القالب: متغيّرٌ بلا قيمة
 * لا يُقبل. فالتذكير لا يصل، ويُقيَّد `failed` بسببٍ لا يقوله أحد.
 *
 * ومعرّفُها كان مكتوبًا في `dedupe_key` («inv:متجر:فاتورة:حدث») — ولا يُقرأ
 * منه: مفتاحٌ وُضع لمنع التكرار، وقراءتُه لغير ما وُضع له تجعل تبديلَ صيغته
 * يومًا يكسر رسالةً لا صلةَ لها به.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            /*
             * ويقبل الفراغ: أكثرُ الرسائل عن طلبٍ لا عن فاتورة.
             *
             * و`nullOnDelete` لا `cascade`: الرسالةُ دفترُ حقيقةٍ يُدقَّق —
             * حُذفت الفاتورةُ أو لم تُحذف، يبقى أنّ رسالةً خرجت يومًا.
             */
            $table->foreignId('customer_invoice_id')->nullable()->after('order_id')
                ->constrained('customer_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_invoice_id');
        });
    }
};
