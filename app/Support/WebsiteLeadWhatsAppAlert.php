<?php

namespace App\Support;

use App\Jobs\SendWebsiteLeadWhatsAppAlert;
use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Models\Setting;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * تنبيهُ واتساب داخليّ لفريق أبعاد بكلِّ طلبٍ جديد من «تواصل معنا» في abaadapp.om.
 *
 * ═══ الدفترُ هو الأصل، وهذا جرس ═══
 *
 * الطلبُ يُحفظ في CRM ويُغلق عليه الـtransaction أوّلًا (`CrmWebsiteInquiries::receive`)،
 * ثمّ يُصفّ هذا التنبيه بعد الـcommit. فتنبيهٌ يسقط لا يمحو طلبًا، ولا يُقال
 * للزائر «فشل»، ولا تُمسّ الملاحظةُ ولا العميل.
 *
 * ═══ من أيّ رقم، وإلى أيّ رقم ═══
 *
 * من **خطّ مبيعات أبعاد** وحده (`purpose = crm_sales`) — لا من رقم الإشعارات
 * الذي يُرسل نيابةً عن المحلّات، ولا من رقم محلّ. وإلى رقمٍ داخليٍّ لفريق
 * أبعاد من ملفّ الخادم (`WEBSITE_LEAD_WHATSAPP_TO`)، لا من الكود. ولا يُرسَل
 * إلى الزائر شيء.
 *
 * ═══ وبقالبٍ معتمَد لا بنصٍّ حرّ ═══
 *
 * التنبيهُ يبدأ محادثةً، وميتا لا تقبل نصًّا حرًّا خارج نافذة الأربعِ والعشرين
 * ساعة. فلا يخرج إلّا بقالب `abaad_website_lead_alert` **بعد** أن تقول ميتا
 * إنّه `APPROVED` على حساب خطّ المبيعات — يُسأل عنه في `syncTemplate` كلَّ ساعة.
 * وقبلها لا نداءَ إلى ميتا، ويُكتب السببُ في السجلّ.
 *
 * ═══ ولا يمسّ التجّار ═══
 *
 * لا صفَّ في `whatsapp_messages` (ذاك دفترُ رسائل المحلّات تحت `business_id`)،
 * ولا حصّةَ تُحجز ولا رصيدَ يُخصم. وما يُسجَّل رقمُ العميل ورقمُ الملاحظة ورمزُ
 * السبب — لا اسمٌ ولا بريدٌ ولا رسالةٌ ولا هاتف.
 */
final class WebsiteLeadWhatsAppAlert
{
    /** اسمُ القالب عند ميتا — في حساب خطّ المبيعات */
    public const TEMPLATE = 'abaad_website_lead_alert';

    public const LANGUAGE = 'ar';

    /** حالُ القالب كما قالتها ميتا آخرَ مرّة — صفٌّ للمنصّة في `settings` */
    public const STATUS_SETTING = 'website_lead_alert_template';

    /** الرسالةُ في التنبيه سطرٌ مقصوص — كاملُها في CRM */
    public const MESSAGE_LIMIT = 300;

    public const SKIP_NO_RECIPIENT = 'recipient_not_configured';

    public const SKIP_BAD_RECIPIENT = 'recipient_invalid';

    public const SKIP_NO_LINE = 'sales_line_not_connected';

    public const SKIP_SELF = 'recipient_is_sender';

    public const SKIP_TEMPLATE = 'template_not_approved';

    /**
     * يُصفّ التنبيه — أو يُكتب لمَ لا يُصفّ. ولا يرمي أبدًا.
     *
     * يُنادى بعد أن أُغلق الـtransaction، وللطلب الجديد وحده (`note` غيرُ فارغة):
     * التكرارُ الحرفيّ لا ملاحظةَ له فلا تنبيهَ له.
     */
    public static function queue(CrmLead $lead, CrmNote $note): void
    {
        try {
            $ready = self::ready();

            if (isset($ready['skip'])) {
                self::skipped($lead->id, $note->id, $ready['skip']);

                return;
            }

            Bus::dispatch(new SendWebsiteLeadWhatsAppAlert($lead->id, $note->id));
        } catch (\Throwable $e) {
            Log::error('website lead alert: could not be queued', [
                'lead' => $lead->id, 'note' => $note->id, 'error' => class_basename($e),
            ]);
        }
    }

