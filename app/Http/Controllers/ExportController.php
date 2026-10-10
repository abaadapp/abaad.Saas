<?php

namespace App\Http\Controllers;

use App\Support\Demo;
use App\Support\Exports\Exports;
use App\Support\Lists\BusinessesList;
use App\Support\Lists\CustomersList;
use App\Support\Lists\ExpensesList;
use App\Support\Lists\InventoryList;
use App\Support\Lists\OrdersList;
use App\Support\Lists\PlatformInvoicesList;
use App\Support\Lists\ProductsList;
use App\Support\Lists\SuppliersList;
use App\Support\Lists\TransactionsList;
use App\Support\Reports;
use App\Support\SalesChannel;
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
        $range = Reports::period('sales', request()->query());
        $report = Reports::salesReport($range, request()->query('channel'), request()->query('boutique'));

        $rows = [];
        // والفرعُ يُكتب كما تُكتب الفترة: الملفّ يغادر الشاشة ولا مبدّلَ
        // فوقه، فإن لم يقل نطاقَه قُرئ على أنّه المتجر كلُّه
        $rows[] = [__('الفرع'), $report['branchName'], ''];
        // و«كل القنوات» لا «غير محدّدة»: `null` غيابُ المُرشِّح، وتلك صفةُ
        // طلبٍ لا يُعرف بابُه — والمختارةُ مُرشِّحًا تصل قيمةً فتبقى باسمها
        $rows[] = [__('القناة'), $report['channel'] === null
            ? __('كل القنوات')
            : SalesChannel::label($report['channel']), ''];
        // ونطاقُ البوتيك لمن عنده بوتيكات — ومن لا، يبقى ملفُّه كما كان
        if ($report['boutiques'] !== []) {
            $rows[] = [__('نطاق التقرير'), $report['boutiqueLabel'], ''];
        }
        $rows[] = [__('الفترة'), $range->label(), ''];
        $rows[] = ['', '', ''];
        $rows[] = [__('— المؤشرات الرئيسية —'), '', ''];
        $rows[] = [__('المؤشر'), __('القيمة'), ''];
        foreach (Reports::rowsFor($report) as $s) {
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
        if ($report['boutique'] !== null) {
            // الدفعُ على الطلب كاملًا لا على بنده — فلا يُنسب إلى نطاقٍ بالظنّ
            $rows[] = [__('لا يُنسب إلى بوتيك: الدفع يُسجَّل على الطلب كاملًا.'), '', ''];
        } else {
            $rows[] = [__('الوسيلة'), __('الإجمالي'), __('عدد العمليات')];
            // بنطاق الحمولة نفسِه — فرعًا وقناةً: جدولٌ يعدّ قنواتٍ لم تُختر
            // يخالف الملخّصَ فوقه في الملفّ الواحد
            foreach (Demo::paymentBreakdown($range, $report['channel'], $report['branchId']) as $m) {
                $rows[] = [$m['name'], number_format((float) $m['total'], 3, '.', ''), $m['count']];
            }
        }
        $rows[] = ['', '', ''];
        $rows[] = [__('— الأكثر مبيعًا —'), '', ''];
        $rows[] = [__('المنتج'), __('المُباع'), __('الإيراد')];
        foreach ($report['topSellingProducts'] as $p) {
            $rows[] = [$p['name'], $p['sold'], number_format((float) $p['revenue'], 3, '.', '')];
        }

        return $this->stream('sales-report-'.implode('-', $range->fileParts()), [__('العنصر'), __('القيمة 1'), __('القيمة 2')], $rows);
    }

    public function products()
    {
        return Exports::csv(ProductsList::export(request()));
    }

    public function orders()
    {
        // الصفوفُ صفوفُ ملفّ Excel نفسُها — انظر `OrdersList::export`
        return Exports::csv(OrdersList::export(request()));
    }

    public function customers()
    {
        return Exports::csv(CustomersList::export(request()));
    }

    /**
     * الموردون — قائمةُ أسماءٍ وأرقامِ تواصل كالعملاء، فتُصدَّر مثلهم.
     *
     * وعدد أوامر الشراء عمودٌ فيها: هو السؤال الأوّل عن أي مورّد — من
     * يُشترى منه فعلًا، ومن بقي اسمًا بلا أمرٍ واحد.
     */
    public function suppliers()
    {
        return Exports::csv(SuppliersList::export(request()));
    }

    public function transactions()
    {
        // مرشِّحاتُ شاشة الحركة نفسُها — انظر `TransactionsList`
        return Exports::csv(TransactionsList::export(request()));
    }

    public function expenses()
    {
        return Exports::csv(ExpensesList::export(request()));
    }

    public function inventory()
    {
        return Exports::csv(InventoryList::export(request()));
    }

    /* ------------------------------ لوحة المنصة ------------------------------ */

    public function businesses()
    {
        return Exports::csv(BusinessesList::export(request()));
    }

    public function invoices()
    {
        return Exports::csv(PlatformInvoicesList::export(request()));
    }

    /* ------------------------------ المولّد ------------------------------ */

    private function stream(string $name, array $headers, array $rows): StreamedResponse
    {
        $filename = "abadpos-{$name}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM لدعم العربية في Excel
            /*
             * و`escape` يُمرَّر فارغًا صراحةً — لا يُترك لافتراضِ PHP.
             *
             * الافتراضيُّ شرطةٌ مائلة، وليس من CSV في شيء: حقلٌ فيه شرطةٌ
             * قبل علامة اقتباس — كمقاس «5 بوصة» يُكتب بعلامةٍ بعد شرطة —
             * يُكتب فيقرؤه إكسل ومَن سواه محرَّفًا. القيمةُ تتبدّل بصمتٍ
             * ولا شيء يقول إنّها تبدّلت. والقياسيُّ (RFC 4180) لا escape
             * فيه: الاقتباسُ يُضاعَف.
             *
             * وPHP 8.4 تُحذّر من تركه، وPHP 9 تجعل الفارغَ افتراضًا —
             * فتمريرُه اليوم يُصلح التلفَ ويُثبّت السلوك قبل أن يتبدّل.
             */
            fputcsv($out, $headers, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
