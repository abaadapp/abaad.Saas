<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * بيعةُ الصندوق تحمل قاعدةَ ضريبتها وما اشترته نقاطُها — لا تُستنتج بعد البيع.
 *
 * ═══ `vat_rounding` ═══
 *
 * صارت ضريبةُ البيعة تُدوَّر سطرًا سطرًا (`VatLines`) كفاتورة العميل. وتصحيحُ
 * بيعةٍ في يومها يعيد حسابَ ضريبتها كلِّها (`OrderCorrection::totals`)، فلو
 * حُسبت بيعةٌ قديمة بالقاعدة الجديدة لتغيّرت ضريبةُ ما لم يُصحَّح فيها ببيسة.
 * فالجديدةُ تُكتب `line` (افتراضُ النموذج)، والقائمةُ فارغةٌ = قاعدتُها القديمة.
 *
 * ═══ `loyalty_basis` ═══
 *
 * إرجاعُ بعض البنود يعيد من النقاط نصيبَها (`TillRedemption`)، والنصيبُ يُقاس
 * بما كان وقت البيع: النقاطُ المصروفة وخصمُها ومجموعُ البنود. وبعد أوّل تصحيحٍ
 * لا يُعرف ذلك من الصفّ. فيُكتب عند البيع، وللقديمة عند أوّل تصحيحٍ ما دامت
 * بنودُها كما بيعت — وإلّا رُفض تعديلُ بنودها ولم يُخمَّن.
 *
 * ولا صفَّ قائمًا يُكتب: العمودان فارغان لكلّ طلبٍ موجود.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->briefly(function () {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('vat_rounding', 8)->nullable();
                $table->json('loyalty_basis')->nullable();
            });
        });
    }

    public function down(): void
    {
        // ولا يُسقط عمودٌ كُتب فيه: بيعاتٌ بقاعدةٍ جديدة أو بنقاطٍ مقيسة تعود مجهولةً فتُصحَّح بغير ما بيعت به
        if (DB::table('orders')->whereNotNull('vat_rounding')->orWhereNotNull('loyalty_basis')->exists()) {
            throw new RuntimeException('قاعدة ضريبة البيعة ونقاطها: في القاعدة بيعاتٌ كُتبت بهما — لا يُسقط العمودان. أرجِع الكود وحده.');
        }

        $this->briefly(function () {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn(['vat_rounding', 'loyalty_basis']);
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
