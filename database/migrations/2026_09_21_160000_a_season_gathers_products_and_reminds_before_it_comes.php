<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الموسمُ يجمع أصنافًا قائمةً ويُذكّر قبل أن يحلّ.
 *
 * طبقةٌ خفيفةٌ فوق المنتجات لا فيها: مدّةٌ، وأصنافٌ تُربط بمعرّفاتها،
 * ومفتاحان للصندوق والموقع، وتذكيراتٌ. ولا شيءَ في `products` يتغيّر —
 * الصنفُ مصدرُ الحقيقة، والموسمُ مجموعةٌ تُبرزه حينًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('name_en', 120)->nullable();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->boolean('active')->default(true);
            $table->boolean('show_in_pos')->default(true);
            $table->boolean('show_on_website')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ما يُسأل عنه الصندوقُ والموقعُ كلَّ فتحة: مواسمُ هذا المتجر الحيّةُ اليوم
            $table->index(['business_id', 'active', 'starts_at', 'ends_at']);
        });

        Schema::create('season_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            /*
             * والصنفُ يُحذف حذفًا ناعمًا فتبقى الصلةُ ولا يُقرأ الصنف —
             * علاقةُ `products()` تُسقطه وحدَها. والحذفُ الصلب يسحب الصلةَ معه.
             */
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['season_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('season_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // relative | fixed
            $table->unsignedSmallInteger('days_before')->nullable();
            $table->dateTime('remind_at')->nullable();
            $table->string('message', 500);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_reminders');
        Schema::dropIfExists('season_product');
        Schema::dropIfExists('seasons');
    }
};
