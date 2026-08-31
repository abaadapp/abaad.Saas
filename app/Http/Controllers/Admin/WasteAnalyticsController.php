<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Support\Demo;
use App\Support\Waste;
use App\Support\WasteInsights;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تحليلات الهالك — قراءةٌ فوق بياناتٍ قائمة، لا نظامُ هالكٍ جديد.
 *
 * تعديلات المخزون تُسجَّل منذ البداية، ولها سببٌ وتكلفةُ لحظة. وكان كلّ ما
 * يُقرأ منها مجموعَين في أعلى الجدول: كم خسرنا وكم كسبنا. وهذا يقول إنّ
 * الخسارة أربعون ولا يقول أين تقع ولا هل تزيد.
 *
 * فلا جدولَ جديد ولا عمودَ جديد: الصفوف نفسها، مقروءةً على ستّة أبعاد.
 */
class WasteAnalyticsController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        /*
         * المدّة الافتراضية شهرٌ إلى اليوم.
         *
         * وتُثبَّت في الخادم لا تُترك فارغةً: مدّةٌ مفتوحة تجمع تاريخ المتجر
         * كلّه، فيقرأ التاجر رقمًا هائلًا يظنّه شهره — والمقارنة بالمدّة
         * السابقة تفقد معناها بلا حدّين.
         */
        $from = (string) $request->query('from', now()->startOfMonth()->toDateString());
        $to = (string) $request->query('to', now()->toDateString());

        $filters = [
            'from' => $from,
            'to' => $to,
            'branch_id' => $request->query('branch_id') ?: null,
            'category_id' => $request->query('category_id') ?: null,
            'product_id' => $request->query('product_id') ?: null,
            'reason' => $request->query('reason') ?: null,
        ];

        $totals = Waste::totals($bid, $filters);
        $previous = Waste::totals($bid, array_merge($filters, Waste::previousWindow($from, $to)));

        return Inertia::render('Admin/Reports/Waste', [
            'totals' => $totals,
            'previous' => $previous,
            'change' => $previous['value'] > 0
                ? round(($totals['value'] - $previous['value']) / $previous['value'] * 100, 1)
                : null,
            'byProduct' => Waste::groupedBy($bid, 'product', $filters),
            'byCategory' => Waste::groupedBy($bid, 'category', $filters),
            'byBranch' => Waste::groupedBy($bid, 'branch', $filters),
            'byReason' => Waste::groupedBy($bid, 'reason', $filters),
            'overTime' => Waste::overTime($bid, $filters),
            'versusConsumption' => Waste::versusConsumption($bid, $filters),
            'insights' => WasteInsights::all($bid, $filters),
            // صفوفٌ قديمة تخالف القاعدة — تُعرض ولا تُصلَح
            'suspicious' => Waste::suspiciousRows($bid),
            'filters' => $filters,
            'options' => [
                'branches' => Branch::where('business_id', $bid)->orderBy('id')
                    ->get(['id', 'name'])->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->all(),
                'categories' => Category::where('business_id', $bid)->orderBy('name')
                    ->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all(),
                'products' => Product::where('business_id', $bid)->orderBy('name')
                    ->get(['id', 'name'])->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all(),
                // القديمة معها: صفوفٌ في القاعدة بأسبابٍ لم تعد تُكتب، وحذفُها
                // من المنتقي يجعلها تُعدّ في المجموع ولا يمكن عزلها
                'reasons' => Waste::all(),
            ],
        ]);
    }
}
