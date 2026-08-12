<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إزالة البيع الآجل والذمم — بطلب صاحب النشاط.
 *
 * أُضيفت في v3.39 ثمّ لم تُرَد. والحذف من القاعدة لا من الشاشة وحدها: عمودٌ
 * باقٍ لا يقرؤه أحد يصير بعد سنةٍ لغزًا — يفتح أحدهم الجدول فيجد `paid_amount`
 * ويظنّ أن في النظام تسديدًا جزئيًّا لا وجود له.
 *
 * ويبقى `coupon_discount` و`expenses.transaction_id`: هما من تغييرٍ آخر في
 * الهجرة نفسها (ثمن العرض، ووصل المصروفات بالدفتر) ولم يُطلب حذفهما.
 *
 * والحرّاس ليست تزيّدًا: تثبيتٌ جديد يمرّ على الهجرة الأصلية بعد تعديلها فلا
 * ينشئ هذه أصلًا، ثمّ يصل إلى هنا فيجد ما لا وجود له.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('customer_payments');

        foreach (['paid_amount', 'due_at'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    public function down(): void
    {
        // لا رجعة: الميزة أُزيلت بطلبٍ صريح، وإعادة عمودٍ فارغ ليست استرجاعًا
    }
};
