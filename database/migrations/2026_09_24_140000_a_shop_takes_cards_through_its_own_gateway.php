<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المتجرُ يقبض بالبطاقة — بحساب صاحبه لا بحساب أبعاد.
 *
 * ═══ ولمَ لكلّ محلٍّ حسابُه ═══
 *
 * حسابٌ واحدٌ لأبعاد يعني أنّ مالَ الزبون يدخل حسابَها ثمّ يُحوَّل — وذلك
 * وساطةُ دفعٍ تحتاج إذنًا من البنك المركزيّ العُمانيّ، وتجعل أبعاد تتحمّل
 * الاستردادَ والنزاعَ عن كلّ متجر. فمفاتيحُ كلِّ محلٍّ له، والمالُ يصل
 * حسابَه البنكيّ مباشرةً ولا يمرّ بنا.
 *
 * ═══ والأسرارُ مشفَّرةٌ في العمود ═══
 *
 * قاعدةُ `WhatsAppConnection` نفسُها: من نسخ قاعدةَ البيانات لا ينسخ معها
 * مفتاحَ القبض. و`$hidden` فوقها حتّى لا تتسرّب في `toArray` — وهي طريقُ
 * خصائص Inertia كلِّها إلى المتصفّح.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('paymob');

            // المفتاحُ العامّ وحدَه يخرج إلى المتصفّح — وهو المقصود به
            $table->string('public_key', 255)->nullable();
            // وهذان لا يخرجان أبدًا: نصُّهما مشفَّرٌ فيطول
            $table->text('secret_key')->nullable();
            $table->text('hmac_secret')->nullable();
            // ورقمُ تكامل البطاقة — يُنشئه التاجر في لوحة Paymob
            $table->string('card_integration_id', 32)->nullable();

            $table->boolean('active')->default(false);
            $table->timestamps();

            // حسابٌ واحدٌ لكلّ مزوّدٍ في المحلّ — لا حسابان يُقرأ أحدُهما صدفة
            $table->unique(['business_id', 'provider']);
        });

        /*
         * ═══ ولا طلبَ قبل أن يصل المال ═══
         *
         * الطلبُ يخصم المخزون. ولو كُتب قبل الدفع لَخصمَ كلُّ زائرٍ فتح
         * صفحةَ البطاقة ثمّ أغلقها باقةً من الرفّ — فينفد ما هو موجود.
         *
         * فتُحفظ نيّةُ الشراء هنا، ولا يُنشأ الطلبُ إلّا حين يُصدّق البنك.
         */
        Schema::create('store_payment_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // مرجعُنا نحن — يُرسَل إلى Paymob ويعود في الإشعار
            $table->string('reference', 64)->unique();

            /*
             * وحمولةُ الطلب كما أرسلها الزائر — تُعاد إلى `WebCheckout::place`
             * بعد التصديق. وتُسعَّر من القاعدة ثانيةً هناك: ما يُحفظ هنا هو
             * ما طلبه لا ما يدفعه.
             */
            $table->json('payload');
            $table->string('lang', 2)->default('ar');
            $table->decimal('amount', 14, 3)->default(0);
            $table->string('currency', 8)->default('OMR');

            $table->string('status', 16)->default('pending');

            $table->string('provider_order_id', 64)->nullable();
            /*
             * ومعرّفُ العمليّة فريدٌ — وهو حارسُ التكرار.
             *
             * Paymob تُعيد إرسال الإشعار إن تأخّر الجواب. وبلا هذا الفهرس
             * يُنشأ للطلب الواحد طلبان ويُخصم المخزونُ مرّتين.
             */
            $table->string('provider_transaction_id', 64)->nullable()->unique();

            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            // ولمَ لم يُنشأ الطلبُ وقد وصل المال — يُقرأ ولا يُخمَّن
            $table->text('error')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_intents');
        Schema::dropIfExists('payment_gateways');
    }
};
