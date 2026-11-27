<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Setting;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessagePrice;

/**
 * ‏‏ما تقوله Meta عن احتساب الرسائل — بثلاث درجاتٍ لا تُخلط.
 *
 *   ١ · **تصنيف**: إشعارُ التسليم يقول أتُحتسب الرسالةُ وبأيّ فئة (`pricing`) — ولا يحمل مبلغًا. يُحفظ كما قيل.
 *   ٢ · **تقدير**: `pricing_analytics` تعطي كلفةً «تقريبيّة قد تختلف عن الفاتورة» بعملة حساب صاحبها. تُقرأ بزرٍّ وتُعرض تقديرًا.
 *   ٣ · **مؤكَّد**: ما رآه المراجعُ في فاتورة Meta أو «الفوترة والمدفوعات» وكتبه — في سجلّ الإثبات وحده.
 *
 * ولا يُحسب مبلغٌ من عندنا: لا سعرَ لرسالةٍ في هذا الملفّ.
 */
final class WhatsAppPrices
{
    /** ‏‏محتسَبة — والبقيّةُ مجّانيّةٌ بسببها كما تسمّيه Meta */
    public const REGULAR = 'regular';

    /** ‏‏قرأنا، ولم تعرض Meta كلفةً لحسابٍ له رسائلُ محتسَبة */
    public const COST_HIDDEN = 'cost_hidden';

    private const SHARED_KEY = 'whatsapp_shared_cost_estimate';

    private const DAYS = 30;

    /* ═════════════ ١ · التصنيف ═════════════ */

