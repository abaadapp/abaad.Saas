<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * صفَّان في إعدادات المنصّة لا يقرؤهما سطر — يُحذفان بطلب صاحب النظام.
 *
 * `business_id IS NULL` هي إعدادات المنصّة. وكانت البذرةُ تكتب فيها ثلاثة
 * مفاتيحَ ميّتة (رُفعت من البذرتين في `v6.179`)، وبقي منها على الإنتاج اثنان:
 *
 * ١) `platform_name` — اسمٌ قديم. شاشةُ الإعدادات تكتب `app_name`،
 *    و`PlatformConfig::apply` تقرؤه. فالصفُّ يحمل «Abad POS» ولا يبلغ شيئًا.
 * ٢) `currency_decimals` — الكسورُ تُقرأ من عملة المتجر
 *    (`Demo::currencyFor`)، لا من صفٍّ على مستوى المنصّة.
 *
 * و`vat_rate` يبقى: يقرؤه `Vat` فعلًا.
 *
 * ═══ والحذفُ مشروطٌ بالموضع ═══
 *
 * `business_id IS NULL` شرطٌ لازم لا زينة: `currency_decimals` مفتاحٌ حيٌّ
 * على مستوى المتجر في متاجرَ أخرى، وحذفُه بالاسم وحده كان يمحو إعداداتِ
 * تجّارٍ لا علاقة لهم بهذا.
 */
return new class extends Migration
{
    /** ما يُحذف — بالاسم وبالموضع */
    private const DEAD = ['platform_name', 'currency_decimals'];

    public function up(): void
    {
        DB::table('settings')
            ->whereNull('business_id')
            ->whereIn('key', self::DEAD)
            ->delete();
    }

    /**
     * ولا تُعاد: صفٌّ لا يقرؤه شيء لا يُستعاد بالتراجع.
     *
     * وإعادتُه تعني أنّ `migrate:rollback` يُنشئ ما حُذف عمدًا — وهو أسوأ
     * من تراجعٍ ناقص. الصفوفُ محفوظةٌ في النسخة الاحتياطية إن لزم.
     */
    public function down(): void
    {
        // لا شيء
    }
};