    /**
     * أيصلح الإرسال الآن؟ — الخطُّ والمستلمُ والقالب، أو سببُ المنع.
     *
     * يُسأل عند الصفّ وعند التنفيذ معًا: ما كان صالحًا قبل دقيقةٍ قد لا يكون.
     *
     * @return array{line: WhatsAppConnection, to: string}|array{skip: string}
     */
    public static function ready(): array
    {
        $raw = trim((string) config('contact.leads_whatsapp_to'));

        if ($raw === '') {
            return ['skip' => self::SKIP_NO_RECIPIENT];
        }

        $to = WhatsAppPhone::normalize($raw);

        if ($to === null) {
            return ['skip' => self::SKIP_BAD_RECIPIENT];
        }

        $line = self::line();

        if ($line === null) {
            return ['skip' => self::SKIP_NO_LINE];
        }

        // رقمٌ يُرسل إلى نفسه لا يُنبّه أحدًا
        if (WhatsAppPhone::normalize((string) $line->display_phone_number) === $to) {
            return ['skip' => self::SKIP_SELF];
        }

        if (self::templateStatus() !== WhatsAppTemplates::APPROVED) {
            return ['skip' => self::SKIP_TEMPLATE];
        }

        return ['line' => $line, 'to' => $to];
    }

    /**
     * خطُّ مبيعات أبعاد — وصلةُ منصّةٍ غرضُها المبيعات، صالحةٌ للإرسال.
     *
     * ولا احتياطَ إلى رقم الإشعارات ولو أُذن بمشاركته (`CrmWhatsApp::shared`):
     * ذاك رقمٌ يقرؤه زبائنُ المحلّات، وقوالبُه في حسابٍ تُعدّ قوالبُه لكلّ متجر.
     */
    public static function line(): ?WhatsAppConnection
    {
        $line = self::salesConnection();

        return $line && $line->isUsable() ? $line : null;
    }

    /** أحدثُ وصلة مبيعاتٍ للمنصّة — ولو لم تكن صالحة، لتُقرأ حالُها */
    private static function salesConnection(): ?WhatsAppConnection
    {
        return WhatsAppConnection::query()->platform()
            ->where('purpose', WhatsAppMode::PURPOSE_CRM_SALES)
            ->whereIn('status', [WhatsAppConnection::ACTIVE, WhatsAppConnection::REAUTH])
            ->orderByDesc('id')->first();
    }

    /**
     * أهذا رقمُ الفريق الذي يصله التنبيه؟
     *
     * يسأله `CrmWhatsApp::receive`: ردُّ موظّفٍ على تنبيهٍ وصله ليس عميلًا
     * محتمَلًا يُفتح له ملفّ.
     */
    public static function isInternalRecipient(string $phone): bool
    {
        $to = WhatsAppPhone::normalize(trim((string) config('contact.leads_whatsapp_to')));

        return $to !== null && WhatsAppPhone::normalize($phone) === $to;
    }

    /**
     * قيمُ متغيّرات القالب بترتيبها — أو `null` إن لم تُقرأ الملاحظة.
     *
     * {{1}} الاسم · {{2}} اسم النشاط · {{3}} الهاتف · {{4}} نوع النشاط ·
     * {{5}} الرسالة (سطرٌ مقصوص) · {{6}} رابطُ العميل في CRM.
     *
     * والبريدُ ولغةُ الصفحة لا يخرجان: لا يحتاجهما من يُنبَّه، وهما في CRM.
     * وكلُّ قيمةٍ سطرٌ واحدٌ غيرُ فارغ: ميتا تردّ المتغيّرَ الفارغ (١٣٢٠٠٠)
     * والمتغيّرَ ذا الأسطر أو المسافات المتتالية.
     *
     * @return list<string>|null
     */
    public static function variables(CrmLead $lead, CrmNote $note): ?array
    {
        // تسمياتُ النوع بالعربية — القالبُ عربيّ، ولغةُ العامل الافتراضيّة غيرُها
        $previous = app()->getLocale();
        app()->setLocale(self::LANGUAGE);

        try {
            $inquiry = CrmWebsiteInquiries::parse((string) $note->body);
        } finally {
            app()->setLocale($previous);
        }

        if ($inquiry === null) {
            return null;
        }

        $phone = filled($lead->phone) ? '+'.$lead->phone : (string) $inquiry['phone'];

        return [
            self::flat($inquiry['name'] ?? $lead->name, 150),
            self::flat($inquiry['businessName'] ?? $lead->business_name, 150),
            self::flat($phone, 40),
            self::flat($inquiry['activity'], 60),
            self::flat($inquiry['message'], self::MESSAGE_LIMIT),
            route('super-admin.crm.leads.show', $lead->id),
        ];
    }

