<?php

namespace App\Http\Controllers;

use App\Support\Demo;
use App\Support\Reports;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تصدير البيانات إلى CSV (متوافق مع Excel — يدعم العربية عبر BOM UTF-8).
 * يعتمد على طبقة Demo نفسها التي تعرض البيانات (محدودة بالمستأجر/الفرع).
 */
class ExportController extends Controller
{
    /* ------------------------------ النشاط التجاري ------------------------------ */

    public function reports()
    {
        // الفترة التي كان التاجر ينظر إليها — الملفّ يغادر الشاشة ولا يصحّحه
        // مبدّلٌ فوقه، فيحملها في أوّل سطرٍ منه وفي اسمه. والحمولة من مصدر
        // الشاشة نفسه — انظر Support\Reports::salesReport
        $report = Reports::salesReport(request()->query('range'));
        $range = $report['range'];

        $rows = [];
        $rows[] = [__('الفترة'), Demo::rangeLabel($range), ''];
        $rows[] = ['', '', ''];
        $rows[] = [__('— المؤشرات الرئيسية —'), '', ''];
        $rows[] = [__('المؤشر'), __('القيمة'), ''];
        foreach (Reports::summaryRows($report['summary']) as $s) {
            $rows[] = [$s['label'], $s['money'] ? number_format((float) $s['value'], 3, '.', '') : $s['value'], ''];
        }
        $rows[] = ['', '', ''];
        $rows[] = [__('— المبيعات —'), '', ''];
        $rows[] = [__('الفترة'), __('المبيعات'), __('عدد الطلبات')];
        $series = $report['salesSeries'];
        foreach ($series['full'] as $i => $label) {
            // ما لم يأتِ بعدُ لا يُكتب: صفٌّ بصفرٍ عن يوم غدٍ رقمٌ لا واقعة
            if (($series['data'][$i] ?? null) === null) {
                continue;
            }
            $rows[] = [$label, number_format((float) $series['data'][$i], 3, '.', ''), (int) ($series['counts'][$i] ?? 0)];
        }
        $rows[] = ['', '', ''];
        $rows[] = [__('— توزيع وسائل الدفع —'), '', ''];
        $rows[] = [__('الوسيلة'), __('الإجمالي'), __('عدد العمليات')];
        foreach (Demo::paymentBreakdown($range) as $m) {
            $rows[] = [$m['name'], number_format((float) $m['total'], 3, '.', ''), $m['count']];
        }
        $rows[] = ['', '', ''];
        $rows[] = [__('— الأكثر مبيعًا —'), '', ''];
        $rows[] = [__('المنتج'), __('المُباع'), __('الإيراد')];
        foreach ($report['topSellingProducts'] as $p) {
            $rows[] = [$p['name'], $p['sold'], number_format((float) $p['revenue'], 3, '.', '')];
        }

        return $this->stream('sales-report-'.$range, [__('العنصر'), __('القيمة 1'), __('القيمة 2')], $rows);
    }

    public function products()
    {
        $rows = array_map(fn ($p) => [
            $p['id'], $p['name'], $p['cat'], $p['sku'], $p['barcode'],
            number_format($p['price'], 3, '.', ''), number_format($p['cost'], 3, '.', ''),
            $p['qty'], $p['alert'], $p['stock_status'], $p['active'] ? __('مفعّل') : __('معطّل'),
        ], Demo::products(null, request()));

        return $this->stream('products', [__('المعرّف'), __('الاسم'), __('القسم'), 'SKU', __('الباركود'), __('السعر'), __('التكلفة'), __('الكمية'), __('حد التنبيه'), __('حالة المخزون'), __('الحالة')], $rows);
    }

    public function orders()
    {
        $rows = array_map(fn ($o) => [
            $o['id'], $o['customer'], $o['employee'], $o['branch'], $o['items_count'],
            number_format($o['total'], 3, '.', ''),
            $o['payment'] === 'بطاقة' ? __('فيزا') : __($o['payment']),
            __($o['status']), $o['date'],
        ], Demo::orders(request()));

        return $this->stream('orders', [__('رقم الطلب'), __('العميل'), __('الموظف'), __('الفرع'), __('عدد الأصناف'), __('الإجمالي'), __('الدفع'), __('الحالة'), __('التاريخ')], $rows);
    }

    public function customers()
    {
        $rows = array_map(fn ($c) => [
            $c['id'], $c['name'], $c['phone'], $c['email'], $c['orders'],
            number_format($c['total_spent'], 3, '.', ''), $c['points'], $c['last_order'],
        ], Demo::customers(request()));

        return $this->stream('customers', [__('المعرّف'), __('الاسم'), __('الهاتف'), __('البريد'), __('عدد الطلبات'), __('إجمالي الإنفاق'), __('النقاط'), __('آخر طلب')], $rows);
    }

    /**
     * الموردون — قائمةُ أسماءٍ وأرقامِ تواصل كالعملاء، فتُصدَّر مثلهم.
     *
     * وعدد أوامر الشراء عمودٌ فيها: هو السؤال الأوّل عن أي مورّد — من
     * يُشترى منه فعلًا، ومن بقي اسمًا بلا أمرٍ واحد.
     */
    public function suppliers()
    {
        $rows = array_map(fn ($s) => [
            $s['id'], $s['name'], $s['phone'] ?? '', $s['email'] ?? '',
            $s['contact'] ?? '', $s['orders_count'],
        ], Demo::suppliers());

        return $this->stream('suppliers', [__('المعرّف'), __('الاسم'), __('الهاتف'), __('البريد'), __('مسؤول التواصل'), __('أوامر الشراء')], $rows);
    }

    public function transactions()
    {
        // الفترة تتبع الشاشة كما في أخواتها — انظر ReportExportController::financeXlsx
        $range = Demo::range(request()->query('range'));

        $rows = array_map(fn ($t) => [
            $t['id'], $t['date'], $t['description'], $t['method'], $t['type'],
            number_format($t['amount'], 3, '.', ''), $t['employee'],
        ], Demo::transactions($range));

        return $this->stream('transactions', [__('المرجع'), __('التاريخ'), __('الوصف'), __('الطريقة'), __('النوع'), __('المبلغ'), __('الموظف')], $rows);
    }

    public function expenses()
    {
        $rows = array_map(fn ($e) => [
            $e['date'], $e['type'], $e['description'],
            number_format($e['amount'], 3, '.', ''), $e['method'], $e['employee'],
        ], Demo::expenses(request()));

        return $this->stream('expenses', [__('التاريخ'), __('النوع'), __('الوصف'), __('المبلغ'), __('الطريقة'), __('الموظف')], $rows);
    }

    public function inventory()
    {
        $rows = array_map(fn ($p) => [
            $p['id'], $p['name'], $p['sku'], $p['qty'], $p['min'],
            number_format($p['value'], 3, '.', ''), $p['status'], $p['updated'],
        ], Demo::inventory());

        return $this->stream('inventory', [__('المعرّف'), __('المنتج'), 'SKU', __('الكمية الحالية'), __('الحد الأدنى'), __('القيمة'), __('حالة المخزون'), __('آخر تحديث')], $rows);
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
        $filename = "abadpos-{$name}-".now()->format('Y-m-d').'.csv';

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
