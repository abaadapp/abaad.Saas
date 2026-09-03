<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مفتاحُ الصمود يصير قيدًا — لا فحصًا يُسبَق.
 *
 * شاشة البيع تحمل صندوق صادرٍ يعيد إرسال ما لم يصل حين يعود الاتصال، ومعه
 * `client_uuid` كي لا تتكرّر الفاتورة. والخادم يفحصه قبل أن يكتب: يسأل هل
 * وُجد، فإن لم يوجد كتب. وبين السؤال والكتابة فرجة.
 *
 * فطلبان بالمفتاح نفسه يصلان معًا — والحال المعتادة أن يكون الأول قد نجح
 * ثم انقطع الردّ فأعاد الجهاز الإرسال — فيقرأ كلاهما «لا شيء» ويكتب كلاهما
 * فاتورة. والنتيجة ليست ورقةً زائدة: مخزونٌ يُخصم مرّتين، ودخلٌ يُقيَّد
 * مرّتين، ونقاطُ ولاءٍ تُمنح مرّتين — على بيعةٍ واحدة وقعت مرّة.
 *
 * والفهرس كان عاديًّا للبحث لا للمنع. والقيد هو ما يجعل الفحص وعدًا: من
 * سبق كتب، ومن تأخّر يُردّ إليه رقمُ الفاتورة الأولى.
 *
 * والفارغ لا يُقيَّد: أكثر الطلبات بلا مفتاح — بيعةُ شاشةٍ متّصلة، وطلبٌ
 * يُنشأ من اللوحة — والفراغات لا تتصادم في أيّ محرّك.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['business_id', 'client_uuid'], 'orders_business_client_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_business_client_uuid_unique');
        });
    }
};
