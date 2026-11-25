<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppBilling;
use App\Models\WhatsAppBillingEvent;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ‏‏قفلُ الفوترة — لا تخرج رسالةٌ من رقمٍ رُبط عبر تطبيق أبعاد قبل أن تثبت جهةُ رسومه.
 *
 * ═══ القاعدة ═══
 *
 * أبعاد تبيع التكاملَ والاشتراك، والتاجرُ يدفع لـMeta مباشرة (Tech Provider + Direct Billing). وملكيّةُ التاجر
 * لحساب واتساب لا تقول من يدفع: قد تكون على الحساب بطاقةُ غيره أو خطُّ ائتمان شريك.
 *
 * ═══ ولمَ مراجعةٌ بيد ═══
 *
 * لا حقلَ في واجهة Meta يسمّي جهةَ الدفع لتطبيقٍ ليس «مزوّد حلول» (قُرئت وثائقُها ٢٠٢٦-١١-٢٤): `primary_funding_id`
 * يُردّ بـ`#10`، وإشعارُ التسليم يصنّف الرسالةَ ولا يحمل مبلغًا، و`pricing_analytics` تقديرٌ لا فاتورة. فوقائعُ
 * Meta **تنقض** ولا تُثبت، والإثباتُ مراجعةُ مدير المنصّة لدليلٍ من Meta: نوعُه ومرجعُه ومعرّفُ الحساب، ومن
 * راجعه ومتى (`confirm`). وما لم يُقرأ ليس نجاحًا.
 *
 * ═══ ومن يشمله ═══
 *
 * كلُّ وصلةٍ تُكتب بعد هذا القفل من أيّ باب. ولا يشمل وصلةَ تطبيق التاجر (لها مراجعتُها: `WhatsAppOwnApp`)، ولا
 * أرقامَ أبعاد، ولا وصلةً كانت قائمةً قبله (`billing_exempt`) ما بقيت على حسابها ورقمها.
 *
 * والحالُ لحساب الرسائل (WABA) مع متجره — لا لصفّ الوصلة: إعادةُ الربط لا تمحو دليلًا، وحسابٌ آخر لا يرثه.
 *
 * ═══ ولا احتياطَ ═══
 *
 * ما وقف لا يخرج من رقم أبعاد ولا من رقمٍ آخر: يُقال سببُه لصاحب المتجر وللفريق (`WhatsAppSender::problem`).
 */
final class WhatsAppBillingLock
{
    public const UNVERIFIED = 'unverified';

    public const PENDING = 'pending';

    public const PROVEN = 'proven';

    public const PROBLEM = 'problem';

    public const RECHECK = 'recheck';

    public const STATES = [self::UNVERIFIED, self::PENDING, self::PROVEN, self::PROBLEM, self::RECHECK];

    private const LABELS = [
        self::UNVERIFIED => 'لم يتم التحقق',
        self::PENDING => 'بانتظار التحقق',
        self::PROVEN => 'الفوترة المباشرة للتاجر مثبتة',
        self::PROBLEM => 'مشكلة في الفوترة',
        self::RECHECK => 'يلزم إعادة التحقق',
    ];

    /* ═══ الأسباب ═══ */

    public const UNPROVEN = WhatsAppOwnApp::AWAITS_BILLING;

    public const REQUESTED = 'billing_requested';

    public const NO_ACCOUNT = 'billing_no_account';

    public const OWNER_CHANGED = 'billing_owner_changed';

    public const PARTNER_ADDED = 'billing_partner_added';

    /** ‏‏ردّت Meta رسالةً لمشكلةٍ في الدفع (131042) */
    public const PAYMENT_REFUSED = 'billing_payment_refused';

    /** ‏‏أعاد الفريقُ فتح التحقّق بيده */
    public const REVOKED = 'billing_revoked';

    /** ‏‏Meta لا تعرض كلفةَ حسابٍ له رسائلُ محتسَبة — وهو ما تفعله مع حسابٍ يدفع عنه شريك */
    public const COST_HIDDEN = 'billing_cost_hidden';

    public const TRIAL_SPENT = 'billing_trial_spent';

    /** ‏‏قالت Meta قاطعةً إنّ التفويض سُحب أو نقص — لا تُحاول رسالةٌ بعدها حتى يُعاد الربط */
    public const LINK_LOST = 'billing_link_lost';

    public const OWNER_UNKNOWN = 'owner_unknown';

    public const ABAAD_UNKNOWN = 'abaad_account_unknown';

    /** ‏‏ما يخالف دليلًا رُوجع — يُعاد التحقّق. وما سواه مشكلةٌ تُصلَح */
    private const NEEDS_REVIEW = [self::OWNER_CHANGED, self::PARTNER_ADDED, WhatsAppOwnApp::FUNDING_CHANGED, self::REVOKED];

    /** ‏‏كلُّ ما يوقف الإرسال عند هذا القفل — تقف عندها الحملةُ ولا تُحرق بقيّتُها */
    public const STOPS = [
        self::LINK_LOST, self::UNPROVEN, self::REQUESTED, self::NO_ACCOUNT, self::TRIAL_SPENT, self::PAYMENT_REFUSED, self::REVOKED, self::COST_HIDDEN,
        self::OWNER_CHANGED, self::PARTNER_ADDED, WhatsAppOwnApp::FUNDING_CHANGED, WhatsAppOwnApp::BILLED_TO_ABAAD, WhatsAppOwnApp::PARTNER_OWNED,
        WhatsAppOwnApp::ACCOUNT_TAKEN, WhatsAppOwnApp::NO_PAYMENT_METHOD, WhatsAppOwnApp::ACCOUNT_UNBOUND, WhatsAppOwnApp::AMBIGUOUS_ACCOUNT,
    ];

    /** ‏‏ردُّ Meta على رسالةٍ لمشكلةٍ في وسيلة الدفع أو حساب الفوترة */
    private const PAYMENT_CODES = ['131042'];

    /** ‏‏وما يقول إنّ الربط نفسَه انقطع — يُفحص في الدقيقة التالية فيقف إن ثبت */
    private const LINK_CODES = ['190', '10', '200', '131005', '131031', '368', '133010'];

