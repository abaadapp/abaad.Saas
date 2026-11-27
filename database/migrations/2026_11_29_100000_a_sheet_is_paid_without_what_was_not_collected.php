<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ورقةُ بوتيكٍ أُلغي تحصيلُ بعضها: يوقَف سدادُ ذلك البعض حتّى يُراجَع — والورقةُ كما صدرت.
 *
 * ═══ ما كان ═══
 *
 * حصّةُ فاتورة العميل تحملها الورقةُ حين يكتمل تحصيلُها. فإن أُلغي التحصيلُ بعدها (ارتدّ
 * شيكٌ أو أُلغي سندُ قبض) قيل «للمراجعة» ولم يُمنع شيء: تُسدَّد الورقةُ كاملةً بمالٍ لم يُقبض.
 *
 * ═══ ما يُضاف ═══
 *
 * `boutique_sheet_holds`: صفٌّ لكلّ بندٍ حملته ورقةٌ ثمّ أُلغي تحصيلُ فاتورته — يُضاف ويُغلق
 * بسببه ولا يُحذف. ولا عمودَ يُضاف إلى ورقةٍ أو مصروفٍ أو قيد، ولا يُمسّ منها شيء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boutique_sheet_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('boutique_id');
            $table->unsignedBigInteger('boutique_settlement_id');
            $table->foreignId('boutique_invoice_line_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_invoice_id');
            // سندُ القبض الذي أُلغي — وفراغٌ حين لا يُعرف واحدٌ بعينه
            $table->unsignedBigInteger('customer_payment_id')->nullable();
            // حصّةُ البوتيك المتأثّرة ساعة فُتح: ما بقي من البند في ورقته
            $table->decimal('amount', 14, 3);
            // ما كان محسومًا من مرتجع البند ساعة فُتح — فيُقرأ ما حُسم بعدها بالفرق لا بالوقت
            $table->decimal('decided_base', 14, 3)->default(0);
            $table->string('why');
            // أكانت الورقةُ مسدَّدةً حين أُلغي التحصيل؟ — فيُعرض للمراجعة ولا سدادَ يوقَف
            $table->boolean('paid_before')->default(false);
            $table->timestamp('opened_at');

            // سُدّدت الورقةُ دونه: بقي في 2160 حتّى يُفرج عنه ويُسدَّد، أو يُسقطه إشعار
            $table->timestamp('withheld_at')->nullable();
            $table->decimal('withheld_amount', 14, 3)->nullable();
            $table->decimal('reduced_base', 14, 3)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->decimal('paid_amount', 14, 3)->nullable();
            $table->string('paid_method', 50)->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();

            // كيف أُغلق: أُعيد التحصيل، أو رُدّت القطعة، أو أُفرج عنه بقرار، أو روجع
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_as', 20)->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->string('note')->nullable();
            // أُعيد التحصيلُ بعد أن حُسم بقرار — فإلغاءٌ بعدها تعليقٌ جديد
            $table->timestamp('recollected_at')->nullable();

            // بندٌ واحد تعليقٌ مفتوحٌ واحد — نداءان لا يفتحان اثنين
            $table->string('live_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['business_id', 'boutique_id']);
            $table->index('boutique_settlement_id');
            $table->index('boutique_invoice_line_id');
            $table->index('customer_invoice_id');
        });
    }

    /** مالٌ موقوفٌ أو سُدّد بعد إيقافه: حذفُ سجلّه يُطلق سدادَه أو يُضيع أثرَه — فيُردّ، والرجوعُ بالكود وحده */
    public function down(): void
    {
        $held = Schema::hasTable('boutique_sheet_holds') ? DB::table('boutique_sheet_holds')->count() : 0;

        if ($held > 0) {
            throw new RuntimeException('لا يُتراجع عن هجرة تعليق سداد أوراق البوتيكات وفيها '.$held.' صفًّا. ارجع بالكود وحده فتبقى الجداول وبياناتها.');
        }

        Schema::dropIfExists('boutique_sheet_holds');
    }
};
