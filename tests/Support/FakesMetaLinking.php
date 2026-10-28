<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * ميتا كما تجيب عن رمزٍ صادرٍ من تطبيق أبعاد — لاختبارات الربط.
 *
 * كلُّ طريقٍ للربط يمرّ اليوم بـ`WhatsAppLinkCheck`: `debug_token` يقول إنّ
 * مُصدرَ الرمز تطبيقُ أبعاد بصلاحياته ولحسابٍ واحد، وأرقامُ ذلك الحساب
 * تُقرأ، والاشتراكُ يُقرأ بعد أن يُنادى. فهذه الأنماطُ تُدمج أمام ما يكتبه
 * كلُّ اختبارٍ لنفسه (`$own + $this->abaadAppMeta(...)`): `Http::fake`
 * تأخذ أوّلَ نمطٍ ينطبق.
 */
trait FakesMetaLinking
{
    /** أشُرك تطبيقُنا في إشعارات الحساب؟ — يتبدّل بـ`POST` كما عند ميتا */
    protected bool $metaSubscribed = false;

    /**
     * @param  array<string, list<string>>  $accounts  كلُّ حسابٍ مُنحه الرمزُ وأرقامُه (`phone_number_id`)
     * @return array<string, mixed>
     */
    protected function abaadAppMeta(array $accounts = ['WABA-1' => ['PN-1']], ?string $appId = null): array
    {
        if (blank(config('whatsapp.app_id'))) {
            config(['whatsapp.app_id' => '1082481610822941']);
        }

        if (blank(config('whatsapp.app_secret'))) {
            config(['whatsapp.app_secret' => 'test-secret-not-a-real-one']);
        }

        $appId ??= (string) config('whatsapp.app_id');
        $wabas = array_keys($accounts);

        /*
         * ولا نداءَ يخرج إلى ميتا الحقيقيّة من اختبار.
         *
         * طلبٌ لم يُزيَّف يُرمى (`StrayRequestException`) فيقرؤه العميلُ «لم
         * نصل ميتا» — بدل أن يصل `graph.facebook.com` برمزٍ مزيّف.
         */
        Http::preventStrayRequests();

        $patterns = [
            '*/debug_token*' => Http::response(['data' => [
                'is_valid' => true,
                'app_id' => $appId,
                'expires_at' => 0,
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_management', 'target_ids' => $wabas],
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => $wabas],
                ],
            ]], 200),
            '*fields=is_on_biz_app*' => Http::response(['is_on_biz_app' => false, 'platform_type' => 'CLOUD_API'], 200),
            // ولا توجيهَ محادثاتٍ على الرقم — كلُّ مستجيبٍ متّصلٍ يصله كلُّ وارد (ردُّ ميتا الموثَّق)
            '*/routing_roles*' => Http::response(['error' => ['code' => 100, 'message' => 'Conversation routing is not enabled for this phone number.']], 400),
        ];

        foreach ($accounts as $waba => $numbers) {
            $patterns["*/{$waba}/phone_numbers*"] = Http::response(['data' => array_map(fn (string $id) => [
                'id' => $id, 'display_phone_number' => '+968 9000 0000', 'platform_type' => 'CLOUD_API',
            ], $numbers)], 200);

            $patterns["*/{$waba}/subscribed_apps"] = function (Request $request) use ($appId) {
                if ($request->method() === 'POST') {
                    $this->metaSubscribed = true;

                    return Http::response(['success' => true], 200);
                }

                // ميتا تُشرك مُصدرَ الرمز — لا التطبيقَ الذي نريده
                return Http::response(['data' => $this->metaSubscribed
                    ? [['whatsapp_business_api_data' => ['id' => $appId, 'name' => 'Messaging']]]
                    : []], 200);
            };
        }

        return $patterns;
    }
}
