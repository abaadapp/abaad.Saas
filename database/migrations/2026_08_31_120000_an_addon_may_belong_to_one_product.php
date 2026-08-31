<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إضافةٌ تخصّ منتجًا واحدًا لا المتجر كلّه.
 *
 * كانت كلّ إضافةٍ تُنشأ إضافةً للمتجر: من صنع «شريط ذهبي» لباقة الورد
 * وجده معروضًا مع كيس السماد ومع الشتلة. والربط في product_addons لا يكفي —
 * لأنّ غياب الربط معناه «كلّ الإضافات»، فالإضافة الجديدة تظهر مع كلّ منتجٍ
 * لم يُربط له شيء، وهم أكثر المنتجات.
 *
 * فالعمود يقول لمن هي:
 *   NULL  →  إضافةُ متجرٍ تُعرض مع الجميع (سلوك اليوم، وكلّ الصفوف القائمة)
 *   رقمٌ  →  خاصّةٌ بذلك المنتج وحده، لا تُعرض ولا تُقبل مع سواه
 *
 * والحذف يُفرغ العمود لا يحذف الإضافة: فاتورةٌ قديمة تحمل لقطتها، وإضافةٌ
 * تختفي فجأةً تترك فراغًا في شاشةٍ لا علاقة لها بالمنتج المحذوف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->index(['business_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'product_id']);
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
