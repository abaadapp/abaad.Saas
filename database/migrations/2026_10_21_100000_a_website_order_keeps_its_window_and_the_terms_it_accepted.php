<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلبُ الموقع يحفظ فترةَ تسليمه، والشروطَ التي وافق عليها مشتريه.
 *
 * ═══ نسخُ الشروط — `store_terms_versions` ═══
 *
 * صاحبُ المتجر يكتب شروطَه بلغتين ويعدّلها متى شاء. والموافقةُ لا تُثبَت
 * بنصٍّ يتبدّل بعدها: زبونٌ وافق في مارس على نصٍّ عُدّل في يونيو يُسأل يومًا
 * «على ماذا وافقت؟» — والجوابُ نسختُه هو، لا ما في الشاشة اليوم.
 *
 * فكلُّ حفظٍ يغيّر النصَّ يكتب **صفًّا جديدًا** برقم إصداره، ولا يُعدَّل صفٌّ
 * ولا يُحذف (`StoreTermsVersion` يردّ ذلك). وبصمةُ كلّ لغةٍ معه (sha256)،
 * فنسخةٌ نُقلت أو نُسخت تُقابَل بالبصمة المكتوبة في الطلب.
 *
 * ═══ وأعمدةُ الطلب ═══
 *
 *   - `delivery_slot` و`scheduled_until` — الفترةُ التي اختارها الزبونُ من جدول
 *     المتجر: معرّفُها الثابت ونهايتُها. وبدايتُها في `scheduled_for` كما كانت.
 *   - `terms_version_id`، `terms_accepted_at`، `terms_lang`، `terms_hash` —
 *     الإصدارُ الذي وافق عليه، ومتى، وبأيّ لغة، وبصمةُ النصّ الذي رآه.
 *
 * فارغةٌ كلُّها لما سبق: لا طلبَ قائمٌ يُمسّ، ولا موافقةَ تُخترع لطلبٍ لم
 * يُسأل صاحبُه عنها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_terms_versions')) {
            Schema::create('store_terms_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->text('body_ar');
                $table->text('body_en');
                $table->string('hash_ar', 64);
                $table->string('hash_en', 64);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // ورقمُ الإصدار فريدٌ في متجره — وأوّلُ الفهرس عمودُ المتجر فيُقرأ به
                $table->unique(['business_id', 'version']);
                $table->index('created_by');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'delivery_slot')) {
                $table->string('delivery_slot', 40)->nullable();
            }
            if (! Schema::hasColumn('orders', 'scheduled_until')) {
                $table->dateTime('scheduled_until')->nullable();
            }
        });

        if (! Schema::hasColumn('orders', 'terms_version_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('terms_version_id')->nullable()
                    ->constrained('store_terms_versions')->nullOnDelete();
                $table->index('terms_version_id');
                $table->timestamp('terms_accepted_at')->nullable();
                $table->string('terms_lang', 2)->nullable();
                $table->string('terms_hash', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'terms_version_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex(['terms_version_id']);
                $table->dropConstrainedForeignId('terms_version_id');
                $table->dropColumn(['terms_accepted_at', 'terms_lang', 'terms_hash']);
            });
        }

        foreach (['delivery_slot', 'scheduled_until'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }

        Schema::dropIfExists('store_terms_versions');
    }
};
