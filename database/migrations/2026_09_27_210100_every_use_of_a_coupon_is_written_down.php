<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلُّ استعمالات الكوبون — من استعمله، وفي أيّ طلب.
 *
 * ═══ ولمَ جدولٌ لا عدّادٌ آخر ═══
 *
 * «مرّتان لكلّ زبون» سؤالٌ عن **زبونٍ بعينه**، ولا يُجاب من عدّادٍ واحد.
 * وعمودٌ على العميل لا يكفي: الزبون قد يشتري بلا صفِّ عميلٍ في القاعدة
 * (الكاشير يكتب رقمه ولا يُنشئ له بطاقة)، وقد يُنشئ حسابًا ثانيًا بالرقم
 * نفسه. فالسجلُّ صفٌّ لكلّ استعمال: الكوبونُ والزبونُ والطلب.
 *
 * ═══ والطلبُ لا يُحتسب مرّتين ═══
 *
 * `order_id` فريدٌ في الجدول — قيدٌ في القاعدة لا فحصٌ في الكود. فإشعارُ
 * بوّابةٍ أُعيد إرسالُه، أو طلبٌ استُكمل مرّتين، أو صندوقان أرسلا الحمولةَ
 * نفسَها: كلُّها تُردّ عند القيد ولا تصير استعمالًا ثانيًا.
 *
 * ═══ وهويّةُ الزبون لقطةٌ لا مرجع ═══
 *
 * `customer_key` الرقمُ مطبَّعًا كما كان يوم الاستعمال — لا يُعاد كتابتُه
 * أبدًا، فطلبٌ قديمٌ يبقى على هويّته. و`customer_id` معه: من غيَّر رقمه على
 * بطاقته نفسِها يُعرف بمعرّفه، ومن أنشأ بطاقةً ثانيةً بالرقم نفسِه يُعرف
 * بالرقم. فلا يُفلت أحدُ الطريقين — انظر `CouponLimits::countFor`.
 *
 * والعزلُ بـ`business_id`: كوبوناتُ التجّار لا تختلط، ورقمُ الهاتف ليس
 * فريدًا في الدنيا — هو فريدٌ في متجرٍ واحد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            // الطلبُ يُحذف فيسقط سجلُّه معه: استعمالٌ لطلبٍ لا وجودَ له ليس استعمالًا
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            /*
             * والعميلُ يُحفظ مرجعًا ضعيفًا: بطاقتُه قد تُحذف حذفًا ناعمًا،
             * ولا يجوز أن يسقط سجلُّ استعماله معها فيُفتح له الحدُّ من جديد.
             */
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_key', 40);
            $table->timestamp('redeemed_at');
            $table->timestamps();

            // طلبٌ واحدٌ استعمالٌ واحد — القيدُ في القاعدة
            $table->unique('order_id');
            // والسؤالُ الذي يُسأل في كلّ بيعة: كم مرّةً استعمل هذا الزبونُ هذا الكود؟
            $table->index(['business_id', 'coupon_id', 'customer_key']);
            $table->index(['business_id', 'coupon_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
