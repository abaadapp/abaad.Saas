<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * التسويةُ تأخذ ما لم يُؤخَذ — لا شهرًا كاملًا.
 *
 * ═══ ولمَ تبدّلت الوحدة ═══
 *
 * كانت الوحدةُ شهرًا، وفهرسٌ فريدٌ على (المحلّ، البوتيك، الشهر) يمنع ثانيةً.
 * فمن أصدرها في العاشر أغلق البابَ على عشرين يومًا: ما يُباع في بقيّة
 * الشهر يبقى في الكشف حيًّا ولا يجد تسويةً تحمله — لا شاشةَ تصرخ ولا
 * ميزانَ يختلّ، ويضيع مالُ البوتيك صامتًا. ولذلك كان الجاري لا يُسوَّى.
 *
 * وطُلب أن يُسوَّى، فبُدِّلت الوحدةُ بدل أن يُرفع الحارس.
 *
 * ═══ ولمَ ختمٌ على البند لا مدّةٌ بين وقتين ═══
 *
 * المدّةُ تبدو أبسط، وفيها ثغرةٌ لا تُسدّ: `ordered_at` دقّتُها **ثانية**.
 * فبيعةٌ تقع في الثانية التي أُصدرت فيها الورقة لا يفصلها عن أختها حدٌّ:
 * إن شملها الحدُّ حُسبت مرّتين، وإن جاوزها لم تُحسب أبدًا. ولا ثالثَ —
 * وكلاهما مالٌ يضيع أو يُدفع مرّتين.
 *
 * والختمُ يقطع السؤال: البندُ يحمل رقمَ ورقته، وما لا رقمَ له لم يُسوَّ.
 * ولا يعتمد على ساعةٍ ولا على دقّةِ عمود.
 *
 * ═══ والتفرّدُ ينتقل إلى الختم ═══
 *
 * ضغطتان متقاربتان: الأولى تختم البنودَ والثانية تجد صفرًا فتُردّ ويُلغى
 * ما كتبته. والقاعدةُ هي الحَكَم — لا فحصٌ يقرأ حالًا تتبدّل بين قراءته
 * وكتابته.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('boutique_settlements')) {
            return;
        }

        Schema::table('boutique_settlements', function (Blueprint $table) {
            if (! Schema::hasColumn('boutique_settlements', 'covered_from')) {
                // وحدّا ما شملته الورقة — خبرٌ يُقرأ عليها، لا حاكمٌ يُحسب به
                $table->timestamp('covered_from')->nullable()->after('period');
                $table->timestamp('covered_to')->nullable()->after('covered_from');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'boutique_settlement_id')) {
                $table->unsignedBigInteger('boutique_settlement_id')->nullable()->after('boutique_rate');
                $table->index(['boutique_settlement_id']);
            }
        });

        /*
         * وما صدر قبل هذه الهجرة أخذ شهرَه كلَّه — فتُختم بنودُه به.
         *
         * ولولا ذلك لَقُرئت بلا ختم، فأخذتها الورقةُ التالية ثانيةً: يُدفع
         * للبوتيك مرّتين عن بيعٍ واحد.
         */
        foreach (DB::table('boutique_settlements')->get(['id', 'business_id', 'boutique_id', 'period']) as $row) {
            $from = Carbon::createFromFormat('Y-m-d', $row->period.'-01')->startOfMonth();
            $to = (clone $from)->endOfMonth();

            DB::table('boutique_settlements')->where('id', $row->id)->update([
                'covered_from' => $from->toDateTimeString(),
                'covered_to' => $to->toDateTimeString(),
            ]);

            DB::table('order_items')
                ->where('boutique_id', $row->boutique_id)
                ->whereNull('boutique_settlement_id')
                ->whereIn('order_id', DB::table('orders')
                    ->where('business_id', $row->business_id)
                    ->whereBetween('ordered_at', [$from->toDateTimeString(), $to->toDateTimeString()])
                    ->select('id'))
                ->update(['boutique_settlement_id' => $row->id]);
        }

        /*
         * والفهرسُ على الشهر يسقط: الشهرُ لم يعد وحدةً، والحارسُ صار الختم.
         */
        Schema::table('boutique_settlements', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'boutique_id', 'period']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('boutique_settlements')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['boutique_settlement_id']);
            $table->dropColumn('boutique_settlement_id');
        });

        Schema::table('boutique_settlements', function (Blueprint $table) {
            $table->dropColumn(['covered_from', 'covered_to']);
            $table->unique(['business_id', 'boutique_id', 'period']);
        });
    }
};
