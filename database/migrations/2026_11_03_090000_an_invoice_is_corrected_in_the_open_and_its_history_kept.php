<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تعديلُ فاتورة العميل بعد كتابتها — وسجلُّه الذي لا يُمحى.
 *
 * ═══ ما يُضاف — وكلُّه إضافة ═══
 *
 * - `customer_invoices.revision`: عدّادُ تعديلات الورقة. يُرسَل مع شاشة التعديل
 *   ويُقارَن تحت قفل الصفّ عند الحفظ: موظّفان فتحا الورقةَ نفسَها لا يمحو
 *   ثانيهما ما حفظه الأوّل في صمت (`CustomerInvoiceEdits::apply`).
 * - `customer_invoice_revisions`: صفٌّ لكلّ تعديل — الورقةُ قبله وبعده بنودًا
 *   ورأسًا، وما تغيّر، والسبب، ومن، ومتى، والمدفوعُ ساعتَها، والفترةُ التي
 *   كُتب فيها التصحيح، وقيدا العكس والترحيل اللذان كتبهما. ولا يُعدَّل ولا
 *   يُحذف من النظام (`CustomerInvoiceRevision`).
 *
 * و`edit_token` فريدٌ مع المتجر: إرسالُ النموذج نفسِه مرّتين لا يكتب قيدين.
 *
 * ولا يُمسّ صفٌّ قائم: الفواتيرُ كلُّها تبدأ بعدّادٍ صفر، ولا سجلَّ لما قبل اليوم.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_invoices', 'revision')) {
            Schema::table('customer_invoices', function (Blueprint $table) {
                $table->unsignedInteger('revision')->default(0);
            });
        }

        if (Schema::hasTable('customer_invoice_revisions')) {
            return;
        }

        Schema::create('customer_invoice_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->constrained()->cascadeOnDelete();
            // رقمُ التعديل داخل الورقة: ١ ثمّ ٢ — يقابل `customer_invoices.revision` بعده
            $table->unsignedInteger('revision');
            // حالُ الورقة ساعةَ عُدّلت: مسودة / صادرة / مدفوعة — ومنه الصلاحيةُ التي سُئلت
            $table->string('kind', 12);
            $table->string('reason', 255)->nullable();
            $table->json('before');
            $table->json('after');
            $table->json('changes');
            $table->decimal('total_before', 12, 3)->default(0);
            $table->decimal('total_after', 12, 3)->default(0);
            $table->decimal('tax_before', 12, 3)->default(0);
            $table->decimal('tax_after', 12, 3)->default(0);
            $table->decimal('paid_at_edit', 12, 3)->default(0);
            $table->decimal('credited_at_edit', 12, 3)->default(0);
            // أين وقع التصحيح في الدفتر: same (شهرُ القيد) / current (الفترة الحاليّة) / original (تاريخ القيد)
            $table->string('period_mode', 12)->nullable();
            $table->date('reversal_date')->nullable();
            $table->date('entry_date')->nullable();
            $table->foreignId('reversal_entry_id')->nullable()->index()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('entry_id')->nullable()->index()->constrained('journal_entries')->nullOnDelete();
            $table->string('edit_token', 64)->nullable();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_invoice_id', 'revision'], 'cir_invoice_revision_unique');
            $table->unique(['business_id', 'edit_token'], 'cir_edit_token_unique');
        });
    }

    public function down(): void
    {
        // سجلُّ تعديل مستندٍ ماليّ لا يُسقط وفيه صفوف — الرجوعُ بالكود وحده (Revert PR)
        if (Schema::hasTable('customer_invoice_revisions') && DB::table('customer_invoice_revisions')->exists()) {
            throw new RuntimeException('لا يُتراجع: في سجلّ تعديلات الفواتير صفوف. ارجع بالكود وحده (Revert PR) واترك الجدول — الكودُ القديم لا يقرؤه.');
        }

        Schema::dropIfExists('customer_invoice_revisions');

        if (Schema::hasColumn('customer_invoices', 'revision')) {
            Schema::table('customer_invoices', fn (Blueprint $t) => $t->dropColumn('revision'));
        }
    }
};
