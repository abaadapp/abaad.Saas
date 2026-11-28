<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * طلبُ تصحيحٍ ماليّ ينتظر شخصًا ثانيًا — حين يُشعل صاحبُ النشاط الاعتمادَ الثنائيّ.
 *
 * الصفُّ يحمل التصحيحَ كما طُلب (`payload`) وبصمةَ الفاتورة يومَ طُلب
 * (`fingerprint`)، ولا يكتب في الفاتورة ولا في الدفتر شيئًا: التصحيحُ يقع
 * يوم يُعتمد، من `CustomerInvoiceEdits::apply` أو `replace` نفسِها، بعد أن
 * يُعاد السؤالُ عن الفاتورة والفترة والصلاحيات.
 *
 * وطلبٌ واحدٌ معلَّق لكلّ فاتورة: فهرسٌ جزئيّ يمنع الثاني ولو سبق قفلَ
 * الكود سباقٌ (PostgreSQL وSQLite يقبلانه كلاهما).
 *
 * ولا يمسّ صفًّا قائمًا ولا يُشعل شيئًا: جدولٌ جديدٌ فارغ، والمفتاحُ مطفأٌ
 * لكلّ نشاطٍ حتى يُشعله صاحبُه — لا صفَّ إعدادٍ يُكتب هنا.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_corrections')) {
            return;
        }

        $this->briefly(function () {
            Schema::create('financial_corrections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->index()->constrained()->cascadeOnDelete();
                // تصحيحُ الفاتورة بإشعار، أو إلغاؤها وإصدارُ بديلتها لعميلٍ آخر
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
                // ما نُفّذ به: نسخةُ التصحيح، أو البديلةُ المكتوبة مسودّة
                $table->foreignId('customer_invoice_revision_id')->nullable()->index()->constrained()->nullOnDelete();
                $table->foreignId('replacement_invoice_id')->nullable()->index()->constrained('customer_invoices')->nullOnDelete();
                // والإشعارُ الدائنُ اليدويّ الذي صدر عند الاعتماد (`FinancialCorrection::CREDIT_NOTE`)
                $table->foreignId('customer_credit_note_id')->nullable()->index()->constrained()->nullOnDelete();
                $table->timestamps();

                $table->unique(['business_id', 'edit_token'], 'fc_edit_token_unique');
                $table->index(['business_id', 'status'], 'fc_business_status_index');
            });

            if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
                DB::statement("CREATE UNIQUE INDEX fc_one_pending_per_invoice ON financial_corrections (customer_invoice_id) WHERE status = 'pending'");
            }
        });
    }

    public function down(): void
    {
        // سجلُّ الاعتمادات لا يُسقط وفيه صفوف — الرجوعُ بالكود وحده (Revert PR)
        if (Schema::hasTable('financial_corrections') && DB::table('financial_corrections')->exists()) {
            throw new RuntimeException('لا يُتراجع: في طلبات التصحيح الماليّ صفوف. ارجع بالكود وحده (Revert PR) واترك الجدول — الكودُ القديم لا يقرؤه.');
        }

        $this->briefly(fn () => Schema::dropIfExists('financial_corrections'));
    }

    /*
     * ═══ والقفلُ لحظةٌ لا انتظار ═══
     *
     * الجدولُ جديد، لكنّ مفاتيحَه الأجنبيّة تطلب قفلًا قصيرًا على ما تشير إليه —
     * الفواتيرَ وتصحيحاتِها والمستخدمين — ولو كان تقريرٌ طويلٌ يقرؤها انتظرَته،
     * وكلُّ بيعةٍ تصل بعدها تنتظر خلفها. فتنتظر القفلَ ثلاثَ ثوانٍ لا أكثر ثمّ
     * تتراجع وتعيد المحاولة بعد ثانية، خمسَ مرّات؛ وإن لم يُتَح سقطت الهجرة
     * فيتوقّف النشرُ قبل إعادة تحميل PHP ويبقى الموقعُ على كوده القديم.
     * (كما في `2026_12_03_090000_a_credit_note_names_the_order_it_was_for`.)
     */
    public $withinTransaction = false;

    public int $attempts = 5;

    public string $lockTimeout = '3s';

    public float $pause = 1.0;

    private function briefly(callable $change): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            DB::transaction(fn () => $change());

            return;
        }

        if (! preg_match('/^\d+(ms|s)$/', $this->lockTimeout)) {
            throw new InvalidArgumentException('lock_timeout: '.$this->lockTimeout);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(function () use ($change) {
                    DB::statement("SET LOCAL lock_timeout = '".$this->lockTimeout."'");
                    $change();
                });

                return;
            } catch (QueryException $e) {
                // 55P03: لم يُتَح القفلُ في مهلته — وما سواه خطأٌ حقيقيٌّ لا يُعاد
                if ((string) ($e->errorInfo[0] ?? $e->getCode()) !== '55P03' || $attempt >= $this->attempts) {
                    throw $e;
                }
                usleep((int) ($this->pause * 1_000_000));
            }
        }
    }
};
