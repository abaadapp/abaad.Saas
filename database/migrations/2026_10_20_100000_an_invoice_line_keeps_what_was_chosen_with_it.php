<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بندُ فاتورة العميل يحفظ ما اختير معه — مقاسَه وإضافاتِه وخياراتِه وملاحظتَه.
 *
 * ═══ ما كان ═══
 *
 * البندُ وصفٌ وكميّةٌ وثمن. فباقةٌ «كبيرة» معها شوكولاتتان وكرتٌ ولونُها
 * أحمرُ وأبيض كانت تُكتب سطرًا باسمها، والإضافةُ سطرًا مستقلًّا لا يُعرف
 * لأيّ صنفٍ هي، والمقاسُ واللونُ والملاحظةُ لا موضعَ لها — فمن يجهّز من
 * الفاتورة يسأل، ومن يستلمها يقرأ ما لم يطلبه.
 *
 * ═══ وما يُضاف ═══
 *
 *   - `parent_item_id` — بندُ الإضافة المدفوعة يعرف صنفَه. والمالُ في سطره
 *     كما كان (ثمنُه وكميّتُه وضريبتُه): الربطُ للعرض لا للحساب.
 *   - `details` — لقطةُ ما لا مالَ فيه: اسمُ الصنف بلغتيه، والمقاسُ، والخياراتُ،
 *     والملاحظةُ، والإضافاتُ المشمولةُ في الثمن.
 *
 * فارغان لكلّ ما سبق: الفواتيرُ القائمة لا يُمسّ منها شيء، ولا يُعاد بناؤها
 * من الكتالوج اليوم.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_invoice_items', 'parent_item_id')) {
            Schema::table('customer_invoice_items', function (Blueprint $table) {
                $table->foreignId('parent_item_id')->nullable()->after('order_item_id')
                    ->constrained('customer_invoice_items')->nullOnDelete();
                $table->index('parent_item_id');
            });
        }

        if (! Schema::hasColumn('customer_invoice_items', 'details')) {
            Schema::table('customer_invoice_items', function (Blueprint $table) {
                $table->json('details')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_invoice_items', 'details')) {
            Schema::table('customer_invoice_items', fn (Blueprint $t) => $t->dropColumn('details'));
        }

        if (Schema::hasColumn('customer_invoice_items', 'parent_item_id')) {
            Schema::table('customer_invoice_items', function (Blueprint $table) {
                $table->dropIndex(['parent_item_id']);
                $table->dropConstrainedForeignId('parent_item_id');
            });
        }
    }
};
