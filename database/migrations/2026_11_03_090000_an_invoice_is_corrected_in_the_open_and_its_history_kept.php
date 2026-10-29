<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تصحيحُ فاتورة العميل بعد كتابتها — بمستنداتٍ تُضاف لا بورقةٍ تُعاد كتابتُها.
 *
 * ═══ ما يُضاف — وكلُّه إضافة ═══
 *
 * - `customer_invoices.revision`: عدّادُ تعديلات الورقة. يُرسَل مع شاشة التعديل
 *   ويُقارَن تحت قفل الصفّ عند الحفظ: موظّفان فتحا الورقةَ نفسَها لا يمحو
 *   ثانيهما ما حفظه الأوّل في صمت (`CustomerInvoiceEdits::apply`).
 * - `customer_invoices.replaces_invoice_id`: الفاتورةُ التي حلّت هذه محلَّها —
 *   حين يتغيّر العميل فتُلغى غيرُ المدفوعة وتُكتب بديلتُها (`CustomerInvoiceEdits::replace`).
 * - `customer_invoice_revisions`: صفٌّ لكلّ تعديل — حالُ الورقة قبله وبعده،
 *   وما تغيّر، وأساسُ التصحيح، والسبب، ومن، ومتى، والمدفوعُ ساعتَها. ولا يُعدَّل
 *   ولا يُحذف من النظام (`CustomerInvoiceRevision`).
 * - `customer_debit_notes`: الإشعارُ المدين — زيادةٌ على فاتورةٍ صدرت برقمٍ
 *   وتسلسلٍ وتاريخٍ وبنودٍ تخصّه، يشير إلى الفاتورة الأصليّة (م٥٠ و١٥٥ من
 *   اللائحة التنفيذيّة لضريبة القيمة المضافة).
 * - `customer_credit_notes.items` و`basis` و`revision_id`: بنودُ الإشعار الدائن
 *   حين يولد من تصحيح، وأساسُه (خطأٌ أو إرجاع)، والتعديلُ الذي أصدره. والإشعارُ
 *   القائم يبقى كما هو: بلا بنود، وبلا أساس.
 *
 * ═══ والفاتورةُ الصادرة لا تُكتب ثانيةً ═══
 *
 * لا عمودَ هنا يحمل بنودًا «بعد التعديل» على الفاتورة نفسِها: بنودُها ومجاميعُها
 * كما صدرت، والتصحيحُ مستندٌ مستقلّ. وحالُها بعد كلّ تصحيح لقطةٌ في سجلّه.
 *
 * ولا يُمسّ صفٌّ قائم: الفواتيرُ كلُّها تبدأ بعدّادٍ صفر، ولا سجلَّ لما قبل اليوم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_invoices', 'revision')) {
                $table->unsignedInteger('revision')->default(0);
            }
            if (! Schema::hasColumn('customer_invoices', 'replaces_invoice_id')) {
                $table->foreignId('replaces_invoice_id')->nullable()->index()->constrained('customer_invoices')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('customer_invoice_revisions')) {
            Schema::create('customer_invoice_revisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignId('customer_invoice_id')->constrained()->cascadeOnDelete();
                // رقمُ التعديل داخل الورقة: ١ ثمّ ٢ — يقابل `customer_invoices.revision` بعده
                $table->unsignedInteger('revision');
                // حالُ الورقة ساعةَ عُدّلت: مسودة / صادرة / مدفوعة — ومنه الصلاحيةُ التي سُئلت
                $table->string('kind', 12);
                // أساسُ التصحيح بعد الإصدار: خطأٌ في الفاتورة أو إرجاع — وللمسودّة فراغ
                $table->string('basis', 20)->nullable();
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
                $table->string('edit_token', 64)->nullable();
                $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['customer_invoice_id', 'revision'], 'cir_invoice_revision_unique');
                $table->unique(['business_id', 'edit_token'], 'cir_edit_token_unique');
            });
        }

        Schema::table('customer_credit_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_credit_notes', 'items')) {
                $table->json('items')->nullable();
            }
            if (! Schema::hasColumn('customer_credit_notes', 'basis')) {
                $table->string('basis', 20)->nullable();
            }
            if (! Schema::hasColumn('customer_credit_notes', 'revision_id')) {
                $table->foreignId('revision_id')->nullable()->index()->constrained('customer_invoice_revisions')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('customer_debit_notes')) {
            Schema::create('customer_debit_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_invoice_id')->constrained()->cascadeOnDelete();
                $table->string('number', 40);
                // شاملٌ للضريبة — و`tax_amount` نصيبُها منه، كالإشعار الدائن
                $table->decimal('amount', 12, 3);
                $table->decimal('tax_amount', 12, 3)->default(0);
                $table->date('issued_at');
                $table->string('basis', 20)->nullable();
                $table->string('reason')->nullable();
                $table->json('items')->nullable();
                $table->foreignId('revision_id')->nullable()->index()->constrained('customer_invoice_revisions')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['business_id', 'number']);
                $table->index('customer_invoice_id');
            });
        }
    }

    public function down(): void
    {
        // مستندٌ ماليٌّ صدر لا يُسقط جدولُه وفيه صفوف — الرجوعُ بالكود وحده (Revert PR)
        foreach (['customer_debit_notes', 'customer_invoice_revisions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("لا يُتراجع: في {$table} صفوف. ارجع بالكود وحده (Revert PR) واترك الجداول — الكودُ القديم لا يقرؤها.");
            }
        }
        if (Schema::hasColumn('customer_credit_notes', 'revision_id') && DB::table('customer_credit_notes')->whereNotNull('revision_id')->exists()) {
            throw new RuntimeException('لا يُتراجع: إشعاراتٌ دائنة وُلدت من تصحيح. ارجع بالكود وحده (Revert PR).');
        }

        Schema::dropIfExists('customer_debit_notes');

        Schema::table('customer_credit_notes', function (Blueprint $table) {
            if (Schema::hasColumn('customer_credit_notes', 'revision_id')) {
                $table->dropConstrainedForeignId('revision_id');
            }
            foreach (['items', 'basis'] as $column) {
                if (Schema::hasColumn('customer_credit_notes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('customer_invoice_revisions');

        Schema::table('customer_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('customer_invoices', 'replaces_invoice_id')) {
                $table->dropConstrainedForeignId('replaces_invoice_id');
            }
            if (Schema::hasColumn('customer_invoices', 'revision')) {
                $table->dropColumn('revision');
            }
        });
    }
};
