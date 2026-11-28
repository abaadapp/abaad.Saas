<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use App\Models\Business;
use App\Models\Setting;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Cache;

/**
 * إنذارُ الاشتراك على واتساب — من رقم أبعاد إلى رقم المتجر.
 *
 * ═══ لمَ واتساب وفي البريد الإنذارُ نفسُه ═══
 *
 * البريدُ على الإنتاج لا يخرج (`mail.default = log`، 2026-11-27): فلم يصل
 * تاجرًا قطّ إنذارُ انتهاء اشتراكه، وثلاثةٌ من خمسة انتهى تاريخُهم. والتاجرُ
 * في عُمان يقرأ واتساب قبل بريده. فالرسالةُ تخرج من الطريقين، ولا يُغني
 * أحدُهما عن الآخر.
 *
 * ═══ لا يخرج شيءٌ حتّى يعتمد صاحبُ المنصّة القوالب ═══
 *
 * القوالبُ لا تُنشأ وحدها: تُرسَل إلى Meta بأمرٍ يكتبه صاحبُ المنصّة بيده
 * (`subscriptions:whatsapp-templates --create`)، ثمّ تراجعها Meta. وقبل أن
 * تقول Meta «APPROVED» لقالب المرحلة لا تُنادى رسالتُه أصلًا — فدمجُ هذا
 * ونشرُه لا يُرسل حرفًا. وحذفُ القوالب من WhatsApp Manager يُوقفه كلَّه.
 *
 * ═══ ومن رقم الإشعارات وحده ═══
 *
 * `WhatsAppConnections::platform` — الرقمُ الذي يعرفه التاجر. وردُّ التاجر
 * يصل إلى من يجيبه بالطريق القائم: رقمُه رقمُ مستخدمٍ عندنا ← الدعم
 * (`SupportWhatsApp::receive`)، وإلّا الصندوقُ المشترك أو العملاءُ المحتملون.
 *
 * ═══ وبلغة صاحب النشاط ═══
 *
 * عربيّةً أو إنجليزيّة بالسلسلة التي تختار بها الواجهةُ لغتَه (`SetLocale`)
 * بلا الجلسة: حسابُ المالك، ثمّ إعدادُ المتجر، ثمّ إعدادُ المنصّة، ثمّ
 * العربيّة. وقالبُ اللغتين اسمٌ واحد عند Meta بلغتين.
 */
final class SubscriptionWhatsApp
{
    /** ‏‏اللغةُ الأولى، وإليها يُرجَع حين لا تُعتمد لغةُ المالك بعد */
    public const LANGUAGE = 'ar';

    public const LANGUAGES = ['ar', 'en'];

    public const CATEGORY = 'UTILITY';

    /**
     * المرحلة ← اسمُ القالب ونصُّه بكلّ لغة.
     *
     * المراحلُ مراحلُ البريد نفسُها (`SubscriptionNotices::stageFor`). والعددُ
     * لا يُكتب في النصّ بل التاريخ: «خلال ١ يومًا» عربيّةٌ مكسورة، والتاريخُ
     * لا يحتاج تصريفًا. وكلُّ إنذارٍ قبل الانتهاء قالبٌ واحد.
     *
     * والنصُّ لا يبدأ بمتغيّرٍ ولا ينتهي به — Meta تردّ القالبَ الذي يفعل.
     * ومتغيّراتُ اللغتين واحدةٌ بترتيبها.
     *
     * ═══ إشعارُ حسابٍ لا دعوةُ تجديد ═══
     *
     * الجيلُ الأوّل (`abaad_subscription_*`) ختم كلَّ نصٍّ بـ«وللتجديد راسلنا»،
     * فصنّفته Meta كلَّه تسويقيًّا (2026-11-28). والتسويقيُّ يُحدّ عددُه لكلّ
     * مستلم وقد لا يُسلَّم، ويُوقفه المستلمُ بنقرة، فلا يُضمن أن يصل إنذارُ
     * «توقّف النظام» صاحبَه. فالنصُّ هنا حقائقُ الحساب وحدها، ودعوتُه
     * الوحيدة ردٌّ للاستفسار. والأسماءُ جديدة: Meta لا تُعيد فئةَ قالبٍ
     * بتعديله، والقديمُ يُترك فلا يُنادى.
     *
     * @var array<string, array{name: string, bodies: array<string, string>}>
     */
    public const TEMPLATES = [
        'before' => [
            'name' => 'abaad_account_ends_soon',
            'bodies' => [
                'ar' => 'تنبيه حساب: اشتراك متجرك {{1}} في أبعاد ينتهي بتاريخ {{2}}. لأي استفسار عن حسابك ردّ على هذه الرسالة.',
                'en' => 'Account notice: the Abaad subscription for your store {{1}} ends on {{2}}. Reply to this message if you have any questions about your account.',
            ],
        ],
        'today' => [
            'name' => 'abaad_account_ends_today',
            'bodies' => [
                'ar' => 'تنبيه حساب: اشتراك متجرك {{1}} في أبعاد ينتهي اليوم {{2}}، والنظام يعمل اليوم كاملًا. لأي استفسار ردّ على هذه الرسالة.',
                'en' => 'Account notice: the Abaad subscription for your store {{1}} ends today, {{2}}, and the system works for the whole day. Reply to this message if you have any questions.',
            ],
        ],
        'grace' => [
            'name' => 'abaad_account_grace_period',
            'bodies' => [
                'ar' => 'تنبيه حساب: انتهى اشتراك متجرك {{1}} في أبعاد بتاريخ {{2}}، ويعمل النظام حتى نهاية يوم {{3}} ثم يتوقف. بياناتك محفوظة. لأي استفسار ردّ على هذه الرسالة.',
                'en' => 'Account notice: the Abaad subscription for your store {{1}} ended on {{2}}. The system works until the end of {{3}} and then stops. Your data is safe. Reply to this message if you have any questions.',
            ],
        ],
        'locked' => [
            'name' => 'abaad_account_stopped',
            'bodies' => [
                'ar' => 'تنبيه حساب: توقف نظام متجرك {{1}} في أبعاد لانتهاء الاشتراك بتاريخ {{2}}. بياناتك محفوظة ولا يُحذف شيء. لأي استفسار ردّ على هذه الرسالة.',
                'en' => 'Account notice: the Abaad system for your store {{1}} stopped because the subscription ended on {{2}}. Your data is safe and nothing is deleted. Reply to this message if you have any questions.',
            ],
        ],
    ];

