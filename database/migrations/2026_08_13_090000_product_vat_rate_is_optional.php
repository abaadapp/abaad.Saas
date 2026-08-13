<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نسبة ضريبة الصنف تصير اختيارية: الفراغ يعني «نسبة المتجر».
 *
 * كان العمود يبدأ من صفر ولا يقرؤه أحد — الفاتورة تحسب نسبة الإعداد العامّ
 * على كل صنف. وبتوصيله كما هو يصير الصفرُ إعلانًا بأن الصنف معفى، فتخرج
 * فواتير المتاجر القائمة بلا ضريبة بين ليلةٍ وضحاها.
 *
 * فالصفر يُفرَّغ ليصير الفراغُ «اتبع المتجر»، ويبقى الصفر متاحًا لمن يريد أن
 * يقول «هذا الصنف صفريّ» — والخبز والحليب والدواء صفرية في عُمان، وهي حاجةٌ
 * لا رفاهية.
 *
 * وما كان يحمل قيمةً غير الصفر يُترك كما هو: أصحابها كتبوها بأيديهم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('tax', 5, 2)->nullable()->default(null)->change();
        });

        DB::table('products')->where('tax', 0)->update(['tax' => null]);
    }

    public function down(): void
    {
        DB::table('products')->whereNull('tax')->update(['tax' => 0]);

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('tax', 5, 2)->default(0)->change();
        });
    }
};
