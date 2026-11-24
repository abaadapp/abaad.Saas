<?php

namespace App\Support;

use App\Models\Business;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplateMapping;
use Illuminate\Support\Facades\Cache;

/**
 * ‏‏قوالبُ إشعارات المتجر في حسابه هو — تُنشأ عند الربط وتُتابَع حتى تُعتمد.
 *
 * ═══ لمَ ═══
 *
 * اعتمادُ القالب عند Meta لحسابٍ بعينه. فقوالبُ أبعاد المعتمَدة لا تُرسل من رقم المتجر: من بُدّل إرسالُه
 * قبل أن تُعتمد قوالبُه في حسابه وقفت رسائلُه كلُّها. وكان إنشاؤها بيد التاجر من لوحة Meta، بأسمائها حرفًا بحرف.
 *
 * فتُنشأ هنا بنصٍّ واحدٍ لكلّ المتاجر، بالعربيّة والإنجليزيّة، ومتغيّراتُها ما يملؤه المُرسِل بعينه
 * (`WhatsAppTemplates::variables` وأخواتها). وقالبٌ موجودٌ باسمه ولغته — أنشأه التاجر أو نحن — لا يُمسّ.
 *
 * ═══ ولمن لا ═══
 *
 * وصلةُ تطبيق التاجر لا يُنشأ في حسابها شيء: قوالبُها لصاحبها (`WhatsAppSender::tracks`).
 */
final class WhatsAppNoticeTemplates
{
    public const CATEGORY = 'UTILITY';

    /** ‏‏ورفضُ Meta لا يُعاد عليه في كلّ دقيقة */
    private const REFUSED_HOURS = 6;

    /** ‏‏ما يُراجَع وقد يُعتمد بلا فعلٍ منّا */
    private const WAITING = ['PENDING', 'IN_APPEAL', 'PENDING_DELETION'];

    /** @var array<string, array<string, string>> الحدث ← اللغة ← النصّ */
    private const BODIES = [
        WhatsAppEvent::ORDER_CONFIRMED => [
            'ar' => 'مرحبًا، تم تأكيد طلبك من {{1}}. رقم الطلب {{2}} ونخبرك عند جاهزيته.',
            'en' => 'Hello, your order from {{1}} is confirmed. Order number {{2}}, and we will tell you when it is ready.',
        ],
        WhatsAppEvent::ORDER_READY => [
            'ar' => 'مرحبًا، طلبك من {{1}} جاهز. رقم الطلب {{2}} وشكرًا لك.',
            'en' => 'Hello, your order from {{1}} is ready. Order number {{2}}, thank you.',
        ],
        WhatsAppEvent::ORDER_OUT_FOR_DELIVERY => [
            'ar' => 'مرحبًا، خرج طلبك من {{1}} للتوصيل. رقم الطلب {{2}} ويصلك قريبًا.',
            'en' => 'Hello, your order from {{1}} is out for delivery. Order number {{2}}, arriving soon.',
        ],
        WhatsAppEvent::ORDER_DELIVERED => [
            'ar' => 'مرحبًا، تم تسليم طلبك من {{1}}. رقم الطلب {{2}} وشكرًا لك.',
            'en' => 'Hello, your order from {{1}} has been delivered. Order number {{2}}, thank you.',
        ],
        WhatsAppEvent::INVOICE_DUE_SOON => [
            'ar' => 'مرحبًا، نذكّرك بفاتورة من {{1}} يقترب موعد سدادها. رقم الفاتورة {{2}} وشكرًا لك.',
            'en' => 'Hello, a reminder that an invoice from {{1}} is due soon. Invoice number {{2}}, thank you.',
        ],
        WhatsAppEvent::INVOICE_OVERDUE => [
            'ar' => 'مرحبًا، فاتورتك من {{1}} تجاوزت موعد سدادها. رقم الفاتورة {{2}} ونرجو سدادها في أقرب وقت.',
            'en' => 'Hello, your invoice from {{1}} is overdue. Invoice number {{2}}, please settle it when you can.',
        ],
        WhatsAppEvent::INVOICE_PAID => [
            'ar' => 'مرحبًا، وصلت دفعتك إلى {{1}} عن الفاتورة رقم {{2}} بمبلغ {{3}} وفاتورتك مرفقة. شكرًا لك.',
            'en' => 'Hello, {{1}} received your payment for invoice {{2}} of {{3}}, and your invoice is attached. Thank you.',
        ],
    ];

