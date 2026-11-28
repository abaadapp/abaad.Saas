<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppBilling;
use App\Models\WhatsAppBillingTrial;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessagePrice;
use Illuminate\Support\Facades\DB;

/**
 * ‏‏التجربةُ الحقيقيّة قبل الإطلاق — متجرٌ واحد يُرسل عددًا محدودًا ليُرى على من تظهر رسومُه عند Meta.
 *
 * ═══ ولمَ ═══
 *
 * لا يُنقل التجّارُ الجدد إلى تطبيق أبعاد قبل أن يُرى رسمُ رسالةٍ محتسَبة على حساب التاجر ولا يظهر على حساب
 * أبعاد. والتقديرُ لا يكفي: فاتورةٌ أو رسمٌ ظاهرٌ في «الفوترة والمدفوعات».
 *
 * ═══ وهي معزولة ═══
 *
 *   · تجربةٌ واحدة مفتوحة في النظام كلّه (فهرسٌ فريد)، لمتجرٍ في «متاجر التجربة»، على حسابه ورقمه بعينهما.
 *   · بعددِ رسائلَ ومدّةٍ يكتبهما مديرُ المنصّة، وبموافقته على رسومٍ حقيقيّة على بطاقة حساب الاختبار.
 *   · وكلُّ محاولةِ إرسالٍ تُعدّ قبل النداء — فلا تزيد رسالةٌ على الحدّ ولو تزامنت.
 *   · وما نقضته Meta (حسابٌ لأبعاد أو شريك، أو دفعٌ رُدّ) يقف في التجربة أيضًا.
 *
 * ونجاحُها يفتح زرَّ النقل (`WhatsAppMerchantLink`) — ولا يُثبت تاجرًا آخر: لكلٍّ مراجعتُه.
 */
final class WhatsAppBillingTrials
{
    public const MAX_MESSAGES = 50;

    public const DEFAULT_MESSAGES = 15;

    public const MAX_HOURS = 168;

    public const DEFAULT_HOURS = 72;

    /** ‏‏أرقامُ الاختبار التي تصلها رسائلُ التجربة — رقمٌ واحدٌ على الأقلّ، وخمسةٌ على الأكثر */
    public const MAX_PHONES = 5;

    /** ‏‏رسالةٌ إلى رقمٍ ليس من أرقام التجربة المفتوحة */
    public const NOT_A_TEST_NUMBER = 'billing_trial_other_number';

    /** ‏‏متجرٌ تجريبيّ يُرسل من رقمه ولا تجربةَ مفتوحةً عليه */
    public const DEMO_SENDS_NOTHING = 'demo_shop_sends_nothing';

    /** ‏‏أسبابُ رفضٍ تخصّ المستلم لا الوصلة: لا توقف الرقم، وتُقال بكلمات هذا القفل */
    public const RECIPIENT_CODES = [self::NOT_A_TEST_NUMBER, self::DEMO_SENDS_NOTHING];

    /** ‏‏الفاتورةُ قد تتأخّر عن الرسالة */
    public const PROOF_DAYS = 120;

    /** ‏‏ما يُقبل دليلًا على رسمٍ وقع — ولا تقديرَ فيها */
    public const PROOF_KINDS = [
        'meta_invoice' => 'فاتورة أو إيصال من Meta على حساب التاجر يشمل الرسالة',
        'billing_charge' => 'رسمٌ ظهر في «الفوترة والمدفوعات» على حساب التاجر',
    ];

    /* ═════════════ أنجحت؟ ═════════════ */

