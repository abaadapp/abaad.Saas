<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Demo;
use App\Support\Reports;

/**
 * بيانات التقارير للعرض داخل نافذة منبثقة (JSON) — كل تقرير له أعمدته وصفوفه.
 *
 * وهي ثلاثةٌ بعدد ما في الفهرس من بطاقاتٍ بلا صفحة، لا أكثر. كانت ثمانية:
 * خمسةٌ منها لا تقصدها بطاقةٌ في الفهرس ولا يفتحها زرّ — تُفتح بكتابة عنوانها
 * وحدها. وقاعدة الفهرس (Support\Reports) أن كل تقريرٍ إمّا صفحةٌ قائمة وإمّا
 * مفتاح بيانات، ولا ثالث؛ ومفتاحٌ لا بطاقة له هو ذلك الثالث بعينه.
 *
 * وفي المحذوفة ما كان يكذب لا ما كان يكرّر فحسب: «الضريبة» تضرب المبيعات في
 * نسبةٍ ثابتة، فلا تسأل هل الضريبة مفعَّلة أصلًا، ولا هل هي مضمَّنة في السعر
 * أم فوقه، ولا هل لبعض الأصناف نسبةٌ خاصّة — فتُخرج للتاجر التزامًا ضريبيًّا
 * مخترَعًا (انظر App\Support\Vat، وهي الباب الوحيد لهذا الحساب). و«الأرباح»
 * تطابق التكلفة بالاسم لا بالمعرّف، وتحسبها على أفضل خمسةٍ ثم تسمّي الناتج
 * «إجمالي الربح».
 *
 * والباقي — المبيعات والمصروفات والمنتجات — لكلٍّ منها في الفهرس بطاقةٌ تقود
 * إلى شاشته الكاملة.
 */
class ReportDataController extends Controller
{
    /**
     * حارس المسار يقيس `admin.reports.*` بصلاحية «التقارير» وحدها — وهي
     * صلاحيةُ فهرسٍ لا صلاحيةُ ما فيه. وهذه التقارير قراءاتٌ على أقسامٍ
     * أخرى: إنفاق العملاء، ومبيعات كل موظف، ومقبوضات الصندوق. فمن مُنح
     * «التقارير» وحدها كان يقرؤها كلّها بكتابة عنوانها، والفهرس نفسه لا
     * يعرض له منها بطاقةً واحدة (انظر Reports::forUser) — منعٌ في الشاشة
     * لا وجود له عند الباب.
     *
     * فيُسأل هنا عن قسم التقرير نفسه، والمجهول يُردّ بـ٤٠٤ لا بجدول.
     */
    public function show(string $key)
    {
        $section = Reports::sectionForData($key);
        abort_if($section === null, 404);
        abort_unless(auth()->user()?->allows($section), 403, __('ليس لديك صلاحية للوصول إلى قسم «:section».', ['section' => $section]));

        $report = match ($key) {
            'payments' => $this->payments(),
            'employees' => $this->employees(),
            'customers' => $this->customers(),
            // لا يُبلَغ: sectionForData ردّ كل ما سواه قبل هذا السطر
            default => abort(404),
        };

        return response()->json($report);
    }

    private function money($v): string { return Demo::money($v); }

    /**
     * الفترة تُقال في الملخّص لا تُترك للتخمين.
     *
     * هذه التقارير تُحسب على الشهر الجاري ولا مبدّل فوقها يقول ذلك، فتُقرأ
     * على أنها عمر المتجر كلّه — و«أكثر العملاء إنفاقًا» في شهرٍ غيرُ
     * «أكثرهم إنفاقًا» منذ فُتح المحل.
     */
    private const RANGE = 'month';

    private function summary(string $text): string
    {
        return $text . ' · ' . Demo::rangeLabel(self::RANGE);
    }

    private function payments(): array
    {
        $rows = [];
        $active = 0;
        foreach (Demo::paymentMethods(self::RANGE) as $m) {
            $rows[] = [$m['name'], $this->money($m['total']), (string) $m['count']];
            $active += $m['count'] > 0 ? 1 : 0;
        }

        // «النشطة» ما تحرّك منها فعلًا: كان العدد مجموع الصفوف، وهي ثلاثةٌ
        // دائمًا مهما كان في الدرج — رقمٌ لا يتغيّر ليس خبرًا
        return ['title' => __('وسائل الدفع'), 'columns' => [__('الوسيلة'), __('الإجمالي'), __('عدد العمليات')], 'rows' => $rows,
                'summary' => $this->summary(__('عدد الوسائل النشطة') . ': ' . $active)];
    }

    private function employees(): array
    {
        $rows = [];
        foreach (Demo::employees() as $e) {
            $rows[] = [$e['name'], $e['role'], $e['branch'], $this->money($e['achieved']), $e['status']];
        }

        return ['title' => __('تقرير الموظفين'), 'columns' => [__('الموظف'), __('الوظيفة'), __('الفرع'), __('مبيعات الشهر'), __('الحالة')], 'rows' => $rows,
                'summary' => $this->summary(__('عدد الموظفين') . ': ' . count($rows))];
    }

    private function customers(): array
    {
        $rows = [];
        foreach (Demo::topCustomers(50, self::RANGE) as $c) {
            $rows[] = [$c['name'], (string) $c['orders'], $this->money($c['total'])];
        }

        return ['title' => __('تقرير العملاء'), 'columns' => [__('العميل'), __('عدد الطلبات'), __('إجمالي الإنفاق')], 'rows' => $rows,
                'summary' => $this->summary(__('عدد العملاء الذين اشتروا') . ': ' . count($rows))];
    }
}
