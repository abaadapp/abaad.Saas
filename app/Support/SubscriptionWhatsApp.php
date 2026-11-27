<?php

namespace App\Support;

use App\Models\Business;
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
 */
final class SubscriptionWhatsApp
{
    public const LANGUAGE = 'ar';

    public const CATEGORY = 'UTILITY';

    /**
     * المرحلة ← اسمُ القالب ونصُّه.
     *
     * المراحلُ مراحلُ البريد نفسُها (`SubscriptionNotices::stageFor`). والعددُ
     * لا يُكتب في النصّ بل التاريخ: «خلال ١ يومًا» عربيّةٌ مكسورة، والتاريخُ
     * لا يحتاج تصريفًا. وقبل الانتهاء بأسبوعٍ وبيومٍ قالبٌ واحد.
     *
     * والنصُّ لا يبدأ بمتغيّرٍ ولا ينتهي به — Meta تردّ القالبَ الذي يفعل.
     *
     * @var array<string, array{name: string, body: string}>
     */
    public const TEMPLATES = [
        'before' => [
            'name' => 'abaad_subscription_ends_soon',
            'body' => 'مرحبًا، ينتهي اشتراك متجرك {{1}} في أبعاد بتاريخ {{2}}. بياناتك محفوظة، وللتجديد راسلنا على هذا الرقم.',
        ],
        'today' => [
            'name' => 'abaad_subscription_ends_today',
            'body' => 'مرحبًا، ينتهي اشتراك متجرك {{1}} في أبعاد اليوم {{2}}، ويبقى النظام يعمل اليوم كاملًا. للتجديد راسلنا على هذا الرقم.',
        ],
        'grace' => [
            'name' => 'abaad_subscription_grace',
            'body' => 'مرحبًا، انتهى اشتراك متجرك {{1}} في أبعاد بتاريخ {{2}}، ويعمل النظام حتى نهاية يوم {{3}} ثم يتوقف. بياناتك محفوظة، وللتجديد راسلنا على هذا الرقم.',
        ],
        'locked' => [
            'name' => 'abaad_subscription_locked',
            'body' => 'مرحبًا، توقف نظام متجرك {{1}} في أبعاد لانتهاء الاشتراك بتاريخ {{2}}. بياناتك محفوظة كما هي ولا يُحذف شيء، وللتجديد راسلنا على هذا الرقم.',
        ],
    ];

    /** أمثلةُ المتغيّرات التي تراجعها Meta مع النصّ */
    private const EXAMPLES = ['متجر الورد', '2026-12-31', '2027-01-07'];

    /** ‏‏إنذارٌ واحدٌ لكلّ متجرٍ ومرحلةٍ في اليوم — ولو أُعيد الأمرُ بيد */
    private const ONCE_HOURS = 36;

    /** خطُّ الإرسال — رقمُ الإشعارات النشط، أو لا شيء */
    public static function line(): ?WhatsAppConnection
    {
        $line = WhatsAppConnections::platform();

        return $line !== null && filled($line->phone_number_id) && filled($line->access_token) ? $line : null;
    }

    /**
     * حالُ قوالب الإنذار في حساب أبعاد — الاسم ← الحال بحروف Meta، أو `null` إن تعذّرت القراءة.
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

            if (in_array($name, $mine, true) && self::base((string) ($row['language'] ?? '')) === self::LANGUAGE) {
                $out[$name] = strtoupper((string) ($row['status'] ?? ''));
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
            if (isset($there[$template['name']])) {
                continue;
            }

            $count = preg_match_all('/\{\{\d+\}\}/', $template['body']);
            $r = MetaWhatsAppClient::createTemplate($line, [
                'name' => $template['name'],
                'language' => self::LANGUAGE,
                'category' => self::CATEGORY,
                'parameter_format' => 'POSITIONAL',
                'components' => [[
                    'type' => 'BODY',
                    'text' => $template['body'],
                    'example' => ['body_text' => [array_slice(self::EXAMPLES, 0, $count)]],
                ]],
            ]);

            $out[$template['name']] = ['ok' => $r['ok'], 'status' => $r['status'], 'message' => $r['message']];
        }

        return $out;
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
    public static function variables(Business $business, string $stage): array
    {
        $vars = [(string) $business->name, (string) $business->ends_at?->format('Y-m-d')];

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

        if ($template === null || ($statuses[$template] ?? null) !== WhatsAppTemplates::APPROVED) {
            return 'not_approved';
        }

        $key = 'subscription-whatsapp:'.$business->id.':'.$stage.':'.now()->toDateString();

        if (! Cache::add($key, 1, now()->addHours(self::ONCE_HOURS))) {
            return 'already';
        }

        $r = MetaWhatsAppClient::sendTemplate($line, $to, $template, self::LANGUAGE, self::variables($business, $stage));

        if (! $r['ok']) {
            // ‏‏ما لم يخرج يُعاد عليه في تشغيلٍ يدويّ اليوم نفسَه
            Cache::forget($key);

            return 'failed:'.($r['code'] ?? 'unknown');
        }

        return 'sent';
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
