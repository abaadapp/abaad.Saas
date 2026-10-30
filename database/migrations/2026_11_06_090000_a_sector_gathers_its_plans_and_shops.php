<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * القطاع — تصنيفٌ إداريٌّ فوق الباقات والشركات، لا بديلٌ عن نوع النشاط.
 *
 * ═══ ما تفعله ═══
 *
 * - جدولُ `sectors`: رمزٌ ثابت (`code`) لا يغيّره الاسم، واسمان، وأيقونة،
 *   وترتيب، وحالة، والنظامُ الذي يخدمه.
 * - قطاعٌ واحد: «التجارة والتجزئة» (`retail`)، نشطٌ، على نظام أبعاد الحاليّ.
 * - `plans.sector_id` و`businesses.sector_id`: كلُّ باقةٍ وكلُّ شركةٍ قائمة
 *   تُربط بالقطاع التجاريّ.
 *
 * ═══ وما لا تفعله ═══
 *
 * لا تُنشئ باقةً ولا تُعيد إنشاءها، ولا تمسّ معرّفًا ولا رمزًا (`basic`،
 * `enterprise`) ولا سعرًا ولا قدرةً ولا سقفًا، ولا تاريخَ اشتراكٍ ولا حالة،
 * ولا فاتورة. العمودُ الجديد وحده يُكتب، وفي الصفوف التي لا قطاعَ لها وحدها.
 *
 * والعمودان يقبلان الفراغ في القاعدة لأنّ كلَّ صفٍّ يُنشأ بعدها يُختم
 * قطاعُه في النموذج (`Plan::booted` و`Business::booted`) — فلا يبقى فراغٌ
 * إلّا ما كُتب في القاعدة يدويًّا. وتُعاد بلا ضرر: كلُّ خطوةٍ تسأل قبلها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sectors')) {
            Schema::create('sectors', function (Blueprint $table) {
                $table->id();
                $table->string('code', 32)->unique();
                $table->string('name', 100);
                $table->string('name_en', 100)->nullable();
                $table->string('icon', 40)->default('store');
                $table->unsignedSmallInteger('sort')->default(0);
                $table->boolean('active')->default(true);
                $table->string('system', 32)->default('abaad');
                $table->timestamps();
            });
        }

        $retail = DB::table('sectors')->where('code', 'retail')->value('id') ?? DB::table('sectors')->insertGetId([
            'code' => 'retail',
            'name' => 'التجارة والتجزئة',
            'name_en' => 'Retail & Commerce',
            'icon' => 'store',
            'sort' => 1,
            'active' => true,
            'system' => 'abaad',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['plans', 'businesses'] as $table) {
            if (! Schema::hasColumn($table, 'sector_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('sector_id')->nullable()->index()->constrained('sectors')->restrictOnDelete();
                });
            }

            // بالاستعلام لا بالنموذج: لا `updated_at` يتغيّر، ولا حدثَ يُطلق
            DB::table($table)->whereNull('sector_id')->update(['sector_id' => $retail]);
        }
    }

    /**
     * الرجوعُ يحذف القطاعَ وعموديه — ولا يمسّ ما سواهما.
     *
     * والرجوعُ على الإنتاج رجوعُ كود (`scripts/rollback-code.sh` مع
     * `ALLOW_MIGRATIONS`): العمودان لا يقرؤهما الكودُ القديم فيبقيان.
     *
     * وعلى SQLite يُرفض والجداولُ فيها صفوف: حذفُ مفتاحٍ هناك يُعيد بناء الجدول،
     * وإعادةُ بناء `businesses` و`plans` والمفاتيحُ قائمة (والهجرةُ في معاملةٍ
     * لا يُطفئها فيها `PRAGMA`) تمحو الفواتير والاشتراكات وتُفرغ باقاتِ الشركات.
     * وPostgreSQL يحذف القيدَ وحده.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite' && (DB::table('businesses')->exists() || DB::table('plans')->exists())) {
            throw new RuntimeException('a_sector_gathers_its_plans_and_shops: down() on SQLite rebuilds businesses and plans and would cascade-delete their invoices — refused while they hold rows.');
        }

        // المفتاحُ ثمّ فهرسُه ثمّ العمود — SQLite لا يحذف عمودًا عليه فهرس
        foreach (['businesses', 'plans'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['sector_id']);
            });
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['sector_id']);
            });
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('sector_id');
            });
        }

        Schema::dropIfExists('sectors');
    }
};
