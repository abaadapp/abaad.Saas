<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * الإشعارُ الدائنُ يسمّي الطلبَ الذي كان عنه — وكلُّ تسميةٍ سطرٌ لا يُمحى.
 *
 * ═══ لماذا ═══
 *
 * إشعارٌ يدويٌّ على فاتورةٍ تجمع طلبات لا يقول أيَّ طلبٍ يخصّ، فيمنع إلغاءَ
 * طلباتها كلِّها (`BilledOrderCancel`): إلغاءُ طلبٍ يعكس إيرادَه وضريبتَه،
 * ولو كان الإشعارُ عنه نقص البيعُ مرّتين. فيسمّي من يُصدر إشعارَ الإرجاع
 * طلبَه، أو يسمّيه صاحبُ النشاط أو المحاسبُ المخوَّل بعد التحقّق — فيُعرف
 * أنّ الإشعارَ عن ذلك الطلب وحده، ويبقى إلغاءُ الطلبات الأخرى ممكنًا.
 *
 * ═══ ولمَ جدولٌ لا عمود ═══
 *
 * `customer_credit_notes.order_id` يعني «وُلد هذا الإشعارُ من إلغاء هذا
 * الطلب» — يقرؤه `onOrderCancelled` ليعرف أنّ الإلغاءَ كُتب. ولو حمل التسميةَ
 * أيضًا لقرأ الإلغاءُ إشعارَ إرجاعٍ مسمًّى إشعارَ إلغاءٍ سابقًا. والجدولُ سجلُّ
 * تدقيقٍ معًا: من سمّى، ومتى، ولماذا، وبأيّ مبلغٍ وضريبة — سطرٌ لكلّ إشعار
 * (`unique`)، لا يُعدَّل ولا يُحذف.
 *
 * ولا يمسّ الربطُ مبلغًا ولا قيدًا: الإشعارُ كما صدر، وقيدُه كما كُتب.
 * ولا بيانات تُنقل: لا إشعارَ اليوم يُعرف طلبُه فيُسمّى عنه.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->briefly(function () {
            Schema::create('customer_credit_note_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_credit_note_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_invoice_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                // عند الإصدار (`issue`) أو بعده (`later`)
                $table->string('mode', 10);
                // لقطةُ الإشعار يومَ سُمّي — لا تُقرأ بها الحدود، تُقرأ للتدقيق
                $table->decimal('amount', 12, 3);
                $table->decimal('tax_amount', 12, 3)->default(0);
                $table->string('reason', 200);
                $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
                // والاسمُ منسوخًا: حسابٌ يُحذف بعد سنة لا يمحو من سمّى
                $table->string('linked_by_name', 120)->nullable();
                $table->timestamps();

                $table->unique('customer_credit_note_id');
                $table->index('business_id');
                $table->index('customer_invoice_id');
                $table->index('order_id');
                $table->index('linked_by');
            });
        });
    }

    public function down(): void
    {
        /*
         * ولا يُسقط سجلٌّ فيه تسمية: بغيره يعود كلُّ إشعارٍ سُمّي «لا يُعرف طلبُه»،
         * ويضيع من سمّاه ولماذا. يُرجع الكودُ وحده (Revert PR).
         */
        if (Schema::hasTable('customer_credit_note_orders') && DB::table('customer_credit_note_orders')->exists()) {
            throw new RuntimeException('تسمية طلب الإشعار الدائن: في القاعدة إشعاراتٌ سُمّي طلبُها — لا يُسقط سجلّها. أرجِع الكود وحده.');
        }

        $this->briefly(fn () => Schema::dropIfExists('customer_credit_note_orders'));
    }

    /*
     * ═══ والقفلُ لحظةٌ لا انتظار ═══
     *
     * الجدولُ جديد، لكنّ مفاتيحَه الأجنبيّة تطلب قفلًا قصيرًا على ما تشير إليه —
     * الطلباتِ والفواتيرَ وإشعاراتِها — ولو كان تقريرٌ طويلٌ يقرؤها انتظرَته،
     * وكلُّ بيعةٍ تصل بعدها تنتظر خلفها. فتنتظر القفلَ ثلاثَ ثوانٍ لا أكثر ثمّ
     * تتراجع وتعيد المحاولة بعد ثانية، خمسَ مرّات؛ وإن لم يُتَح سقطت الهجرة
     * فيتوقّف النشرُ قبل إعادة تحميل PHP ويبقى الموقعُ على كوده القديم.
     * (كما في `2026_11_11_090000_a_customer_invoice_remembers_the_points_it_spent`.)
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
