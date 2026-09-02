<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Pagination;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * دفتر اليومية — القيود كما كُتبت، ولا يُكتب منها إلا المتوازن.
 *
 * الحفظ يمرّ بـ`Ledger::post` لا بالنموذج مباشرةً: هي التي تفحص التوازن
 * وتَحرس الترحيل إلى حسابٍ له أبناء وإلى حسابٍ مغلق، وتلفّ الكلّ في معاملة
 * فلا يبقى رأسُ قيدٍ مرفوض. وشاشةٌ تكتب بنفسها كانت ستفترق عن الترحيل
 * التلقائي في هذه الفحوص كلّها.
 */
class JournalController extends Controller
{
    /**
     * ما يُرتَّب في القيود اليومية.
     *
     * و«الإجمالي» مجموعُ سطور القيد لا عمودٌ فيه، فلا يُرتَّب به بلا ضمٍّ
     * يُثقل استعلامًا يحمل سطوره وحساباتها أصلًا.
     */
    private const SORTS = [
        'number' => 'number',
        'date' => 'entry_date',
        'description' => 'description',
        'source' => 'source',
    ];

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        Ledger::ensureSystemAccounts($bid);

        $q = JournalEntry::where('business_id', $bid)->with(['lines.account', 'creator']);

        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('number', $like, "%{$s}%")
                ->orWhere('description', $like, "%{$s}%"));
        }
        if ($source = $request->query('source')) {
            $q->where('source', $source);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('entry_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('entry_date', '<=', $to);
        }

        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('entry_date')->orderByDesc('id'));

        $entries = $q->paginate((int) $request->query('per_page', 20))->withQueryString();

        return Inertia::render('Admin/Finance/Journal', [
            'entries' => collect($entries->items())->map(fn ($e) => [
                'id' => $e->id,
                'number' => $e->number,
                'date' => optional($e->entry_date)->format('Y-m-d'),
                'description' => $e->description,
                'source' => $e->source,
                'author' => $e->creator?->name,
                'total' => $e->totalDebit(),
                'lines' => $e->lines->map(fn ($l) => [
                    'account' => $l->account?->code.' — '.$l->account?->name,
                    'debit' => (float) $l->debit,
                    'credit' => (float) $l->credit,
                    'memo' => $l->memo,
                ])->all(),
            ])->all(),
            'pagination' => Pagination::meta($entries),
            'filters' => $request->only('q', 'source', 'from', 'to')
                + Sort::params($request, self::SORTS),
            'sorts' => Sort::keys(self::SORTS),
            // الأوراق وحدها تقبل القيد — الآباء والمغلقة لا تُعرض أصلًا
            'accounts' => $this->postableAccounts($bid),
            'sources' => JournalEntry::where('business_id', $bid)->distinct()->pluck('source')->filter()->values()->all(),
            'today' => now()->format('Y-m-d'),
        ]);
    }

    public function store(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', Rule::exists('accounts', 'id')->where('business_id', $bid)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $accounts = Account::where('business_id', $bid)
            ->whereIn('id', collect($data['lines'])->pluck('account_id'))->get()->keyBy('id');

        try {
            Ledger::post(
                $bid,
                $data['description'],
                collect($data['lines'])->map(fn ($l) => [
                    'account' => $accounts[$l['account_id']],
                    'debit' => (float) ($l['debit'] ?? 0),
                    'credit' => (float) ($l['credit'] ?? 0),
                    'memo' => $l['memo'] ?? null,
                ])->all(),
                Carbon::parse($data['entry_date']),
                'يدوي',
                Demo::currentBranchId(),
                auth()->id(),
            );
        } catch (RuntimeException $e) {
            /*
             * سبب الرفض يُقال في الشاشة لا في السجلّ.
             *
             * «تعذّر الحفظ» وحدها تجعل التاجر يعيد الضغط عشر مرّات: هو لا يرى
             * الفرق بين مدينه ودائنه، والرسالة تحمله («المدين ١٠٠ والدائن ٩٠»).
             */
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        Activity::log('created', 'قيّد: '.$data['description']);

        return back()->with('toast', ['msg' => __('رُحّل القيد'), 'type' => 'success']);
    }

    /**
     * الحسابات التي تقبل قيدًا: الأوراق المفتوحة وحدها.
     *
     * عرضُ الشجرة كاملةً في القائمة كان يجعل التاجر يختار «الأصول» فيُردّ عند
     * الحفظ برسالةٍ لا يفهم سببها — والاختيار الذي يُرفض دائمًا لا يُعرض.
     */
    private function postableAccounts(int $bid): array
    {
        $parents = Account::where('business_id', $bid)->whereNotNull('parent_id')
            ->distinct()->pluck('parent_id')->all();

        return Account::where('business_id', $bid)->where('active', true)
            ->whereNotIn('id', $parents)->orderBy('code')
            ->get()->map(fn ($a) => [
                'value' => $a->id,
                'label' => $a->code.' — '.$a->name,
                'type' => $a->type,
            ])->all();
    }
}