    /** أمثلةُ المتغيّرات التي تراجعها Meta مع النصّ */
    private const EXAMPLES = [
        'ar' => ['متجر الورد', '2026-12-31', '2027-01-07'],
        'en' => ['Rose Shop', '2026-12-31', '2027-01-07'],
    ];

    /** ‏‏إنذارٌ واحدٌ لكلّ متجرٍ ومرحلةٍ في اليوم — ولو أُعيد الأمرُ بيد */
    private const ONCE_HOURS = 36;

    /** خطُّ الإرسال — رقمُ الإشعارات النشط، أو لا شيء */
    public static function line(): ?WhatsAppConnection
    {
        $line = WhatsAppConnections::platform();

        return $line !== null && filled($line->phone_number_id) && filled($line->access_token) ? $line : null;
    }

    /**
     * حالُ قوالب الإنذار في حساب أبعاد — «الاسم|اللغة» ← الحال بحروف Meta، أو `null` إن تعذّرت القراءة.
     *
     * @return array<string, string>|null
     */
    public static function statuses(WhatsAppConnection $line): ?array
    {
        if (blank($line->waba_id)) {
            return null;
        }

        $read = MetaWhatsAppClient::templateRows($line);

        if (! $read['ok']) {
            return null;
        }

        $mine = array_column(self::TEMPLATES, 'name');
        $out = [];

        foreach ($read['rows'] as $row) {
            $name = (string) ($row['name'] ?? '');

            $language = self::base((string) ($row['language'] ?? ''));

            if (in_array($name, $mine, true) && in_array($language, self::LANGUAGES, true)) {
                $out[self::key($name, $language)] = strtoupper((string) ($row['status'] ?? ''));
            }
        }

        return $out;
    }

    /**
     * يُرسل إلى Meta ما ينقص من القوالب لتراجعه — بأمر صاحب المنصّة وحده.
     *
     * @param  array<string, string>  $there  ما في الحساب الآن (`statuses`)
     * @return array<string, array{ok: bool, status: ?string, message: ?string}>
     */
    public static function submitMissing(WhatsAppConnection $line, array $there): array
    {
        $out = [];

        foreach (self::TEMPLATES as $template) {
            foreach ($template['bodies'] as $language => $body) {
                $key = self::key($template['name'], $language);

                if (isset($there[$key])) {
                    continue;
                }

                $count = preg_match_all('/\{\{\d+\}\}/', $body);
                $r = MetaWhatsAppClient::createTemplate($line, [
                    'name' => $template['name'],
                    'language' => $language,
                    'category' => self::CATEGORY,
                    'parameter_format' => 'POSITIONAL',
                    'components' => [[
                        'type' => 'BODY',
                        'text' => $body,
                        'example' => ['body_text' => [array_slice(self::EXAMPLES[$language], 0, $count)]],
                    ]],
                ]);

                $out[$key] = ['ok' => $r['ok'], 'status' => $r['status'], 'message' => $r['message']];
            }
        }

        return $out;
    }

