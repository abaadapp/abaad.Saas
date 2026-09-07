<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * الذممُ المدينة — «ما لك» — من موضعٍ واحد.
 *
 * الملخّصُ الماليّ، وشاشةُ الذمم، وصفحةُ العميل، وتقريرُ الأعمار: أربعةٌ
 * تقرأ من هنا. ولو حسب كلٌّ منها بطريقته لقالت شاشةٌ إنّ لك ألفًا وقالت
 * التي بجوارها تسعمئة — ومن رأى رقمين لم يصدّق أيًّا منهما.
 *
 * ولا `customers.balance`: عمودٌ يُجمَّع فيه الرصيد يفترق عن الفواتير أوّلَ
 * دفعةٍ تُلغى أو فاتورةٍ تُعدَّل. والباقي يُشتقّ دائمًا:
 *
 *     إجمالي الفاتورة − ما خُصّص لها من دفعاتٍ حيّة − ما رُدّ منها
 *
 * وهذا هو نفسُه ما يرحّله دفتر الأستاذ إلى حساب `receivable` — انظر
 * `reconcile()`.
 */
final class Receivables
{
    /** حدودُ أعمار الدَّين — أيّامًا بعد الاستحقاق */
    public const BUCKETS = [
        ['key' => 'not_due', 'label' => 'لم يستحقّ بعد', 'from' => null, 'to' => 0],
        ['key' => '1_30', 'label' => 'متأخّر ١–٣٠ يومًا', 'from' => 1, 'to' => 30],
        ['key' => '31_60', 'label' => 'متأخّر ٣١–٦٠', 'from' => 31, 'to' => 60],
        ['key' => '61_90', 'label' => 'متأخّر ٦١–٩٠', 'from' => 61, 'to' => 90],
        ['key' => '90_plus', 'label' => 'متأخّر أكثر من ٩٠', 'from' => 91, 'to' => null],
    ];

    /** فواتيرُ المتجر القائمة — الصادرةُ وحدها، وما بقي منها أكبرُ من صفر */
    public static function openInvoices(int $businessId, ?int $customerId = null): Collection
    {
        $q = CustomerInvoice::where('business_id', $businessId)->live()
            ->with('customer')
            ->orderBy('due_at')->orderBy('id');

        if ($customerId !== null) {
            $q->where('customer_id', $customerId);
        }

        return $q->get()->filter(fn (CustomerInvoice $i) => $i->outstanding() > 0)->values();
    }

    /** ما على عميلٍ واحد */
    public static function customerOutstanding(int $businessId, int $customerId): float
    {
        return round(self::openInvoices($businessId, $customerId)
            ->sum(fn (CustomerInvoice $i) => $i->outstanding()), 3);
    }

    /**
     * رصيدُ العميل الدائن — ما دفعه ولم يُخصَّص بعد.
     *
     * دفعةٌ زادت عن فواتيره لا تُهدر ولا تجعل فاتورةً سالبة: تبقى هنا حتّى
     * تُخصَّص على فاتورةٍ قادمة.
     */
    public static function customerCredit(int $businessId, int $customerId): float
    {
        return round(CustomerPayment::where('business_id', $businessId)
            ->where('customer_id', $customerId)->live()->get()
            ->sum(fn (CustomerPayment $p) => $p->unallocated()), 3);
    }

    /** ما يستطيع العميل أن يستدينه بعد — `null` حين لا حدَّ له */
    public static function creditHeadroom(Customer $customer): ?float
    {
        if ($customer->credit_limit === null) {
            return null;
        }

        return round((float) $customer->credit_limit
            - self::customerOutstanding((int) $customer->business_id, (int) $customer->id), 3);
    }

    /**
     * مجاميعُ المتجر — تقرؤها شاشةُ الذمم والملخّص المالي معًا.
     *
     * @return array{total: float, overdue: float, due_soon: float, credit: float, invoices: int, customers: int}
     */
    public static function totals(int $businessId): array
    {
        $open = self::openInvoices($businessId);
        $soon = now()->addDays(7)->endOfDay();

        return [
            'total' => round($open->sum(fn ($i) => $i->outstanding()), 3),
            'overdue' => round($open->filter(fn ($i) => $i->daysOverdue() > 0)
                ->sum(fn ($i) => $i->outstanding()), 3),
            'due_soon' => round($open->filter(fn ($i) => $i->due_at !== null
                && $i->daysOverdue() === 0 && $i->due_at->endOfDay()->lte($soon))
                ->sum(fn ($i) => $i->outstanding()), 3),
            'credit' => round(CustomerPayment::where('business_id', $businessId)->live()->get()
                ->sum(fn ($p) => $p->unallocated()), 3),
            'invoices' => $open->count(),
            'customers' => $open->pluck('customer_id')->unique()->count(),
        ];
    }

