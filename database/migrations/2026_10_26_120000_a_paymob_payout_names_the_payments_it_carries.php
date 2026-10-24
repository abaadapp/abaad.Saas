<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تحويلُ Paymob يسمّي المدفوعاتِ التي يحملها — فلا يدخل مالٌ البنكَ مرّتين.
 *
 * ═══ ولمَ ═══
 *
 * كانت التسويةُ ثلاثةَ أرقامٍ (إجماليٌّ ورسومٌ وصافٍ) تُدين البنكَ من
 * «Paymob تحت التسوية». ومنذ #168 تنتظر بيعةُ الموقع بالبطاقة في التسوية —
 * وما بيع قبله قُيِّد في البنك يومَ البيع. فأوّلُ تحويلٍ بعد النشر يحمل
 * الاثنين معًا، ورقمٌ واحدٌ لا يفرّق بينهما: مالُ البيعات القديمة يدخل البنكَ
 * مرّةً ثانية. فصار لكلّ تسويةٍ سطورٌ — مدفوعةٌ بعينها لكلّ سطر — ولكلّ سطرٍ
 * أين كان مالُه في الدفتر ساعةَ التسوية (`PaymobSettlements`).
 *
 * ═══ ولا تُسوّى مدفوعةٌ مرّتين ═══
 *
 * فهرسٌ فريدٌ على النيّة وعلى الرابط: القاعدةُ نفسُها تردّ السطرَ الثاني.
 * والتسويةُ الملغاةُ قبل ترحيلها تُفرغ العمودين وتُبقي `source` — يبقى أنّها
 * ادّعت تلك المدفوعة، وتعود المدفوعةُ لتُختار.
 *
 * ═══ والحالُ ═══
 *
 * `posted` رُحّلت، `held` حُفظت ولم تُرحَّل لأنّ حالَ مدفوعةٍ فيها غيرُ مؤكَّد
 * (`hold_reason`)، `cancelled` أُلغيت قبل الترحيل. والصفوفُ القائمة كلُّها
 * مرحَّلة — فالافتراضيّ `posted`.
 *
 * إضافةٌ لا غير: جدولٌ جديد وعمودان. الكودُ السابق لا يقرؤها ولا يحتاجها، ولا
 * قيدَ ولا صفَّ قائمًا يتغيّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paymob_settlements', function (Blueprint $table) {
            $table->string('status', 16)->default('posted')->after('net');
            $table->string('hold_reason', 500)->nullable()->after('status');
        });

        Schema::create('paymob_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('paymob_settlement_id')->index()->constrained()->cascadeOnDelete();

            // المدفوعةُ نفسُها — واحدٌ من الاثنين، وكلٌّ منهما لا يُسوّى مرّتين
            $table->foreignId('store_payment_intent_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_payment_link_id')->nullable()->unique()->constrained()->restrictOnDelete();
            // ويبقى اسمُها بعد الإلغاء: intent:12 · link:7
            $table->string('source', 40);

            $table->decimal('amount', 12, 3);
            // أين كان مالُها ساعةَ التسوية: clearing · bank · unknown
            $table->string('booked', 16);
            $table->foreignId('account_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'paymob_settlement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paymob_settlement_items');

        Schema::table('paymob_settlements', function (Blueprint $table) {
            $table->dropColumn(['status', 'hold_reason']);
        });
    }
};