    /** ‏‏ما يُقبل دليلًا — من Meta نفسها، باسم نشاط التاجر، لهذا الحساب. ولا يُرفع ملفٌّ: صورةُ الدليل قد تحمل بطاقة */
    public const EVIDENCE = WhatsAppOwnApp::EVIDENCE + [
        'meta_charge' => 'رسمٌ على رسالة من هذا الحساب ظهر في «الفوترة والمدفوعات» عند التاجر',
    ];

    /** ‏‏حسابٌ ليس للمتجر أو لم يعد كما رُوجع — لا يُنشأ فيه قالبٌ ولا يُشرَك فيه تطبيق */
    public const FOREIGN = [
        self::NO_ACCOUNT, self::OWNER_CHANGED, self::PARTNER_ADDED, WhatsAppOwnApp::BILLED_TO_ABAAD, WhatsAppOwnApp::PARTNER_OWNED,
        WhatsAppOwnApp::ACCOUNT_TAKEN, WhatsAppOwnApp::ACCOUNT_UNBOUND,
    ];

    /** ‏‏حكمٌ قاطعٌ على الرمز — لا قراءةٌ فشلت (`WhatsAppLinkCheck::record`) */
    private const LINK_BROKEN = [WhatsAppLinkCheck::INVALID_TOKEN, WhatsAppLinkCheck::WRONG_APP, WhatsAppLinkCheck::MISSING_SCOPES, WhatsAppLinkCheck::WABA_NOT_GRANTED];

    private const FRESH_MINUTES = 30;

    private const DAILY_MINUTES = 60 * 23;

    private static bool $columnReady = false;

    /* ═════════════ من يشمله القفل ═════════════ */

    public static function applies(?WhatsAppConnection $c): bool
    {
        return $c !== null && $c->owner_type === WhatsAppMode::OWNER_BUSINESS && ! WhatsAppOwnApp::is($c) && ! self::exempt($c);
    }

    /** ‏‏وصلةُ متجرٍ عبر تطبيق أبعاد — تُراجَع فوترتُها، مقفلةً كانت أو من قبل القفل */
    public static function reviewable(?WhatsAppConnection $c): bool
    {
        return $c !== null && $c->owner_type === WhatsAppMode::OWNER_BUSINESS && ! WhatsAppOwnApp::is($c)
            && filled($c->waba_id) && filled($c->access_token) && $c->status !== WhatsAppConnection::INACTIVE;
    }

    /**
     * ‏‏أكانت قائمةً قبل القفل؟ — وصفٌّ لم يُقرأ عمودُه يُسأل عنه، ولا يُفترض أنّه مُعفى.
     *
     * وفي الثواني بين سحب الكود وجريان الهجرة لا عمودَ بعد: كلُّ وصلةٍ كما كانت.
     */
    private static function exempt(WhatsAppConnection $c): bool
    {
        if (array_key_exists('billing_exempt', $c->getAttributes())) {
            return (bool) $c->billing_exempt;
        }

        if (! self::$columnReady && ! (self::$columnReady = Schema::hasColumn('whatsapp_connections', 'billing_exempt'))) {
            return true;
        }

        return (bool) WhatsAppConnection::whereKey($c->getKey())->value('billing_exempt');
    }

    /**
     * ‏‏أتبقى وصلةٌ يُعاد ربطُها خارج القفل؟ — كانت قائمةً قبله، وعادت إلى حسابها ورقمها نفسيهما.
     */
    public static function staysExempt(?WhatsAppConnection $existing, string $waba, string $phone): bool
    {
        return $existing !== null && ! WhatsAppOwnApp::is($existing) && self::exempt($existing)
            && (string) $existing->phone_number_id === $phone
            && (blank($existing->waba_id) || (string) $existing->waba_id === $waba);
    }

    /** ‏‏حسابٌ ثبتت فوترتُه لهذا المتجر — أو متجرُ التجربة المفتوحة على هذا الحساب والرقم */
    public static function accountMaySend(int $businessId, string $waba, string $phone): bool
    {
        return WhatsAppBilling::where('business_id', $businessId)->where('waba_id', $waba)->where('state', self::PROVEN)->exists()
            || WhatsAppBillingTrials::matching($businessId, $waba, $phone) !== null;
    }

    /* ═════════════ أيُرسل؟ ═════════════ */

    public static function row(?WhatsAppConnection $c): ?WhatsAppBilling
    {
        if ($c === null || blank($c->waba_id) || blank($c->business_id)) {
            return null;
        }

        return $c->relationLoaded('billings')
            ? $c->billings->firstWhere('waba_id', (string) $c->waba_id)
            : WhatsAppBilling::where('business_id', $c->business_id)->where('waba_id', (string) $c->waba_id)->first();
    }

    /** ‏‏ما يوقف الإرسال من هذه الوصلة — أو `null`. من المخزَّن وحده */
    public static function stop(?WhatsAppConnection $c): ?string
    {
        if (! self::applies($c)) {
            return null;
        }

        if (blank($c->waba_id)) {
            return self::NO_ACCOUNT;
        }

        if (in_array($c->link_problem, self::LINK_BROKEN, true)) {
            return self::LINK_LOST;
        }

        $row = self::row($c);

        if ($row?->state === self::PROVEN) {
            return null;
        }

        // ‏‏وما نقضته Meta لا تفتحه تجربة
        if (in_array($row?->state, [self::PROBLEM, self::RECHECK], true)) {
            return (string) ($row->code ?: self::UNPROVEN);
        }

        if (($trial = WhatsAppBillingTrials::of($c)) !== null) {
            return $trial->messages_used < $trial->message_limit ? null : self::TRIAL_SPENT;
        }

        return $row?->state === self::PENDING ? self::REQUESTED : self::UNPROVEN;
    }

    /** ‏‏أثبتت فوترتُها، أو هي متجرُ التجربة؟ — شرطُ أن يصير رقمُها رقمَ الإرسال، مقفلةً كانت أو من قبل القفل */
    public static function mayAdopt(WhatsAppConnection $c): bool
    {
        $row = self::row($c);

        return $row?->state === self::PROVEN
            || (! in_array($row?->state, [self::PROBLEM, self::RECHECK], true) && WhatsAppBillingTrials::of($c) !== null);
    }