    /** سطرٌ واحدٌ مقصوص، و«—» مكانَ الفراغ */
    private static function flat(?string $value, int $limit): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        if ($value === '') {
            return '—';
        }

        return mb_strlen($value) > $limit ? rtrim(mb_substr($value, 0, $limit - 1)).'…' : $value;
    }

    /** حالُ القالب آخرَ ما قالته ميتا — `null` إن لم يُسأل بعد */
    public static function templateStatus(): ?string
    {
        $saved = json_decode((string) Setting::whereNull('business_id')
            ->where('key', self::STATUS_SETTING)->value('value'), true);

        return is_array($saved) && filled($saved['status'] ?? null) ? (string) $saved['status'] : null;
    }

    /**
     * يسأل ميتا عن القالب في حساب خطّ المبيعات ويكتب ما قالته.
     *
     * قراءةٌ لا إرسال. ولا يُكتب شيءٌ إن تعذّر السؤال: انقطاعُ شبكةٍ ليس رفضًا،
     * والحالُ القديم أصدقُ من حالٍ يُخترع — كما في `WhatsAppTemplates::sync`.
     * ويُكتب في صفٍّ له لا في `whatsapp_template_mappings`: تلك قوالبُ إشعارات
     * المحلّات، وصفٌّ فيها `MISSING` يُحمِّر «قوالب رسائلك معتمَدة» عند كلّ متجر.
     *
     * @return array{ok: bool, status: ?string, reason: ?string}
     */
    public static function syncTemplate(): array
    {
        $line = self::line();

        if ($line === null) {
            return ['ok' => false, 'status' => null, 'reason' => self::SKIP_NO_LINE];
        }

        if (blank($line->waba_id)) {
            return ['ok' => false, 'status' => null, 'reason' => 'waba_id_missing'];
        }

        $result = MetaWhatsAppClient::templates($line);

        if (! $result['ok']) {
            return ['ok' => false, 'status' => null, 'reason' => 'meta_unreachable'];
        }

        $byLanguage = (array) ($result['templates'][self::TEMPLATE] ?? []);
        $status = (string) ($byLanguage[self::LANGUAGE] ?? $byLanguage['*'] ?? WhatsAppTemplates::MISSING);

        Setting::updateOrCreate(
            ['business_id' => null, 'key' => self::STATUS_SETTING],
            ['value' => json_encode([
                'status' => $status,
                'connection' => $line->id,
                'checked_at' => now()->toIso8601String(),
            ])],
        );

        return ['ok' => true, 'status' => $status, 'reason' => null];
    }

    /**
     * ما يُقرأ قبل التشغيل — نعم/لا، بلا رمزٍ ولا رقم.
     *
     * @return array<string, string>
     */
    public static function readiness(): array
    {
        $connection = self::salesConnection();
        $raw = trim((string) config('contact.leads_whatsapp_to'));
        $status = self::templateStatus();
        $ready = self::ready();

        return [
            'CRM sales line connected' => $connection ? 'yes' : 'no',
            'CRM sales line usable' => $connection?->isUsable() ? 'yes' : 'no',
            'WABA id available' => filled($connection?->waba_id) ? 'yes' : 'no',
            'Internal recipient configured' => $raw === '' ? 'no' : (WhatsAppPhone::normalize($raw) === null ? 'invalid' : 'yes'),
            'Template exists at Meta' => $status === null ? 'not-checked' : ($status === WhatsAppTemplates::MISSING ? 'no' : 'yes'),
            'Template status' => $status ?? 'not-checked',
            'Alert would send' => isset($ready['skip']) ? 'no ('.$ready['skip'].')' : 'yes',
        ];
    }

    /** سببُ عدم الإرسال — أرقامٌ داخليّةٌ ورمز، لا بياناتُ الزائر */
    public static function skipped(int $leadId, int $noteId, string $reason): void
    {
        Log::warning('website lead alert: not sent', ['lead' => $leadId, 'note' => $noteId, 'reason' => $reason]);
    }
}
