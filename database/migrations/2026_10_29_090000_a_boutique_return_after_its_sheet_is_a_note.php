<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرتجعُ قطعة بوتيكٍ بعد ورقتها إشعارٌ مرتبطٌ بالورقة — لا تعديلٌ فيها.
 *
 * كانت البيعةُ التي حملتها ورقةٌ لا تُلغى: الورقةُ مجمَّدة، وإلغاءُ بيعتها
 * يردّ للزبون مالَه ويُبقي للبوتيك حصّتَه عن بيعٍ لم يقع. فصار المرتجعُ
 * يُكتب إشعارًا بحصّة القطعة كما حملتها الورقة، وبثلاثة أحوالٍ منفصلة:
 * ردُّ الزبون (الإلغاءُ نفسُه)، والقطعةُ (عادت سليمة، تالفة، لم تُستلم)،
 * وحصّةُ البوتيك (مَن يتحمّلها، وكيف تُستردّ).
 *
 * وشروطُ المرتجع في اتّفاقية البوتيك تُكتب على صفّه بمرجعها. وبلا شرطٍ
 * موثَّق لا يُفترض أنّ البوتيك يتحمّل: يُعرض الإشعارُ للمراجعة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            // ما تقوله الاتّفاقية عن المرتجع: `sellable` السليمُ على البوتيك، `all` كلُّه عليه، والفراغُ غيرُ موثَّق
            $table->string('returns_terms', 16)->nullable()->after('notes');
            $table->string('returns_terms_ref', 255)->nullable()->after('returns_terms');
        });

        Schema::create('boutique_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('boutique_id');
            $table->foreignId('boutique_settlement_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            // والبندُ قد يُحذف بإنقاص كمّيّته إلى الصفر في يومه — فلا مفتاحَ أجنبيّ، ولقطتُه هنا
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->unsignedBigInteger('order_edit_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('source', 16);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name');
            $table->string('variant_name')->nullable();
            $table->decimal('rate', 5, 2);
            $table->decimal('unit_price', 14, 3);
            $table->unsignedInteger('quantity');
            $table->decimal('gross', 14, 3);
            $table->decimal('amount', 14, 3);
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('returned_at');
            $table->string('condition', 16);
            $table->timestamp('condition_at')->nullable();
            $table->string('status', 16);
            $table->string('bearer', 16)->nullable();
            $table->string('basis', 16)->nullable();
            $table->string('terms_ref', 255)->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            // يومُ دخول أثره الدفترَ — وورقةٌ قديمةٌ لم تُسدَّد يدخل يومَ سدادها
            $table->date('booked_at')->nullable();
            $table->unsignedBigInteger('split_from_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'boutique_id', 'status']);
            $table->index('order_id');
            $table->index('boutique_settlement_id');
        });

        Schema::create('boutique_return_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('boutique_id');
            $table->foreignId('boutique_return_id')->constrained()->cascadeOnDelete();
            // `receipt` استلامٌ من البوتيك بمستند، و`offset` خصمٌ من ورقةٍ لاحقة لم تُسدَّد
            $table->string('kind', 16);
            $table->decimal('amount', 14, 3);
            $table->foreignId('boutique_settlement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('method', 32)->nullable();
            $table->string('reference', 120)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            // والإشعارُ الواحد لا يُخصم من الورقة الواحدة مرّتين
            $table->unique(['boutique_return_id', 'boutique_settlement_id']);
            $table->index(['business_id', 'boutique_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boutique_return_recoveries');
        Schema::dropIfExists('boutique_returns');

        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropColumn(['returns_terms', 'returns_terms_ref']);
        });
    }
};
