<?php

namespace App\Support;

use App\Mail\WebsiteLeadMail;
use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Models\CrmWebsiteInquiryRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * طلبُ «تواصل معنا» من الموقع التعريفيّ abaadapp.om — يُكتب في دفتر مبيعات أبعاد.
 *
 * ═══ الدفترُ هو الأصل، والبريدُ إشعار ═══
 *
 * يُحفظ الطلبُ أوّلًا في `crm_leads` و`crm_notes` ويُغلق عليه الـtransaction،
 * ثمّ يُصفّ إشعارُ البريد. فبريدٌ يسقط لا يمحو طلبًا، ولا يُقال للزائر «فشل»
 * عن طلبٍ وصلنا.
 *
 * ═══ ورقمٌ واحدٌ عميلٌ واحد — وكلُّ رسالةٍ تبقى ═══
 *
 * العميلُ يُوجد أو يُنشأ من `CrmLeads::findOrCreateByPhone` نفسِها، لا من
 * فحصٍ ثانٍ موازٍ. وكلُّ طلبٍ ملاحظةٌ مؤرَّخةٌ على ملفّه، كاتبُها «الموقع
 * الإلكتروني» بلا مستخدم: `crm_notes.user_id` يقبل الفراغ، فلا يُخترع حسابٌ وهميّ.
 *
 * وما في الملفّ لا يُستبدل بما في النموذج: الاسمُ واسمُ النشاط يُملآن إن كانا
 * فارغين وحدهما، والمصدرُ والمرحلةُ والإسنادُ لا تُمسّ.
 *
 * ═══ وما لا عمودَ له لا يُخترع له عمود ═══
 *
 * البريدُ ونوعُ النشاط والرسالةُ ولغةُ الصفحة لا أعمدةَ لها في `crm_leads`،
 * فتُكتب في الملاحظة نفسِها — وهي ما يقرؤه موظّفُ المبيعات في ملفّ العميل.
 */
final class CrmWebsiteInquiries
{
    /** كاتبُ الملاحظة — نصٌّ ثابت لا يتبع لغةَ الطلب، فيُقارَن به التكرار */
    public const AUTHOR = 'الموقع الإلكتروني';

    /** أنواعُ النشاط في نموذج الموقع — المفتاحُ ما يُرسَل، والتسميةُ ما يُقرأ */
    public const ACTIVITY_TYPES = [
        'retail' => 'التجزئة',
        'ecommerce' => 'التجارة الإلكترونية',
        'services' => 'الخدمات والحجوزات',
        'subscriptions' => 'الاشتراكات',
        'other' => 'أخرى',
    ];

    public const LOCALES = ['ar' => 'العربية', 'en' => 'الإنجليزية'];

    /**
     * الطلبُ نفسُه بحروفه في هذه النافذة لا يُكتب ثانيةً ولا يُرسَل بريدُه.
     *
     * إعادةُ محاولةٍ بعد انقطاعٍ وصل فيه الطلبُ الأوّل — لا رسالةٌ جديدة. وأيُّ
     * حرفٍ مختلف رسالةٌ جديدةٌ تُكتب ويُرسل إشعارُها.
     */
    private const REPEAT_WINDOW_MINUTES = 10;

    /**
     * @param  array{name:string,business_name:string,phone:string,email:string,activity_type:string,message:string,locale:string}  $data  ما تحقّق منه المتحكّم
     * @return array{lead: CrmLead, note: ?CrmNote, created: bool} و`note` فارغةٌ للتكرار الحرفيّ
     */
    public static function receive(array $data): array
    {
        $data = self::clean($data);
        $body = self::body($data);

        return DB::transaction(function () use ($data, $body) {
            $found = CrmLeads::findOrCreateByPhone(
                $data['phone'],
                Crm::SOURCE_WEBSITE,
                $data['name'],
                ['business_name' => $data['business_name']],
            );
            $lead = $found['lead'];

            $repeated = CrmNote::where('lead_id', $lead->id)
                ->whereNull('user_id')
                ->where('user_name', self::AUTHOR)
                ->where('created_at', '>=', now()->subMinutes(self::REPEAT_WINDOW_MINUTES))
                ->where('body', $body)
                ->exists();

            if ($repeated) {
                return ['lead' => $lead, 'note' => null, 'created' => false];
            }

            if (! $found['created']) {
                $lead->forceFill([
                    'name' => filled($lead->name) ? $lead->name : $data['name'],
                    'business_name' => filled($lead->business_name) ? $lead->business_name : $data['business_name'],
                    // تواصلٌ واردٌ جديد — كما تكتبه رسالةُ واتساب الواردة
                    'last_contact_at' => now(),
                ])->save();
            }

            $note = CrmNote::create([
                'lead_id' => $lead->id,
                'user_id' => null,
                'user_name' => self::AUTHOR,
                'body' => $body,
            ]);

            // سطرُ المعاينة في القائمة — نسخةٌ للعرض، والأصلُ الملاحظة
            $lead->forceFill(['notes_summary' => mb_substr($data['message'], 0, 300)])->save();

            Activity::log('updated', 'وصل طلب تواصل من الموقع الإلكتروني للعميل المحتمل '.$lead->displayName());

            return ['lead' => $lead, 'note' => $note, 'created' => $found['created']];
        });
    }

