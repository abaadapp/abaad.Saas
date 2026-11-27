<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppOptOut;
use Illuminate\Support\Facades\DB;

/**
 * ‏‏لمن تُرسل الحملة — من عملاء المتجر وحده، وبعد أن يُخرج من لا تُرسل إليه، ويُقال كم ولماذا.
 *
 * والمشترياتُ بتعريف قائمة العملاء نفسِه (`CustomerPurchases`): ما يُقرأ في القائمة هو ما يُختار به هنا.
 *
 * ═══ ومن يُخرج ═══
 *
 * بلا رقمٍ يصلح، أو طلب الإيقاف، أو وصلته حملةٌ قبل أيّامٍ يختارها التاجر، أو رقمُه رقمُ عميلٍ آخر سبقه.
 */
class WhatsAppCampaignAudience
{
    public const ALL = 'all';

    /** ‏‏اشترى في المدّة */
    public const RECENT = 'recent';

    /** ‏‏اشترى يومًا ولم يشترِ في المدّة */
    public const AWAY = 'away';

    /** ‏‏لم يشترِ بعد */
    public const NEVER = 'never';

    public const SEGMENTS = [self::ALL, self::RECENT, self::AWAY, self::NEVER];

    public const DAYS = [7, 14, 30, 60, 90, 180, 365];

    /** ‏‏لا تُرسل لمن وصلته حملةٌ في هذه الأيّام — وصفرٌ يعني «أرسل» */
    public const QUIET = [0, 1, 3, 7, 14, 30];

    public const MAX_RECIPIENTS = 5000;

    /**
     * ‏‏ما اختاره التاجر مضبوطًا على ما نعرف — قيمةٌ لا نعرفها تُردّ إلى الافتراض الأضيق.
     *
     * @param  array<string, mixed>  $raw
     * @return array{segment: string, days: int, min_spent: float, branch_id: ?int, language: ?string, quiet_days: int}
     */
    public static function clean(Business $business, array $raw): array
    {
        $branch = filled($raw['branch_id'] ?? null) && Branch::where('business_id', $business->id)->whereKey($raw['branch_id'])->exists()
            ? (int) $raw['branch_id'] : null;

        return [
            'segment' => in_array($raw['segment'] ?? null, self::SEGMENTS, true) ? $raw['segment'] : self::RECENT,
            'days' => in_array((int) ($raw['days'] ?? 0), self::DAYS, true) ? (int) $raw['days'] : 90,
            // ‏‏«٢٠٫٥» و«20.5» سواء — ورقمٌ لا يُقرأ كان سيُسقط الشرطَ ويوسّع الحملة
            'min_spent' => max(0, round((float) strtr(Digits::western((string) ($raw['min_spent'] ?? '')), ['٫' => '.', ',' => '.', '،' => '.']), 3)),
            'branch_id' => $branch,
            'language' => in_array($raw['language'] ?? null, ['ar', 'en'], true) ? $raw['language'] : null,
            'quiet_days' => in_array((int) ($raw['quiet_days'] ?? 3), self::QUIET, true) ? (int) ($raw['quiet_days'] ?? 3) : 3,
        ];
    }

    /**
     * ‏‏أهلُ الحملة الآن — ومن خرج منها وبأيّ سبب.
     *
     * @param  array<string, mixed>  $filter  من `clean`
     * @return array{people: list<array{customer_id: int, phone: string, name: string}>, skipped: array{no_phone: int, opted_out: int, recent: int, duplicate: int}}
     */
    public static function resolve(Business $business, array $filter): array
    {
        $people = [];
        $skipped = ['no_phone' => 0, 'opted_out' => 0, 'recent' => 0, 'duplicate' => 0];

        $out = WhatsAppOptOut::where('business_id', $business->id)->pluck('phone')->flip();
        $lately = $filter['quiet_days'] > 0
            ? WhatsAppCampaignRecipient::where('business_id', $business->id)->whereIn('status', WhatsAppCampaignRecipient::OUT)
                ->where('sent_at', '>', now()->subDays($filter['quiet_days']))->pluck('phone')->flip()
            : collect();

        foreach (self::customers($business, $filter) as $c) {
            $phone = WhatsAppPhone::normalize($c->phone ?: $c->contact_phone);

            if ($phone === null) {
                $skipped['no_phone']++;
            } elseif ($out->has($phone)) {
                $skipped['opted_out']++;
            } elseif ($lately->has($phone)) {
                $skipped['recent']++;
            } elseif (isset($people[$phone])) {
                $skipped['duplicate']++;
            } else {
                $people[$phone] = ['customer_id' => (int) $c->id, 'phone' => (string) $phone, 'name' => mb_substr(trim((string) $c->name), 0, 80)];
            }
        }

        return ['people' => array_values($people), 'skipped' => $skipped];
    }

    /** @param array<string, mixed> $filter */
    private static function customers(Business $business, array $filter): iterable
    {
        $base = CustomerPurchases::apply(
            Customer::where('customers.business_id', $business->id)
                ->when($filter['branch_id'], fn ($q) => $q->where('customers.branch_id', $filter['branch_id']))
                ->when($filter['language'] === 'en', fn ($q) => $q->where('customers.language', 'en'))
                // ‏‏ومن لا لغةَ في ملفّه عربيٌّ — كما تُرسل إليه إشعاراتُ طلباته
                ->when($filter['language'] === 'ar', fn ($q) => $q->where(fn ($w) => $w->whereNull('customers.language')->orWhere('customers.language', '!=', 'en')))
                ->select('customers.id', 'customers.name', 'customers.phone', 'customers.contact_phone')
        );

        $since = now()->subDays($filter['days'])->format('Y-m-d H:i:s');

        return DB::query()->fromSub($base->toBase(), 'c')
            ->when($filter['segment'] === self::RECENT, fn ($q) => $q->where('c.last_purchase_at', '>=', $since))
            ->when($filter['segment'] === self::AWAY, fn ($q) => $q->whereNotNull('c.last_purchase_at')->where('c.last_purchase_at', '<', $since))
            ->when($filter['segment'] === self::NEVER, fn ($q) => $q->whereNull('c.last_purchase_at'))
            ->when($filter['min_spent'] > 0 && $filter['segment'] !== self::NEVER, fn ($q) => $q
                // ‏‏رقمٌ حرفيٌّ لا مربوط: SQLite تقرأ المربوطَ نصًّا فلا يساوي مجموعًا أبدًا — والقيمةُ عشريّةٌ نظيفة من `clean`
                ->whereRaw('c.purchases_total >= '.number_format((float) $filter['min_spent'], 3, '.', '')))
            ->orderBy('c.id')
            ->get(['c.id', 'c.name', 'c.phone', 'c.contact_phone']);
    }
}
