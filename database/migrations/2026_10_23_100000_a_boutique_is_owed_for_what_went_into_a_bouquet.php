<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ما يُستحقّ للبوتيك عن صنفٍ دخل في باقةٍ أو أُضيف إليها.
 *
 * ═══ ولمَ جدولٌ لا عمودٌ على البند ═══
 *
 * البندُ الواحد يحمل مكوّناتٍ من بوتيكين، وإضافةً من ثالث. وعمودٌ على
 * `order_items` يسع مالكًا واحدًا — وهو اليوم مالكُ الصنف المباع نفسِه
 * (`order_items.boutique_id`)، وحسبتُه عمولةٌ على الإجماليّ لا ثمنُ مكوّن.
 * فلكلّ استعمالٍ صفٌّ: من أيّ بند، وبأيّ مصدر (وصفة أو إضافة)، ولمن، وبكم.
 *
 * ═══ والصفُّ لا يُعدَّل ═══
 *
 * المبلغُ والسعرُ والكمّيّةُ والمالكُ لقطةُ ساعة البيع. والإلغاءُ والتصحيح
 * صفٌّ عكسيٌّ يشير إلى أصله (`reverses_id` فريد: لا يُعكس الصفُّ مرّتين).
 *
 * و`live_key` فريدٌ لما لم يُعكس: الاستعمالُ الواحد لا يُستحقّ مرّتين ولو
 * نودي الحفظُ مرّتين. ويُفرَّغ حين يُعكس الصفّ — وهو وحده ما يتغيّر فيه.
 *
 * والمفتاحُ مطفأٌ لكلّ متجر حتى يُفتح له (`boutique_components_enabled`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'boutique_components_enabled')) {
                $table->boolean('boutique_components_enabled')->default(false)->after('boutiques_enabled');
            }
        });

        Schema::create('boutique_component_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // البندُ يُحذف بتصحيح الفاتورة — والصفُّ يبقى بلقطته وعكسِه
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_addon_id')->nullable()->constrained('order_item_addons')->nullOnDelete();

            $table->unsignedBigInteger('boutique_id');
            $table->string('boutique_name')->nullable();

            $table->string('source', 16);
            $table->unsignedBigInteger('recipe_item_id')->nullable();
            $table->unsignedBigInteger('addon_id')->nullable();

            $table->unsignedBigInteger('product_id');
            $table->string('product_name');

            $table->decimal('unit_price', 12, 3);
            // ما يأخذه البندُ الواحد (الباقةُ أو الإضافةُ) — والكمّيّةُ هذا في كمّيّة البند
            $table->decimal('unit_quantity', 12, 3);
            $table->decimal('quantity', 12, 3);
            // موجبٌ للمستحقّ، سالبٌ لعكسه
            $table->decimal('amount', 14, 3);

            $table->unsignedBigInteger('reverses_id')->nullable()->unique();
            $table->string('live_key', 80)->nullable()->unique();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['business_id', 'boutique_id']);
            $table->index('order_id');
            $table->index('order_item_id');
            $table->index('order_item_addon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boutique_component_dues');

        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'boutique_components_enabled')) {
                $table->dropColumn('boutique_components_enabled');
            }
        });
    }
};
