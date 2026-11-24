<?php

namespace App\Support;

use App\Models\Business;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Cache;

/**
 * ‏‏أجاهزٌ هذا الرقمُ ليصير رقمَ إرسال متجره؟ — بأدلّةٍ من Meta، لا بأنّه «رُبط».
 *
 * ستُّ مراحل، كلٌّ بحالها:
 *
 *   ١ · الربط: الرمزُ من تطبيق أبعاد وصالحٌ، والرقمُ من حساب المتجر.
 *   ٢ · الرسوم: حسابُ واتساب ليس لأبعاد — فلا تُحتسب رسائلُ المتجر عليها.
 *   ٣ · الإرسال: الرقمُ متّصلٌ عند Meta ولا تمنعه.
 *   ٤ · الاستقبال: تطبيقُ أبعاد مشتركٌ في إشعارات الحساب، وعنوانُها عنوانُنا، والواردُ لا يذهب إلى غيره.
 *   ٥ · القوالب: إشعاراتُ المتجر معتمَدةٌ في حسابه هو.
 *   ٦ · الطابور: لا رسالةَ له في الطريق من رقم أبعاد — فلا تضيع بالتبديل ولا تتكرّر.
 *
 * وما لم يُقرأ «لا نعرف» — لا نجاحٌ ولا فشل. ولا يُبدَّل الإرسالُ إلّا وكلُّها تمّت.
 */
final class WhatsAppSenderReadiness
{
    public const LINK = 'link';

    public const BILLING = 'billing';

    public const SEND = 'send';

    public const RECEIVE = 'receive';

    public const TEMPLATES = 'templates';

    public const QUEUE = 'queue';

    public const STEPS = [self::LINK, self::BILLING, self::SEND, self::RECEIVE, self::TEMPLATES, self::QUEUE];

    /** ‏‏ورسالةٌ بقيت في الطابور أطول من هذا عالقة — لا يُنتظر بسببها */
    public const DRAIN_MINUTES = 30;

    /** ‏‏علّةٌ في الرمز لا يزيلها إلّا إعادةُ الربط — يُحكم بها من أوّل قراءة */
    private const SURE = [WhatsAppLinkCheck::INVALID_TOKEN, WhatsAppLinkCheck::WRONG_APP, WhatsAppLinkCheck::MISSING_SCOPES, WhatsAppLinkCheck::WABA_NOT_GRANTED];

    private const BILLING_HOURS = 24;

    /** ‏‏رمزُ Meta لمشكلةٍ في وسيلة الدفع — تقوله على حساب واتساب في `health_status` */
    private const META_PAYMENT = '141006';

    /**
     * @return array{ok: bool, code: ?string, checks: list<array{key: string, state: string, code: ?string, params: array<string, string>}>}
     */
    public static function check(Business $business, WhatsAppConnection $connection): array
    {
        $gate = self::gate($business) ?? ($connection->isUsable() ? null : 'link_'.WhatsAppLink::state($connection));

        if ($gate !== null) {
            return self::sum([self::LINK => self::step('fail', $gate)]);
        }

        $found = WhatsAppLinkCheck::inspect($connection);
        $link = self::link($found);

        // ‏‏وما يكتبه الفحصُ في أعمدته يُكتب لحكمٍ قاطعٍ وحده — قراءةُ أرقامٍ فشلت ليست «رقمٌ ليس في حسابه»
        if ($found['problem'] === null || in_array($found['problem'], self::SURE, true)) {
            WhatsAppLinkCheck::record($connection, $found);
        }

        if ($link['state'] !== 'ok') {
            return self::sum([self::LINK => $link]);
        }

        $billing = self::billing($connection);

        // ‏‏وحسابٌ لأبعاد لا يُنشأ فيه شيءٌ ولا يُشرَك — رُبط خطأً
        if ($billing['code'] === 'billed_to_abaad') {
            return self::sum([self::LINK => $link, self::BILLING => $billing]);
        }

        return self::sum([
            self::LINK => $link,
            self::BILLING => $billing,
            self::SEND => self::sends($connection),
            self::RECEIVE => self::receives($connection, (string) $found['webhook'], $found['routing'], heal: true),
            self::TEMPLATES => self::templates($business, $connection),
            self::QUEUE => self::queue($business),
        ]);
    }