    public static function proven(?WhatsAppConnection $c): bool
    {
        return self::row($c)?->state === self::PROVEN;
    }

    /**
     * ‏‏على أيّ حساب رسائل تُحتسب؟ — يُسمّى الحسابُ الذي رُوجع في كلّ رسالة، فلا تختار Meta غيرَه.
     *
     * @return array{account: ?string, problem: ?string}
     */
    public static function sendAccount(WhatsAppConnection $c): array
    {
        if (! self::applies($c)) {
            return ['account' => null, 'problem' => null];
        }

        if (($stop = self::stop($c)) !== null) {
            return ['account' => null, 'problem' => $stop];
        }

        if (WhatsAppOwnApp::namesAccount()) {
            return ['account' => (string) $c->waba_id, 'problem' => null];
        }

        // ‏‏ولا يُسمّى الحسابُ على هذا الإصدار: لا تخرج إلّا رسالةُ رمزٍ لا يصل على الرقم إلّا حسابًا واحدًا
        $reach = ((array) self::row($c)?->facts)['reach'] ?? null;

        return ['account' => null, 'problem' => $reach === 1 ? null : WhatsAppOwnApp::AMBIGUOUS_ACCOUNT];
    }

    /* ═════════════ القراءةُ عند Meta ═════════════ */

    /** ‏‏صفُّ هذا الحساب — يُفتح عند الربط «لم يتم التحقق» */
    public static function open(WhatsAppConnection $c): WhatsAppBilling
    {
        return self::row($c) ?? Contention::attempt(fn () => WhatsAppBilling::create([
            'business_id' => $c->business_id, 'waba_id' => (string) $c->waba_id, 'state' => self::UNVERIFIED,
        ])) ?? WhatsAppBilling::where('business_id', $c->business_id)->where('waba_id', (string) $c->waba_id)->firstOrFail();
    }

