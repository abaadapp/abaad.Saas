<?php

namespace App\Support\Lists;

use App\Models\Customer;
use App\Support\CustomerPurchases;
use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\ListFilters;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * قائمةُ العملاء — استعلامٌ واحد تقرؤه الشاشةُ وملفُّها.
 *
 * وملفُّ الاستيراد (`CustomerImportExportController::exportXlsx`) غيرُه:
 * أعمدةُ الاستيراد بلا ترويسة، ليُعاد رفعُه كما خرج.
 */
final class CustomersList
{
    /**
     * ما يُرتَّب في قائمة العملاء.
     *
     * والمجاميع تُرتَّب بأسماء أعمدتها في `CustomerPurchases::apply`: هي أعمدةٌ
     * في الاستعلام المُنتَج، فترتيبها لا يحتاج ضمًّا زائدًا. والمفاتيحُ باقيةٌ
     * بأسمائها القديمة لروابط الترتيب المحفوظة — وما تعدّه المشترياتُ لا الطلبات.
     */
    public const SORTS = [
        'name' => 'name',
        'orders' => 'purchases_count',
        'total_spent' => 'purchases_total',
        'last_order' => 'last_purchase_at',
        'points' => 'points',
    ];

    /** المرشِّحاتُ بلا ترتيب */
    public static function filtered(Request $request): Builder
    {
        /*
         * ما اشتراه العميل فعلًا — طلباتُه المبيعة وفواتيرُه المستقلّة، لا
         * الملغى ولا سلّةً معلّقة ولا دفعةً بلا بيع. انظر `CustomerPurchases`.
         */
        $q = CustomerPurchases::apply(Customer::where('business_id', auth()->user()->business_id ?? Demo::bid()));

        ListFilters::customers($q, $request);

        return $q;
    }

    /** وبترتيب الشاشة: الأحدثُ تسجيلًا ما لم يُختر غيره */
    public static function query(Request $request): Builder
    {
        $q = self::filtered($request);
        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('id'));
        $q->orderByDesc('id');

        return $q;
    }

    public static function row(Customer $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'name_en' => $c->name_en,
            // الاسمُ كما كُتب، بأيّ لغةٍ كانت الواجهة — انظر Customers::asEntered
            'label' => $c->name,
            'phone' => $c->phone, 'email' => $c->email,
            // عددُ المشتريات وإجماليُّها — الطلبُ والفاتورةُ المستقلّة، انظر `CustomerPurchases`
            'orders' => CustomerPurchases::count($c),
            'total_spent' => CustomerPurchases::total($c),
            'last_order' => ($last = CustomerPurchases::last($c)) ? $last['date'] : '—',
            // وآخرُ شراءٍ برقمه وقيمته — طلبًا أو فاتورة — يُفتح من صفّه
            'last_purchase' => $last,
            'points' => $c->points,
            'language' => $c->language,
        ];
    }

    public static function export(Request $request): Dataset
    {
        $query = self::query($request);
        $count = (clone $query)->count();

        return new Dataset(
            title: __('العملاء'),
            file: 'customers',
            fileParts: [now()->format('Y-m-d')],
            filters: [
                __('البحث') => Search::term($request),
                __('اللغة') => (string) $request->query('missing') === 'language' ? __('لم تُحدَّد') : null,
            ],
            perBranch: null,
            columns: [
                __('الاسم') => Workbook::TEXT,
                __('الهاتف') => Workbook::CODE,
                __('البريد') => Workbook::TEXT,
                __('عدد المشتريات') => Workbook::INT,
                Workbook::money('إجمالي المشتريات') => Workbook::MONEY,
                __('آخر شراء') => Workbook::DATE,
                __('رقم آخر شراء') => Workbook::TEXT,
                Workbook::money('قيمة آخر شراء') => Workbook::MONEY,
                __('النقاط') => Workbook::INT,
                __('اللغة') => Workbook::TEXT,
            ],
            rows: function () use ($query) {
                foreach ($query->cursor() as $c) {
                    $r = self::row($c);
                    yield [
                        $r['label'], $r['phone'], $r['email'], $r['orders'], $r['total_spent'],
                        $r['last_order'], $r['last_purchase']['number'] ?? '—', $r['last_purchase']['amount'] ?? null, $r['points'],
                        match ($r['language']) {
                            'ar' => __('العربية'), 'en' => 'English', default => '—'
                        },
                    ];
                }
            },
            totals: [[__('عدد العملاء'), $count, Workbook::INT]],
        );
    }
}
