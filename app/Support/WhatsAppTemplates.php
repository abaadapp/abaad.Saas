<?php

namespace App\Support;

use App\Models\Business;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\WhatsAppConnection;
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
    /** الحالُ الوحيدة التي تسمح بالنداء — بحروف ميتا */
    public const APPROVED = 'APPROVED';

    /** سألنا ميتا فلم تعرف هذا الاسم — غيرُ «لم نسأل» */
    public const MISSING = 'MISSING';

    /**
     * القالب المناسب لهذا المتجر وهذا الحدث.
     *
     * قالب المحلّ إن كان يُرسل من رقمه، وقالب أبعاد إن كان على المشترك.
     * ولا يُخلط بينهما: اسم القالب معتمَدٌ داخل حسابٍ بعينه، وقالب أبعاد لا
     * وجود له في حساب المحلّ فيُردّ النداء بخطأ.
     */
    public static function resolve(Business $business, string $event, string $mode): ?WhatsAppTemplateMapping
    {
        $mapping = $mode === WhatsAppMode::BUSINESS_OWN
            ? WhatsAppTemplateMapping::where('scope_type', WhatsAppMode::OWNER_BUSINESS)
                ->where('business_id', $business->id)
                ->where('event_type', $event)->where('enabled', true)->first()
            : WhatsAppTemplateMapping::query()->platform()
                ->where('event_type', $event)->where('enabled', true)->first();

        /*
         * وقالبٌ لم تعتمده ميتا لا يُنادى به.
         *
         * ═══ ولمَ يُمنع هنا لا يُترك لميتا تردّه ═══
         *
         * تركُه يعني: يُبنى الصفّ، وتُحجز الحصّة، ويُنادى ميتا، فتردُّ —
         * وتُقيَّد `failed` بنصٍّ إنجليزيّ عن قالبٍ لا يعرفه التاجر، ويحمرّ
         * جرسُ لوحته بعطبٍ لا يملك إصلاحه. والمنعُ هنا يكتب `skipped`
         * بسببٍ يُقرأ، ولا يُحاسَب أحدٌ على رسالةٍ لم تخرج.
         *
         * ═══ وفارغٌ ليس رفضًا ═══
         *
         * `meta_status` الفارغ يعني «لم نسأل ميتا بعد» لا «مرفوض». ولو
         * قُرئ رفضًا لَأطفأ إشعاراتِ كلّ متجرٍ لحظةَ الترحيل — قبل أن يجري
         * أوّلُ مزامنة. فالمنعُ على ما **عُلم** أنّه غير معتمَد وحدَه،
         * والمجهولُ يُقال في الشاشة ولا يُغلق به باب.
         */
        if ($mapping !== null && filled($mapping->meta_status)
            && $mapping->meta_status !== self::APPROVED) {
            return null;
        }

        return $mapping;
    }

    /**
     * أسألُ ميتا عن قوالب هذا الحساب وأكتب ما قالته.
     *
     * والحالُ يُكتب بحروف ميتا نفسِها لا بترجمةٍ لها: `APPROVED`، `PENDING`،
     * `REJECTED`، `PAUSED`. وترجمتُه إلى قاموسٍ عندنا تعني أنّ حالًا جديدةً
     * تُصدرها ميتا غدًا تسقط في «غير معروف» فتُقرأ معتمَدةً أو مرفوضة بلا
     * أن يقول أحدٌ أيَّهما.
     *
     * ولا يُكتب شيءٌ إن تعذّر السؤال: انقطاعُ شبكةٍ لا يعني أنّ القوالب
     * صارت غيرَ معتمَدة — والحالُ القديم أصدقُ من حالٍ مخترَعٍ من فشلِ نداء.
     *
     * @return array{ok:bool, checked:int, approved:int, message:?string}
     */
    public static function sync(WhatsAppConnection $connection, string $scope, ?int $businessId = null): array
    {
        $result = MetaWhatsAppClient::templates($connection);

        if (! $result['ok']) {
            return ['ok' => false, 'checked' => 0, 'approved' => 0, 'message' => $result['message']];
        }

        $rows = WhatsAppTemplateMapping::where('scope_type', $scope)
            ->when($businessId === null, fn ($q) => $q->whereNull('business_id'))
            ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
            ->get();

        $approved = 0;

        foreach ($rows as $row) {
            /*
             * وقالبٌ لا تعرفه ميتا يُكتب `MISSING` لا يُترك فارغًا.
             *
             * الفارغُ يعني «لم نسأل»، وهذا سُئل ولم يُوجد — وهما حالان:
             * الأوّل يُنتظر، والثاني يُنشأ عند ميتا أو يُصحَّح اسمُه.
             */
            $byLanguage = $result['templates'][$row->template_name] ?? [];
            $status = $byLanguage[$row->language_code] ?? $byLanguage['*'] ?? self::MISSING;

            /*
             * ولغاتُ الاسم المعتمَدةُ كلُّها تُكتب معه — منها يُختار للزبون.
             *
             * `*` (صفٌّ بلا لغة) يُنسب إلى لغة القالب نفسِه لا يُعدّ لغةً.
             */
            $approvedLanguages = collect($byLanguage)
                ->filter(fn ($s) => $s === self::APPROVED)
                ->keys()
                ->map(fn ($lang) => $lang === '*' ? (string) $row->language_code : (string) $lang)
                ->unique()->values()->all();

            $row->forceFill([
                'meta_status' => $status,
                'approved_languages' => $approvedLanguages,
                'meta_synced_at' => now(),
            ])->save();

            if ($status === self::APPROVED) {
                $approved++;
            }
        }

        return ['ok' => true, 'checked' => $rows->count(), 'approved' => $approved, 'message' => null];
    }

    /**
     * أكلُّ قوالب هذا النطاق معتمَدةٌ عند ميتا؟
     *
     * و`null` تعني «لم يُسأل بعد» — وهي غير `false`: تلك تقول «سألنا فلا»،
     * وهذه تقول «لا نعرف». وخلطُهما يجعل الشاشة تُنذر بما لم يُقَس.
     */
    public static function approvalState(string $scope, ?int $businessId = null): ?bool
    {
        $rows = WhatsAppTemplateMapping::where('scope_type', $scope)
            ->when($businessId === null, fn ($q) => $q->whereNull('business_id'))
            ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
            ->where('enabled', true)
            ->get(['meta_status']);

        if ($rows->isEmpty() || $rows->every(fn ($r) => blank($r->meta_status))) {
            return null;
        }

        return $rows->every(fn ($r) => $r->meta_status === self::APPROVED);
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

    /**
     * قيمُ متغيّرات رسائل الفواتير — ولا فراغَ فيها.
     *
     * وميتا ترفض متغيّرًا بلا قيمة (`132000`)، فكان تذكيرُ السداد يُبنى
     * بـ`['اسم المحلّ', '']` ويُردّ قبل أن يصل أحدًا. والقالبُ يقول «رقم
     * الفاتورة: {{2}}» — فيُملأ برقمها.
     *
     * @return array<int, string>
     */
    public static function invoiceVariables(Business $business, CustomerInvoice $invoice): array
    {
        return [
            (string) ($business->name ?: __('متجر')),
            (string) $invoice->number,
        ];
    }

    /**
     * حالُ قوالب أبعاد كما تُقرأ في لوحة المنصّة.
     *
     * والحدثُ يُسمّى بالعربية والقالبُ باسمه عند ميتا: مالكُ المنصّة يقارن
     * ما يراه هنا بما في لوحة ميتا، فالاسمُ الإنجليزيّ هو الجسر بينهما.
     *
     * و`synced_at` يُعرض: قائمةٌ بلا وقتٍ لا يُعرف أهي حالُ الساعة أم حالُ
     * الأسبوع الماضي — وقارئُها يبني عليها قرارًا.
     *
     * @return array{synced_at:?string, approved:int, total:int,
     *               rows:list<array{event:string, name:string, status:?string}>}
     */
    public static function platformStatus(): array
    {
        $rows = WhatsAppTemplateMapping::query()->platform()->orderBy('id')->get();

        return [
            'synced_at' => optional($rows->max('meta_synced_at'))->format('Y-m-d H:i'),
            'approved' => $rows->where('meta_status', self::APPROVED)->count(),
            'total' => $rows->count(),
            'rows' => $rows->map(fn (WhatsAppTemplateMapping $m) => [
                'event' => WhatsAppEvent::label($m->event_type),
                'name' => $m->template_name,
                'status' => $m->meta_status,
            ])->all(),
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