    /** ‏‏رُبط الآن: يُفتح صفُّه، ويُقرأ ما قد ينقض دليلًا سبق */
    public static function linked(WhatsAppConnection $c): void
    {
        if (! self::reviewable($c)) {
            return;
        }

        try {
            self::open($c);
            self::read($c, force: true);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * ‏‏يقرأ ما تقوله Meta عن الحساب — قراءةٌ لا غير — ويكتب ما نقضته. ولا تُثبت القراءةُ شيئًا.
     *
     * @param  int  $fresh  قراءةٌ أحدثُ من هذه الدقائق تُستعمل كما هي
     */
    public static function read(WhatsAppConnection $c, bool $force = false, int $fresh = self::FRESH_MINUTES): ?WhatsAppBilling
    {
        if (! self::reviewable($c)) {
            return null;
        }

        $row = self::open($c);

        if (! $force && $row->checked_at !== null && $row->checked_at->gt(now()->subMinutes($fresh)) && self::known($row)) {
            return $row;
        }

        return self::settle($row, $c, self::ask($c));
    }

    /** ‏‏كلَّ يومٍ لما ثبت، وقبلها إن قالت Meta إنّ شيئًا تبدّل (`accountUpdate`) */
    public static function daily(WhatsAppConnection $c): ?WhatsAppBilling
    {
        return self::read($c, fresh: self::DAILY_MINUTES);
    }

    /** @return array{state: string, owner_id: ?string, owner_name: ?string, facts: array<string, mixed>, meta: array{code: ?string, message: ?string}} */
    private static function ask(WhatsAppConnection $c): array
    {
        $waba = (string) $c->waba_id;
        $token = (string) $c->access_token;
        $billing = WhatsAppOwnApp::billing($waba, $token);
        $billing['facts'] += WhatsAppOwnApp::relation($waba, $token, (string) $c->phone_number_id, WhatsAppLinkCheck::appId(), (string) config('whatsapp.app_secret'));
        $billing['facts']['owner_known'] = filled($billing['owner_id']);
        $billing['facts']['abaad_known'] = WhatsAppOwnApp::abaadBusinessIds() !== [];

        return $billing;
    }

    /** ‏‏قُرئ المالكُ وعُرفت حساباتُ أبعاد؟ — وإلّا فلا يُعرف أنّ الحساب ليس لها */
    private static function known(WhatsAppBilling $row): bool
    {
        $facts = (array) $row->facts;

        return ($facts['owner_known'] ?? false) === true && ($facts['abaad_known'] ?? false) === true;
    }

    private static function settle(WhatsAppBilling $row, WhatsAppConnection $c, array $billing): WhatsAppBilling
    {
        return DB::transaction(function () use ($row, $c, $billing) {
            $row = WhatsAppBilling::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $why = self::refuted($c, $billing, (array) $row->evidence);

            $row->fill([
                'facts' => $billing['facts'] + array_intersect_key((array) $row->facts, ['estimate' => 1]),
                'owner_id' => $billing['owner_id'] ?? $row->owner_id,
                'owner_name' => $billing['owner_name'] !== null ? mb_substr((string) $billing['owner_name'], 0, 150) : $row->owner_name,
                'checked_at' => now(),
            ])->save();

            if ($why !== null) {
                self::halt($row, $why, $c);
            } elseif ($row->state === self::PROBLEM && self::lifted((string) $row->code, $billing)) {
                self::lift($row);
            }

            return $row;
        });
    }

    /**
     * ‏‏ما تنقضه وقائعُ هذه القراءة — أو `null`. وما لم يُقرأ لا ينقض ولا يُثبت.
     *
     * @param  array<string, mixed>  $evidence  ما رُوجع من قبل، إن كان
     */
    private static function refuted(WhatsAppConnection $c, array $billing, array $evidence): ?string
    {
        $facts = $billing['facts'];
        $waba = (string) $c->waba_id;

        return match (true) {
            $billing['state'] === WhatsAppOwnApp::BILLING_ABAAD, ($facts['abaad_access'] ?? null) === 'owned',
            in_array($waba, WhatsAppOwnApp::platformWabas(), true) => WhatsAppOwnApp::BILLED_TO_ABAAD,
            ($facts['partner_owned'] ?? false) === true => WhatsAppOwnApp::PARTNER_OWNED,
            WhatsAppOwnApp::accountTaken($waba, (int) $c->business_id) => WhatsAppOwnApp::ACCOUNT_TAKEN,
            ($facts['funding'] ?? null) === 'absent' => WhatsAppOwnApp::NO_PAYMENT_METHOD,
            ($facts['token_sends'] ?? null) === false, ($facts['phone_in_account'] ?? null) === false => WhatsAppOwnApp::ACCOUNT_UNBOUND,
            filled($evidence['owner_id'] ?? null) && filled($billing['owner_id']) && $evidence['owner_id'] !== $billing['owner_id'] => self::OWNER_CHANGED,
            filled($evidence['funding_mark'] ?? null) && filled($facts['funding_mark'] ?? null) && $evidence['funding_mark'] !== $facts['funding_mark'] => WhatsAppOwnApp::FUNDING_CHANGED,
            default => null,
        };
    }

    /** ‏‏أزالت العلّةُ بقراءةٍ صريحة؟ — وغيابُ المعلومة لا يزيلها */
    private static function lifted(string $code, array $billing): bool
    {
        $facts = $billing['facts'];

        return match ($code) {
            WhatsAppOwnApp::NO_PAYMENT_METHOD => ($facts['funding'] ?? null) === 'present',
            WhatsAppOwnApp::ACCOUNT_UNBOUND => ($facts['token_sends'] ?? null) === true && ($facts['phone_in_account'] ?? null) === true,
            WhatsAppOwnApp::BILLED_TO_ABAAD, WhatsAppOwnApp::PARTNER_OWNED => ($facts['owner_known'] ?? false) === true && ($facts['abaad_known'] ?? false) === true,
            WhatsAppOwnApp::ACCOUNT_TAKEN => true,
            default => false,
        };
    }

    /** ‏‏عاد إلى ما كان: ما انقطع ثمّ اتّصل يبقى دليلُه، وما تبدّل مالكُه يُراجَع من جديد */
    private static function lift(WhatsAppBilling $row): void
    {
        $was = (string) $row->code;
        $keeps = in_array($was, [WhatsAppOwnApp::NO_PAYMENT_METHOD, WhatsAppOwnApp::ACCOUNT_UNBOUND], true);

        $row->update([
            'state' => match (true) {
                ((array) $row->evidence) === [] => $row->requested_at !== null ? self::PENDING : self::UNVERIFIED,
                $keeps => self::PROVEN,
                default => self::RECHECK,
            },
            'code' => ((array) $row->evidence) === [] || $keeps ? null : self::UNPROVEN,
            'stopped_at' => ((array) $row->evidence) === [] || $keeps ? null : $row->stopped_at,
        ]);

        self::event($row, 'cleared', $was);
    }

    /* ═════════════ الإيقاف ═════════════ */

    /** ‏‏يوقف الإرسال من هذا الحساب بسببه — ويُقيَّد ويُقال. ولا يُنقل شيءٌ إلى رقمٍ آخر */
    private static function halt(WhatsAppBilling $row, string $code, ?WhatsAppConnection $c = null, string $by = 'النظام', array $data = []): void
    {
        $state = in_array($code, self::NEEDS_REVIEW, true) ? self::RECHECK : self::PROBLEM;

        if ($row->state === $state && $row->code === $code) {
            return;
        }

        $row->update(['state' => $state, 'code' => $code, 'stopped_at' => now()]);

        self::event($row, $state === self::RECHECK ? 'recheck' : 'problem', $code, data: $data, byName: $by);

        if ($c === null || self::applies($c)) {
            Activity::log('settings', 'واتساب — أُوقف الإرسال من رقم المتجر لجهة الرسوم: '.self::arabic($code), [
                'business_id' => $row->business_id, 'subject_type' => WhatsAppBilling::class, 'subject_id' => $row->id, 'by' => $by,
            ]);
        }
    }

    /**
     * ‏‏ما ردّت به Meta على رسالةٍ من هذه الوصلة — ردُّ الدفع يوقف ما بعدها، وردُّ الربط يُفحص في الدقيقة التالية.
     */
    public static function observe(WhatsAppConnection $c, ?string $code): void
    {
        if (blank($code) || ! self::applies($c)) {
            return;
        }

        try {
            if (in_array($code, self::PAYMENT_CODES, true)) {
                self::halt(self::open($c), self::PAYMENT_REFUSED, $c, data: ['meta_code' => $code]);
            } elseif (in_array($code, self::LINK_CODES, true)) {
                WhatsAppSender::soon((int) $c->business_id);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * ‏‏إشعارُ Meta بأنّ شيئًا تبدّل في الحساب: مالكٌ آخر أو شريكٌ أُضيف يوقف حتى يُراجَع، وما سواهما يُعاد قراءته.
     *
     * @param  array<string, mixed>  $value
     */
    public static function accountUpdate(string $waba, array $value): void
    {
        if ($waba === '') {
            return;
        }

        try {
            $info = (array) ($value['waba_info'] ?? []);
            $owner = (string) ($info['owner_business_id'] ?? '');
            $partners = array_map('strval', array_filter((array) ($info['solution_partner_business_ids'] ?? []), 'is_scalar'));
            $ours = WhatsAppOwnApp::abaadBusinessIds();

            WhatsAppConnection::where('waba_id', $waba)->where('owner_type', WhatsAppMode::OWNER_BUSINESS)->whereNotNull('business_id')->get()
                // ‏‏وما كان قبل القفل يُحدَّث حالُه ولا يقف إرسالُه؛ ووصلةُ تطبيق التاجر لا شأنَ لها هنا
                ->filter(fn (WhatsAppConnection $c) => self::reviewable($c))
                ->each(function (WhatsAppConnection $c) use ($value, $owner, $partners, $ours) {
                    $row = self::row($c);

                    if ($row === null) {
                        return;
                    }

                    $known = (string) (((array) $row->evidence)['owner_id'] ?? '');
                    $why = match (true) {
                        $row->state !== self::PROVEN => null,
                        $owner !== '' && $known !== '' && $owner !== $known => self::OWNER_CHANGED,
                        ($value['event'] ?? null) === 'PARTNER_ADDED' && $ours !== [] && array_diff($partners, $ours) !== [] => self::PARTNER_ADDED,
                        default => null,
                    };

                    if ($why !== null) {
                        self::halt($row, $why, $c);
                    }

                    $row->update(['checked_at' => null]);
                });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* ═════════════ الإثبات ═════════════ */

    /**
     * ‏‏يطلب التاجرُ التحقّق بعد أن أضاف وسيلة دفعه — طلبٌ لا إثبات.
     *
     * @return array{ok: bool, message: string}
     */
    public static function request(WhatsAppConnection $c, ?User $by): array
    {
        if (! self::applies($c) || ! $c->isConnected()) {
            return ['ok' => false, 'message' => __('اربط رقم متجرك أولًا ثم اطلب التحقق.')];
        }

        $row = self::read($c, force: true);

        if ($row->state === self::PROVEN) {
            return ['ok' => true, 'message' => __('جهة الرسوم مثبتة — لا شيء مطلوب.')];
        }

        if ($row->state === self::PROBLEM) {
            return ['ok' => false, 'message' => self::says((string) $row->code)['text']];
        }

        $row->update(['state' => self::PENDING, 'requested_at' => now(), 'requested_by' => mb_substr((string) ($by?->name ?? '—'), 0, 120)]);
        self::event($row, 'requested', null, $by);
        WhatsAppSender::soon((int) $c->business_id);

        return ['ok' => true, 'message' => __('وصل طلب التحقق إلى فريق أبعاد. أرسل لهم صفحة «الفوترة والمدفوعات» من Meta تُظهر وسيلة دفعك على هذا الحساب، وأخفِ رقم البطاقة.')];
    }

    /**
     * ‏‏بعد أن أصلح التاجرُ وسيلة دفعه: تُقرأ Meta، ويعود الإرسالُ إن كان دليلُه قائمًا. وردٌّ آخر يوقفه من جديد.
     *
     * @return array{ok: bool, message: string}
     */
    public static function retry(WhatsAppConnection $c, ?User $by): array
    {
        if (! self::applies($c) || ! $c->isConnected()) {
            return ['ok' => false, 'message' => __('اربط رقم متجرك أولًا.')];
        }

        $row = self::read($c, force: true);

        if ($row->state !== self::PROBLEM || $row->code !== self::PAYMENT_REFUSED) {
            return $row->state === self::PROBLEM
                ? ['ok' => false, 'message' => self::says((string) $row->code)['text']]
                : ['ok' => true, 'message' => __('لا مشكلة دفع مسجّلة الآن.')];
        }

        $row->update([
            'state' => ((array) $row->evidence) !== [] ? self::PROVEN : ($row->requested_at !== null ? self::PENDING : self::UNVERIFIED),
            'code' => null, 'stopped_at' => null,
        ]);
        self::event($row, 'retried', self::PAYMENT_REFUSED, $by);
        Activity::log('settings', 'واتساب — أُعيدت المحاولة بعد إصلاح وسيلة الدفع', ['business_id' => $c->business_id, 'subject_type' => WhatsAppBilling::class, 'subject_id' => $row->id]);
        WhatsAppSender::soon((int) $c->business_id);

        return ['ok' => true, 'message' => self::stop($c) === null
            ? __('عاد الإرسال. إن ردّت Meta رسالة أخرى بسبب الدفع يتوقف من جديد.')
            : __('أُزيلت علامة مشكلة الدفع — وبقي إثبات جهة الرسوم قبل الإرسال.')];
    }

    /**
     * ‏‏مراجعةُ مدير المنصّة — الطريقُ الوحيد إلى «مثبتة».
     *
     * يُقرأ الحسابُ عند Meta لحظتها: مالكٌ لم يُعرف، أو حسابٌ لأبعاد أو شريك، يردّها ولو رُئي الدليل. ويُطابَق
     * معرّفُ الحساب بما كتبه المراجعُ من صفحة Meta. وما رُفض يُقيَّد كما يُقيَّد ما قُبل.
     *
     * @param  array{kind: string, reference: string, waba_id: string}  $e
     * @return array{ok: bool, field: string, message: string}
     */
    public static function confirm(WhatsAppConnection $c, User $reviewer, array $e): array
    {
        if (! self::reviewable($c)) {
            return self::no('whatsapp', __('لا ربطَ لهذا المتجر ينتظر إثبات الفوترة.'));
        }

        if (! array_key_exists($e['kind'], self::EVIDENCE)) {
            return self::no('evidence_kind', __('اختر نوع الدليل.'));
        }

        if (self::looksLikeCard($e['reference'])) {
            return self::no('evidence_reference', __('لا يُكتب رقم بطاقة هنا — اكتب رقم الفاتورة أو تاريخ الصورة.'));
        }

        $seen = preg_replace('/\D/', '', $e['waba_id']);

        if ($seen === '' || ! hash_equals((string) $c->waba_id, (string) $seen)) {
            return self::no('evidence_waba_id', WhatsAppOwnApp::sayFor(WhatsAppOwnApp::EVIDENCE_OTHER_ACCOUNT, (string) $c->waba_id, (string) $seen));
        }

        $row = self::open($c);
        $billing = self::ask($c);
        // ‏‏والدليلُ الجديد يحلّ محلَّ ما قبله — فلا يُقاس بمالكٍ أو وسيلةٍ رُوجعا من قبل
        $why = self::refuted($c, $billing, []) ?? match (true) {
            ! $billing['facts']['abaad_known'] => self::ABAAD_UNKNOWN,
            ! $billing['facts']['owner_known'] => self::OWNER_UNKNOWN,
            (((array) $row->facts)['estimate']['state'] ?? null) === WhatsAppPrices::COST_HIDDEN => self::COST_HIDDEN,
            default => null,
        };

        if ($why !== null) {
            self::settle($row, $c, $billing);
            self::event($row, 'refused', $why, $reviewer, ['kind' => $e['kind']]);

            return self::no('whatsapp', self::says($why)['text']);
        }

        $row = self::prove($row, $c, $billing, $reviewer, ['kind' => $e['kind'], 'reference' => mb_substr(trim($e['reference']), 0, 120)]);

        return ['ok' => true, 'field' => '', 'message' => __('ثبتت الفوترة المباشرة للتاجر على الحساب :waba.', ['waba' => $row->waba_id])];
    }

    /** ‏‏يكتب الإثبات — من مراجعةٍ (`confirm`) أو من تجربةٍ نجحت (`WhatsAppBillingTrials::pass`) */
    public static function prove(WhatsAppBilling $row, WhatsAppConnection $c, array $billing, User $by, array $proof, ?int $trialId = null): WhatsAppBilling
    {
        $row = DB::transaction(function () use ($row, $billing, $by, $proof, $trialId) {
            $row = WhatsAppBilling::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $row->update([
                'state' => self::PROVEN, 'code' => null, 'stopped_at' => null, 'proven_at' => now(), 'checked_at' => now(),
                'owner_id' => $billing['owner_id'], 'owner_name' => mb_substr((string) $billing['owner_name'], 0, 150) ?: null,
                'facts' => $billing['facts'] + array_intersect_key((array) $row->facts, ['estimate' => 1]),
                'evidence' => $proof + [
                    'waba_id' => $row->waba_id, 'owner_id' => $billing['owner_id'], 'owner_name' => $billing['owner_name'],
                    'funding_mark' => $billing['facts']['funding_mark'] ?? null,
                    'reviewed_by_id' => $by->id, 'reviewed_by' => $by->name, 'reviewed_at' => now()->toIso8601String(),
                ],
            ]);
            self::event($row, 'confirmed', null, $by, $proof, $trialId);

            return $row;
        });

        Activity::log('settings', 'واتساب — ثبتت الفوترة المباشرة للتاجر على حساب الرسائل '.$row->waba_id.' ('.($proof['kind'] ?? '—').')', [
            'business_id' => $row->business_id, 'subject_type' => WhatsAppBilling::class, 'subject_id' => $row->id,
        ]);
        $c->unsetRelation('billings');
        WhatsAppSender::soon((int) $row->business_id);

        return $row;
    }

    /** @return array{billing: array<string, mixed>, refuted: ?string} قراءةٌ لحظةَ الإثبات — للتجربة */
    public static function readForProof(WhatsAppConnection $c): array
    {
        $billing = self::ask($c);

        return ['billing' => $billing, 'refuted' => self::refuted($c, $billing, []) ?? match (true) {
            ! $billing['facts']['abaad_known'] => self::ABAAD_UNKNOWN,
            ! $billing['facts']['owner_known'] => self::OWNER_UNKNOWN,
            default => null,
        }];
    }

    /** ‏‏دليلٌ رُوجع على وصلةٍ من قبل القفل — يمضي مع حسابها إن أُعيد ربطُها (`WhatsAppOwnApp::confirmBilling`) */
    public static function carry(WhatsAppConnection $c, User $reviewer, array $billing, array $record): void
    {
        if (! self::reviewable($c) || blank($billing['owner_id'])) {
            return;
        }

        $billing['facts'] += ['owner_known' => true, 'abaad_known' => WhatsAppOwnApp::abaadBusinessIds() !== []];
        self::prove(self::open($c), $c, $billing, $reviewer, ['kind' => $record['kind'], 'reference' => $record['reference']]);
    }

    /** ‏‏يعيد الفريقُ فتح التحقّق — فيقف الإرسالُ من وصلةٍ مقفلة حتى يُراجَع دليلٌ جديد */
    public static function reopen(WhatsAppConnection $c, User $by, string $why): bool
    {
        $row = self::row($c);

        if ($row === null || ! self::reviewable($c) || $row->state === self::RECHECK) {
            return false;
        }

        self::halt($row, self::REVOKED, $c, $by->name, ['why' => mb_substr(trim($why), 0, 200)]);

        return true;
    }

    /** ‏‏رقمٌ بطول بطاقةٍ ويجتاز فحصها — لا يُحفظ في حقلٍ حرّ */
    public static function looksLikeCard(string $text): bool
    {
        preg_match_all('/(?:\d[ \-]?){13,19}/u', strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']), $m);

        foreach ($m[0] as $run) {
            $digits = (string) preg_replace('/\D/', '', $run);
            $sum = 0;

            foreach (array_reverse(str_split($digits)) as $i => $d) {
                $n = (int) $d * ($i % 2 === 1 ? 2 : 1);
                $sum += $n > 9 ? $n - 9 : $n;
            }

            if ($sum % 10 === 0) {
                return true;
            }
        }

        return false;
    }

    /* ═════════════ ما يُعرض ═════════════ */

    /**
     * ‏‏مرحلةُ «جهة الرسوم» في فحص الجاهزيّة (`WhatsAppSenderReadiness`) — لا تتمّ إلّا بإثباتٍ أو تجربة.
     *
     * وتُقرأ Meta الآن لا كما قُرئت أمس: رقمٌ لا يصير رقمَ الإرسال ومالكُ حسابه لم يُعرف لحظتَها.
     */
    public static function step(WhatsAppConnection $c): array
    {
        $row = self::read($c, force: true);
        $s = fn (string $state, ?string $code) => ['state' => $state, 'code' => $code, 'params' => []];

        return match (true) {
            $row === null => $s('fail', self::NO_ACCOUNT),
            in_array($row->state, [self::PROBLEM, self::RECHECK], true) => $s('fail', (string) ($row->code ?: self::UNPROVEN)),
            ! (((array) $row->facts)['abaad_known'] ?? false) => $s('unknown', self::ABAAD_UNKNOWN),
            ! (((array) $row->facts)['owner_known'] ?? false) => $s('unknown', self::OWNER_UNKNOWN),
            self::mayAdopt($c) => $s('ok', null),
            $row->state === self::PENDING => $s('wait', self::REQUESTED),
            default => $s('wait', self::UNPROVEN),
        };
    }

    /** ‏‏حالُ أيّ وصلةِ متجر بإحدى الخمس — مقفلةً أو من قبل القفل أو بتطبيق التاجر */
    public static function stateOf(?WhatsAppConnection $c): ?string
    {
        if ($c === null || $c->owner_type !== WhatsAppMode::OWNER_BUSINESS || $c->status === WhatsAppConnection::INACTIVE) {
            return null;
        }

        if (! WhatsAppOwnApp::is($c)) {
            $row = self::row($c);

            return $row !== null ? (string) $row->state : match ($c->billing_state) {
                WhatsAppOwnApp::BILLING_CONFIRMED => self::PROVEN,
                WhatsAppOwnApp::BILLING_ABAAD => self::PROBLEM,
                default => self::UNVERIFIED,
            };
        }

        return match (true) {
            $c->billing_state === WhatsAppOwnApp::BILLING_CONFIRMED => WhatsAppOwnApp::sendAccount($c)['problem'] === null ? self::PROVEN : self::RECHECK,
            $c->billing_state === WhatsAppOwnApp::BILLING_ABAAD => self::PROBLEM,
            $c->last_error_code === WhatsAppOwnApp::AWAITS_BILLING && filled($c->last_error_message) => self::RECHECK,
            default => self::PENDING,
        };
    }

    public static function label(string $state): string
    {
        return __(self::LABELS[$state] ?? $state);
    }

    /** @return array{state: string, label: string, locked: bool, own_app: bool, text: ?string}|null سطرٌ واحد لجدول الأرقام وبطاقة المتجر */
    public static function brief(?WhatsAppConnection $c): ?array
    {
        $state = self::stateOf($c);

        if ($state === null) {
            return null;
        }

        $stop = self::stop($c);

        return [
            'state' => $state,
            'label' => self::label($state),
            'locked' => self::applies($c),
            'own_app' => WhatsAppOwnApp::is($c),
            'text' => $stop !== null ? self::says($stop)['text'] : null,
        ];
    }

    /**
     * ‏‏ما يراه التاجرُ عن جهة رسومه — بلا رمزٍ ولا وقائعَ داخليّة.
     *
     * @return array<string, mixed>|null
     */
    public static function merchantView(Business $business): ?array
    {
        $c = WhatsAppConnections::forBusiness((int) $business->id);

        if (! self::applies($c) || $c->status === WhatsAppConnection::INACTIVE) {
            return null;
        }

        $row = self::row($c);
        $stop = self::stop($c);
        $state = (string) ($row->state ?? self::UNVERIFIED);
        $says = $stop !== null ? self::says($stop) : null;

        return [
            'state' => $state,
            'label' => self::label($state),
            'account' => filled($c->waba_id) ? (string) $c->waba_id : null,
            'owner' => $row?->owner_name,
            'text' => $says['text'] ?? null,
            'who' => $says['who'] ?? null,
            'trial' => $row?->state !== self::PROVEN && WhatsAppBillingTrials::of($c) !== null,
            'may_request' => $c->isConnected() && in_array($state, [self::UNVERIFIED, self::RECHECK], true),
            'may_retry' => $c->isConnected() && $state === self::PROBLEM && $row?->code === self::PAYMENT_REFUSED,
            'requested_at' => self::stamp($row?->requested_at),
            'proven_at' => self::stamp($row?->proven_at),
        ];
    }

    /**
     * ‏‏ما يراه مديرُ المنصّة قبل أن يراجع — وقائعُ Meta كما قُرئت، وما رُوجع، وما جرى. بلا رمزٍ ولا بصمة.
     *
     * @return array<string, mixed>|null
     */
    public static function platformView(?WhatsAppConnection $c): ?array
    {
        if ($c === null || WhatsAppOwnApp::is($c) || $c->owner_type !== WhatsAppMode::OWNER_BUSINESS || $c->status === WhatsAppConnection::INACTIVE) {
            return null;
        }

        $row = self::row($c);
        $facts = (array) $row?->facts;
        $evidence = (array) $row?->evidence;
        $stop = self::stop($c);

        return [
            'state' => self::stateOf($c),
            'label' => self::label((string) self::stateOf($c)),
            'locked' => self::applies($c),
            'stopped' => $stop !== null ? self::says($stop)['text'] : null,
            'account' => filled($c->waba_id) ? (string) $c->waba_id : null,
            'owner' => $row?->owner_name,
            'owner_id' => $row?->owner_id,
            'may_confirm' => self::reviewable($c),
            'may_reopen' => $row !== null && self::reviewable($c) && in_array($row->state, [self::PROVEN, self::PENDING], true),
            'may_retry' => $row?->state === self::PROBLEM && $row->code === self::PAYMENT_REFUSED,
            'requested_at' => self::stamp($row?->requested_at),
            'requested_by' => $row?->requested_by,
            'facts' => $facts === [] ? null : [
                'owner_known' => (bool) ($facts['owner_known'] ?? false),
                'abaad_known' => (bool) ($facts['abaad_known'] ?? false),
                'ownership' => $facts['ownership'] ?? null,
                'partner_owned' => (bool) ($facts['partner_owned'] ?? false),
                'funding' => $facts['funding'] ?? null,
                'abaad_access' => $facts['abaad_access'] ?? null,
                'read_at' => self::stamp($row->checked_at),
            ],
            'evidence' => $evidence === [] ? null : [
                'kind' => __(self::EVIDENCE[$evidence['kind'] ?? ''] ?? (string) ($evidence['kind'] ?? '')),
                'reference' => $evidence['reference'] ?? null,
                'reviewed_by' => $evidence['reviewed_by'] ?? null,
                'reviewed_at' => filled($evidence['reviewed_at'] ?? null) ? self::stamp(Carbon::parse($evidence['reviewed_at'])) : null,
            ],
            'estimate' => WhatsAppPrices::estimateView($facts['estimate'] ?? null),
            'classified' => WhatsAppPrices::summary($c),
            'trial' => WhatsAppBillingTrials::of($c) !== null,
            'kinds' => collect(self::EVIDENCE)->map(fn (string $label, string $key) => ['key' => $key, 'label' => __($label)])->values()->all(),
            'events' => self::events((int) $c->business_id),
        ];
    }

    /** @return list<array{at: ?string, by: string, what: string, why: ?string}> */
    public static function events(int $businessId, int $limit = 8): array
    {
        return WhatsAppBillingEvent::where('business_id', $businessId)->orderByDesc('id')->limit($limit)->get()
            ->map(fn (WhatsAppBillingEvent $e) => [
                'at' => self::stamp($e->created_at),
                'by' => $e->by_name,
                'what' => self::eventLabel($e->event),
                'why' => $e->code !== null ? self::says($e->code)['text'] : ($e->data['why'] ?? null),
            ])->all();
    }

    private static function eventLabel(string $event): string
    {
        return match ($event) {
            'requested' => __('طُلب التحقق'),
            'confirmed' => __('ثبتت الفوترة'),
            'refused' => __('رُدّ الإثبات'),
            'problem' => __('أُوقف الإرسال'),
            'recheck' => __('يلزم إعادة التحقق'),
            'cleared' => __('زالت المشكلة'),
            'retried' => __('أُعيدت المحاولة'),
            'trial_opened' => __('فُتحت التجربة'),
            'trial_closed' => __('أُغلقت التجربة'),
            'trial_passed' => __('نجحت التجربة'),
            'relocked' => __('أُعيد القفل'),
            default => $event,
        };
    }

    public static function event(?WhatsAppBilling $row, string $event, ?string $code = null, ?User $by = null, array $data = [], ?int $trialId = null, string $byName = 'النظام'): void
    {
        WhatsAppBillingEvent::create([
            'business_id' => $row?->business_id, 'waba_id' => $row?->waba_id, 'trial_id' => $trialId, 'event' => $event, 'code' => $code,
            'by_id' => $by?->id, 'by_name' => mb_substr((string) ($by?->name ?? $byName), 0, 120), 'data' => $data === [] ? null : $data, 'created_at' => now(),
        ]);
    }

    /**
     * ‏‏السببُ بكلماته، ومن يفعل ما بقي.
     *
     * @return array{text: string, who: 'merchant'|'abaad'|'meta'}
     */
    public static function says(string $code): array
    {
        [$text, $who] = match ($code) {
            self::UNPROVEN => [__('لم تثبت جهة رسوم واتساب بعد — أضف وسيلة دفعك في حسابك عند Meta ثم اطلب التحقق.'), 'merchant'],
            self::REQUESTED => [__('وصل طلب التحقق من جهة الرسوم — يراجعه فريق أبعاد.'), 'abaad'],
            self::NO_ACCOUNT => [__('لا معرّف حساب واتساب على هذا الربط — أعد الربط.'), 'merchant'],
            self::LINK_LOST => [__('سحبت Meta تفويض واتساب أو نقصت صلاحياته — الإرسال متوقف، أعد الربط.'), 'merchant'],
            self::TRIAL_SPENT => [__('انتهى عدد رسائل التجربة المسموح به.'), 'abaad'],
            self::PAYMENT_REFUSED => [__('ردّت Meta رسالة بسبب وسيلة الدفع — أصلحها في حسابك عند Meta ثم اضغط «أعد المحاولة».'), 'merchant'],
            self::OWNER_CHANGED => [__('تبدّل مالك حساب واتساب عند Meta بعد التحقق — الإرسال متوقف حتى يُعاد التحقق.'), 'abaad'],
            self::PARTNER_ADDED => [__('أُضيف شريك إلى حساب واتساب عند Meta بعد التحقق — الإرسال متوقف حتى يُعاد التحقق.'), 'abaad'],
            WhatsAppOwnApp::FUNDING_CHANGED => [__('تبدّلت وسيلة الدفع على حساب واتساب بعد التحقق — الإرسال متوقف حتى يُعاد التحقق.'), 'abaad'],
            self::REVOKED => [__('أعاد فريق أبعاد فتح التحقق من جهة الرسوم — الإرسال متوقف حتى يُراجع.'), 'abaad'],
            self::COST_HIDDEN => [__('Meta لا تعرض كلفة هذا الحساب مع أنّ له رسائل محتسبة — وهذا ما تفعله مع حساب يدفع عنه شريك. لا يُعتمد حتى يتّضح.'), 'abaad'],
            WhatsAppOwnApp::BILLED_TO_ABAAD => [__('حساب واتساب المربوط تملكه أبعاد لا المتجر — يربط التاجر حسابه هو.'), 'abaad'],
            WhatsAppOwnApp::PARTNER_OWNED => [__('حساب واتساب المربوط يملكه شريك آخر لا المتجر — اربط حسابًا يملكه نشاطك في Meta.'), 'merchant'],
            WhatsAppOwnApp::ACCOUNT_TAKEN => [__('حساب واتساب هذا مربوط بمتجر آخر في أبعاد — لكل متجر حسابه ووسيلة دفعه.'), 'merchant'],
            WhatsAppOwnApp::NO_PAYMENT_METHOD => [__('لا وسيلة دفع على حساب واتساب — أضفها من مدير واتساب (WhatsApp Manager) ‹ Overview.'), 'merchant'],
            WhatsAppOwnApp::ACCOUNT_UNBOUND => [__('الربط لم يعد يصل حساب واتساب هذا أو رقمه — أعد الربط.'), 'merchant'],
            WhatsAppOwnApp::AMBIGUOUS_ACCOUNT => [__('لا يُعرف بأمان على أي حساب واتساب تُحتسب الرسالة — أعد الربط بحساب واحد.'), 'abaad'],
            self::OWNER_UNKNOWN => [__('لم نعرف بعد من يملك حساب واتساب — لا يُعتمد حتى يُقرأ.'), 'meta'],
            self::ABAAD_UNKNOWN => [__('حساب أعمال أبعاد عند Meta غير معروف للنظام — يُضبط من فريق أبعاد.'), 'abaad'],
            default => [__('جهة رسوم واتساب لم تثبت.'), 'abaad'],
        };

        return ['text' => $text, 'who' => $who];
    }

    /** ‏‏أهذا السببُ من هذا القفل؟ — ليقوله `WhatsAppSender::say` بكلماته */
    public static function owns(string $code): bool
    {
        return in_array($code, self::STOPS, true);
    }

    private static function arabic(string $code): string
    {
        $was = app()->getLocale();
        app()->setLocale('ar');

        try {
            return self::says($code)['text'];
        } finally {
            app()->setLocale($was);
        }
    }

    /** @return array{ok: bool, field: string, message: string} */
    private static function no(string $field, string $message): array
    {
        return ['ok' => false, 'field' => $field, 'message' => $message];
    }

    public static function stamp(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : Carbon::instance($at)->timezone((string) config('app.timezone'))->format('Y-m-d H:i');
    }
}
