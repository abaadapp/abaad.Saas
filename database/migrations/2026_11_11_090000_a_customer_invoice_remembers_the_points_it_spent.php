<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * فاتورةُ العميل تحفظ ما استُبدل عليها من نقاط — عددَها وقيمتَها.
 *
 * ═══ ولمَ لقطةٌ في الصفّ ═══
 *
 * الخصمُ يُحسب مرّةً عند كتابة الفاتورة، وتُخصم النقاطُ معه في المعاملة
 * نفسِها. وما بعد ذلك — الإصدارُ، والرابط، والسداد، والوصولُ إلى لوحة
 * التجهيز — يقرأ هذين العمودين ولا يعيد الحساب: إعدادٌ تغيّر غدًا لا يغيّر
 * فاتورةً كُتبت اليوم، ولا تُخصم النقاطُ مرّتين.
 *
 * ولا بيانات تُمسّ: فاتورةٌ قائمة تبدأ بصفرين، وهو ما وقع لها فعلًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->briefly(function () {
            Schema::table('customer_invoices', function (Blueprint $table) {
                $table->unsignedInteger('loyalty_points')->default(0);
                $table->decimal('loyalty_discount', 12, 3)->default(0);
            });

            /*
             * ونصيبُ كلّ بندٍ من خصم النقاط — داخلَ خصمه لا فوقه.
             *
             * `discount` يبقى مجموعَ خصم البند (يدويًّا ونقاطًا) فتُحسب منه
             * الضريبةُ والإجماليّ كما كانت. وهذا العمودُ يقول كم منه نقاط: فتطبع
             * الورقةُ خصمَ البند وخصمَ النقاط سطرين، ولا يُعدّ أحدُهما مرّتين.
             */
            Schema::table('customer_invoice_items', function (Blueprint $table) {
                $table->decimal('loyalty_discount', 12, 3)->default(0);
            });
        });
    }

    public function down(): void
    {
        /*
         * ولا يُسقط عمودٌ فيه نقاطٌ صُرفت: محوُه يجعل إلغاءَ الفاتورة لا يعرف
         * كم يُعيد إلى العميل. يُرجع الكودُ وحده (Revert PR).
         */
        if (DB::table('customer_invoices')->where('loyalty_points', '>', 0)->exists()) {
            throw new RuntimeException('نقاط الولاء على فواتير العملاء: في القاعدة فواتيرُ صُرفت عليها نقاط — لا تُسقط العمودين. أرجِع الكود وحده.');
        }

        $this->briefly(function () {
            Schema::table('customer_invoice_items', function (Blueprint $table) {
                $table->dropColumn('loyalty_discount');
            });

            Schema::table('customer_invoices', function (Blueprint $table) {
                $table->dropColumn(['loyalty_points', 'loyalty_discount']);
            });
        });
    }

    /*
     * ═══ والقفلُ لحظةٌ لا انتظار ═══
     *
     * إضافةُ عمودٍ بقيمةٍ ثابتة أو فارغ لا تعيد كتابةَ صفٍّ في PostgreSQL —
     * تغييرٌ في وصف الجدول وحده. لكنّها تطلب قفلَ الجدول كلِّه لتلك اللحظة،
     * ولو كان تقريرٌ طويلٌ يقرؤه انتظرَته — وكلُّ بيعةٍ تصل بعدها تنتظر خلفها.
     *
     * فتنتظر القفلَ ثلاثَ ثوانٍ لا أكثر (`lock_timeout`) ثمّ تتراجع وتعيد
     * المحاولة بعد ثانية، خمسَ مرّات. فأسوأُ ما تراه البيعةُ ثلاثُ ثوانٍ، لا
     * وقوفٌ بلا حدّ. وإن لم يُتَح القفلُ سقطت الهجرة — فيتوقّف النشرُ قبل إعادة
     * تحميل PHP ويبقى الموقعُ على كوده القديم (`scripts/deploy.sh`)، ويُعاد
     * النشرُ في وقتٍ أهدأ. والمعاملةُ تجمع التغييرَ كلَّه: يقع كلُّه أو لا شيء.
     *
     * والأرقامُ خصائصُ يضبطها اختبارُها (`AColumnIsAddedWithoutHoldingTheSaleTest`).
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
