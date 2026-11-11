<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * بيعةُ صندوقٍ اكتملت بلا اتصال وخصمُ نقاطها لا يغطّيه رصيدُ العميل حين وصلت.
 *
 * ═══ ولمَ عمودٌ على الطلب ═══
 *
 * البيعةُ لا تُردّ ولا يُغيَّر مبلغُها: الزبونُ دفع ما قيل له وانصرف. فيُكتب
 * عليها ما طُلب وما صُرف والفرقُ (`PosController::reviewRedemption`)، ومنه
 * يُشتقّ جرسُ صاحب المحلّ (`loyalty-review-`) — التنبيهاتُ هنا مشتقّةٌ من
 * القاعدة لا مخزَّنة (`Notifications`). وفارغٌ لكلّ طلبٍ قائم: لم يقع له ذلك.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->briefly(function () {
            Schema::table('orders', function (Blueprint $table) {
                $table->json('loyalty_review')->nullable();
            });
        });
    }

    public function down(): void
    {
        // ولا يُسقط عمودٌ فيه مراجعاتٌ مكتوبة — محوُه يُخفي عن صاحب المحلّ خصمًا لم تغطّه نقاط
        if (DB::table('orders')->whereNotNull('loyalty_review')->exists()) {
            throw new RuntimeException('مراجعات نقاط الصندوق: في القاعدة بيعاتٌ تنتظر المراجعة — لا يُسقط العمود. أرجِع الكود وحده.');
        }

        $this->briefly(function () {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('loyalty_review');
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
