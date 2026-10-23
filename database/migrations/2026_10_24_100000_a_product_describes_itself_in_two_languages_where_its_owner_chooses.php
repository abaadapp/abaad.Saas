<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وصفُ الصنف بلغتين، ويختار صاحبُه أين يُعرض — مستقلًّا عن أين يُباع.
 *
 * ═══ ما كان ═══
 *
 * `description` واحدٌ بالعربيّة، تعرضه صفحةُ الصنف في RIBBON والمتجرُ المبنيّ
 * بأيّ لغةٍ كانت الصفحة، ولا تعرضه نقطةُ البيع إطلاقًا.
 *
 * ═══ وما يُضاف ═══
 *
 *   - `description_en`: الوصفُ الإنجليزيّ — اختياريّ، يكتبه التاجر بيده.
 *   - `description_web_enabled`: يُعرض الوصفُ في الموقع. افتراضُه `true`:
 *     كلُّ صنفٍ اليوم يعرض وصفَه هناك، ويبقى يعرضه.
 *   - `description_pos_enabled`: يُعرض للكاشير في نقطة البيع. افتراضُه
 *     `false`: لم يظهر وصفٌ في الصندوق قطّ، فلا يظهر فجأةً لصنفٍ قديم.
 *
 * ولا يُمسّ `pos_enabled` ولا `published`: ذانك أين يُباع، وهذان أين يُقرأ.
 * ولا يُكتب في `description` حرف: الأوصافُ القائمة كما هي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'description_en')) {
                $table->text('description_en')->nullable()->after('description');
            }
            if (! Schema::hasColumn('products', 'description_web_enabled')) {
                $table->boolean('description_web_enabled')->default(true)->after('pos_enabled');
            }
            if (! Schema::hasColumn('products', 'description_pos_enabled')) {
                $table->boolean('description_pos_enabled')->default(false)->after('pos_enabled');
            }
        });
    }

    public function down(): void
    {
        foreach (['description_en', 'description_web_enabled', 'description_pos_enabled'] as $column) {
            if (Schema::hasColumn('products', $column)) {
                Schema::table('products', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
