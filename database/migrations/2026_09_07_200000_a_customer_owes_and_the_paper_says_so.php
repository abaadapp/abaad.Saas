<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فواتيرُ العملاء وذممُهم.
 *
 * والطلبُ ليس فاتورة: الطلبُ تنفيذٌ وتجهيزٌ ومخزون، والفاتورةُ **التزامٌ
 * ماليٌّ على العميل**. وكان الالتزام يُقرأ من عمودٍ في الطلب (`payment_status`)
 * فلا يحمل تاريخَ استحقاق، ولا رقمَ أمر شراء، ولا يُسدَّد على دفعات، ولا
 * يجمع طلبين لشركةٍ واحدة في ورقةٍ آخرَ الشهر.
 *
 * وقد أُزيل البيع الآجل من هذا النظام مرّةً بطلبٍ صريح (هجرة
 * `2026_08_12_150000_drop_credit_sales`) — وكان عمودَين في `orders` وجدولَ
 * تسديدات. وهذا ليس ردًّا لذاك: ذاك حالةٌ في الطلب، وهذا **مستندٌ قائمٌ
 * بذاته** له رقمُه وبنودُه وقيدُه في دفتر الأستاذ.
 *
 * ولا يُخلط بـ`App\Models\Invoice`: تلك فواتيرُ اشتراك منصّة أبعاد على
 * التجّار، وهذه فواتيرُ التاجر على عملائه. اسمان مختلفان لأنّ المعنيين
 * مختلفان — وجدولٌ واحدٌ لهما كان سيجعل تقريرَ مبيعات التاجر يقرأ اشتراكَه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            /*
             * الطلبُ الذي وُلدت منه — إن وُلدت من طلب.
             *
             * وهو مفتاحُ منعِ الترحيل المزدوج: بيعةُ الطلب رُحّلت في
             * `Books::recordSale` لحظةَ وقوعها، فالفاتورةُ المولودة منه
             * **مستندٌ فوق حدثٍ رُحّل** لا حدثٌ جديد. انظر `CustomerInvoices::issue`.
             */
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 40);
            // مسودة / صادرة / ملغاة — والسداد يُشتقّ ولا يُخزَّن
            $table->string('status', 20)->default('مسودة');

            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();

            // لقطةُ العملة: ورقةٌ صدرت بالريال تبقى بالريال وإن غُيّرت العملة
            $table->string('currency', 8)->nullable();

            $table->decimal('subtotal', 12, 3)->default(0);
            $table->decimal('discount_total', 12, 3)->default(0);
            $table->decimal('tax_total', 12, 3)->default(0);
            $table->decimal('total', 12, 3)->default(0);

            /*
             * ولا عمودَ «المدفوع»: يُشتقّ من التخصيصات.
             *
             * عمودٌ يقول المدفوع وجدولٌ يقول التخصيصات موضعان يجب أن يتّفقا،
             * وأوّلُ دفعةٍ تُلغى ولا يُحدَّث فيها العمود تجعل الفاتورة تقول
             * مدفوعةً وهي ليست كذلك. والاشتقاق لا يُنسى.
             */

            // لقطةُ العميل — ورقةٌ صدرت لا يغيّرها تبديلُ اسمٍ بعد سنة
            $table->string('customer_name')->nullable();
            $table->string('customer_tax_number', 50)->nullable();
            $table->string('customer_cr', 50)->nullable();
            $table->text('customer_address')->nullable();

            // بياناتُ الجهة — كلُّها اختيارية، وتُطبع إن كُتبت
            $table->string('po_number', 60)->nullable();
            $table->string('contract_number', 60)->nullable();
            $table->string('external_reference', 60)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('cost_center', 60)->nullable();
            $table->string('attention_to', 120)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_by_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            // الرقمُ فريدٌ داخل المتجر لا في المنصّة: متجران يبدآن من واحد
            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'due_at']);
            $table->index(['business_id', 'issued_at']);
        });

        Schema::create('customer_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_invoice_id')->constrained()->cascadeOnDelete();
            // المنتجُ يُقيَّد لا يُحذف معه — واللقطة تحمل اسمَه وثمنَه أصلًا
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();

            /*
             * الوصفُ لقطةٌ لا إحالة.
             *
             * «توريد وتنسيق زهور لفعالية رسمية» ليس منتجًا في الكتالوج. ومنتجٌ
             * كان اسمُه «باقة» فصار «باقة كبيرة» لا يعيد كتابة ورقةٍ صدرت.
             */
            $table->string('description');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 3)->default(0);
            $table->decimal('discount', 12, 3)->default(0);
            // لقطةُ الضريبة: تغييرُ النسبة الشهر القادم لا يمسّ ورقةً صدرت
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->decimal('line_total', 12, 3)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('customer_invoice_id');
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->decimal('amount', 12, 3);
            // نقدي / بطاقة / تحويل — الصندوقُ أو البنك يتبعها
            $table->string('method', 30)->default('نقدي');
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('occurred_at');
            $table->string('external_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'occurred_at']);
        });

        /*
         * تخصيصُ الدفعة على الفواتير.
         *
         * دفعةٌ واحدة قد تغطّي ثلاث فواتير — شركةٌ تسدّد حسابَ الشهر بحوالةٍ
         * واحدة. وما زاد عن الفواتير يبقى **غيرَ مخصَّص** في رصيد العميل: ولا
         * يُنقص فاتورةً إلى ما دون الصفر، ولا يضيع.
         */
        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 3);
            $table->timestamps();

            $table->unique(['customer_payment_id', 'customer_invoice_id']);
            $table->index('customer_invoice_id');
        });

        /*
         * إشعارُ الدائن — الإرجاعُ لا يُعيد كتابة ورقةٍ صدرت.
         *
         * فاتورةٌ بمئة سُدِّد منها ثلاثون ورُدّ منها بعشرين: الباقي خمسون.
         * ولو خُفّض إجماليُّ الفاتورة إلى ثمانين لضاع أثرُ الإرجاع، ولقالت
         * الورقةُ في يد الزبون غيرَ ما يقوله النظام.
         */
        Schema::create('customer_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->decimal('amount', 12, 3);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->date('issued_at');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index('customer_invoice_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            // فردٌ / شركة / جهة حكومية — يُغيّر ما يُطلب على الورقة لا الصلاحية
            $table->string('customer_type', 20)->default('فرد');
            $table->string('legal_name')->nullable();
            $table->string('commercial_registration', 50)->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('contact_email', 120)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('department', 120)->nullable();
            $table->string('customer_reference', 60)->nullable();

            /*
             * والآجلُ لا يُفتح لكلّ عميل بالافتراض.
             *
             * زبونُ المارّة لا يُشترى منه بالدَّين، وفتحُه للجميع يجعل أوّلَ
             * ضغطةِ «آجل» بالخطأ ذمّةً على من لن يعود.
             */
            $table->boolean('allow_credit_sales')->default(false);
            $table->decimal('credit_limit', 12, 3)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'customer_type', 'legal_name', 'commercial_registration',
                'contact_person', 'contact_email', 'contact_phone',
                'billing_address', 'department', 'customer_reference',
                'allow_credit_sales', 'credit_limit', 'payment_terms_days',
            ]);
        });
        Schema::dropIfExists('customer_credit_notes');
        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('customer_invoice_items');
        Schema::dropIfExists('customer_invoices');
    }
};
