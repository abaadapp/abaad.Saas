<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ملاحظةٌ للعميل ليست ملاحظةً لأنفسنا — ومرفقاتُ الورقة تُحفظ.
 *
 * ═══ الملاحظتان ═══
 *
 * كان الحقل واحدًا وهو يُطبع على الفاتورة. فمن أراد أن يكتب لنفسه «العميل
 * يماطل، لا تُسلَّم قبل الدفع» كتبها حيث يقرؤها العميلُ في الورقة التي
 * تصله. وحقلٌ واحد لغرضين يجعل أحدَ الغرضين خطرًا.
 *
 * فصار الأصلُ `notes` ملاحظةَ العميل — تُطبع كما كانت، وما مضى لا يتبدّل
 * معناه — و`internal_notes` ما لا يُطبع.
 *
 * ═══ والمرفقات ═══
 *
 * فاتورةُ جهةٍ حكوميّة تُرفَق بأمر شرائها وعقدها وطلبها الموقَّع. وكانت
 * لا تُرفَق بشيء: تُحفظ الأوراق في بريدٍ أو مجلّدٍ على جهاز أحدهم.
 *
 * وجدولٌ لا عمود: الورقةُ الواحدة تحمل مستنداتٍ عدّة، وعمودٌ واحد يعني أنّ
 * رفعَ العقد يمحو أمرَ الشراء — وهي الحفرةُ نفسُها التي وقع فيها إيصالُ
 * أمر الشراء.
 *
 * والمسارُ على القرص الخاصّ لا العامّ: أمرُ شراء وزارةٍ ليس مستندًا يُفتح
 * برابطٍ يُخمَّن.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->text('internal_notes')->nullable()->after('notes');
        });

        Schema::create('customer_invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->constrained()->cascadeOnDelete();

            // المسارُ على القرص الخاصّ، والاسمُ كما سمّاه صاحبُه
            $table->string('path');
            $table->string('name');
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'customer_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoice_attachments');

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropColumn('internal_notes');
        });
    }
};
