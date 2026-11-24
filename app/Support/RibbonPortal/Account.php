<?php

namespace App\Support\RibbonPortal;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\PointTransaction;
use App\Support\CustomerPurchases;
use App\Support\Lists\SalesRegister;
use App\Support\Loyalty;
use App\Support\Money;
use App\Support\SalesChannel;
use App\Support\Search;
use Illuminate\Support\Carbon;

/**
 * ما يقرؤه العميلُ عن نفسه — من مصادر النظام، بلا نسخةٍ ثانية.
 *
 *   - المشترياتُ من سجلّ عمليات البيع (`SalesRegister::forCustomer`): كلُّ بيعةٍ
 *     صفٌّ واحد، والطلبُ الذي غطّته فاتورةٌ لا يتكرّر، والمسودّةُ والمعلَّقُ ليسا
 *     بيعًا؛
 *   - ومجاميعُها من `CustomerPurchases` — رقمُه في قائمة العملاء بعينه؛
 *   - والنقاطُ من `customers.points` وحركاتِها، وقواعدُها من `Loyalty::rules`.
 *
 * ولا يُردّ من الصفّ إلّا ما يخصّ العميل: لا بائعَ ولا فرعَ ولا تكلفةَ ولا ملاحظةَ
 * حركة. وكلُّ قراءةٍ بمتجره ومعرّفه معًا.
 */
final class Account
{
    public const PAGE = 10;

    public const MAX = 50;

    private const STATES = [
        SalesRegister::PAID => 'paid', SalesRegister::PARTIAL => 'partial',
        SalesRegister::UNPAID => 'unpaid', SalesRegister::VOID => 'void',
    ];

    public function __construct(private readonly Customer $customer, private readonly string $lang) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $c = CustomerPurchases::apply(Customer::whereKey($this->customer->id)->where('business_id', $this->customer->business_id))->firstOrFail();

