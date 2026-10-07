<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ملاحظاتُ العميل وتعديلُ أصناف الفاتورة بعد صدورها — مفتاحٌ على النشاط.
 *
 * ═══ ما يفتحه ═══
 *
 * - في الموقع: «ملاحظة المنتج» تحت كلّ صنف، و«ملاحظات الطلب» في الإتمام —
 *   نصٌّ حرٌّ بالإنجليزيّة (`NotesAndEdits`).
 * - في الفاتورة بعد صدورها: إضافةُ صنفٍ واستبدالُه وتعديلُ ملاحظته، من باب
 *   التصحيح نفسِه (`OrderCorrection`) ولمن يملك «order.edit».
 *
 * ═══ ولمن ═══
 *
 * مغلقٌ لكلّ نشاطٍ افتراضًا، ويفتحه مديرُ المنصّة من شاشة النشاط كالإهداء.
 * ويُفتح هنا مرّةً لمتاجر قائمة «الإتمام بالإنجليزيّة»
 * (`storefront.ribbon_english_checkout_businesses`): هي التي طلبته، وهي التي
 * تكتب زبائنُها بالإنجليزيّة أصلًا. ولا اسمَ ولا رقمَ في الكود.
 *
 * ═══ و«المتبقّي على الطلب» ═══
 *
 * صنفٌ يُضاف إلى فاتورةٍ دُفعت يرفع إجماليَّها، والفرقُ لم يُدفع. فلا يُكتب
 * مدفوعًا ولا يُعاد شحنُ بطاقة: يبقى في `orders.balance_due` ذمّةً على
 * العميل حتّى يُحصَّل (`Books::recordSale` تُدين به الذمم).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('businesses', 'order_notes_and_edits_enabled')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->boolean('order_notes_and_edits_enabled')->default(false);
            });
        }

        if (! Schema::hasColumn('orders', 'balance_due')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('balance_due', 12, 3)->default(0);
            });
        }

        $ids = array_values(array_filter(array_map('intval', (array) config('storefront.ribbon_english_checkout_businesses', []))));

        if ($ids !== []) {
            DB::table('businesses')->whereIn('id', $ids)->update(['order_notes_and_edits_enabled' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'balance_due')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('balance_due');
            });
        }

        if (Schema::hasColumn('businesses', 'order_notes_and_edits_enabled')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->dropColumn('order_notes_and_edits_enabled');
            });
        }
    }
};
