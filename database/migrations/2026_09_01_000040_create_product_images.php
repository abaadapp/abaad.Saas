<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صورُ المنتج الإضافية — والرئيسية تبقى حيث كانت.
 *
 * `products.image` عمودٌ يقرؤه عشرةُ مواضع: شاشة البيع، ولوحة التجهيز،
 * والقائمة، والتصدير، والبطاقة. ونقلُ الصورة الرئيسية منه إلى جدولٍ جديد
 * كان يعني تعديل كلّ قارئٍ منها — وكلُّ واحدٍ يُنسى يعرض بضاعةً بلا صورة.
 *
 * فبقي العمود هو **الصورة الرئيسية**، وهذا الجدول للإضافيّة وحدها. لا
 * تكرار: كلُّ صورةٍ في موضعٍ واحد، والرئيسية معروفةٌ بموضعها لا بعَلَمٍ
 * ثانٍ يناقضه. و«اجعلها رئيسية» تبديلٌ بين الموضعين في معاملةٍ واحدة.
 *
 * والصور تُدار بمسارها الخاصّ لا بنموذج المنتج: من يبدّل صورةً لا يُعيد
 * إرسال السعر والكمية والوصف معها، فلا يخسر تعديلًا لم يقصد لمسه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // المسار على قرص `public` — أو رابطٌ خارجيّ كما في `products.image`
            $table->string('path');
            // ترتيب العرض — والرئيسية ليست منه، هي في `products.image`
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
