<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نموذجُ فاتورة العميل يُصدر ورقةً واحدة — مهما ضُغط وأُعيد.
 *
 * ضغطتان على «إصدار الفاتورة وإنشاء رابط دفع»، أو انقطاعٌ يعيد المتصفّحُ
 * بعده الطلب، أو تحديثُ الصفحة بعد الإرسال — كان كلٌّ منها يكتب فاتورةً
 * صادرةً ثانية برقمٍ جديد وقيدٍ ثانٍ، ويطلب لها رابطَ دفعٍ ثانيًا.
 *
 * فالشاشةُ تحمل رمزًا عشوائيًّا يُكتب مع الورقة، والقاعدةُ تردّ الثانيةَ
 * بالرمز نفسه في المتجر نفسه — كأمر الشراء (`purchase_orders.form_token`).
 * وفارغٌ لكلّ ما سبق: الفواتيرُ القائمة لا يُمسّ منها شيء.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customer_invoices', 'form_token')) {
            return;
        }

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->string('form_token', 64)->nullable();
            $table->unique(['business_id', 'form_token'], 'customer_invoices_form_token_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customer_invoices', 'form_token')) {
            return;
        }

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropUnique('customer_invoices_form_token_unique');
            $table->dropColumn('form_token');
        });
    }
};