    /**
     * ‏‏أما زال يعمل؟ — لرقمٍ صار رقمَ الإرسال. قراءةٌ لا غير، ولا يُصلح شيئًا عند Meta.
     *
     * @return array{state: 'ok'|'fail'|'unknown', code: ?string, params: array<string, string>, sure: bool}
     */
    public static function health(WhatsAppConnection $connection): array
    {
        $found = WhatsAppLinkCheck::inspect($connection);

        if ($found['problem'] === null || in_array($found['problem'], self::SURE, true)) {
            WhatsAppLinkCheck::record($connection, $found);
        }

        $link = self::link($found);

        if ($link['state'] !== 'ok') {
            return ['state' => $link['state'] === 'unknown' ? 'unknown' : 'fail', 'code' => $link['code'], 'params' => $link['params'],
                'sure' => in_array($found['problem'], self::SURE, true)];
        }

        foreach ([self::sends($connection), self::receives($connection, (string) $found['webhook'], $found['routing'])] as $step) {
            if ($step['state'] === 'fail') {
                return ['state' => 'fail', 'code' => $step['code'], 'params' => $step['params'], 'sure' => false];
            }
        }

        return ['state' => 'ok', 'code' => null, 'params' => [], 'sure' => false];
    }

    /** ‏‏ما يمنع المتجر أصلًا — من عندنا لا من Meta */
    public static function gate(Business $business): ?string
    {
        return match (true) {
            in_array((string) $business->status, ['معطل', 'معطّل', Business::AWAITING_PAYMENT], true) || Tenancy::locked($business) => 'shop_suspended',
            ! WhatsAppFeature::globallyEnabled() => 'platform_off',
            ! $business->whatsapp_enabled => 'account_off',
            ! WhatsAppFeature::servicePurchased($business) => 'plan',
            ! WhatsAppFeature::canUseOwnNumber($business) => 'own_not_allowed',
            default => null,
        };
    }

    /** ‏‏رسالةٌ قُرّرت على رقم أبعاد ولم تخرج بعد — تُترك لتخرج قبل التبديل */
    public static function draining(int $businessId): bool
    {
        return WhatsAppMessage::where('business_id', $businessId)
            ->where('direction', 'outbound')
            ->where('source_mode', WhatsAppMode::ABAAD_SHARED)
            ->where('status', WhatsAppStatus::QUEUED)
            ->where('created_at', '>', now()->subMinutes(self::DRAIN_MINUTES))
            ->exists();
    }

    /**
     * @param  array{problem: ?string, skipped: bool, ...}  $found
     * @return array{state: string, code: ?string, params: array<string, string>}
     */
    private static function link(array $found): array
    {
        return match (true) {
            $found['skipped'] => self::step('fail', 'link_'.WhatsAppLink::CONNECTING),
            $found['problem'] === WhatsAppLinkCheck::UNVERIFIABLE => self::step('unknown', 'unreachable'),
            // ‏‏والقائمةُ التي لم تُقرأ والرقمُ الذي ليس فيها يصلان بعلّةٍ واحدة — فلا يُجزم بأيّهما
            $found['problem'] === WhatsAppLinkCheck::NUMBER_NOT_IN_WABA => self::step('wait', (string) $found['problem']),
            $found['problem'] !== null => self::step('fail', (string) $found['problem']),
            default => self::step('ok'),
        };
    }

    /** @return array{state: string, code: ?string, params: array<string, string>} */
    private static function billing(WhatsAppConnection $connection): array
    {
        $key = 'whatsapp.sender_billing_ok.'.$connection->waba_id;

        if (Cache::get($key) === true) {
            return self::step('ok');
        }

        if (WhatsAppOwnApp::abaadBusinessIds() === []) {
            return self::step('unknown', 'abaad_account_unknown');
        }

        $read = WhatsAppOwnApp::billing((string) $connection->waba_id, (string) $connection->access_token);

        $step = match (true) {
            $read['state'] === WhatsAppOwnApp::BILLING_ABAAD => self::step('fail', 'billed_to_abaad'),
            blank($read['owner_id']) => self::step('unknown', 'owner_unknown'),
            $read['facts']['funding'] === 'absent' => self::step('fail', 'no_payment_method'),
            default => self::step('ok'),
        };

        if ($step['state'] === 'ok') {
            Cache::put($key, true, now()->addHours(self::BILLING_HOURS));
        }

        return $step;
    }