    /** ‏‏وما يخرج بملفٍّ في رأسه */
    private const WITH_DOCUMENT = [WhatsAppEvent::INVOICE_PAID];

    private const EXAMPLES = ['ar' => ['متجر الورد', '1024', '12.500 ر.ع'], 'en' => ['Rose Shop', '1024', '12.500 OMR']];

    /**
     * ‏‏أحداثُ هذا المتجر التي لا يجوز أن تقف بالتبديل — ما فعّله من إشعاراته.
     *
     * وما لا يخرج اليوم من رقم أبعاد (قالبُه لم يُعتمد عندها بعد) لا يُنتظر: لا يخسره أحد.
     *
     * @return list<string>
     */
    public static function required(Business $business): array
    {
        $on = MarketingSettings::group((int) $business->id, 'whatsapp');

        return array_values(array_filter(
            array_diff(WhatsAppEvent::eventsForBusiness((int) $business->id), WhatsAppEvent::GUIDED_ORDER),
            fn (string $event) => ($on[WhatsAppEvent::SETTING_KEYS[$event] ?? ''] ?? '0') !== '0'
                && (! in_array($event, WhatsAppEvent::ON_APPROVAL, true)
                    || WhatsAppTemplateMapping::query()->platform()->where('event_type', $event)->where('enabled', true)
                        ->where('meta_status', WhatsAppTemplates::APPROVED)->exists()),
        ));
    }

    /**
     * ‏‏يُنشئ ما ينقص، ويقرأ الحال، ويقول أتُرسل رسائلُ المتجر من رقمه الآن.
     *
     * @return array{state: 'ok'|'wait'|'fail'|'unknown', code: ?string, params: array<string, string>}
     */
    public static function ensure(Business $business, WhatsAppConnection $connection): array
    {
        WhatsAppTemplates::seedBusinessDefaults((int) $business->id);

        $read = MetaWhatsAppClient::templateRows($connection);

        if (! $read['ok']) {
            return self::answer('unknown', 'unreachable');
        }

        $there = [];

        foreach ($read['rows'] as $row) {
            $there[(string) ($row['name'] ?? '').'|'.self::base((string) ($row['language'] ?? ''))] = (string) ($row['status'] ?? '');
        }

        self::create($business, $connection, $there);

        $sync = WhatsAppTemplates::sync($connection, WhatsAppMode::OWNER_BUSINESS, (int) $business->id);

        return $sync['ok'] ? self::verdict($business, $connection) : self::answer('unknown', 'unreachable');
    }

    /** @param  array<string, string>  $there  ما في حساب المتجر: الاسم|اللغة ← الحال */
    private static function create(Business $business, WhatsAppConnection $connection, array $there): void
    {
        // ‏‏ومتجرٌ له قوالبُه بأسمائها لا يُنشأ له غيرُها
        if (WhatsAppEvent::isRibbonBusiness((int) $business->id)) {
            return;
        }

        foreach (self::BODIES as $event => $bodies) {
            $name = WhatsAppEvent::templateNameForBusiness((int) $business->id, $event);

            foreach ($bodies as $language => $body) {
                $key = self::refusedKey($connection, $name, $language);

                if (isset($there[$name.'|'.$language]) || Cache::has($key)) {
                    continue;
                }

                $r = self::submit($connection, $event, $name, $language, $body);

                if ($r['ok']) {
                    continue;
                }

                // ‏‏وانقطاعٌ أو حدُّ نداءات: لا يُحكم على القالب به، ويُعاد في الدورة التالية
                if (in_array((string) $r['code'], ['network_error', '4', '80007', '80008', '429'], true)) {
                    return;
                }

                Cache::put($key, mb_substr((string) $r['message'], 0, 200), now()->addHours(self::REFUSED_HOURS));
                WhatsAppLinkCheck::log('notice_template_refused', (int) $business->id, ['code' => $r['code'], 'message' => $name.' '.$language.' '.$r['message']]);
            }
        }
    }

