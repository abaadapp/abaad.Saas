<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاتورةُ العميل تُجهَّز بعد سدادها — مفتاحٌ على النشاط، وعملُ تجهيزٍ مستقلّ.
 *
 * - `businesses.customer_invoice_preparation_enabled`: مغلقٌ لكلّ نشاطٍ افتراضًا،
 *   ويفتحه مديرُ المنصّة من شاشة النشاط (`CustomerInvoicePreparations::on`).
 *   ولا يُفتح هنا لأحد: لا اسمَ ولا رقمَ في الكود، ولا تُكتب بياناتُ متجرٍ بالهجرة.
 *
 *   و`customer_invoice_preparation_enabled_at` متى فُتح آخرَ مرّة: دفعٌ وصل
 *   والمفتاحُ مغلق لا يُفعِّل وحده حين يُفتح من جديد (`linkCounts`) — يُرسَل
 *   بيدٍ إن لم يُجهَّز بعد، لا يظهر في اللوحة ويُطبع بعد أسابيع.
 *
 * - `customer_invoice_preparations`: ما يُجهَّز وإلى من ومتى — لا بيعٌ ثانٍ.
 *   الفاتورةُ ورقةٌ ماليّة، وبنودُها مصدرُ الأصناف والكميّات. وهذا الصفُّ يقول
 *   ما لا تقوله: الفرعُ والموعدُ والمستلِمُ والبطاقة، وحالُ التجهيز.
 *   وفريدٌ بالفاتورة: مهمّةٌ واحدةٌ لكلّ فاتورة، ولو تكرّر إشعارُ الدفع.
 *
 *   و`activated_at` فارغٌ حتى تُسدَّد الفاتورةُ كلُّها بدفعٍ موثَّق
 *   (`PaymentLinks::callback`) — وحينها وحدها تظهر في لوحة التجهيز.
 *
 * - `pos_peripherals.auto_print_customer_invoices`: الطابعةُ التي تستلم
 *   «فاتورة عميل — نسخة داخلية» حين تُسدَّد. خيارٌ مستقلّ عن «بعد البيع»
 *   وعن «طلب الموقع عند تأكيده».
 *
 * والإضافةُ وحدها — لا يُحذف شيءٌ ولا يُعاد تسميتُه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('businesses', 'customer_invoice_preparation_enabled')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->boolean('customer_invoice_preparation_enabled')->default(false);
            });
        }

        if (! Schema::hasColumn('businesses', 'customer_invoice_preparation_enabled_at')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->timestamp('customer_invoice_preparation_enabled_at')->nullable();
            });
        }

        if (! Schema::hasColumn('pos_peripherals', 'auto_print_customer_invoices')) {
            Schema::table('pos_peripherals', function (Blueprint $table) {
                $table->boolean('auto_print_customer_invoices')->default(false);
            });
        }

        if (! Schema::hasTable('customer_invoice_preparations')) {
            Schema::create('customer_invoice_preparations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                // مهمّةٌ واحدةٌ لكلّ فاتورة — الفهرسُ الفريد يقول ذلك لا الكود وحده
                $table->foreignId('customer_invoice_id')->unique()->constrained()->cascadeOnDelete();
                // فرعُ التجهيز — وفرعٌ يُحذف لا يمحو المهمّة: تبقى بلا فرعٍ ويُرى ذلك
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

                $table->string('fulfillment_type', 16);
                $table->dateTime('scheduled_for');

                // المستلِم — بأطوال أعمدة الطلب نفسِها (`FlowerOrder`)
                $table->string('recipient_name', 120)->nullable();
                $table->string('recipient_phone', 32)->nullable();
                $table->string('delivery_address', 500)->nullable();
                // ما يُقال لمن يسلّم — يُطبع في سند التسليم
                $table->text('delivery_notes')->nullable();
                // ما يُقال لمن يجهّز — لا يخرج إلى المستلِم أبدًا
                $table->text('internal_notes')->nullable();

                // الهديّة والبطاقة
                $table->boolean('is_gift')->default(false);
                $table->string('occasion_type', 32)->nullable();
                $table->string('occasion_text', 40)->nullable();
                $table->text('card_message')->nullable();
                $table->string('sender_name', 120)->nullable();
                $table->boolean('hide_sender')->default(false);
                // من أكّد أنّ رسالةً تكشف اسمَ المُهدي تُطبع كما هي — ومتى
                $table->timestamp('card_identity_confirmed_at')->nullable();

                // حالُ التجهيز — بقيم لوحة التجهيز نفسِها (`OrderStatus`). وفارغةٌ قبل السداد
                $table->string('status', 20)->nullable();
                $table->timestamp('status_changed_at')->nullable();

                // التفعيلُ — مرّةً واحدة، وبأيّ دفعةٍ ومن أيّ باب
                $table->timestamp('activated_at')->nullable();
                $table->string('activation_source', 20)->nullable();
                $table->foreignId('activation_payment_id')->nullable()->constrained('customer_payments')->nullOnDelete();
                $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
                // ما منع التفعيلَ آخرَ مرّة — يُرى في الفاتورة ويُعاد منه
                $table->string('activation_error', 500)->nullable();

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['business_id', 'activated_at', 'status'], 'cip_business_board_idx');
                $table->index(['business_id', 'scheduled_for'], 'cip_business_scheduled_idx');
                $table->index('branch_id', 'cip_branch_idx');
                $table->index('activation_payment_id', 'cip_payment_idx');
                $table->index('activated_by', 'cip_activated_by_idx');
                $table->index('created_by', 'cip_created_by_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoice_preparations');

        if (Schema::hasColumn('pos_peripherals', 'auto_print_customer_invoices')) {
            Schema::table('pos_peripherals', fn (Blueprint $t) => $t->dropColumn('auto_print_customer_invoices'));
        }

        if (Schema::hasColumn('businesses', 'customer_invoice_preparation_enabled_at')) {
            Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('customer_invoice_preparation_enabled_at'));
        }

        if (Schema::hasColumn('businesses', 'customer_invoice_preparation_enabled')) {
            Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('customer_invoice_preparation_enabled'));
        }
    }
};