    /** @return array{state: string, code: ?string, params: array<string, string>} */
    private static function sends(WhatsAppConnection $connection): array
    {
        $n = WhatsAppEmbeddedSignup::numberState((string) $connection->phone_number_id, (string) $connection->access_token);

        return match (true) {
            ! $n['ok'] => self::step('unknown', 'unreachable'),
            $n['status'] !== null && $n['status'] !== 'CONNECTED' => self::step('fail', 'number_off', ['status' => $n['status']]),
            $n['can_send'] === 'BLOCKED' => self::step('fail', $n['code'] === self::META_PAYMENT ? 'number_payment' : 'number_blocked', ['why' => (string) $n['why']]),
            // ‏‏ولا دليلَ على أنّه يُرسل — لا يُعدّ جاهزًا بالسكوت
            $n['status'] === null && $n['can_send'] === null => self::step('unknown', 'number_unknown'),
            default => self::step('ok'),
        };
    }

    /** @return array{state: string, code: ?string, params: array<string, string>} */
    private static function receives(WhatsAppConnection $connection, string $webhook, ?string $routing, bool $heal = false): array
    {
        // ‏‏وتطبيقٌ غاب عن اشتراك الحساب يُشرَك — بالباب نفسه الذي يُشركه عند الربط
        if ($heal && $webhook === WhatsAppLinkCheck::NOT_SUBSCRIBED) {
            $webhook = WhatsAppLinkCheck::subscribe((string) $connection->waba_id, (string) $connection->access_token)['state'];
            $connection->forceFill(['webhook_state' => $webhook === WhatsAppLinkCheck::UNKNOWN ? $connection->webhook_state : $webhook, 'webhook_checked_at' => now()])->save();
        }

        return match (true) {
            $webhook === WhatsAppLinkCheck::NOT_SUBSCRIBED => self::step('fail', 'webhook_missing'),
            $webhook === WhatsAppLinkCheck::OVERRIDE => self::step('fail', 'webhook_elsewhere'),
            $webhook !== WhatsAppLinkCheck::SUBSCRIBED => self::step('unknown', 'unreachable'),
            $routing === WhatsAppLinkCheck::ROUTING_ELSEWHERE => self::step('fail', 'routed_away'),
            default => self::listening(),
        };
    }

    /** ‏‏وتطبيقُ أبعاد نفسُه يستمع — واحدٌ لكلّ المتاجر، فيُقرأ مرّةً كلَّ عشر دقائق */
    private static function listening(): array
    {
        $key = 'whatsapp.sender_app_listening';

        if (Cache::get($key) === true) {
            return self::step('ok');
        }

        $state = WhatsAppLinkCheck::appWebhook()['state'];

        if ($state === WhatsAppLinkCheck::APP_LISTENING) {
            Cache::put($key, true, now()->addMinutes(10));

            return self::step('ok');
        }

        return $state === WhatsAppLinkCheck::UNKNOWN ? self::step('unknown', 'unreachable') : self::step('fail', 'app_deaf');
    }

    /** @return array{state: string, code: ?string, params: array<string, string>} */
    private static function templates(Business $business, WhatsAppConnection $connection): array
    {
        $r = WhatsAppNoticeTemplates::ensure($business, $connection);

        return self::step($r['state'], $r['code'], $r['params']);
    }

    /** @return array{state: string, code: ?string, params: array<string, string>} */
    private static function queue(Business $business): array
    {
        return self::draining((int) $business->id) ? self::step('wait', 'queue_busy') : self::step('ok');
    }

    /**
     * @param  array<string, string>  $params
     * @return array{state: string, code: ?string, params: array<string, string>}
     */
    private static function step(string $state, ?string $code = null, array $params = []): array
    {
        return ['state' => $state, 'code' => $code, 'params' => $params];
    }

    /**
     * ‏‏ما يمنع أوّلًا: ما يُصلحه أحدٌ، ثمّ ما يُنتظر، ثمّ ما لم يُقرأ — والمراحلُ كلُّها تُحفظ.
     *
     * @param  array<string, array{state: string, code: ?string, params: array<string, string>}>  $steps
     * @return array{ok: bool, code: ?string, checks: list<array{key: string, state: string, code: ?string, params: array<string, string>}>}
     */
    private static function sum(array $steps): array
    {
        $checks = [];

        foreach (self::STEPS as $key) {
            $checks[] = ['key' => $key] + ($steps[$key] ?? self::step('unknown'));
        }

        $code = null;

        foreach (['fail', 'wait', 'unknown'] as $state) {
            foreach ($checks as $c) {
                if ($code === null && $c['state'] === $state && $c['code'] !== null) {
                    $code = $c['code'];
                }
            }
        }

        $ok = collect($checks)->every(fn ($c) => $c['state'] === 'ok');

        return ['ok' => $ok, 'code' => $ok ? null : ($code ?? 'unreachable'), 'checks' => $checks];
    }
}
