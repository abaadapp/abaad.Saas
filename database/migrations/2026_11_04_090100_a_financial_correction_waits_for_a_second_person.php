<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * طلبُ تصحيحٍ ماليّ ينتظر شخصًا ثانيًا — حين يُشعل صاحبُ النشاط الاعتمادَ الثنائيّ.
 *
 * الصفُّ يحمل التعديلَ كما طُلب (`payload`) وبصمةَ الفاتورة يومَ طُلب
 * (`fingerprint`)، ولا يكتب في الفاتورة ولا في الدفتر شيئًا: التصحيحُ يقع
 * يوم يُعتمد، من `CustomerInvoiceEdits::apply` نفسِها، بعد أن يُعاد السؤالُ
 * عن الفاتورة والفترة والصلاحيات.
 *
 * وطلبٌ واحدٌ معلَّق لكلّ فاتورة: فهرسٌ جزئيّ يمنع الثاني ولو سبق قفلَ
 * الكود سباقٌ (PostgreSQL وSQLite يقبلانه كلاهما).
 *
 * ولا يمسّ صفًّا قائمًا ولا يُشعل شيئًا: المفتاحُ مطفأٌ لكلّ نشاط حتى
 * يُشعله صاحبُه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_corrections')) {
            return;
        }

        Schema::create('financial_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->index()->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->foreignId('customer_invoice_id')->index()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('expected_revision');
            $table->string('fingerprint', 64);
            $table->json('payload');
            $table->json('summary');
            $table->string('reason', 255);
            $table->string('edit_token', 64);
            $table->foreignId('requested_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->foreignId('customer_invoice_revision_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'edit_token'], 'fc_edit_token_unique');
            $table->index(['business_id', 'status'], 'fc_business_status_index');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX fc_one_pending_per_invoice ON financial_corrections (customer_invoice_id) WHERE status = 'pending'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('financial_corrections') && DB::table('financial_corrections')->exists()) {
            throw new RuntimeException('لا يُتراجع: في طلبات التصحيح الماليّ صفوف. ارجع بالكود وحده (Revert PR) واترك الجدول — الكودُ القديم لا يقرؤه.');
        }

        Schema::dropIfExists('financial_corrections');
    }
};
