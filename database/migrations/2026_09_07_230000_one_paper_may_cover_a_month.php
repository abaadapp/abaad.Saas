<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ورقةٌ واحدة تغطّي شهرًا — وإلغاءُ الطلب يصل إليها.
 *
 * شركةٌ تشتري ثلاث مرّاتٍ في الشهر لا تريد ثلاثَ فواتير: تريد ورقةً واحدة
 * آخرَ الشهر بمجموعها. وكان الربط عمودًا واحدًا `customer_invoices.order_id`
 * فلا يحمل إلّا طلبًا واحدًا.
 *
 * والعمودُ يُرفع ولا يبقى بجوار الجدول: عمودٌ وجدولٌ يقولان الشيء نفسه
 * يفترقان يومًا — تُضاف طلبٌ إلى الجدول ولا يُحدَّث العمود، فتقول شاشةٌ إنّ
 * الفاتورة من طلبٍ وتقول أختُها إنّها من ثلاثة.
 *
 * ولا بيانَ يُفقد: ما في العمود يُنقل إلى الجدول قبل رفعه، والعودةُ تُعيده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invoice_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_invoice_id', 'order_id']);
            // والطلبُ يُبحث عنه: «هل فُوتر هذا الطلب؟» سؤالٌ يُسأل عند كلّ فوترة
            $table->index('order_id');
        });

        foreach (DB::table('customer_invoices')->whereNotNull('order_id')->get(['id', 'order_id']) as $row) {
            DB::table('customer_invoice_orders')->insert([
                'customer_invoice_id' => $row->id,
                'order_id' => $row->order_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            /*
             * فوترةٌ شهريّة: بيعاتُ هذا العميل الآجلة لا تُفوتَر واحدةً واحدة.
             *
             * تتراكم بلا ورقة حتّى تُجمع آخرَ الشهر. ورصيدُها يُقرأ من الطلبات
             * غير المفوترة — انظر `Receivables::uninvoicedOrders` — فلا يختفي
             * دَينٌ من الشاشة لأنّ ورقتَه لم تُطبع بعد.
             */
            $table->boolean('monthly_billing')->default(false);
        });

        Schema::table('customer_credit_notes', function (Blueprint $table) {
            /*
             * مصدرُ الإشعار — وهو الذي يقرّر أيُقيَّد في الدفتر أم لا.
             *
             * إشعارٌ يكتبه التاجر بيده حدثٌ ماليٌّ جديد: يُقيَّد. وإشعارٌ يولد
             * من إلغاء طلبٍ لا: `Books::unpostSale` عكست قيدَ ذلك الطلب لحظةَ
             * إلغائه، فقيدٌ ثانٍ هنا يُنقص الذمّة مرّتين.
             */
            $table->string('source', 20)->default('يدوي');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn('source');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('monthly_billing');
        });

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
        });

        // ويعود العمود بأوّل طلبٍ في الجدول — وما زاد عنه يبقى في الجدول
        foreach (DB::table('customer_invoice_orders')->orderBy('id')->get() as $row) {
            DB::table('customer_invoices')->where('id', $row->customer_invoice_id)
                ->whereNull('order_id')->update(['order_id' => $row->order_id]);
        }

        Schema::dropIfExists('customer_invoice_orders');
    }
};