    /**
     * ‏‏يحفظ ما في إشعار حالةٍ من تصنيف — مرّةً لكلّ رسالة، ولا يبدّل ما قيل. ولا يُسقط الإشعار.
     *
     * @param  array<string, mixed>  $status
     */
    public static function record(WhatsAppConnection $c, array $status): void
    {
        $id = mb_substr((string) ($status['id'] ?? ''), 0, 191);
        $state = (string) ($status['status'] ?? '');

        if ($id === '' || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        try {
            $said = self::said(is_array($status['pricing'] ?? null) ? $status['pricing'] : []);
            $row = WhatsAppMessagePrice::where('wamid', $id)->first();

            if ($row === null) {
                // ‏‏ولا صفَّ لما لم تقل فيه Meta شيئًا
                if ($said === []) {
                    return;
                }

                $row = Contention::attempt(fn () => WhatsAppMessagePrice::create($said + self::whose($c, $id) + [
                    'whatsapp_connection_id' => $c->id, 'owner_type' => (string) $c->owner_type, 'waba_id' => $c->waba_id,
                    'phone_number_id' => $c->phone_number_id, 'wamid' => $id,
                ])) ?? WhatsAppMessagePrice::where('wamid', $id)->first();
            }

            // ‏‏ورسالةُ خطٍّ لا يعدّلها إشعارُ خطٍّ آخر
            if ($row === null || (int) $row->whatsapp_connection_id !== (int) $c->id) {
                return;
            }

            $at = isset($status['timestamp']) && is_numeric($status['timestamp']) ? now()->setTimestamp((int) $status['timestamp']) : now();

            $row->fill(array_filter($said, fn ($v, $k) => $row->{$k} === null, ARRAY_FILTER_USE_BOTH) + [
                'last_status' => $state,
                'delivered_at' => $row->delivered_at ?? (in_array($state, ['delivered', 'read'], true) ? $at : null),
            ])->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * ‏‏الحقولُ التي توثّقها Meta وحدها، بأطوالها — وما ليس منها يُترك.
     *
     * @return array<string, mixed>
     */
    private static function said(array $pricing): array
    {
        $word = fn (string $key, int $max) => is_string($pricing[$key] ?? null) && preg_match('/^[A-Za-z0-9_\-]{1,'.$max.'}$/', $pricing[$key]) ? $pricing[$key] : null;

        return array_filter([
            'billable' => is_bool($pricing['billable'] ?? null) ? $pricing['billable'] : null,
            'pricing_model' => $word('pricing_model', 20),
            'pricing_type' => $word('type', 40),
            'category' => $word('category', 40),
            'subtype' => $word('subtype', 40),
        ], fn ($v) => $v !== null);
    }

    /** ‏‏لأيّ متجرٍ هذه الرسالة، وما هي؟ — من سجلّاتنا لا من الإشعار @return array{business_id: ?int, kind: string} */
    private static function whose(WhatsAppConnection $c, string $wamid): array
    {
        $own = $c->business_id !== null ? (int) $c->business_id : null;

        if ($c->purpose === WhatsAppMode::PURPOSE_CRM_SALES) {
            return ['business_id' => $own, 'kind' => 'crm'];
        }

        $notice = WhatsAppMessage::where('provider_message_id', $wamid)->where('whatsapp_connection_id', $c->id)->value('business_id');

        if ($notice !== null) {
            return ['business_id' => (int) $notice, 'kind' => 'notice'];
        }

        $chat = WhatsAppChatMessage::where('wamid', $wamid)->where('whatsapp_connection_id', $c->id)->value('business_id');

        if ($chat !== null) {
            return ['business_id' => (int) $chat, 'kind' => 'chat'];
        }

        return ['business_id' => $own, 'kind' => $own !== null && WhatsAppCampaignRecipient::where('wamid', $wamid)->exists() ? 'campaign' : 'other'];
    }

    /** ‏‏أمحتسَبةٌ عند Meta؟ — `null` لم تقل */
    public static function charged(WhatsAppMessagePrice $m): ?bool
    {
        return $m->pricing_type !== null ? $m->pricing_type === self::REGULAR : $m->billable;
    }

    /** @return array<string, mixed> سطرُ رسالةٍ في شاشة مدير المنصّة — بلا رقم العميل ولا نصّ */
    public static function line(WhatsAppMessagePrice $m): array
    {
        return [
            'id' => (int) $m->id,
            'tail' => mb_substr($m->wamid, -8),
            'at' => WhatsAppBillingLock::stamp($m->created_at),
            'kind' => self::kind($m->kind),
            'category' => self::category($m->category),
            'status' => $m->last_status,
            'delivered' => $m->delivered_at !== null,
            'charged' => self::charged($m),
            'why_free' => self::charged($m) === false ? self::free($m->subtype ?: $m->pricing_type) : null,
        ];
    }

    public static function category(?string $category): string
    {
        return match ($category) {
            'marketing', 'marketing_lite' => __('تسويق'),
            'utility' => __('إشعار'),
            'service' => __('ردّ خدمة'),
            'authentication', 'authentication-international' => __('رمز تحقق'),
            null, '' => __('لم تقل Meta'),
            default => $category,
        };
    }

    private static function kind(string $kind): string
    {
        return match ($kind) {
            'notice' => __('إشعار من النظام'),
            'campaign' => __('حملة'),
            'chat' => __('محادثة'),
            'crm' => __('مبيعات أبعاد'),
            default => __('أخرى'),
        };
    }

    private static function free(?string $type): string
    {
        return match ($type) {
            'free_customer_service' => __('مجانية داخل نافذة الخدمة'),
            'free_entry_point' => __('مجانية: بدأ العميل من إعلان أو صفحة'),
            'free_tier' => __('ضمن الردود المجانية الشهرية'),
            'paid_exempt' => __('معفاة عند Meta'),
            default => __('مجانية عند Meta'),
        };
    }

    /** @return array{total: int, charged: int, free: int, unknown: int, days: int} عددٌ لا مبلغ */
    public static function summary(?WhatsAppConnection $c, int $days = self::DAYS): array
    {
        $out = ['total' => 0, 'charged' => 0, 'free' => 0, 'unknown' => 0, 'days' => $days];

        if ($c === null) {
            return $out;
        }

        WhatsAppMessagePrice::where('whatsapp_connection_id', $c->id)->whereNotNull('delivered_at')->where('delivered_at', '>=', now()->subDays($days))
            ->selectRaw('pricing_type, billable, count(*) as n')->groupBy('pricing_type', 'billable')->get()
            ->each(function (WhatsAppMessagePrice $m) use (&$out) {
                $key = match (self::charged($m)) {
                    true => 'charged',
                    false => 'free',
                    default => 'unknown',
                };
                $out[$key] += (int) $m->n;
                $out['total'] += (int) $m->n;
            });

        return $out;
    }

    /* ═════════════ ٢ · التقدير ═════════════ */

    /**
     * ‏‏يقرأ تقديرَ Meta لكلفة حسابٍ في آخر ثلاثين يومًا — قراءةٌ لا غير. ولا يُخترع ما لم تقله.
     *
     * وكلفةٌ لا تعرضها لحسابٍ له رسائلُ محتسَبة تُقال كما هي: Meta تحجبها عن حسابٍ يشارك خطَّ ائتمان شريك.
     *
     * @return array{state: 'read'|'none'|'cost_hidden'|'failed', cost: ?float, volume: int, charged: int, rows: list<array{category: string, type: string, volume: int, cost: ?float}>, from: string, to: string, read_at: string, code: ?string}
     */
    public static function estimate(WhatsAppConnection $c, int $days = self::DAYS): array
    {
        $from = now()->subDays($days)->startOfDay();
        $to = now();
        $out = ['state' => 'failed', 'cost' => null, 'volume' => 0, 'charged' => 0, 'rows' => [], 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'read_at' => $to->toIso8601String(), 'code' => null];

        if (blank($c->waba_id)) {
            return ['code' => 'no_waba'] + $out;
        }

        $r = MetaWhatsAppClient::pricingAnalytics($c, $from->getTimestamp(), $to->getTimestamp());

        if (! $r['ok']) {
            return ['code' => $r['code']] + $out;
        }

        $rows = [];
        $priced = false;

        foreach ($r['points'] as $p) {
            $key = ($p['category'] ?? '').'|'.($p['type'] ?? '');
            $rows[$key] ??= ['category' => (string) ($p['category'] ?? ''), 'type' => (string) ($p['type'] ?? ''), 'volume' => 0, 'cost' => null];
            $rows[$key]['volume'] += $p['volume'];
            $out['volume'] += $p['volume'];

            if (strtolower($rows[$key]['type']) === self::REGULAR) {
                $out['charged'] += $p['volume'];
            }

            if ($p['cost'] !== null) {
                $priced = true;
                $rows[$key]['cost'] = round(($rows[$key]['cost'] ?? 0) + $p['cost'], 6);
                $out['cost'] = round(($out['cost'] ?? 0) + $p['cost'], 6);
            }
        }

        $out['rows'] = array_values($rows);
        $out['state'] = match (true) {
            $out['volume'] === 0 => 'none',
            ! $priced && $out['charged'] > 0 => self::COST_HIDDEN,
            default => 'read',
        };

        return $out;
    }

    /** ‏‏يقرأ تقديرَ حساب متجرٍ ويحفظه مع صفّ فوترته */
    public static function refresh(WhatsAppConnection $c): ?array
    {
        if (! WhatsAppBillingLock::reviewable($c)) {
            return null;
        }

        $estimate = self::estimate($c);
        $row = WhatsAppBillingLock::open($c);

        // ‏‏وقراءةٌ فشلت لا تمحو ما قُرئ
        if ($estimate['state'] !== 'failed') {
            $row->update(['facts' => ['estimate' => $estimate] + (array) $row->facts]);
        }

        return $estimate;
    }

    /** ‏‏ورقمُ أبعاد المشترك: ما يُحتسب على أبعاد نفسها — تقديرُ Meta */
    public static function refreshShared(): ?array
    {
        $c = WhatsAppConnections::platform();

        if ($c === null || blank($c->waba_id)) {
            return null;
        }

        $estimate = self::estimate($c);

        if ($estimate['state'] !== 'failed') {
            Setting::updateOrCreate(['business_id' => null, 'key' => self::SHARED_KEY], ['value' => (string) json_encode($estimate)]);
        }

        return $estimate;
    }

    /** @return array<string, mixed>|null */
    public static function estimateView(mixed $e): ?array
    {
        if (! is_array($e) || ! isset($e['state'])) {
            return null;
        }

        return [
            'state' => (string) $e['state'],
            'cost' => isset($e['cost']) ? (float) $e['cost'] : null,
            'volume' => (int) ($e['volume'] ?? 0),
            'charged' => (int) ($e['charged'] ?? 0),
            'rows' => array_map(fn ($r) => [
                'category' => self::category(strtolower((string) ($r['category'] ?? ''))),
                'charged' => strtolower((string) ($r['type'] ?? '')) === self::REGULAR,
                'volume' => (int) ($r['volume'] ?? 0),
                'cost' => isset($r['cost']) ? (float) $r['cost'] : null,
            ], (array) ($e['rows'] ?? [])),
            'from' => (string) ($e['from'] ?? ''),
            'to' => (string) ($e['to'] ?? ''),
            'read_at' => isset($e['read_at']) ? WhatsAppBillingLock::stamp(\Illuminate\Support\Carbon::parse((string) $e['read_at'])) : null,
        ];
    }

    /**
     * ‏‏كلفةُ رقم أبعاد المشترك كما تُعرف: تقديرُ Meta، وعددُ ما صنّفته محتسَبًا لكلّ متجر. ولا مبلغَ من عندنا.
     *
     * @return array<string, mixed>
     */
    public static function sharedView(): array
    {
        $c = WhatsAppConnections::platform();
        $saved = Setting::whereNull('business_id')->where('key', self::SHARED_KEY)->value('value');

        $rows = WhatsAppMessagePrice::where('owner_type', WhatsAppMode::OWNER_PLATFORM)->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', now()->subDays(self::DAYS))
            ->selectRaw('business_id, kind, category, pricing_type, billable, count(*) as n')->groupBy('business_id', 'kind', 'category', 'pricing_type', 'billable')->get();

        $names = Business::whereIn('id', $rows->pluck('business_id')->filter()->unique())->pluck('name', 'id');

        return [
            'linked' => $c !== null,
            'days' => self::DAYS,
            'estimate' => self::estimateView(is_string($saved) ? json_decode($saved, true) : null),
            'classified' => self::summary($c),
            'by_category' => $rows->filter(fn ($m) => self::charged($m) === true)->groupBy(fn ($m) => self::category($m->category))
                ->map(fn ($g, $label) => ['label' => (string) $label, 'n' => (int) $g->sum('n')])->sortByDesc('n')->values()->all(),
            'by_shop' => $rows->filter(fn ($m) => self::charged($m) === true)->groupBy(fn ($m) => (int) $m->business_id)
                ->map(fn ($g, $id) => ['business' => $id > 0 ? (string) ($names[$id] ?? '—') : __('غير منسوبة لمتجر'), 'n' => (int) $g->sum('n')])
                ->sortByDesc('n')->take(10)->values()->all(),
        ];
    }
}
