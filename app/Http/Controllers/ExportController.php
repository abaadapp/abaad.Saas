<?php

namespace App\Http\Controllers;

use App\Support\Demo;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تصدير البيانات إلى CSV (متوافق مع Excel — يدعم العربية عبر BOM UTF-8).
 * يعتمد على طبقة Demo نفسها التي تعرض البيانات (محدودة بالمستأجر/الفرع).
 */
class ExportController extends Controller
{
    /* ------------------------------ النشاط التجاري ------------------------------ */


    public function products()
    {
        $rows = array_map(fn ($p) => [
            $p['id'], $p['name'], $p['cat'], $p['sku'], $p['barcode'],
            number_format($p['price'], 3, '.', ''), number_format($p['cost'], 3, '.', ''),
            $p['qty'], $p['alert'], $p['stock_status'], $p['active'] ? __('مفعّل') : __('معطّل'),
        ], Demo::products());

        return $this->stream('products', [__('المعرّف'), __('الاسم'), __('التصنيف'), 'SKU', __('الباركود'), __('السعر'), __('التكلفة'), __('الكمية'), __('حد التنبيه'), __('حالة المخزون'), __('الحالة')], $rows);
    }

    public function customers()
    {
        $rows = array_map(fn ($c) => [
            $c['id'], $c['name'], $c['phone'], $c['email'], $c['orders'],
            number_format($c['total_spent'], 3, '.', ''), $c['points'], $c['last_order'],
        ], Demo::customers());

        return $this->stream('customers', [__('المعرّف'), __('الاسم'), __('الهاتف'), __('البريد'), __('عدد الطلبات'), __('إجمالي الإنفاق'), __('النقاط'), __('آخر طلب')], $rows);
    }

    public function transactions()
    {
        $rows = array_map(fn ($t) => [
            $t['id'], $t['date'], $t['description'], $t['method'], $t['type'],
            number_format($t['amount'], 3, '.', ''), $t['employee'],
        ], Demo::transactions());

        return $this->stream('transactions', [__('المرجع'), __('التاريخ'), __('الوصف'), __('الطريقة'), __('النوع'), __('المبلغ'), __('الموظف')], $rows);
    }

    public function analytics()
    {
        $rows = [];
        $rows[] = [__('— أفضل المنتجات —'), '', ''];
        $rows[] = [__('المنتج'), __('الكمية المباعة'), __('الإيراد')];
        foreach (Demo::topProducts() as $p) {
            $rows[] = [$p['name'], $p['qty'], number_format($p['total'], 3, '.', '')];
        }
        $rows[] = ['', '', ''];
        $rows[] = [__('— أفضل العملاء —'), '', ''];
        $rows[] = [__('العميل'), __('عدد الطلبات'), __('إجمالي الإنفاق')];
        foreach (Demo::topCustomers() as $c) {
            $rows[] = [$c['name'], $c['orders'], number_format($c['total'], 3, '.', '')];
        }
        $rows[] = ['', '', ''];
        $rows[] = [__('— المبيعات حسب التصنيف —'), '', ''];
        $cat = Demo::categorySales();
        foreach ($cat['labels'] as $i => $label) {
            $rows[] = [$label, number_format($cat['series'][$i] ?? 0, 3, '.', ''), ''];
        }

        return $this->stream('analytics', [__('العنصر'), __('القيمة 1'), __('القيمة 2')], $rows);
    }

    public function expenses()
    {
        $rows = array_map(fn ($e) => [
            $e['date'], $e['type'], $e['description'],
            number_format($e['amount'], 3, '.', ''), $e['method'], $e['employee'],
        ], Demo::expenses());

        return $this->stream('expenses', [__('التاريخ'), __('النوع'), __('الوصف'), __('المبلغ'), __('الطريقة'), __('الموظف')], $rows);
    }

    public function inventory()
    {
        $rows = array_map(fn ($p) => [
            $p['id'], $p['name'], $p['sku'], $p['qty'], $p['min'], $p['status'], $p['updated'],
        ], Demo::inventory());

        return $this->stream('inventory', [__('المعرّف'), __('المنتج'), 'SKU', __('الكمية الحالية'), __('الحد الأدنى'), __('حالة المخزون'), __('آخر تحديث')], $rows);
    }

    /* ------------------------------ لوحة المنصة ------------------------------ */

    public function businesses()
    {
        $rows = array_map(fn ($b) => [
            $b['id'], $b['name'], $b['type'], $b['owner'], $b['phone'], $b['email'],
            $b['city'], $b['plan'], $b['status'], $b['branches'], $b['registered'],
        ], Demo::businesses());

        return $this->stream('businesses', [__('المعرّف'), __('الشركة'), __('النوع'), __('المالك'), __('الهاتف'), __('البريد'), __('المدينة'), __('الباقة'), __('الحالة'), __('الفروع'), __('التسجيل')], $rows);
    }

    public function invoices()
    {
        $rows = array_map(fn ($i) => [
            $i['number'], $i['business'], $i['plan'],
            number_format($i['amount'], 3, '.', ''), $i['date'], $i['status'],
        ], Demo::invoices());

        return $this->stream('invoices', [__('رقم الفاتورة'), __('الشركة'), __('الباقة'), __('المبلغ'), __('التاريخ'), __('الحالة')], $rows);
    }

    /* ------------------------------ المولّد ------------------------------ */

    private function stream(string $name, array $headers, array $rows): StreamedResponse
    {
        $filename = "abadpos-{$name}-" . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM لدعم العربية في Excel
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
