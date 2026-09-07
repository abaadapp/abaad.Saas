<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أمرُ الشراء يقول ماذا يشتري وبأيّ وحدة — وبكم في الإجمال.
 *
 * ═══ ما كان ═══
 *
 * الأمرُ ثلاثةُ حقولٍ تجاريّة: `total` مجموعُ (تكلفة × كمية) لا غير. لا خصمَ
 * مورّد، ولا شحنًا، ولا ضريبة. والتاجرُ يتفاوض على الثلاثة في كلّ صفقة، ثمّ
 * يصل سندُ المورّد بها فلا يطابق أمرَه.
 *
 * وأخطرُ من ذلك أثرُه على المطابقة الثلاثيّة: `SupplierInvoices::match`
 * تقابل `invoice.total` (وهو مجموعٌ + ضريبة) بـ`po.total` (وهو مجموعٌ بلا
 * ضريبة). فمتجرٌ ضريبتُه مفعّلة كان **كلُّ سندٍ فيه يُمنع** بحجّة «أعلى من
 * أمر الشراء» — بمقدار الضريبة بالضبط. الحقلان يقولان شيئين ويُقابَلان
 * كأنّهما شيءٌ واحد.
 *
 * فصار `total` الإجماليَّ التجاريَّ الكامل، ومكوّناتُه محفوظةٌ إلى جانبه.
 *
 * ═══ ووحدةُ الشراء ═══
 *
 * محلُّ الورد يشتري «ربطة» و«صندوقًا» و«كرتونًا»، ويخزّن «حبّة». وخمسُ ربطاتٍ
 * في الربطة عشرون حبّة هي **مئة حبّة** على الرفّ لا خمس. والنظام كان يعرف
 * رقمًا واحدًا لا يقول عن أيّهما يتكلّم.
 *
 * فصار للبند وحدةُ شرائه ومحتواها — **لقطةً على البند** لا قراءةً من
 * المنتج: من غيّر تعبئة الصنف بعد شهرٍ لا يُعيد كتابة أوامرَ مضت.
 *
 * والكميّاتُ كلُّها تبقى بوحدة الشراء: في الأمر، وفي إشعار الاستلام،
 * وفي `received_quantity`، وفي المطابقة. والتحويلُ يقع في موضعٍ واحد
 * فقط — `GoodsReceipts::shelve` حين تدخل البضاعةُ الرفَّ فعلًا.
 *
 * ═══ وما لم يُضَف ═══
 *
 * `base_quantity` على البند: هو `quantity × units_per_purchase_unit` ولا
 * شيء غير ذلك. وحقلان يقولان الشيء نفسه يفترقان يومًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // موعدُ الوصول المتوقَّع — وعدُ المورّد، لا تاريخُ وصولٍ وقع
            $table->date('expected_delivery_at')->nullable()->after('ordered_at');
            /*
             * ومرجعُ المورّد على الأمر غيرُ `supplier_invoices.supplier_ref`.
             *
             * هذا رقمُ عرض السعر أو الطلب كما يسمّيه المورّد قبل أن يشحن،
             * وذاك رقمُ فاتورته بعد أن شحن. وخلطُهما يجعل مرجعًا واحدًا
             * يُطلب في موضعين مختلفين.
             */
            $table->string('supplier_reference', 100)->nullable()->after('supplier_name');
            $table->decimal('items_subtotal', 12, 3)->default(0)->after('total');
            $table->decimal('supplier_discount', 12, 3)->default(0)->after('items_subtotal');
            $table->decimal('shipping_cost', 12, 3)->default(0)->after('supplier_discount');
            $table->decimal('tax', 12, 3)->default(0)->after('shipping_cost');
            // ولقطةُ النسبة: من بدّل نسبةَ متجره لا يُعيد حساب أوامرَ مضت
            $table->decimal('tax_rate', 5, 2)->default(0)->after('tax');
            // مرفقُ الأمر: عرضُ سعرٍ أو مستندُ طلب — لا إيصالُ دفع
            $table->string('attachment')->nullable()->after('receipt_name');
            $table->string('attachment_name')->nullable()->after('attachment');
            /*
             * ومفتاحُ النموذج: يرسله المتصفّح مرّةً واحدة لكلّ صفحةٍ تُفتح.
             *
             * ضغطتان على «إرسال» — أو ردٌّ يبطؤ فيُعاد الطلب — كانتا تكتبان
             * أمرين متطابقين لمورّدٍ واحد. والفهرسُ الفريدُ يحرسه في القاعدة
             * لا في الكود وحده، فطلبان متزامنان لا يمرّان معًا.
             */
            $table->string('form_token', 64)->nullable()->after('attachment_name');
            $table->unique(['business_id', 'form_token']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('purchase_unit', 40)->nullable()->after('name');
            $table->decimal('units_per_purchase_unit', 12, 3)->default(1)->after('purchase_unit');
            // إجماليُّ السطر محفوظًا: الخادمُ يحسبه، ولا يُعاد اشتقاقه بقسمة
            $table->decimal('line_total', 14, 3)->default(0)->after('quantity');
        });

        /*
         * ولقطةٌ ثانية على بند الاستلام.
         *
         * `shelve` تُحوّل عند الاعتماد، فتحتاج المعاملَ في يدها. وقراءتُه من
         * بند الأمر تربط لحظةَ الإدخال ببندٍ قد يكون حُذف — والورقةُ تبقى
         * ولو حُذف أمرُها. فيُنسخ إليها كما نُسخت التكلفة.
         */
        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            $table->decimal('units_per_purchase_unit', 12, 3)->default(1)->after('quantity');
        });

        /*
         * والقديمُ يبقى كما هو بالحرف.
         *
         * `items_subtotal = total` لأنّ `total` كان مجموعَ البنود وحدَه،
         * والباقي أصفار — فلا يتغيّر إجماليُّ أمرٍ مضى ولا مطابقتُه. والمعامل
         * واحدٌ لأنّ كلّ ما مضى اشتُري بوحدة التخزين نفسها.
         */
        DB::table('purchase_orders')->update(['items_subtotal' => DB::raw('total')]);
        DB::table('purchase_order_items')->update(['line_total' => DB::raw('cost * quantity')]);
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'form_token']);
            $table->dropColumn([
                'expected_delivery_at', 'supplier_reference', 'items_subtotal',
                'supplier_discount', 'shipping_cost', 'tax', 'tax_rate',
                'attachment', 'attachment_name', 'form_token',
            ]);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['purchase_unit', 'units_per_purchase_unit', 'line_total']);
        });

        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            $table->dropColumn('units_per_purchase_unit');
        });
    }
};
