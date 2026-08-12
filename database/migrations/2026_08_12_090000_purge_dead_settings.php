<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * مسحُ المفاتيح التي كانت تُحفظ ولا يقرؤها سطرٌ واحد.
 *
 * بقاؤها في الجدول بعد إزالتها من الشاشة لا يضرّ اليوم، لكنه يضرّ غدًا: من
 * يقرأ الجدول بعد سنة يجد `pay_card = 0` فيظنّ أن التاجر منع البطاقة، وهو
 * لم يمنع شيئًا — إنما بدّل مقبضًا لا يُمسك. والقيمة الكاذبة أسوأ من غيابها.
 *
 * وما يخصّ التجّار (perm_*, order_*, delivery_*) يُمسح لكلّ متجر؛ وما يخصّ
 * المنصة يُمسح من صفوف business_id = null وحدها.
 *
 * ولا `down()` يعيدها: إعادةُ صفوفٍ ميّتة ليست استرجاعًا، والقيم نفسها لا
 * معنى لها بعد أن زالت حقولها.
 */
return new class extends Migration
{
    /** مفاتيح التجّار الميتة — والطباعة التلقائية حيّةٌ في «الأجهزة» لا هنا */
    private const TENANT = [
        'perm_0_0', 'perm_0_1', 'perm_0_2',
        'perm_1_0', 'perm_1_1', 'perm_1_2',
        'perm_2_0', 'perm_2_1', 'perm_2_2',
        'invoice_show_logo', 'copies', 'print_auto', 'print_kitchen',
        'order_prefix', 'default_status', 'order_allow_edit', 'order_confirm_cancel',
        'delivery_enabled', 'delivery_fee', 'free_threshold',
    ];

    /** مفاتيح المنصة الميتة — وSMTP بقيت اعتماداته في .env */
    private const PLATFORM = [
        'date_format', 'timezone', 'default_plan_id',
        'auto_renew', 'tax_number', 'platform_vat_enabled',
        'base_currency', 'currency_symbol', 'symbol_position',
        'platform_notif_0', 'platform_notif_1', 'platform_notif_2',
        'platform_notif_3', 'platform_notif_4', 'platform_notif_5',
        'mailer', 'mail_host', 'mail_port', 'mail_encryption',
        'terms', 'privacy',
    ];

    public function up(): void
    {
        DB::table('settings')->whereNotNull('business_id')->whereIn('key', self::TENANT)->delete();
        DB::table('settings')->whereNull('business_id')->whereIn('key', self::PLATFORM)->delete();

        /*
         * `decimals` مفتاحٌ باسمين: للتاجر حيٌّ (منازل عملته)، وللمنصة ميّت.
         * فيُمسح من صفّ المنصة وحده — وخلطُهما كان سيمحو ضبط كلّ تاجر.
         */
        DB::table('settings')->whereNull('business_id')->where('key', 'decimals')->delete();

        // وصفٌّ شاذّ على مستوى المنصة قيمته «ريال عماني» لا رمزًا — صار يُقرأ
        // بعد أن أُحييت العملة، فيظهر الاسم كاملًا بجانب كلّ مبلغ في لوحة المشغّل
        DB::table('settings')->whereNull('business_id')->where('key', 'currency')->delete();
    }

    public function down(): void
    {
        // لا رجعة: انظر التعليق أعلاه
    }
};
