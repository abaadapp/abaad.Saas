<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ما يظهر في المتجر على الإنترنت اختيارٌ، لا كلُّ ما في الجرد.
 *
 * `active` تعني «يُباع في نقطة البيع»، ولا تعني «يراه الزبون على الإنترنت».
 * وفي جرد كلِّ متجرٍ أصنافٌ لا يُراد عرضها: مكوّناتٌ تُشترى ولا تُباع وحدها،
 * وأصنافٌ داخلية، وباقاتٌ تحت التجهيز، وأسعارُ جملة. فلو عُرض كلُّ نشِطٍ
 * لَما ملك التاجر أن يمنع صنفًا واحدًا إلا بإطفائه في نقطة البيع أيضًا.
 *
 * والافتراضيّ «لا يُعرض» عمدًا: متجرٌ يُنشر فيظهر فيه ما لم يقصد صاحبه عرضه
 * عطبٌ لا يُستدرَك — رآه الزبائن. والعكسُ يراه التاجر في أوّل نظرةٍ إلى
 * موقعه فيصلحه بضغطة «اعرض كلّ الأصناف النشطة».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('published')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('published');
        });
    }
};
