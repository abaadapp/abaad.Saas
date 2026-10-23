<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التجديدُ ينتظر سدادَ فاتورته — وتعرف الفاتورةُ تجديدَها بعينه.
 *
 * كان `Billing::renew` يُطيل `ends_at` ويُنشّط المتجر لحظةَ إصدار الفاتورة،
 * فمن لم يدفع حصل على دورته كاملةً. و`markPaid` كانت تُعلّم «آخرَ اشتراكٍ
 * غيرِ مدفوع» مدفوعًا — تخمينٌ يُخطئ متى كان للمتجر أكثرُ من دورةٍ معلّقة.
 *
 * فالتجديدُ صفٌّ في `subscriptions` بحالة «بانتظار السداد» بلا تاريخَين،
 * يحمل فاتورتَه (`invoice_id`، فريد: فاتورةٌ واحدة لتجديدٍ واحد) ودورتَه
 * (`cycle`) التي يُحسب منها الطول يوم السداد لا يوم الإصدار.
 *
 * والصفوفُ القائمة لا تُمسّ: عمودان فارغان لها، وهي «دوراتٌ مُنحت» كما
 * كانت. فاتورتُها القديمة تُسدَّد بلا تمديدٍ ثانٍ — انظر `Billing::markPaid`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->unique()->constrained('invoices')->nullOnDelete();
            // monthly | semiannual | yearly
            $table->string('cycle', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropUnique(['invoice_id']);
            $table->dropColumn(['invoice_id', 'cycle']);
        });
    }
};
