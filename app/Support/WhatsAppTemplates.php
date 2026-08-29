<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Order;
use App\Models\WhatsAppTemplateMapping;

/**
 * أيّ قالبٍ يُرسَل، وبأيّ متغيّرات.
 *
 * ميتا لا تقبل نصًّا حرًّا في رسالةٍ يبدؤها العمل: بل قالبًا معتمَدًا باسمه
 * ولغته ومتغيّراته المرقّمة. فقوالب `wa_template_*` القديمة في إعدادات
 * المتجر تبقى كما هي لشاشة «افتح محادثة» — وهي شيءٌ آخر، ولا تُحذف.
 *
 * ------------------------------------------------------------------
 *
 * واسم المحلّ متغيّرٌ في كلّ قالب، وهذا شرطٌ لا زينة: الرقم رقم أبعاد، فإن
 * لم تقل الرسالة «من محلّ الورد فلان» قرأها الزبون رسالةً من أبعاد عن طلبٍ
 * لا يعرفه — فلا يثق بها، أو يردّ على رقمٍ لا يقرأ ردَّه أحد.
 *
 * فترتيب المتغيّرات: {{1}} اسم المحلّ، {{2}} رقم الطلب. ومن ربط رقمه الخاصّ
 * يبقى الترتيب نفسه — قالبٌ واحد ومسار إرسالٍ واحد، ولو افترقا لَافترق
 * سلوكهما عند أوّل تعديل.
 */
class WhatsAppTemplates
{
    /**
     * القالب المناسب لهذا المتجر وهذا الحدث.
     *
     * قالب المحلّ إن كان يُرسل من رقمه، وقالب أبعاد إن كان على المشترك.
     * ولا يُخلط بينهما: اسم القالب معتمَدٌ داخل حسابٍ بعينه، وقالب أبعاد لا
     * وجود له في حساب المحلّ فيُردّ النداء بخطأ.
     */
    public static function resolve(Business $business, string $event, string $mode): ?WhatsAppTemplateMapping
    {
        if ($mode === WhatsAppMode::BUSINESS_OWN) {
            return WhatsAppTemplateMapping::where('scope_type', WhatsAppMode::OWNER_BUSINESS)
                ->where('business_id', $business->id)
                ->where('event_type', $event)->where('enabled', true)->first();
        }

        return WhatsAppTemplateMapping::query()->platform()
            ->where('event_type', $event)->where('enabled', true)->first();
    }

    /**
     * قيم المتغيّرات بترتيبها — نصوصٌ لا أرقام، فميتا تقبل النصّ وحده.
     *
     * ولا سعرَ فيها ولا إجمالي: القالب معتمَدٌ مسبقًا بعدد متغيّراته، وزيادةُ
     * متغيّرٍ تعني إعادة اعتماد. والاسم والرقم يكفيان لأن يعرف الزبون طلبَه.
     *
     * @return array<int, string>
     */
    public static function variables(Business $business, Order $order): array
    {
        return [
            (string) ($business->name ?: __('متجر')),
            (string) $order->number,
        ];
    }

    /** قوالب أبعاد الأربعة — تُهيَّأ عند ربط الرقم المشترك */
    public static function seedPlatformDefaults(?string $language = null): void
    {
        self::seed(WhatsAppMode::OWNER_PLATFORM, null, $language);
    }

    /** قوالب المحلّ الأربعة — تُهيَّأ عند ربط رقمه */
    public static function seedBusinessDefaults(int $businessId, ?string $language = null): void
    {
        self::seed(WhatsAppMode::OWNER_BUSINESS, $businessId, $language);
    }

    /**
     * التهيئة في موضعٍ واحد للنطاقين.
     *
     * ولو كُتبت مرّتين لَافترقتا: تُضاف الحدثُ الخامس في إحداهما، فيرسل
     * المشترك ولا يرسل من ربط رقمه — ولا شيء يقول لماذا.
     *
     * و`firstOrCreate` لا `updateOrCreate`: اسمٌ صحّحه التاجر بعد اعتماد
     * قالبه عند ميتا لا يُعاد إلى الافتراضيّ عند أوّل إعادة ربط.
     */
    private static function seed(string $scope, ?int $businessId, ?string $language): void
    {
        $language ??= (string) config('whatsapp.language', 'ar');

        foreach (WhatsAppEvent::ALL as $event) {
            WhatsAppTemplateMapping::firstOrCreate(
                ['scope_type' => $scope, 'business_id' => $businessId, 'event_type' => $event],
                [
                    'template_name' => WhatsAppEvent::DEFAULT_TEMPLATES[$event],
                    'language_code' => $language,
                    'enabled' => true,
                    // {{1}} اسم المحلّ، {{2}} رقم الطلب — يُقرأ ولا يُخمَّن
                    'variable_mapping' => ['1' => 'business_name', '2' => 'order_number'],
                ],
            );
        }
    }
}
