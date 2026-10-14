<?php

namespace App\Support;

use App\Mail\WebsiteLeadMail;
use App\Models\CrmLead;
use App\Models\CrmNote;
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

    /** نصُّ الملاحظة — كلُّ ما كتبه الزائر، بأسماءٍ يقرؤها موظّف المبيعات */
    private static function body(array $data): string
    {
        return implode("\n", [
            'طلب تواصل من الموقع الإلكتروني',
            'الاسم: '.$data['name'],
            'اسم النشاط: '.$data['business_name'],
            'الهاتف: '.$data['phone'],
            'البريد: '.$data['email'],
            'نوع النشاط: '.self::ACTIVITY_TYPES[$data['activity_type']],
            'لغة الصفحة: '.self::LOCALES[$data['locale']],
            'الرسالة:',
            $data['message'],
        ]);
    }
}
