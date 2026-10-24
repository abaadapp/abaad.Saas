<?php

namespace Tests\Support;

use App\Models\PlatformDocumentTemplate;

/**
 * «عقد اشتراك أبعاد» كما تزرعه الهجرة.
 *
 * القالبُ الأوّل تكتبه الهجرة نفسُها، لكنّ اختبارًا بـ DatabaseTruncation يفرّغ
 * كلَّ الجداول بعده، وما يليه في القسم نفسه لا يُعيد الهجرة — فيجد الجدولَ
 * فارغًا. فيُزرع هنا من بنود الهجرة نفسها إن غاب، لا من نسخةٍ تُكتب باليد.
 */
trait AbaadSubscriptionContract
{
    protected function subscriptionContract(): PlatformDocumentTemplate
    {
        $migration = require database_path('migrations/2026_10_26_090000_abaad_sends_its_clients_documents_to_sign.php');

        return PlatformDocumentTemplate::firstOrCreate(['name' => 'عقد اشتراك أبعاد'], [
            'service' => 'اشتراك النظام',
            'title' => 'عقد اشتراك',
            'title_en' => 'Subscription Agreement',
            'terms' => $migration::SUBSCRIPTION_TERMS,
            'includes_plan' => true,
            'includes_amount' => false,
            'active' => true,
        ]);
    }
}