    /**
     * إشعارُ بريد أبعاد — بعد أن حُفظ الطلب، ولا يُسقطه شيء.
     *
     * لا يُرسَل حين لا يُوصل البريدُ شيئًا (`log` يكتب الرسالةَ كاملةً في سجلّ
     * الخادم، ومعها بياناتُ الزائر) أو حين لا مستلم. ويُصفّ في الطابور: عاملُه
     * يعيد المحاولة، والزائرُ لا ينتظر خادمَ SMTP. وما يُسجَّل عن الفشل رقمُ
     * العميل ونوعُ الخطأ — لا اسمٌ ولا بريدٌ ولا رسالة.
     *
     * @param  array{name:string,business_name:string,phone:string,email:string,activity_type:string,message:string,locale:string}  $data
     */
    public static function notify(CrmLead $lead, CrmNote $note, array $data): void
    {
        $to = trim((string) config('contact.leads_to'));

        if ($to === '' || ! Mailer::configured()) {
            Log::warning('website contact: notification not sent, mail is not configured', ['lead' => $lead->id]);

            return;
        }

        $data = self::clean($data);

        try {
            Mail::to($to)->queue(new WebsiteLeadMail($lead->id, [
                'name' => $data['name'],
                'business_name' => $data['business_name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'activity' => self::ACTIVITY_TYPES[$data['activity_type']],
                'message' => $data['message'],
                'locale' => self::LOCALES[$data['locale']],
                'received_at' => $note->created_at->format('Y-m-d H:i'),
                'source' => Crm::sourceLabel(Crm::SOURCE_WEBSITE),
                'lead_url' => route('super-admin.crm.leads.show', $lead->id),
            ]));
        } catch (\Throwable $e) {
            Log::error('website contact: notification could not be queued', [
                'lead' => $lead->id, 'error' => class_basename($e),
            ]);
        }
    }

    /**
     * سطرٌ واحدٌ للحقول القصيرة — لا أسطرَ تُدسّ في عنوان بريدٍ أو ملاحظة.
     *
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    private static function clean(array $data): array
    {
        foreach (['name', 'business_name', 'phone', 'email'] as $field) {
            $data[$field] = trim((string) preg_replace('/\s+/u', ' ', (string) $data[$field]));
        }

        $data['message'] = trim(str_replace(["\r\n", "\r"], "\n", (string) $data['message']));

        return $data;
    }

    /*
     * ═══ شكلُ الملاحظة — يكتبه `body` ويقرؤه `parse`، من هذه الثوابت وحدها ═══
     *
     * سطرُ العنوان، ثمّ «تسمية: قيمة» لكلّ حقلٍ قصير، ثمّ «الرسالة:» وما بعدها
     * كلُّه الرسالة. وتغييرُ تسميةٍ هنا يغيّر الكتابةَ والقراءةَ معًا فلا يفترقان.
     */
    public const HEADER = 'طلب تواصل من الموقع الإلكتروني';

    /** @var array<string, string> الحقلُ ← تسميتُه في الملاحظة، بترتيبها */
    private const LABELS = [
        'name' => 'الاسم',
        'business_name' => 'اسم النشاط',
        'phone' => 'الهاتف',
        'email' => 'البريد',
        'activity' => 'نوع النشاط',
        'locale' => 'لغة الصفحة',
    ];

    private const MESSAGE_LABEL = 'الرسالة:';

    /** نصُّ الملاحظة — كلُّ ما كتبه الزائر، بأسماءٍ يقرؤها موظّف المبيعات */
    private static function body(array $data): string
    {
        $values = [
            'name' => $data['name'],
            'business_name' => $data['business_name'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'activity' => self::ACTIVITY_TYPES[$data['activity_type']],
            'locale' => self::LOCALES[$data['locale']],
        ];

        $lines = [self::HEADER];
        foreach (self::LABELS as $field => $label) {
            $lines[] = $label.': '.$values[$field];
        }

        return implode("\n", [...$lines, self::MESSAGE_LABEL, $data['message']]);
    }

    /* ═══════════════════ القراءة: طلباتُ الموقع في ملفّ العميل ═══════════════════ */

    /**
     * طلباتُ الموقع على هذا العميل — الأحدثُ أوّلًا، وكلُّها لا آخرُها.
     *
     * تُعرف الملاحظةُ بكاتبها لا بنصّها: `user_id` فارغ و`user_name` هو
     * «الموقع الإلكتروني». ملاحظةُ الموظّف لها مستخدمٌ دائمًا، فلا تُقرأ طلبًا
     * ولو بدأت بالسطر نفسِه. وملاحظةٌ آليّةٌ لا تُقرأ تُترك — لا تُسقط الصفحة.
     *
     * @return list<array{id:int,name:?string,businessName:?string,phone:?string,email:?string,activity:?string,locale:?string,message:?string,receivedAt:?string}>
     */
    public static function forLead(CrmLead $lead): array
    {
        return $lead->notes()
            ->whereNull('user_id')
            ->where('user_name', self::AUTHOR)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(function (CrmNote $note) {
                $fields = self::parse((string) $note->body);

                return $fields === null ? null : [
                    'id' => $note->id,
                    ...$fields,
                    'receivedAt' => optional($note->created_at)->format('Y-m-d H:i'),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * ملاحظةُ طلبٍ ← حقولُه — للشكل الذي يكتبه `body` وحده.
     *
     * بلا سطر العنوان: ليست طلبَ موقع (`null`). وما سواه يُقرأ بما وُجد:
     * تسميةٌ غائبة حقلُها `null` لا تخمين، والرسالةُ كلُّ ما بعد «الرسالة:»
     * بأسطرها — فسطرٌ فيها يشبه «الاسم: …» يبقى من الرسالة. ونوعُ النشاط ولغةُ
     * الصفحة محفوظان بتسميتهما العربيّة، فيُترجمان للشاشة كما هما.
     *
     * @return array{name:?string,businessName:?string,phone:?string,email:?string,activity:?string,locale:?string,message:?string}|null
     */
    public static function parse(string $body): ?array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));

        if (trim($lines[0] ?? '') !== self::HEADER) {
            return null;
        }

        $found = array_fill_keys(array_keys(self::LABELS), null);
        $message = null;
        $byLabel = array_flip(self::LABELS);

        for ($i = 1; $i < count($lines); $i++) {
            if (trim($lines[$i]) === self::MESSAGE_LABEL) {
                $message = trim(implode("\n", array_slice($lines, $i + 1)));
                break;
            }

            [$label, $value] = array_pad(explode(': ', $lines[$i], 2), 2, null);
            $field = $byLabel[trim((string) $label)] ?? null;

            if ($field !== null && $found[$field] === null && $value !== null && trim($value) !== '') {
                $found[$field] = trim($value);
            }
        }

        foreach (['activity', 'locale'] as $field) {
            if ($found[$field] !== null) {
                $found[$field] = __($found[$field]);
            }
        }

        return [
            'name' => $found['name'],
            'businessName' => $found['business_name'],
            'phone' => $found['phone'],
            'email' => $found['email'],
            'activity' => $found['activity'],
            'locale' => $found['locale'],
            'message' => $message === '' ? null : $message,
        ];
    }

    /* ═══════════════════ غيرُ المقروء — لكلّ قارئٍ وحدَه ═══════════════════ */

    /**
     * إعدادُ المنصّة الذي كتبه ترحيلُ الميزة: أعلى رقم ملاحظةٍ ساعتَها.
     *
     * وما كان عنده أو قبله مقروءٌ للجميع — الشارةُ تبدأ بما يصل بعد النشر.
     */
    public const UNREAD_AFTER_KEY = 'crm_website_inquiries_unread_after_note_id';

    public static function unreadAfter(): int
    {
        return (int) DB::table('settings')->whereNull('business_id')
            ->where('key', self::UNREAD_AFTER_KEY)->value('value');
    }

    /**
     * ملاحظاتُ طلبات الموقع التي تُعدّ — بكاتبها وسطر عنوانها، بلا قراءة نصّها كلِّه.
     *
     * الكاتبُ نفسُه الذي يكتب به `receive` ويقرأ به `forLead`، وسطرُ العنوان
     * نفسُه الذي يشترطه `parse`. فملاحظةُ موظّفٍ أو ملاحظةٌ آليّةٌ أخرى لا
     * تُعدّ طلبًا، ولا ملاحظةٌ قديمةٌ بلا عنوان. ولا يُقرأ ما قبل الميزة.
     *
     * @return Builder<CrmNote>
     */
    public static function inquiryNotes(): Builder
    {
        return CrmNote::query()
            ->whereNull('crm_notes.user_id')
            ->where('crm_notes.user_name', self::AUTHOR)
            ->where('crm_notes.body', 'like', self::HEADER.'%')
            ->where('crm_notes.id', '>', self::unreadAfter());
    }

    /**
     * شارةُ «العملاء المحتملون» — كم عميلًا له طلبُ موقعٍ لم يقرأه هذا الموظّف.
     *
     * عددُ العملاء لا عددُ الطلبات: من كتب ثلاثًا ولم يُفتح ملفُّه واحدٌ ينتظر،
     * كما تعدّ `CrmWhatsApp::badge` المحادثاتِ لا الرسائل. وأخوها لا هي: تلك
     * لواتساب في `crm_reads`، وهذه لطلبات الموقع في `crm_website_inquiry_reads`.
     */
    public static function badge(User $user): int
    {
        if (! $user->isSuperAdmin()) {
            return 0;
        }

        return self::inquiryNotes()
            ->whereRaw(
                'crm_notes.id > coalesce((select r.last_read_note_id from crm_website_inquiry_reads r
                    where r.lead_id = crm_notes.lead_id and r.user_id = ?), 0)',
                [$user->id],
            )
            ->distinct()
            ->count('crm_notes.lead_id');
    }

    /**
     * أيُّ عملاء هذه الصفحة لهم طلبُ موقعٍ لم يقرأه هذا الموظّف — باستعلامين لا بعدد الصفوف.
     *
     * @param  list<int>  $leadIds
     * @return array<int, bool>
     */
    public static function unreadMap(array $leadIds, User $user): array
    {
        if ($leadIds === []) {
            return [];
        }

        $latest = self::inquiryNotes()
            ->whereIn('crm_notes.lead_id', $leadIds)
            ->groupBy('crm_notes.lead_id')
            ->selectRaw('crm_notes.lead_id as lead_id, max(crm_notes.id) as latest')
            ->pluck('latest', 'lead_id');

        $read = CrmWebsiteInquiryRead::where('user_id', $user->id)
            ->whereIn('lead_id', $leadIds)
            ->pluck('last_read_note_id', 'lead_id');

        $out = array_fill_keys($leadIds, false);

        foreach ($latest as $leadId => $noteId) {
            $out[(int) $leadId] = (int) $noteId > (int) ($read[$leadId] ?? 0);
        }

        return $out;
    }

    /**
     * فُتح ملفُّ العميل: طلباتُه حتّى أحدثِها **الآن** مقروءةٌ لهذا الموظّف وحدَه.
     *
     * المؤشّرُ أحدثُ طلبٍ موجودٍ لحظةَ الفتح — فما يصل بعدها غيرُ مقروء. ولا
     * يتراجع المؤشّر، ولا يُكتب صفٌّ لعميلٍ بلا طلب موقع. ولا يمسّ العميلَ ولا
     * الملاحظةَ ولا قراءةَ واتساب.
     */
    public static function markRead(CrmLead $lead, User $user): void
    {
        $latest = (int) self::inquiryNotes()->where('crm_notes.lead_id', $lead->id)->max('crm_notes.id');

        if ($latest === 0) {
            return;
        }

        $current = (int) CrmWebsiteInquiryRead::where('user_id', $user->id)
            ->where('lead_id', $lead->id)->value('last_read_note_id');

        if ($latest <= $current) {
            return;
        }

        // و`upsert` لا `updateOrCreate`: فتحتان متزامنتان لا تتصادمان على الفهرس الفريد
        CrmWebsiteInquiryRead::upsert(
            [[
                'user_id' => $user->id,
                'lead_id' => $lead->id,
                'last_read_note_id' => $latest,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id', 'lead_id'],
            ['last_read_note_id', 'updated_at'],
        );
    }
}