    /** ‏‏نجحت تجربةٌ ولم يُعد القفلُ بعدها؟ — وما لم يُقرأ قفل */
    public static function launched(): bool
    {
        try {
            return WhatsAppBillingTrial::where('state', WhatsAppBillingTrial::PASSED)->whereNull('revoked_at')->exists();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public static function current(): ?WhatsAppBillingTrial
    {
        return WhatsAppBillingTrial::where('state', WhatsAppBillingTrial::OPEN)->where('expires_at', '>', now())->orderByDesc('id')->first();
    }

    public static function matching(int $businessId, string $waba, string $phone): ?WhatsAppBillingTrial
    {
        return WhatsAppBillingTrial::where('state', WhatsAppBillingTrial::OPEN)->where('expires_at', '>', now())
            ->where('business_id', $businessId)->where('waba_id', $waba)->where('phone_number_id', $phone)->first();
    }

    /** ‏‏تجربةٌ مفتوحة على هذه الوصلة بحسابها ورقمها — أو لا شيء */
    public static function of(WhatsAppConnection $c): ?WhatsAppBillingTrial
    {
        return blank($c->waba_id) || blank($c->phone_number_id) || blank($c->business_id) ? null
            : self::matching((int) $c->business_id, (string) $c->waba_id, (string) $c->phone_number_id);
    }

    /**
     * ‏‏يعدّ رسالةَ تجربةٍ قبل أن تخرج — سببُ المنع إن بلغت حدَّها، أو `null`.
     *
     * وحسابٌ ثبتت فوترتُه تُعدّ رسائلُه في تجربته ولا يقف بحدّها.
     */
    public static function spend(WhatsAppConnection $c): ?string
    {
        if (! WhatsAppBillingLock::applies($c)) {
            return null;
        }

        $proven = WhatsAppBillingLock::proven($c);
        $counted = WhatsAppBillingTrial::where('state', WhatsAppBillingTrial::OPEN)->where('expires_at', '>', now())
            ->where('business_id', $c->business_id)->where('waba_id', (string) $c->waba_id)->where('phone_number_id', (string) $c->phone_number_id)
            ->whereColumn('messages_used', '<', 'message_limit')
            ->update(['messages_used' => DB::raw('messages_used + 1'), 'updated_at' => now()]);

        return $proven || $counted === 1 ? null : WhatsAppBillingLock::TRIAL_SPENT;
    }

    /**
     * ‏‏أتصل هذه الرسالةُ هذا الرقم؟ — سببُ المنع، أو `null`.
     *
     * يُسأل عند آخر باب (`MetaWhatsAppClient::account`) قبل أن تُعدّ الرسالة: فما رُفض لا يأكل من حدّ التجربة.
     *
     *   · وصلةٌ عليها تجربةٌ مفتوحة لا تصل إلّا أرقامَ تلك التجربة — وتجربةٌ بلا أرقام لا تصل أحدًا.
     *   · ومتجرٌ تجريبيّ لا يُرسل من رقمه إلّا أثناء تجربةٍ مفتوحة على ذلك الرقم: عملاؤه أرقامٌ عشوائيّة
     *     (`DemoStore`)، ونجاحُ تجربته يُثبت فوترتَه فيفتح رقمَه لهم لولا هذا.
     */
    public static function recipient(WhatsAppConnection $c, string $to): ?string
    {
        $trial = self::of($c);

        if ($trial !== null) {
            $phone = WhatsAppPhone::normalize($to);

            return $phone !== null && in_array($phone, $trial->allowed_phones ?? [], true) ? null : self::NOT_A_TEST_NUMBER;
        }

        $demo = ! blank($c->business_id) && (bool) DB::table('businesses')->where('id', $c->business_id)->value('is_demo');

        return $demo ? self::DEMO_SENDS_NOTHING : null;
    }

    /**
     * ‏‏أرقامُ الاختبار كما كتبها مديرُ المنصّة — رقمٌ في كلّ سطر أو بينها فاصلة — بصيغة واتساب.
     *
     * @return array{ok: bool, phones: list<string>, message: string}
     */
    public static function phones(string $raw): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/[\r\n,،;]+/u', $raw) ?: []), fn ($l) => $l !== ''));
        $phones = [];

        foreach ($lines as $line) {
            $phone = WhatsAppPhone::normalize($line);

            if ($phone === null) {
                return ['ok' => false, 'phones' => [], 'message' => __('رقم اختبار لا يصلح لواتساب: :phone', ['phone' => $line])];
            }

            $phones[$phone] = $phone;
        }

        if ($phones === []) {
            return ['ok' => false, 'phones' => [], 'message' => __('اكتب رقم اختبار واحدًا على الأقل — لا تخرج رسالة التجربة إلا إليه.')];
        }

        if (count($phones) > self::MAX_PHONES) {
            return ['ok' => false, 'phones' => [], 'message' => __('أرقام الاختبار :max على الأكثر.', ['max' => self::MAX_PHONES])];
        }

        return ['ok' => true, 'phones' => array_values($phones), 'message' => ''];
    }

    /* ═════════════ مديرُ المنصّة ═════════════ */

    /** @return array{ok: bool, field: string, message: string} */
    /** @param  list<string>  $phones  أرقامُ الاختبار بصيغة واتساب (`phones`) */
    public static function open(Business $business, User $by, int $limit, int $hours, array $phones): array
    {
        // ‏‏تجربةٌ بلا أرقام اختبار لا تُفتح — ولو فاتت الشاشة
        $phones = array_values(array_unique(array_filter(array_map(fn ($p) => WhatsAppPhone::normalize((string) $p), $phones))));

        if ($phones === [] || count($phones) > self::MAX_PHONES) {
            return self::no('phones', __('اكتب رقم اختبار واحدًا على الأقل — لا تخرج رسالة التجربة إلا إليه.'));
        }

        if (self::launched()) {
            return self::no('trial', __('نجحت التجربة من قبل — لا حاجة إلى أخرى.'));
        }

        if (! in_array((int) $business->id, WhatsAppMerchantLink::pilot(), true)) {
            return self::no('business_id', __('اكتب المتجر في «متاجر التجربة» أولًا.'));
        }

        $c = WhatsAppConnections::forBusiness((int) $business->id);

        if (WhatsAppOwnApp::holds($c)) {
            return self::no('business_id', __('هذا المتجر على تطبيقه الخاص — لا تُفتح عليه تجربة.'));
        }

        if (! WhatsAppBillingLock::applies($c) || ! $c->isConnected() || blank($c->waba_id)) {
            return self::no('business_id', __('اربط رقم المتجر الاختباري عبر تطبيق أبعاد أولًا، ثم افتح التجربة.'));
        }

        $read = WhatsAppBillingLock::readForProof($c);

        if ($read['refuted'] !== null) {
            WhatsAppBillingLock::read($c, force: true);

            return self::no('business_id', WhatsAppBillingLock::says($read['refuted'])['text']);
        }

        $row = WhatsAppBillingLock::read($c, force: true);

        if (in_array($row->state, [WhatsAppBillingLock::PROBLEM, WhatsAppBillingLock::RECHECK], true)) {
            return self::no('business_id', WhatsAppBillingLock::says((string) ($row->code ?: WhatsAppBillingLock::UNPROVEN))['text']);
        }

        $trial = DB::transaction(function () use ($business, $c, $by, $limit, $hours, $phones) {
            WhatsAppBillingTrial::where('state', WhatsAppBillingTrial::OPEN)->where('expires_at', '<=', now())
                ->update(['state' => WhatsAppBillingTrial::CLOSED, 'open_slot' => null, 'closed_at' => now(), 'closed_why' => 'expired', 'updated_at' => now()]);

            return Contention::attempt(fn () => WhatsAppBillingTrial::create([
                'business_id' => $business->id, 'waba_id' => (string) $c->waba_id, 'phone_number_id' => (string) $c->phone_number_id,
                'state' => WhatsAppBillingTrial::OPEN, 'open_slot' => 1,
                'message_limit' => max(1, min(self::MAX_MESSAGES, $limit)), 'messages_used' => 0,
                'opened_at' => now(), 'expires_at' => now()->addHours(max(1, min(self::MAX_HOURS, $hours))),
                'opened_by' => $by->id, 'opened_by_name' => mb_substr((string) $by->name, 0, 120),
                'allowed_phones' => $phones,
            ]));
        });

        if ($trial === null) {
            return self::no('trial', __('تجربة أخرى مفتوحة الآن — أغلقها أولًا.'));
        }

        WhatsAppBillingLock::event($row, 'trial_opened', null, $by, ['limit' => $trial->message_limit, 'until' => $trial->expires_at->toIso8601String(), 'numbers' => count($phones)], $trial->id);
        Activity::log('settings', 'واتساب — فُتحت تجربة الفوترة لمتجر «'.$business->name.'»: '.$trial->message_limit.' رسالة على الأكثر، إلى '.count($phones).' من أرقام الاختبار وحدها', [
            'business_id' => $business->id, 'subject_type' => WhatsAppBillingTrial::class, 'subject_id' => $trial->id,
        ]);
        WhatsAppSender::soon((int) $business->id);

        return ['ok' => true, 'field' => '', 'message' => __('فُتحت التجربة. رسائل هذا المتجر وحده تخرج من رقمه حتى حدّها أو موعدها.')];
    }

    public static function close(WhatsAppBillingTrial $trial, User $by): bool
    {
        $done = WhatsAppBillingTrial::whereKey($trial->id)->where('state', WhatsAppBillingTrial::OPEN)
            ->update(['state' => WhatsAppBillingTrial::CLOSED, 'open_slot' => null, 'closed_at' => now(), 'closed_why' => 'by_platform', 'updated_at' => now()]);

        if ($done === 1) {
            WhatsAppBillingLock::event(self::row($trial), 'trial_closed', null, $by, trialId: $trial->id);
            Activity::log('settings', 'واتساب — أُغلقت تجربة الفوترة', ['business_id' => $trial->business_id, 'subject_type' => WhatsAppBillingTrial::class, 'subject_id' => $trial->id]);
        }

        return $done === 1;
    }

    /**
     * ‏‏يسجّل نجاحَ التجربة — برسمٍ رُئي على حساب التاجر لرسالةٍ بعينها، ولم يُرَ على حساب أبعاد.
     *
     * @param  array{kind: string, reference: string, waba_id: string, price_id: int, abaad_reference: string, amount?: ?string, currency?: ?string}  $data
     * @return array{ok: bool, field: string, message: string}
     */
    public static function pass(WhatsAppBillingTrial $trial, User $by, array $data): array
    {
        if ($trial->state === WhatsAppBillingTrial::PASSED) {
            return self::no('trial', __('سُجّل نجاح هذه التجربة من قبل.'));
        }

        if ($trial->opened_at->copy()->addDays(self::PROOF_DAYS)->isPast()) {
            return self::no('trial', __('مضى على التجربة وقت طويل — افتح تجربة جديدة.'));
        }

        if (! array_key_exists($data['kind'], self::PROOF_KINDS)) {
            return self::no('kind', __('اختر نوع الدليل.'));
        }

        foreach (['reference', 'abaad_reference'] as $field) {
            if (WhatsAppBillingLock::looksLikeCard($data[$field])) {
                return self::no($field, __('لا يُكتب رقم بطاقة هنا — اكتب رقم الفاتورة أو تاريخ الصورة.'));
            }
        }

        $seen = (string) preg_replace('/\D/', '', $data['waba_id']);

        if ($seen === '' || ! hash_equals($trial->waba_id, $seen)) {
            return self::no('waba_id', WhatsAppOwnApp::sayFor(WhatsAppOwnApp::EVIDENCE_OTHER_ACCOUNT, $trial->waba_id, $seen));
        }

        $message = self::chargeable($trial)->whereKey($data['price_id'])->first();

        if ($message === null) {
            return self::no('price_id', __('اختر رسالة خرجت في التجربة ووصلت، وقالت Meta إنها محتسبة.'));
        }

        $c = WhatsAppConnections::forBusiness((int) $trial->business_id);

        if (! WhatsAppBillingLock::reviewable($c) || (string) $c->waba_id !== $trial->waba_id) {
            return self::no('trial', __('المتجر الاختباري لم يعد مربوطًا بالحساب نفسه — أعد ربطه ثم سجّل النتيجة.'));
        }

        $read = WhatsAppBillingLock::readForProof($c);

        if ($read['refuted'] !== null) {
            return self::no('trial', WhatsAppBillingLock::says($read['refuted'])['text']);
        }

        $proof = array_filter([
            'kind' => $data['kind'],
            'reference' => mb_substr(trim($data['reference']), 0, 120),
            'abaad_reference' => mb_substr(trim($data['abaad_reference']), 0, 120),
            // ‏‏ما كتبه المراجعُ من فاتورة Meta — لا ما قدّرته الواجهة
            'amount' => filled($data['amount'] ?? null) ? (string) $data['amount'] : null,
            'currency' => filled($data['currency'] ?? null) ? mb_strtoupper((string) $data['currency']) : null,
            'wamid' => $message->wamid,
            'category' => $message->category,
            'trial_id' => $trial->id,
        ], fn ($v) => $v !== null);

        $passed = DB::transaction(function () use ($trial, $by, $proof) {
            $fresh = WhatsAppBillingTrial::whereKey($trial->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->state === WhatsAppBillingTrial::PASSED) {
                return false;
            }

            $fresh->update([
                'state' => WhatsAppBillingTrial::PASSED, 'open_slot' => null, 'passed_at' => now(),
                'closed_at' => $fresh->closed_at ?? now(), 'closed_why' => $fresh->closed_why ?? 'passed',
                'proof' => $proof + ['by_id' => $by->id, 'by' => $by->name],
            ]);

            return true;
        });

        if (! $passed) {
            return self::no('trial', __('سُجّل نجاح هذه التجربة من قبل.'));
        }

        $row = WhatsAppBillingLock::prove(WhatsAppBillingLock::open($c), $c, $read['billing'], $by, ['kind' => 'meta_charge', 'reference' => $proof['reference']], $trial->id);
        WhatsAppBillingLock::event($row, 'trial_passed', null, $by, $proof, $trial->id);
        Activity::log('settings', 'واتساب — نجحت تجربة الفوترة: ظهر الرسم على حساب التاجر لا على أبعاد', [
            'business_id' => $trial->business_id, 'subject_type' => WhatsAppBillingTrial::class, 'subject_id' => $trial->id,
        ]);

        return ['ok' => true, 'field' => '', 'message' => __('سُجّل نجاح التجربة، وفُتح زرّ نقل التجّار الجدد. لم يُنقل أحد.')];
    }

    /** ‏‏يعيد القفلَ بعد نجاح التجربة — يعود التجّارُ الجدد إلى تطبيقاتهم، وما ثبت لتاجرٍ يبقى */
    public static function relock(User $by, string $why): bool
    {
        $done = WhatsAppBillingTrial::where('state', WhatsAppBillingTrial::PASSED)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by_name' => mb_substr((string) $by->name, 0, 120), 'revoked_why' => mb_substr(trim($why), 0, 200), 'updated_at' => now()]);

        if ($done > 0) {
            WhatsAppBillingLock::event(null, 'relocked', null, $by, ['why' => mb_substr(trim($why), 0, 200)]);
            Activity::log('settings', 'واتساب — أُعيد قفل نقل التجّار الجدد إلى تطبيق أبعاد: '.mb_substr(trim($why), 0, 200));
        }

        return $done > 0;
    }

    /** ‏‏رسائلُ التجربة التي وصلت وقالت Meta إنّها محتسَبة */
    private static function chargeable(WhatsAppBillingTrial $trial)
    {
        return self::messages($trial)->whereNotNull('delivered_at')
            ->where(fn ($q) => $q->where('pricing_type', WhatsAppPrices::REGULAR)->orWhere(fn ($q) => $q->whereNull('pricing_type')->where('billable', true)));
    }

    private static function messages(WhatsAppBillingTrial $trial)
    {
        return WhatsAppMessagePrice::where('business_id', $trial->business_id)->where('owner_type', WhatsAppMode::OWNER_BUSINESS)
            ->where('waba_id', $trial->waba_id)->where('phone_number_id', $trial->phone_number_id)->where('created_at', '>=', $trial->opened_at);
    }

    private static function row(WhatsAppBillingTrial $trial): ?WhatsAppBilling
    {
        return WhatsAppBilling::where('business_id', $trial->business_id)->where('waba_id', $trial->waba_id)->first();
    }

    /**
     * ‏‏ما تعرضه شاشةُ مدير المنصّة: أمقفلٌ النقلُ ولماذا، والتجربةُ وما خرج فيها.
     *
     * @return array<string, mixed>
     */
    public static function state(): array
    {
        $trial = WhatsAppBillingTrial::with('business:id,name')->orderByDesc('id')->first();
        $passed = WhatsAppBillingTrial::with('business:id,name')->where('state', WhatsAppBillingTrial::PASSED)->whereNull('revoked_at')->orderByDesc('id')->first();
        $open = $trial !== null && $trial->state === WhatsAppBillingTrial::OPEN && $trial->expires_at->isFuture();

        return [
            'launched' => $passed !== null,
            'passed' => $passed === null ? null : [
                'business' => (string) $passed->business?->name,
                'at' => WhatsAppBillingLock::stamp($passed->passed_at),
                'by' => $passed->proof['by'] ?? null,
                'kind' => __(self::PROOF_KINDS[$passed->proof['kind'] ?? ''] ?? '—'),
                'reference' => $passed->proof['reference'] ?? null,
                'amount' => isset($passed->proof['amount']) ? trim($passed->proof['amount'].' '.($passed->proof['currency'] ?? '')) : null,
            ],
            'trial' => $trial === null || $trial->state === WhatsAppBillingTrial::PASSED ? null : [
                'id' => (int) $trial->id,
                'business_id' => (int) $trial->business_id,
                'business' => (string) $trial->business?->name,
                'waba_id' => $trial->waba_id,
                'open' => $open,
                'used' => $trial->messages_used,
                'limit' => $trial->message_limit,
                'until' => WhatsAppBillingLock::stamp($trial->expires_at),
                'opened_by' => $trial->opened_by_name,
                // ‏‏أرقامُ الاختبار التي كتبها مديرُ المنصّة نفسُه — وحدها تصلها رسائلُ التجربة
                'phones' => array_values($trial->allowed_phones ?? []),
                'may_pass' => ! $trial->opened_at->copy()->addDays(self::PROOF_DAYS)->isPast(),
                'messages' => self::messages($trial)->orderByDesc('id')->limit(30)->get()->map(fn (WhatsAppMessagePrice $m) => WhatsAppPrices::line($m))->all(),
            ],
            'kinds' => collect(self::PROOF_KINDS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => __($label)])->values()->all(),
            'defaults' => ['messages' => self::DEFAULT_MESSAGES, 'hours' => self::DEFAULT_HOURS, 'max_messages' => self::MAX_MESSAGES, 'max_hours' => self::MAX_HOURS, 'max_phones' => self::MAX_PHONES],
        ];
    }

    /** @return array{ok: bool, field: string, message: string} */
    private static function no(string $field, string $message): array
    {
        return ['ok' => false, 'field' => $field, 'message' => $message];
    }
}
