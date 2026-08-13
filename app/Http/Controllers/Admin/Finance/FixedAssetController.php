<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Support\Demo;
use App\Support\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * الأصول الثابتة وإهلاكها الشهري.
 *
 * الإهلاك ليس رقمًا في شاشة: هو مصروفٌ يُقيَّد كلّ شهر (مصروف الإهلاك مدين،
 * مجمّع الإهلاك دائن). وبلا قيده يظهر الأصل في الميزانية بثمن شرائه بعد خمس
 * سنين من استعماله، ويظهر الربح أكبر ممّا هو كلّ شهر.
 *
 * وشهرٌ لا يُهلَك مرّتين: `depreciated_through` يقول إلى أين وصل كل أصل،
 * فالضغط على الزرّ مرّتين لا يُضاعف المصروف.
 */
class FixedAssetController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        Ledger::ensureSystemAccounts($bid);

        $assets = FixedAsset::where('business_id', $bid)->orderByDesc('purchased_at')->orderByDesc('id')->get();
        $through = $this->through($request);

        return Inertia::render('Admin/Finance/Assets', [
            'assets' => $assets->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'code' => $a->code,
                'category' => $a->category,
                'purchased_at' => optional($a->purchased_at)->format('Y-m-d'),
                'cost' => (float) $a->cost,
                'salvage_value' => (float) $a->salvage_value,
                'life_months' => (int) $a->life_months,
                'monthly' => $a->monthlyDepreciation(),
                'accumulated' => (float) $a->accumulated,
                'book_value' => $a->bookValue(),
                'depreciated_through' => optional($a->depreciated_through)->format('Y-m'),
                'status' => $a->status,
                'disposed_at' => optional($a->disposed_at)->format('Y-m-d'),
                'disposal_amount' => $a->disposal_amount === null ? null : (float) $a->disposal_amount,
                // ما ينتظر الترحيل عن هذا الأصل حتى الشهر المختار
                'due' => $a->dueThrough($through),
            ])->all(),
            'summary' => [
                'count' => $assets->where('status', 'نشط')->count(),
                'cost' => round((float) $assets->where('status', 'نشط')->sum('cost'), 3),
                'accumulated' => round((float) $assets->where('status', 'نشط')->sum('accumulated'), 3),
                'book_value' => round($assets->where('status', 'نشط')->sum(fn ($a) => $a->bookValue()), 3),
                'due' => round($assets->sum(fn ($a) => $a->dueThrough($through)), 3),
            ],
            'month' => $through->format('Y-m'),
            'today' => now()->format('Y-m-d'),
            'categories' => $assets->pluck('category')->filter()->unique()->values()->all(),
        ]);
    }

    public function store(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:30'],
            'category' => ['nullable', 'string', 'max:255'],
            'purchased_at' => ['required', 'date'],
            'cost' => ['required', 'numeric', 'min:0.001'],
            // الخردة لا تتجاوز التكلفة وإلا صار القسط سالبًا: أصلٌ يُنتج ربحًا كلّ شهر
            'salvage_value' => ['nullable', 'numeric', 'min:0', 'lte:cost'],
            'life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // من أين دُفع ثمنه — وبلا اختيار لا يُقيَّد شراؤه
            'paid_from' => ['nullable', Rule::in(['cash', 'bank', 'payable'])],
        ]);

        $asset = FixedAsset::create([
            'business_id' => $bid,
            'branch_id' => Demo::currentBranchId(),
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'category' => $data['category'] ?? null,
            'purchased_at' => $data['purchased_at'],
            'cost' => $data['cost'],
            'salvage_value' => $data['salvage_value'] ?? 0,
            'life_months' => $data['life_months'],
            'notes' => $data['notes'] ?? null,
        ]);

        if (! empty($data['paid_from'])) {
            try {
                Ledger::post($bid, __('شراء أصل ثابت: ').$asset->name, [
                    ['account' => 'fixed_assets', 'debit' => (float) $asset->cost, 'memo' => $asset->name],
                    ['account' => $data['paid_from'], 'credit' => (float) $asset->cost],
                ], Carbon::parse($asset->purchased_at), 'أصل ثابت', Demo::currentBranchId(), auth()->id(), $asset);
            } catch (RuntimeException $e) {
                // الأصل يبقى مسجَّلًا وقيدُه وحده تعذّر — والسبب يُقال لا يُبتلع
                return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'warning']);
            }
        }

        \App\Support\Activity::log('created', 'سجّل أصلًا ثابتًا: '.$asset->name);

        return back()->with('toast', ['msg' => __('سُجّل الأصل'), 'type' => 'success']);
    }

    /**
     * ترحيل إهلاك الشهر — قيدٌ واحد بسطرٍ لكل أصل.
     *
     * سطرٌ لكل أصل لا سطرٌ جامع: بعد سنتين يُسأل «من أين جاء مصروف الإهلاك
     * هذا؟» فيُفتح القيد ويُقرأ اسم كل أصل ونصيبه.
     */
    public function depreciate(Request $request)
    {
        $bid = $this->bid();
        $through = $this->through($request);

        $assets = FixedAsset::where('business_id', $bid)->where('status', 'نشط')->get()
            ->map(fn ($a) => ['asset' => $a, 'due' => $a->dueThrough($through)])
            ->filter(fn ($r) => $r['due'] > 0)->values();

        if ($assets->isEmpty()) {
            return back()->with('toast', ['msg' => __('لا إهلاك مستحقّ حتى هذا الشهر'), 'type' => 'info']);
        }

        $total = round($assets->sum('due'), 3);

        try {
            DB::transaction(function () use ($bid, $assets, $total, $through) {
                $lines = $assets->map(fn ($r) => [
                    'account' => 'depreciation',
                    'debit' => $r['due'],
                    'memo' => $r['asset']->name,
                ])->all();

                $lines[] = ['account' => 'accumulated_depreciation', 'credit' => $total];

                Ledger::post(
                    $bid,
                    __('إهلاك شهر :m', ['m' => $through->format('Y-m')]),
                    $lines,
                    $through->copy()->endOfMonth(),
                    'إهلاك',
                    null,
                    auth()->id(),
                );

                /*
                 * الأصول تُحدَّث داخل المعاملة نفسها.
                 *
                 * قيدٌ يُرحَّل ثم يسقط التحديث يجعل الشهر يُهلَك مرّتين: الدفتر
                 * يحمل المصروف و`depreciated_through` لا يزال يقول إنه لم يُهلك.
                 */
                foreach ($assets as $r) {
                    $r['asset']->update([
                        'accumulated' => round((float) $r['asset']->accumulated + $r['due'], 3),
                        'depreciated_through' => $through->copy()->endOfMonth()->toDateString(),
                    ]);
                }
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['depreciate' => $e->getMessage()]);
        }

        \App\Support\Activity::log('created', 'رحّل إهلاك '.$through->format('Y-m').' بقيمة '.$total);

        return back()->with('toast', [
            'msg' => __('رُحّل إهلاك :n أصلًا بقيمة :v', ['n' => $assets->count(), 'v' => number_format($total, 3)]),
            'type' => 'success',
        ]);
    }

    /**
     * استبعاد الأصل أو بيعه.
     *
     * القيد يُخرج الأصل ومجمّع إهلاكه من الدفتر معًا، ويعترف بالفرق ربحًا أو
     * خسارة. وبلا هذا يبقى أصلٌ بِيع قبل عامين في الميزانية إلى الأبد.
     */
    public function dispose(Request $request, $id)
    {
        $bid = $this->bid();
        $asset = FixedAsset::where('business_id', $bid)->findOrFail($id);

        if ($asset->status !== 'نشط') {
            return back()->with('toast', ['msg' => __('الأصل مستبعدٌ أصلًا'), 'type' => 'info']);
        }

        $data = $request->validate([
            'disposed_at' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'received_in' => ['nullable', Rule::in(['cash', 'bank'])],
        ]);

        $amount = round((float) ($data['amount'] ?? 0), 3);
        $cost = round((float) $asset->cost, 3);
        $accumulated = round((float) $asset->accumulated, 3);
        // ما زاد عن القيمة الدفترية ربح، وما نقص خسارة
        $result = round($amount + $accumulated - $cost, 3);

        $lines = [];
        if ($accumulated > 0) {
            $lines[] = ['account' => 'accumulated_depreciation', 'debit' => $accumulated, 'memo' => $asset->name];
        }
        if ($amount > 0) {
            $lines[] = ['account' => $data['received_in'] ?? 'cash', 'debit' => $amount];
        }
        if ($result < 0) {
            $lines[] = ['account' => 'other_expenses', 'debit' => abs($result), 'memo' => __('خسارة استبعاد أصل')];
        }
        $lines[] = ['account' => 'fixed_assets', 'credit' => $cost, 'memo' => $asset->name];
        if ($result > 0) {
            $lines[] = ['account' => 'other_income', 'credit' => $result, 'memo' => __('ربح بيع أصل')];
        }

        try {
            DB::transaction(function () use ($bid, $asset, $lines, $data, $amount) {
                Ledger::post(
                    $bid,
                    ($amount > 0 ? __('بيع أصل: ') : __('استبعاد أصل: ')).$asset->name,
                    $lines,
                    Carbon::parse($data['disposed_at']),
                    'أصل ثابت',
                    $asset->branch_id,
                    auth()->id(),
                    $asset,
                );

                $asset->update([
                    'status' => $amount > 0 ? 'مباع' : 'مستبعد',
                    'disposed_at' => $data['disposed_at'],
                    'disposal_amount' => $amount ?: null,
                ]);
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['dispose' => $e->getMessage()]);
        }

        \App\Support\Activity::log('updated', 'استبعد الأصل: '.$asset->name, ['subject_id' => $asset->id]);

        return back()->with('toast', ['msg' => __('سُجّل استبعاد الأصل'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $asset = FixedAsset::where('business_id', $this->bid())->findOrFail($id);

        /*
         * أصلٌ دخل الدفتر لا يُحذف — يُستبعد.
         *
         * حذفُه يترك قيوده بلا مستند: مصروف إهلاكٍ في القوائم لا يُعرف عمّا
         * نشأ، ومجمّعُ إهلاكٍ لا أصل تحته.
         */
        if ($asset->accumulated > 0 || \App\Models\JournalEntry::where('sourceable_type', FixedAsset::class)
            ->where('sourceable_id', $asset->id)->exists()) {
            return back()->with('toast', ['msg' => __('على الأصل قيودٌ في الدفتر — استبعده ولا تحذفه'), 'type' => 'warning']);
        }

        \App\Support\Activity::log('deleted', 'حذف الأصل: '.$asset->name, ['subject_id' => $asset->id]);
        $asset->delete();

        return back()->with('toast', ['msg' => __('حُذف الأصل'), 'type' => 'warning']);
    }

    /** الشهر المطلوب — آخر يومٍ منه، وافتراضًا الشهر الجاري */
    private function through(Request $request): Carbon
    {
        $month = (string) $request->input('month', '');

        return preg_match('/^\d{4}-\d{2}$/', $month)
            ? Carbon::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()
            : now()->endOfMonth();
    }
}
