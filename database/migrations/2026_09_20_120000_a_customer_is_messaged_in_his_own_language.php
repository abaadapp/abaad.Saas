<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الزبونُ يُراسَل بلغته — والقالبُ يعرف بأيّ لغاتٍ اعتُمد.
 *
 * `customers.language`: `ar` أو `en` أو فارغٌ («لغة المتجر»). و
 * `whatsapp_template_mappings.approved_languages`: ما اعتمدته ميتا من لغاتٍ
 * للاسم نفسِه — فيُرسَل بلغة الزبون إن كانت فيها، وإلّا بلغة القالب الأصل.
 * ولا يُطلب من ميتا ما لم تعتمده فتردّه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('language', 5)->nullable()->after('email');
        });

        Schema::table('whatsapp_template_mappings', function (Blueprint $table) {
            $table->json('approved_languages')->nullable()->after('meta_status');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('language'));
        Schema::table('whatsapp_template_mappings', fn (Blueprint $table) => $table->dropColumn('approved_languages'));
    }
};
