<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إشعار استلام بضاعة — توأمُ إشعار التسليم بالاتجاه المعاكس.
 *
 * الخارج له ورقة تُوقَّع (`delivery_notes`) والداخل بلا ورقة: استلامُ أمر
 * الشراء كان يرفع الكمية ويكتب حركةَ مخزونٍ ثمّ ينتهي. والحركة سطرٌ في سجلّ
 * لا مستندٌ يُفتح ويُطبع ويُقابَل بفاتورة المورّد — ولا يقول من استلم ولا
 * كم استلم من كلّ صنفٍ في تلك الدفعة.
 *
 * وهو مستند حركةٍ لا مستند مال، كتوأمه: لا يُنشئ ذمّةً للمورّد ولا قيدًا.
 * الذمّة تنشأ بسند المورّد (`supplier_invoices`)، وخلطُهما يُحمّل المورّد
 * مرّتين.
 *
 * والمخزون لا يتحرّك به: `PurchaseOrderController::receive` هي التي تُدخل
 * الكمية، وهذا الإشعار ورقتُها. ولو أدخلها ثانيةً لدخلت البضاعة مرّتين من
 * شحنةٍ واحدة — وهي القاعدة نفسها التي يمشي عليها إشعار التسليم المربوط
 * بطلب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            /*
             * الأمر يبقى ولو حُذف: `nullOnDelete` لا `cascade`.
             *
             * الإشعار واقعةٌ جرت — بضاعةٌ دخلت المخزن — وحذفُ أمرٍ لا يُخرجها
             * منه. فتبقى الورقة وتفقد إشارتها إلى أمرها لا غير.
             */
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('number', 30);
            $table->date('received_at');
            $table->string('receiver')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'purchase_order_id']);
        });

        Schema::create('goods_receipt_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_note_id')->constrained('goods_receipt_notes')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            // الاسم منسوخ كما في إشعار التسليم: حذف المنتج لا يُفرّغ ورقةً وُقّعت
            $table->string('name');
            $table->decimal('quantity', 12, 3);
            // تكلفة الوحدة وقتها — الورقة تُقابَل بفاتورة المورّد بالسعر لا بالكمية وحدها
            $table->decimal('cost', 14, 3)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_note_items');
        Schema::dropIfExists('goods_receipt_notes');
    }
};
