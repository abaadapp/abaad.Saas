<?php

namespace Tests\Support;

use App\Models\PlatformDocumentTemplate;
use App\Models\Setting;

/**
 * «عقد اشتراك أبعاد» كما تزرعه الهجرة — وبيانُ مقدّم الخدمة.
 *
 * القالبُ الأوّل تكتبه الهجرة نفسُها، لكنّ اختبارًا بـ DatabaseTruncation يفرّغ
 * كلَّ الجداول بعده، وما يليه في القسم نفسه لا يُعيد الهجرة — فيجد الجدولَ
 * فارغًا. فيُزرع هنا من بنود الهجرة نفسها إن غاب، لا من نسخةٍ تُكتب باليد.
 *
 * وهجرةُ النماذج وسمته «قائمٌ قبل سياسة الاعتماد» ببصمة محتواه — فيُوسم هنا
 * كذلك إن زُرع من جديد، ليبقى صفُّه كما على الإنتاج (لا يقرؤه الإصدار).
 */
trait AbaadSubscriptionContract
{
    protected function subscriptionContract(): PlatformDocumentTemplate
    {
        $migration = require database_path('migrations/2026_10_26_090000_abaad_sends_its_clients_documents_to_sign.php');

        $template = PlatformDocumentTemplate::firstOrCreate(['name' => 'عقد اشتراك أبعاد'], [
            'service' => 'اشتراك النظام',
            'title' => 'عقد اشتراك',
            'title_en' => 'Subscription Agreement',
            'terms' => $migration::SUBSCRIPTION_TERMS,
            'includes_plan' => true,
            'includes_amount' => false,
            'active' => true,
        ]);
        if ($template->approved_hash === null) {
            $template->forceFill([
                'approved_hash' => $template->contentHash(),
                'approval_basis' => PlatformDocumentTemplate::LEGACY,
                'approved_at' => now(),
            ])->save();
        }

        return $template;
    }

    /**
     * طلبُ الإصدار كما ترسله نافذتُه بعد المعاينة: ومعه بصمةُ الإصدار الذي عاينه المدير
     * فأكّده — وما لم يُذكر قالبُه بقي بلا تأكيد.
     */
    protected function confirmed(array $data): array
    {
        $template = PlatformDocumentTemplate::find($data['template_id'] ?? null);

        return $data + ($template ? ['template_hash' => $template->contentHash()] : []);
    }

    /**
     * مقدّمُ الخدمة ببياناته القانونيّة كاملة — بياناتُ اختبارٍ لا سجلّ حقيقيّ.
     *
     * والاسمُ كما أعطاه المالك؛ السجلُّ والعنوانُ رمزان ظاهرا الاصطناع، فلا
     * يُظنّان بياناتٍ لأبعاد.
     */
    protected function completeProvider(): array
    {
        $values = [
            'legal_name' => 'مشاريع الذهلي الجديدة',
            'legal_trade_name' => 'أبعاد',
            'legal_cr' => 'TEST-CR-0001',
            'legal_address' => 'عنوان اختبار — ليس عنوانًا حقيقيًّا',
        ];
        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
        }

        return $values;
    }
}
