<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * للبائع اسمٌ ثانٍ بالإنجليزية — كما للصنف والفئة والعميل.
 *
 * ═══ ولمَ عمودٌ ولم يكفِ إعداد ═══
 *
 * رقمُ السجلّ التجاريّ صفٌّ في `settings` بجانب `vat_number`: كلاهما رقمُ
 * تسجيلٍ لدى جهة، ويُقرأ في الورق ولا يُبحث به. والاسمُ شيءٌ آخر: يسكن
 * `businesses.name` عمودًا يُبحث به ويُرتَّب عليه ويُقرأ في لوحة المنصّة —
 * فاسمُه الثاني يسكن بجانبه لا في جدولٍ آخر.
 *
 * وهو النمطُ القائم في المستودع نفسِه: `products.name_en` و`categories`
 * و`addons` و`customers` — كلُّها أعمدةٌ بهذا الاسم، تقرؤها `Demo::ln`
 * بقاعدةٍ واحدة. وحقلٌ سادسٌ يقول الشيءَ نفسه بطريقةٍ سادسة يفترق يومًا.
 *
 * ═══ ولا يُملأ من العربيّ ═══
 *
 * فارغٌ يعني «لم يُسجَّل»، و`Demo::ln` تردّ العربيَّ حينها. وترجمةٌ
 * تلقائيّةٌ لاسمٍ قانونيّ تضع على فاتورةٍ ضريبيّة اسمًا لا يطابق شهادةَ
 * السجلّ — وهو أسوأُ من غيابه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('name_en'));
    }
};