    /** @return array{ok: bool, code: ?string, message: ?string} */
    private static function submit(WhatsAppConnection $connection, string $event, string $name, string $language, string $body): array
    {
        $components = [];

        if (in_array($event, self::WITH_DOCUMENT, true)) {
            $sample = MetaWhatsAppClient::uploadSample($connection, 'sample-invoice.pdf', self::samplePdf(), 'application/pdf');

            if (! $sample['ok']) {
                return $sample;
            }

            $components[] = ['type' => 'HEADER', 'format' => 'DOCUMENT', 'example' => ['header_handle' => [$sample['handle']]]];
        }

        $components[] = ['type' => 'BODY', 'text' => $body, 'example' => ['body_text' => [array_slice(self::EXAMPLES[$language], 0, self::variables($body))]]];

        return MetaWhatsAppClient::createTemplate($connection, [
            'name' => $name,
            'language' => $language,
            'category' => self::CATEGORY,
            'parameter_format' => 'POSITIONAL',
            'components' => $components,
        ]);
    }

    /** @return array{state: 'ok'|'wait'|'fail'|'unknown', code: ?string, params: array<string, string>} */
    private static function verdict(Business $business, WhatsAppConnection $connection): array
    {
        $required = self::required($business);
        $rows = WhatsAppTemplateMapping::where('scope_type', WhatsAppMode::OWNER_BUSINESS)->where('business_id', $business->id)
            ->where('enabled', true)->whereIn('event_type', $required)->get()->keyBy('event_type');

        $waiting = [];

        foreach ($required as $event) {
            $row = $rows->get($event);
            $status = (string) $row?->meta_status;
            $label = WhatsAppEvent::label($event);

            if ($status === WhatsAppTemplates::APPROVED) {
                $body = ((array) $row->body_by_language)[(string) $row->language_code] ?? null;

                // ‏‏قالبٌ أنشأه غيرُنا بالاسم نفسه ومتغيّراتٍ أخرى: ترده Meta عند كلّ رسالة
                if (is_string($body) && ! WhatsAppEvent::isRibbonBusiness((int) $business->id) && self::variables($body) !== self::expected($event)) {
                    return self::answer('fail', 'template_mismatch', ['template' => $label]);
                }

                continue;
            }

            if ($status === '' || $status === WhatsAppTemplates::MISSING) {
                $refused = Cache::get(self::refusedKey($connection, (string) ($row?->template_name ?? WhatsAppEvent::templateNameForBusiness((int) $business->id, $event)), (string) ($row?->language_code ?? 'ar')));

                if ($refused !== null) {
                    return self::answer('fail', 'template_refused', ['template' => $label, 'why' => (string) $refused]);
                }
            } elseif (! in_array($status, self::WAITING, true)) {
                return self::answer('fail', 'template_rejected', ['template' => $label]);
            }

            $waiting[] = $label;
        }

        return $waiting === [] ? self::answer('ok') : self::answer('wait', 'templates_pending', ['n' => (string) count($waiting), 'total' => (string) count($required)]);
    }

    /** ‏‏كم متغيّرًا يملأ المُرسِلُ لهذا الحدث */
    private static function expected(string $event): int
    {
        return isset(self::BODIES[$event]) ? self::variables(self::BODIES[$event]['ar']) : 2;
    }

    private static function variables(string $body): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m);

        return count(array_unique($m[1]));
    }

    /**
     * ‏‏نموذجُ فاتورةٍ تراه Meta عند المراجعة — صفحةٌ واحدة بلا اسم عميلٍ ولا متجر.
     *
     * يُكتب بحروفه هنا لا بمحرّك الطباعة: هذا يجري من الجدول، ومحرّكُ الطباعة يكتب خطوطَه على القرص
     * باسم من شغّله — فلا يترك ملفًّا لا تملكه صفحاتُ الموقع.
     */
    public static function samplePdf(): string
    {
        $text = 'BT /F1 24 Tf 190 700 Td (Invoice No. 1024) Tj 0 -40 Td (Total: 12.500 OMR) Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($text)." >>\nstream\n".$text."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $at = [];

        foreach ($objects as $i => $body) {
            $at[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($at as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private static function base(string $language): string
    {
        return strtolower(explode('_', str_replace('-', '_', $language))[0]);
    }

    private static function refusedKey(WhatsAppConnection $connection, string $name, string $language): string
    {
        return 'whatsapp.notice_template_refused.'.$connection->waba_id.'.'.$name.'.'.$language;
    }

    /**
     * @param  'ok'|'wait'|'fail'|'unknown'  $state
     * @param  array<string, string>  $params
     * @return array{state: 'ok'|'wait'|'fail'|'unknown', code: ?string, params: array<string, string>}
     */
    private static function answer(string $state, ?string $code = null, array $params = []): array
    {
        return ['state' => $state, 'code' => $code, 'params' => $params];
    }
}
