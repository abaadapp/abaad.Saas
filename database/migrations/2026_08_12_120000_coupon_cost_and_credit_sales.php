<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            /*
             * ثمن العرض منفصلًا عن ثمن الولاء.
             *
             * كان `discount` يجمع خصم الكوبون ونقاط العميل في رقمٍ واحد، فلا
             * يُعرف كم كلّف كوبونٌ بعينه — وهو السؤال الوحيد الذي يقرّر
             * إعادةَ العرض أو إيقافه.
             */
            $table->decimal('coupon_discount', 12, 3)->default(0)->after('coupon_code');

            /*
             * البيع الآجل.
             *
             * كان `payment_status` يُكتب «مدفوع» مثبّتًا في الكود: لا بيع على
             * الحساب، ولا كشفَ «من عليه لي» — وهو أكثر ما يحتاجه بائع الجملة،
             * وأكثر ما يضيع بلا نظام. والمدفوع هنا رقمٌ لا علَم: البيعة قد
             * تُسدَّد على دفعات، والفرق هو الدَّين.
             */
            $table->decimal('paid_amount', 12, 3)->default(0)->after('total');
            $table->date('due_at')->nullable()->after('paid_amount');
        });

        // ما مضى مدفوعٌ بالكامل — وإلا ظهر تاريخ المتجر كلّه دَينًا في أوّل يوم
        DB::table('orders')->where('is_held', false)->update(['paid_amount' => DB::raw('total')]);

        Schema::table('expenses', function (Blueprint $table) {
            // المصروف المسجَّل من شاشة المالية له صفٌّ هناك أيضًا — يُربط بها
            // كي لا يُعدّ مرّتين ولا يُحذف أحدهما فيبقى الآخر يتيمًا
            $table->foreignId('transaction_id')->nullable()->after('employee_name')
                ->constrained('transactions')->nullOnDelete();
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            // الدفعة قد تكون على فاتورةٍ بعينها أو على الحساب كلّه
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->decimal('amount', 12, 3);
            $table->string('method')->default('نقدي');
            $table->string('note')->nullable();
            $table->string('employee_name')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['business_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payments');
        if (Schema::hasColumn('expenses', 'transaction_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropConstrainedForeignId('transaction_id');
            });
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_discount', 'paid_amount', 'due_at']);
        });
    }
};
