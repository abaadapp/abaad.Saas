{{--
    جدولُ الأصناف — رأسٌ في لغتين، وخطٌّ تحت كلّ سطر، ولا شيءَ غير ذلك.

    ═══ وما ذهب منه ═══

    عمودُ الترقيم `#`: لا وجودَ له في المرجع المعتمد ولا في قائمة الأعمدة
    التي تطلبها المواصفة. وهو عمودٌ يشغل حيّزًا ليقول ما تقوله العينُ بلا
    مساعدة — والفاتورةُ ليست كشفًا يُحال إلى سطرٍ برقمه.

    وأرضيّةُ الرأس، وتناوبُ الصفوف، والحدودُ الرأسيّة: «ممنوع spreadsheet
    appearance». ما بقي خطّان اثنان — تحت الرأس وتحت كلّ صفّ — بسُمكٍ
    واحدٍ ولونٍ واحد.

    ═══ ورأسُه بالمقاس نفسِه الذي تُكتب به الصفوف ═══

    لا أصغرَ ولا أثقلَ ولا متباعدَ الحروف. يفصله عن الصفوف وزنُ التسمية
    والخطُّ أسفله — وهذا يكفي. ورأسٌ يصرخ في ورقةٍ هادئةٍ يُقرأ أوّلَ ما
    تقع عليه العين، وليس هو الخبر.

    والأسعارُ علمٌ واحد يحكم الرأسَ والصفوف معًا: لو حُسب في موضعين
    لخرجت ورقةٌ بعمودٍ في الرأس بلا خلايا تحته — وسندُ التسليم يمشي مع
    الشحنة في يد سائق، وورقةٌ تُظهر له تكلفةَ ما يحمل عطبٌ لا سهو.

    المتغيّرات: `$items` قائمةُ `['name','qty','unit','total','note']` ·
    `$showPrices` · `$showOrdered` عمودُ الكميّة المطلوبة لسند الاستلام.
--}}
@php
    $items = $items ?? [];
    $prices = (bool) ($showPrices ?? true);
    $ordered = (bool) ($showOrdered ?? false);
    $cols = 2 + ($ordered ? 1 : 0) + ($prices ? 2 : 0);

    /** رأسٌ في لغتين — تسميةٌ فوق تسمية */
    $head = function (string $key) {
        [$a, $b] = \App\Support\Paper::pair($key);

        return '<span class="lbl">'.e($a).'</span>'.($b !== '' ? '<br><span class="lbl2">'.e($b).'</span>' : '');
    };
@endphp

<table class="items">
    <thead>
        <tr>
            <th>{!! $head($itemsLabel ?? 'البيان') !!}</th>
            @if ($ordered)
                <th style="width:14%" class="num">{!! $head('المطلوبة') !!}</th>
                <th style="width:14%" class="num">{!! $head('المستلمة') !!}</th>
            @else
                {{--
                    وعمودُ الكميّة يتّسع حين لا أسعارَ على الورقة.

                    عمودان في ورقةٍ بعرض A4 يتركان بين اسم الصنف والكميّة
                    فراغًا بعرض راحة اليد: العينُ تقطعه فتقرأ الرقمَ في
                    غير سطره. والسعرُ حين يُطفأ يُترك عرضُه للكميّة بدل
                    أن يُترك بياضًا.
                --}}
                <th style="width:{{ $prices ? 12 : 26 }}%" class="num">{!! $head('الكمية') !!}</th>
            @endif
            @if ($prices)
                <th style="width:18%" class="amt">{!! $head('السعر') !!}</th>
                <th style="width:24%" class="amt">{!! $head('المبلغ') !!}</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @forelse ($items as $item)
            <tr>
                {{--
                    والاتّجاهُ محسوبٌ لاسم الصنف: تاجرٌ عربيٌّ يبيع أصنافًا
                    بأسماءٍ إنجليزية — والعكس. فيتبع كلُّ سطرٍ أوّلَ حرفٍ
                    قويٍّ فيه ولا تنقلب نقطتُه إلى أوّله. و`dir="auto"` لا
                    يكفي: mpdf لا يعرفه — انظر `Paper::dirOf`.
                --}}
                <td class="bidi item" dir="{{ \App\Support\Paper::dirOf($item['name']) }}">
                    {{ $item['name'] }}
                    @if (filled($item['note'] ?? null))
                        <div dir="{{ \App\Support\Paper::dirOf($item['note']) }}" class="bidi lbl2">{{ $item['note'] }}</div>
                    @endif
                </td>
                @if ($ordered)
                    <td class="num lbl2">{{ $item['ordered'] ?? '—' }}</td>
                @endif
                <td class="num">{{ $item['qty'] }}</td>
                @if ($prices)
                    {{--
                        والمبلغُ معزولٌ عن اتّجاه السطر.

                        «5.000 ر.ع» في فقرةٍ عربيّة يخرج «ر.ع 5.000»: الرقمُ
                        محايدٌ ضعيف و«ر.ع» عربيّة، فتتقدّمها العينُ في القراءة
                        من اليمين. و`direction` في CSS لا يكفي هنا — mpdf يقرأ
                        السمة. فيُلَفّ المبلغُ ليُقرأ كما كُتب: رقمٌ ثمّ عملة.
                    --}}
                    <td class="amt"><span dir="ltr">{{ $item['unit'] ?? '—' }}</span></td>
                    <td class="amt"><span dir="ltr">{{ $item['total'] ?? '—' }}</span></td>
                @endif
            </tr>
        @empty
            <tr><td class="empty" colspan="{{ $cols }}">{{ __('لا أصناف على هذا المستند') }}</td></tr>
        @endforelse
    </tbody>
</table>
