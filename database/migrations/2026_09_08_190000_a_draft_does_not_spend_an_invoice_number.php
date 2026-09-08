<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مسودّةٌ لا تُنفق رقمَ فاتورة.
 *
 * ═══ ما كان ═══
 *
 * الرقمُ كان يُقطع لحظةَ الحفظ: تفتح شاشةَ الإنشاء وتحفظ مسودّةً فتأخذ
 * `CINV-000042`. ومسودّةٌ تُهجر تأخذ الرقمَ معها إلى القبر — فيقفز التسلسل
 * من ٤١ إلى ٤٣ في دفترٍ يُقرأ عند المحاسب القانونيّ وعند الضريبة، ولا
 * جوابَ عن «أين الثانية والأربعون؟» إلّا «كتبها أحدٌ ثمّ عدل».
 *
 * ═══ وما صار ═══
 *
 * العمودُ يقبل الفراغ. المسودّةُ بلا رقم، والرقمُ يُقطع عند الإصدار وحده —
 * حيث يقع القيد. انظر `CustomerInvoices::issue`.
 *
 * ═══ وما مضى لا يُمسّ ═══
 *
 * كلُّ ورقةٍ تحمل رقمًا اليوم تبقى به: الصادرةُ والملغاة، والمسودّاتُ التي
 * أخذت أرقامَها تحت القاعدة القديمة. و`issue` تكتب الرقمَ إن كان فارغًا
 * ولا تستبدل موجودًا — فمسودّةٌ قديمة تُصدَر برقمها الذي حجزته، ولا تُرقَّم
 * ورقةٌ مرّتين.
 *
 * ═══ والتفرّدُ يبقى ═══
 *
 * `unique(business_id, number)` كما هو: PostgreSQL وSQLite يسمحان بتكرار
 * الفراغ في الفهرس الفريد، فمسودّاتٌ كثيرة بلا رقم لا تصطدم — ورقمان
 * متطابقان يُردّان كما كانا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->string('number', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * والعودةُ تملأ الفراغَ قبل أن تمنعه.
         *
         * عمودٌ يُعاد إلزامُه وفيه فراغٌ يردّه المحرّك. فتأخذ المسودّاتُ
         * بلا رقمٍ مرجعًا داخليًّا من معرّفها — لا رقمًا رسميًّا يُخلط
         * بأرقام الصادرة.
         */
        DB::table('customer_invoices')->whereNull('number')
            ->update(['number' => DB::raw("'DRAFT-' || id")]);

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->string('number', 40)->nullable(false)->change();
        });
    }
};