        return [
            'name' => (string) $this->customer->name,
            'points' => (int) $this->customer->points,
            'total' => $this->money(CustomerPurchases::total($c)),
            'count' => CustomerPurchases::count($c),
            'last' => CustomerPurchases::last($c)['date'] ?? null,
        ];
    }

    /**
     * صفحةٌ من مشترياته — الأحدثُ أوّلًا.
     *
     * @param  array{q?: ?string, from?: ?string, to?: ?string}  $filter
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    public function purchases(array $filter = [], int $offset = 0, int $limit = self::PAGE): array
    {
        $bid = (int) $this->customer->business_id;
        $q = SalesRegister::forCustomer($bid, (int) $this->customer->id);

        $term = trim(str_replace(['%', '_', '\\'], '', (string) ($filter['q'] ?? '')));
        if ($term !== '') {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('r.number', $like, "%{$term}%")->orWhere('r.cover_number', $like, "%{$term}%"));
        }
        if ($from = $this->day($filter['from'] ?? null)) {
            $q->where('r.sold_at', '>=', $from->format('Y-m-d 00:00:00'));
        }
        if ($to = $this->day($filter['to'] ?? null)) {
            $q->where('r.sold_at', '<', $to->addDay()->format('Y-m-d 00:00:00'));
        }

        $limit = max(1, min(self::MAX, $limit));
        $found = SalesRegister::sorted($q)->offset(max(0, $offset))->limit($limit + 1)->get();
        $rows = $found->take($limit);

        // فاتورةٌ صادرةٌ غطّت الطلب تُفتح معه — وفاتورتُه هو وحده
        $covers = CustomerInvoice::where('business_id', $bid)->where('customer_id', $this->customer->id)
            ->where('status', CustomerInvoice::ISSUED)
            ->whereIn('id', $rows->where('kind', SalesRegister::ORDER)->pluck('cover_id')->filter()->all())
            ->pluck('number', 'id');

        return [
            'rows' => $rows->map(fn ($r) => $this->row($r, $covers->all()))->values()->all(),
            'more' => $found->count() > $limit,
        ];
    }

    /** @param  array<int, string>  $covers */
    private function row(object $r, array $covers): array
    {
        $order = $r->kind === SalesRegister::ORDER;
        $at = $r->sold_at ? Carbon::parse($r->sold_at) : null;
        $credited = round((float) ($r->credited ?? 0), 3);
        $cover = $order && $r->cover_id !== null && isset($covers[(int) $r->cover_id]) ? (int) $r->cover_id : null;

        return [
            'key' => $r->kind.'-'.(int) $r->id,
            'kind' => $r->kind,
            'id' => (int) $r->id,
            'number' => (string) $r->number,
            'what' => match (true) {
                ! $order => 'kind.invoice',
                $r->source === SalesChannel::POS => 'kind.pos',
                $r->source === SalesChannel::WEBSITE => 'kind.website',
                default => 'kind.order',
            },
            'date' => $at?->format('Y-m-d'),
            // الفاتورةُ بيومها: لا ساعةَ لها في النظام
            'time' => $order ? $at?->format('H:i') : null,
            'amount' => $this->money((float) $r->amount),
            'credited' => $credited > 0.0005 ? $this->money($credited) : null,
            'state' => self::STATES[$r->pay_state] ?? 'unpaid',
            'cover' => $cover !== null ? ['id' => $cover, 'number' => (string) $covers[$cover]] : null,
        ];
    }

    /**
     * النقاط — رصيدُها ومجموعاها، وصفحةٌ من حركاتها، وكيف تُستعمل.
     *
     * والملاحظةُ لا تُردّ: يكتبها الموظّفُ لنفسه، وفيها اسمُه. ويُقرأ منها نوعُ
     * ما يكتبه النظامُ بيده وحدَه (`move`).
     *
     * @return array<string, mixed>
     */
    public function points(int $offset = 0, int $limit = self::PAGE): array
    {
        $q = PointTransaction::where('business_id', $this->customer->business_id)->where('customer_id', $this->customer->id);

        $earned = $used = 0;
        foreach ((clone $q)->get(['type', 'points', 'source_key', 'note']) as $p) {
            $n = (int) $p->points;
            match (self::move($p)) {
                'earned', 'reversed', 'corrected' => $earned += $n,
                'used', 'returned' => $used -= $n,
                default => null,
            };
        }

        $limit = max(1, min(self::MAX, $limit));
        $found = (clone $q)->latest('id')->offset(max(0, $offset))->limit($limit + 1)
            ->get(['id', 'type', 'points', 'balance_after', 'source_key', 'note', 'created_at']);

        return [
            'balance' => (int) $this->customer->points,
            'earned' => max(0, $earned),
            'used' => max(0, $used),
            'rows' => $found->take($limit)->map(fn (PointTransaction $p) => [
                'key' => 'p-'.$p->id,
                'move' => 'move.'.self::move($p),
                'points' => (int) $p->points,
                'after' => (int) $p->balance_after,
                'at' => $p->created_at?->format('Y-m-d H:i'),
            ])->values()->all(),
            'more' => $found->count() > $limit,
        ];
    }

    /** نوعُ الحركة — من عمودها ومفتاحها، ومن صدر ملاحظةٍ يكتبها النظامُ بنصٍّ ثابت */
    private static function move(PointTransaction $p): string
    {
        $key = (string) $p->source_key;
        $starts = fn (string $s) => str_starts_with((string) $p->note, $s);

        return match (true) {
            $p->type === PointTransaction::ADJUST => 'adjusted',
            $starts('تصحيح فاتورة') => 'corrected',
            $p->type === 'redeem' => $starts('إلغاء فاتورة') || $starts('عكس نقاط') ? 'reversed' : 'used',
            default => str_starts_with($key, 'till-return:') || str_contains($key, ':redeem:') || $starts('ردّ نقاط') ? 'returned' : 'earned',
        };
    }

    /**
     * كيف تُستعمل النقاط — من إعدادات المتجر الآن، بلا قيمةٍ تُفترض.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        $r = Loyalty::rules((int) $this->customer->business_id);
        $say = fn (string $key, array $with = []) => Texts::say($this->lang, $key, $with);
        $n = fn (float $v) => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');

        if (! $r['on']) {
            return [$say('rule.off')];
        }

        $lines = [];
        if ($r['earn_rate'] > 0) {
            $lines[] = $say('rule.earn', ['n' => $n($r['earn_rate']), 'amount' => $this->money(1.0)]);
        }
        if (! $r['redeem']) {
            return [...$lines, $say('rule.noRedeem')];
        }

        $lines[] = $r['mode'] === Loyalty::PERCENT
            ? $say('rule.percent', ['n' => $r['points'], 'p' => $n($r['percent'])])
            : $say('rule.fixed', ['n' => $r['points'], 'amount' => $this->money($r['value'])]);

        if ($r['min_points'] > 0) {
            $lines[] = $say('rule.minPoints', ['n' => $r['min_points']]);
        }
        if ($r['min_invoice'] > 0) {
            $lines[] = $say('rule.minInvoice', ['amount' => $this->money($r['min_invoice'])]);
        }
        if ($r['max_pct'] !== null && $r['max_pct'] < 100) {
            $lines[] = $say('rule.maxPct', ['p' => $n($r['max_pct'])]);
        }
        if ($r['max_amount'] !== null) {
            $lines[] = $say('rule.maxAmount', ['amount' => $this->money($r['max_amount'])]);
        }

        return [...$lines, $say('rule.where')];
    }

    private function money(float $value): string
    {
        return Money::format($value, Money::of((int) $this->customer->business_id));
    }

    private function day(?string $raw): ?Carbon
    {
        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) && Carbon::hasFormat($raw, 'Y-m-d')
            ? Carbon::createFromFormat('Y-m-d', $raw)->startOfDay()
            : null;
    }
}
