<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * زرُّ حذفٍ بلا رجعة.
 *
 * حذف المنتج والمصروف كان محوًا نهائيًّا من صفٍّ واحد: ضغطةٌ خاطئة تُذهب
 * التكلفة والباركود والتصنيف وتاريخ الحركة، ولا شيء يُستدرَك. والسجلّ يقيّد
 * «حذف المنتج: كذا» — فيشهد على الخسارة ولا يردّها.
 *
 * والمصروف أسوأ: قيدٌ مالي بمرفقٍ وفاتورة. من يحذفه بالخطأ يفقد ما يُقدَّم
 * للمحاسب، ومن يحذفه عمدًا يمحو أثر صرفٍ تمّ.
 *
 * وفائدةٌ ثانية لم تكن مقصودة: تقارير المبيعات تصل المنتجَ بأصنافِ الطلبات
 * (leftJoin في Demo::‎ وAlertMetrics)، وحذفُ المنتج نهائيًّا كان يُفرغ اسمه
 * من كل بيعةٍ ماضية. الحذف الناعم يُبقي التاريخ مقروءًا.
 *
 * ما لا يُحذف ناعمًا عمدًا: الطلبات المعلّقة (مسوّدات سلّة، لا قيود)،
 * والفئات والموردون (لا تُحذف إلا فارغة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
