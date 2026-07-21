<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Demo;

/**
 * بيانات التقارير للعرض داخل نافذة منبثقة (JSON) — كل تقرير له أعمدته وصفوفه.
 */
class ReportDataController extends Controller
{
    public function show(string $key)
    {
        $report = match ($key) {
            'sales' => $this->sales(),
            'profit' => $this->profit(),
            'expenses' => $this->expenses(),
            'tax' => $this->tax(),
            'payments' => $this->payments(),
            'products' => $this->products(),
            'inventory' => $this->inventory(),
            'employees' => $this->employees(),
            'customers' => $this->customers(),
            'categories' => $this->categories(),
            default => null,
        };

        abort_if($report === null, 404);

        return response()->json($report);
    }

    private function money($v): string { return Demo::money($v); }

    private function sales(): array
    {
        $s = Demo::salesSeries();
        $rows = [];
        foreach ($s['labels'] as $i => $label) {
            $rows[] = [$label, $this->money($s['data'][$i] ?? 0)];
        }

        return ['title' => 'تقرير المبيعات', 'columns' => ['الشهر', 'المبيعات'], 'rows' => $rows,
                'summary' => 'إجمالي المبيعات: ' . $this->money(array_sum($s['data']))];
    }

    private function profit(): array
    {
        // topProducts لا تُرجع التكلفة — نأخذها من بطاقة المنتج بالاسم
        $costs = collect(Demo::products())->pluck('cost', 'name');

        $rows = [];
        $totalRevenue = $totalProfit = 0;
        foreach (Demo::topProducts() as $p) {
            $revenue = (float) $p['total'];
            $cost = (float) ($costs[$p['name']] ?? 0) * (int) $p['qty'];
            $profit = $revenue - $cost;
            $totalRevenue += $revenue;
            $totalProfit += $profit;
            $rows[] = [$p['name'], $this->money($revenue), $this->money($cost), $this->money($profit)];
        }

        return ['title' => 'تقرير الأرباح', 'columns' => ['المنتج', 'الإيراد', 'التكلفة', 'الربح'], 'rows' => $rows,
                'summary' => 'إجمالي الربح: ' . $this->money($totalProfit) . ' من إيراد ' . $this->money($totalRevenue)];
    }

    private function expenses(): array
    {
        $rows = [];
        $total = 0;
        foreach (Demo::expenses() as $e) {
            $amount = abs((float) $e['amount']);
            $total += $amount;
            $rows[] = [$e['date'], $e['type'], $e['description'], $this->money($amount)];
        }

        return ['title' => 'تقرير المصروفات', 'columns' => ['التاريخ', 'النوع', 'البيان', 'المبلغ'], 'rows' => $rows,
                'summary' => 'إجمالي المصروفات: ' . $this->money($total)];
    }

    private function tax(): array
    {
        $rate = (float) (\App\Models\Setting::where('business_id', Demo::bid())->where('key', 'vat_rate')->value('value') ?? 5);
        $rows = [];
        $totalTax = 0;
        foreach (Demo::salesSeries()['labels'] as $i => $label) {
            $sales = (float) (Demo::salesSeries()['data'][$i] ?? 0);
            $tax = $sales * $rate / 100;
            $totalTax += $tax;
            $rows[] = [$label, $this->money($sales), $rate . '%', $this->money($tax)];
        }

        return ['title' => 'تقرير الضرائب', 'columns' => ['الشهر', 'المبيعات', 'النسبة', 'الضريبة'], 'rows' => $rows,
                'summary' => 'إجمالي الضريبة المستحقة: ' . $this->money($totalTax)];
    }

    private function payments(): array
    {
        $rows = [];
        foreach (Demo::paymentMethods() as $m) {
            $rows[] = [$m['name'], $this->money($m['total']), (string) $m['count']];
        }

        return ['title' => 'وسائل الدفع', 'columns' => ['الوسيلة', 'الإجمالي', 'عدد العمليات'], 'rows' => $rows,
                'summary' => 'عدد الوسائل النشطة: ' . count($rows)];
    }

    private function products(): array
    {
        $rows = [];
        foreach (Demo::products() as $p) {
            $rows[] = [$p['name'], $p['cat'], $this->money($p['price']), (string) $p['qty'], $p['stock_status']];
        }

        return ['title' => 'تقرير المنتجات', 'columns' => ['المنتج', 'التصنيف', 'السعر', 'الكمية', 'حالة المخزون'], 'rows' => $rows,
                'summary' => 'عدد المنتجات: ' . count($rows)];
    }

    private function inventory(): array
    {
        $rows = [];
        $low = 0;
        foreach (Demo::inventory() as $i) {
            if ((int) $i['qty'] <= (int) $i['min']) {
                $low++;
            }
            $rows[] = [$i['name'], $i['sku'], (string) $i['qty'], (string) $i['min'], $i['status']];
        }

        return ['title' => 'تقرير المخزون', 'columns' => ['المنتج', 'SKU', 'الكمية', 'الحد الأدنى', 'الحالة'], 'rows' => $rows,
                'summary' => "أصناف تحتاج إعادة طلب: {$low} من " . count($rows)];
    }

    private function employees(): array
    {
        $rows = [];
        foreach (Demo::employees() as $e) {
            $rows[] = [$e['name'], $e['role'], $e['branch'], $this->money($e['achieved']), $e['status']];
        }

        return ['title' => 'تقرير الموظفين', 'columns' => ['الموظف', 'الوظيفة', 'الفرع', 'مبيعات الشهر', 'الحالة'], 'rows' => $rows,
                'summary' => 'عدد الموظفين: ' . count($rows)];
    }

    private function customers(): array
    {
        $rows = [];
        foreach (Demo::topCustomers(50) as $c) {
            $rows[] = [$c['name'], (string) $c['orders'], $this->money($c['total'])];
        }

        return ['title' => 'تقرير العملاء', 'columns' => ['العميل', 'عدد الطلبات', 'إجمالي الإنفاق'], 'rows' => $rows,
                'summary' => 'عدد العملاء الذين اشتروا: ' . count($rows)];
    }

    private function categories(): array
    {
        $cat = Demo::categorySales();
        $rows = [];
        foreach ($cat['labels'] as $i => $label) {
            $rows[] = [$label, $this->money($cat['series'][$i] ?? 0)];
        }

        return ['title' => 'المبيعات حسب التصنيف', 'columns' => ['التصنيف', 'المبيعات'], 'rows' => $rows,
                'summary' => 'عدد التصنيفات: ' . count($rows)];
    }
}
