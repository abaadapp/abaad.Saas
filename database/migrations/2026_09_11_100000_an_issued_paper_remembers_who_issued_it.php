<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الورقةُ الصادرة تحفظ من أصدرها — وبأيّ عملة.
 *
 * ═══ العطبُ الذي وُلدت منه هذه الهجرة ═══
 *
 * بنودُ الفاتورة ملقوطةٌ منذ البدء: `order_items` تحمل الاسمَ والسعرَ نسخةً
 * لا مرجعًا، و`orders` تحمل اسمَ العميل والفرعَ والموظّف نصًّا. فتغييرُ اسم
 * منتجٍ أو عميلٍ بعد البيع لا يمسّ ورقةً صدرت.
 *
 * **أمّا هويّةُ البائع والعملة فكانتا تُقرآن حيّتين.** قِستُ ذلك: أصدرتُ
 * فاتورةً ثمّ غيّرتُ اسمَ المتجر وعنوانَه ورقمَه الضريبيّ وعملتَه، وأعدتُ
 * رسمَ الورقة نفسِها:
 *
 *   • الاسمُ والعنوانُ والهاتفُ والبريد  →  تبدّلت كلُّها
 *   • الرقمُ الضريبيّ  OM1100234567 → OM9999999999
 *   • والمبالغُ  «12.500 OMR» → «12.50 د.إ»
 *
 * والأخيرةُ أسوأُها: الرقمُ المحفوظ في القاعدة لم يتغيّر — ١٢٫٥٠٠ — لكنّه
 * أُعيد وسمُه بعملةٍ أخرى وفقد منزلةً. فورقةٌ تقول إنّ الزبون دفع اثني عشر
 * درهمًا ونصفًا وهو دفع ريالًا ونصفًا. وجهةٌ تراجع الفواتير بعد سنة تجد
 * رقمًا ضريبيًّا لم يكن قائمًا يومَ البيع.
 *
 * ═══ ولمَ عمودٌ واحدٌ لا جدولٌ ولا HTML ═══
 *
 * الملقوطُ صغيرٌ وثابتُ الشكل: هويّةُ بائعٍ ووصفُ عملةٍ ونسخةُ قالب. وجدولٌ
 * جانبيٌّ يعني وصلةً في كلّ رسم، وحفظُ الرسم كاملًا يعني كيلوبايتاتٍ لكلّ
 * فاتورة تُخزَّن لتُقرأ مرّةً.
 *
 * وهو **قابلٌ للإفراغ**: صفوفُ ما قبل هذه الهجرة تبقى فارغةً وتُقرأ من
 * الحيّ كما كانت — لا تُختلق لها لقطةٌ بحالٍ لم يكن حالَها يومَ صدرت.
 */
return new class extends Migration
{
    /** الجداولُ التي تخرج منها أوراقٌ تبلغ جهةً خارجية */
    private const TABLES = ['orders', 'customer_invoices'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'document_snapshot')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->json('document_snapshot')->nullable()->after('id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'document_snapshot')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('document_snapshot'));
            }
        }
    }
};