    /**
     * أعمارُ الدَّين — محسوبةً من تاريخ الاستحقاق لا من عمودٍ مخزَّن.
     *
     * وفاتورةٌ بلا تاريخ استحقاق تُعدّ غيرَ مستحقّة: لم يُتّفق على موعد،
     * فلا يُقال إنّه تأخّر.
     *
     * @return array{buckets: array<int, array<string, mixed>>, customers: array<int, array<string, mixed>>, total: float}
     */
    public static function aging(int $businessId): array
    {
        $open = self::openInvoices($businessId);

        $buckets = array_map(function (array $b) use ($open) {
            $sum = $open->filter(function (CustomerInvoice $i) use ($b) {
                $d = $i->daysOverdue();

                return ($b['from'] === null || $d >= $b['from'])
                    && ($b['to'] === null || $d <= $b['to']);
            })->sum(fn ($i) => $i->outstanding());

            return $b + ['amount' => round($sum, 3)];
        }, self::BUCKETS);

        $customers = $open->groupBy('customer_id')->map(function (Collection $rows) {
            $oldest = $rows->sortByDesc(fn ($i) => $i->daysOverdue())->first();

            return [
                'customer_id' => (int) $rows->first()->customer_id,
                'customer' => $rows->first()->customer?->name ?? '—',
                'outstanding' => round($rows->sum(fn ($i) => $i->outstanding()), 3),
                'overdue' => round($rows->filter(fn ($i) => $i->daysOverdue() > 0)
                    ->sum(fn ($i) => $i->outstanding()), 3),
                'oldest_invoice' => $oldest?->daysOverdue() > 0 ? $oldest->number : null,
                'days_overdue' => (int) ($oldest?->daysOverdue() ?? 0),
            ];
        })->sortByDesc('outstanding')->values()->all();

        return [
            'buckets' => $buckets,
            'customers' => $customers,
            'total' => round($open->sum(fn ($i) => $i->outstanding()), 3),
        ];
    }

    /**
     * كشفُ حساب العميل — رصيدٌ افتتاحيٌّ ثمّ حركةٌ برصيدٍ جارٍ.
     *
     * @return array{opening: float, rows: array<int, array<string, mixed>>, closing: float}
     */
    public static function statement(int $businessId, int $customerId, Carbon $from, Carbon $to): array
    {
        $rows = [];

        $invoices = CustomerInvoice::where('business_id', $businessId)
            ->where('customer_id', $customerId)
            ->where('status', CustomerInvoice::ISSUED)->get();

        foreach ($invoices as $inv) {
            $rows[] = ['at' => $inv->issued_at, 'kind' => 'فاتورة', 'ref' => $inv->number,
                'debit' => round((float) $inv->total, 3), 'credit' => 0.0];

            foreach ($inv->creditNotes as $note) {
                $rows[] = ['at' => $note->issued_at, 'kind' => 'إشعار دائن', 'ref' => $note->number,
                    'debit' => 0.0, 'credit' => round((float) $note->amount, 3)];
            }
        }

        $payments = CustomerPayment::where('business_id', $businessId)
            ->where('customer_id', $customerId)->live()->get();

        foreach ($payments as $p) {
            $rows[] = ['at' => $p->occurred_at, 'kind' => 'تحصيل', 'ref' => $p->number,
                'debit' => 0.0, 'credit' => round((float) $p->amount, 3)];
        }

        usort($rows, fn ($a, $b) => [$a['at']?->timestamp ?? 0, $a['ref']] <=> [$b['at']?->timestamp ?? 0, $b['ref']]);

        $opening = 0.0;
        $out = [];
        $running = 0.0;

        foreach ($rows as $r) {
            $delta = $r['debit'] - $r['credit'];

            // ما قبل المدى يُطوى في الرصيد الافتتاحيّ لا يُعرض سطرًا
            if ($r['at'] !== null && $r['at']->lt($from)) {
                $opening = round($opening + $delta, 3);

                continue;
            }
            if ($r['at'] !== null && $r['at']->gt($to)) {
                continue;
            }

            $running = round(($out === [] ? $opening : $running) + $delta, 3);
            $out[] = $r + ['at' => $r['at']?->format('Y-m-d'), 'balance' => $running];
        }

        return [
            'opening' => $opening,
            'rows' => $out,
            'closing' => $out === [] ? $opening : $running,
        ];
    }

    /**
     * مطابقةُ الذمم التشغيليّة برصيد حساب `receivable` في دفتر الأستاذ.
     *
     * وهذا الفحصُ هو الفرق بين نظامٍ يقول رقمًا ونظامٍ يستطيع إثباتَه.
     * ويُقارَن ما يُشتقّ من الفواتير بما رُحّل فعلًا — فإن افترقا فأحدُهما
     * كاذب، والفارقُ يُعرض لا يُخفى.
     *
     * @return array{operational: float, ledger: float, difference: float, balanced: bool}
     */
    public static function reconcile(int $businessId): array
    {
        $operational = self::totals($businessId)['total'] - self::totals($businessId)['credit'];
        $ledger = round(Ledger::balance($businessId, 'receivable'), 3);

        return [
            'operational' => round($operational, 3),
            'ledger' => $ledger,
            'difference' => round($ledger - $operational, 3),
            'balanced' => abs($ledger - $operational) < 0.005,
        ];
    }
}