    /** مفتاحُ قالبٍ بلغته في `statuses` */
    public static function key(string $name, string $language): string
    {
        return $name.'|'.$language;
    }

    /**
     * لغةُ صاحب النشاط — بسلسلة `SetLocale` بلا الجلسة.
     *
     * حسابُ المالك (`MerchantAccount::owner`) أوّلًا: هو من يقرأ الرسالة،
     * ولغتُه التي اختارها لنفسه تغلب افتراضَ متجره. ثمّ إعدادُ المتجر، ثمّ
     * المنصّة، ثمّ العربيّة.
     */
    public static function language(Business $business): string
    {
        $candidates = [
            MerchantAccount::owner($business)?->locale,
            Setting::where('business_id', $business->id)->where('key', 'locale')->value('value'),
            Tenancy::platform('locale'),
        ];

        foreach ($candidates as $locale) {
            if (in_array($locale, SetLocale::SUPPORTED, true) && in_array($locale, self::LANGUAGES, true)) {
                return $locale;
            }
        }

        return self::LANGUAGE;
    }

    /**
     * رقمُ المتجر بصيغة واتساب — أو لا شيء.
     */
    public static function phone(Business $business): ?string
    {
        return WhatsAppPhone::normalize((string) $business->phone);
    }

    /**
     * متغيّراتُ رسالة المرحلة بترتيبها في النصّ.
     *
     * @return list<string>
     */
    public static function variables(Business $business, string $stage, string $language = self::LANGUAGE): array
    {
        // ‏‏وبالإنجليزيّة اسمُ المتجر الإنجليزيّ إن كُتب
        $name = $language === 'en' && filled($business->name_en) ? (string) $business->name_en : (string) $business->name;
        $vars = [$name, (string) $business->ends_at?->format('Y-m-d')];

        if ($stage === 'grace') {
            $vars[] = (string) Tenancy::locksAt($business)?->format('Y-m-d');
        }

        return $vars;
    }

    /**
     * يُرسل إنذارَ مرحلةٍ واحدة لمتجرٍ واحد — ويقول ما جرى.
     *
     * @param  array<string, string>|null  $statuses  حالُ القوالب كما قُرئت مرّةً لهذا التشغيل
     * @return string sent · demo · no_phone · no_line · not_approved · already · failed:<code>
     */
    public static function send(Business $business, string $stage, ?WhatsAppConnection $line, ?array $statuses): string
    {
        if ((bool) $business->is_demo) {
            return 'demo';
        }

        $to = self::phone($business);

        if ($to === null) {
            return 'no_phone';
        }

        if ($line === null) {
            return 'no_line';
        }

        $template = self::TEMPLATES[$stage]['name'] ?? null;
        $language = self::approvedLanguage($template, self::language($business), $statuses);

        if ($template === null || $language === null) {
            return 'not_approved';
        }

        $key = 'subscription-whatsapp:'.$business->id.':'.$stage.':'.now()->toDateString();

        if (! Cache::add($key, 1, now()->addHours(self::ONCE_HOURS))) {
            return 'already';
        }

        $r = MetaWhatsAppClient::sendTemplate($line, $to, $template, $language, self::variables($business, $stage, $language));

        if (! $r['ok']) {
            // ‏‏ما لم يخرج يُعاد عليه في تشغيلٍ يدويّ اليوم نفسَه
            Cache::forget($key);

            return 'failed:'.($r['code'] ?? 'unknown');
        }

        return 'sent';
    }

    /**
     * لغةُ المالك إن اعتُمد قالبُها، وإلّا العربيّة إن اعتُمدت — وإلّا لا إرسال.
     *
     * إنذارٌ بالعربيّة لمن اختار الإنجليزيّة خيرٌ من إقفالٍ بلا إنذار، والعكسُ
     * لا يقع: العربيّةُ أوّلُ ما يُعتمد.
     *
     * @param  array<string, string>|null  $statuses
     */
    private static function approvedLanguage(?string $template, string $wanted, ?array $statuses): ?string
    {
        if ($template === null) {
            return null;
        }

        foreach (array_unique([$wanted, self::LANGUAGE]) as $language) {
            if (($statuses[self::key($template, $language)] ?? null) === WhatsAppTemplates::APPROVED) {
                return $language;
            }
        }

        return null;
    }

    /** آخرُ أربعة أرقام — ما يُطبع في السجلّ والشاشة بدل الرقم كاملًا */
    public static function tail(?string $phone): string
    {
        return $phone === null ? '—' : '…'.substr($phone, -4);
    }

    private static function base(string $language): string
    {
        return strtolower(explode('_', $language)[0]);
    }
}
