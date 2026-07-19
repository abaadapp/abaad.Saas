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

    public function orders()
    {
        $rows = array_map(fn ($o) => [
            $o['id'], $o['customer'], $o['employee'], $o['branch'], $o['items_count'],
            number_format($o['total'], 3, '.', ''), $o['payment'], $o['status'], $o['date'],
        ], Demo::orders());

        return $this->stream('orders', ['رقم الطلب', 'العميل', 'الموظف', 'الفرع', 'عدد الأصناف', 'الإجمالي', 'الدفع', 'الحالة', 'التاريخ'], $rows);
    }

    public function products()
    {
        $rows = array_map(fn ($p) => [
            $p['id'], $p['name'], $p['cat'], $p['sku'], $p['barcode'],
            number_format($p['price'], 3, '.', ''), number_format($p['cost'], 3, '.', ''),
            $p['qty'], $p['alert'], $p['stock_status'], $p['active'] ? 'مفعّل' : 'معطّل',
        ], Demo::products());

        return $this->stream('products', ['المعرّف', 'الاسم', 'التصنيف', 'SKU', 'الباركود', 'السعر', 'التكلفة', 'الكمية', 'حد التنبيه', 'حالة المخزون', 'الحالة'], $rows);
    }

    public function customers()
    {
        $rows = array_map(fn ($c) => [
            $c['id'], $c['name'], $c['phone'], $c['email'], $c['orders'],
            number_format($c['total_spent'], 3, '.', ''), $c['points'], $c['last_order'],
        ], Demo::customers());

        return $this->stream('customers', ['المعرّف', 'الاسم', 'الهاتف', 'البريد', 'عدد الطلبات', 'إجمالي الإنفاق', 'النقاط', 'آخر طلب'], $rows);
    }

    public function transactions()
    {
        $rows = array_map(fn ($t) => [
            $t['id'], $t['date'], $t['description'], $t['method'], $t['type'],
            number_format($t['amount'], 3, '.', ''), $t['employee'],
        ], Demo::transactions());

        return $this->stream('transactions', ['المرجع', 'التاريخ', 'الوصف', 'الطريقة', 'النوع', 'المبلغ', 'الموظف'], $rows);
    }

    /* ------------------------------ لوحة المنصة ------------------------------ */

    public function businesses()
    {
        $rows = array_map(fn ($b) => [
            $b['id'], $b['name'], $b['type'], $b['owner'], $b['phone'], $b['email'],
            $b['city'], $b['plan'], $b['status'], $b['branches'], $b['registered'],
        ], Demo::businesses());

        return $this->stream('businesses', ['المعرّف', 'الشركة', 'النوع', 'المالك', 'الهاتف', 'البريد', 'المدينة', 'الباقة', 'الحالة', 'الفروع', 'التسجيل'], $rows);
    }

    public function invoices()
    {
        $rows = array_map(fn ($i) => [
            $i['number'], $i['business'], $i['plan'],
            number_format($i['amount'], 3, '.', ''), $i['date'], $i['status'],
        ], Demo::invoices());

        return $this->stream('invoices', ['رقم الفاتورة', 'الشركة', 'الباقة', 'المبلغ', 'التاريخ', 'الحالة'], $rows);
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
