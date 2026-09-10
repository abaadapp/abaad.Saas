{{--
    جدولُ الأصناف — واحدٌ لكلّ الأنواع، وأعمدتُه تتبع ما تعرضه الورقة.

    ═══ والأسعارُ علمٌ واحد يحكم العمودين ═══

    لو حُسب في رأس الجدول وفي الصفوف كلٌّ على حدة لخرجت ورقةٌ بعمودٍ في
    الرأس بلا خلايا تحته — أو العكس. وسندُ التسليم يمشي مع الشحنة في يد
    سائق، وورقةٌ تُظهر له تكلفةَ ما يحمل عطبٌ لا سهو.

    ═══ ولا صفوفَ فارغة ═══

    مستندٌ بلا أصناف يقول ذلك بسطرٍ واحدٍ في وسط الجدول، لا بجدولٍ خاوٍ
    يُقرأ «لم تُحمَّل البيانات».

    المتغيّرات: `$items` قائمةُ `['name','qty','unit','total','note']` ·
    `$showPrices` · `$showOrdered` عمودُ الكميّة المطلوبة لسند الاستلام.
--}}
@php
    $items = $items ?? [];
    $prices = (bool) ($showPrices ?? true);
    $ordered = (bool) ($showOrdered ?? false);
    $cols = 3 + ($ordered ? 1 : 0) + ($prices ? 2 : 0);
@endphp

<table class="items">
    <thead>
        <tr>
            <th style="width:6%" class="num">#</th>
            <th>{{ $itemsLabel ?? __('البيان') }}</th>
            @if ($ordered)
                <th style="width:13%" class="num">{{ __('المطلوبة') }}</th>
                <th style="width:13%" class="num">{{ __('المستلمة') }}</th>
            @else
                <th style="width:13%" class="num">{{ __('الكمية') }}</th>
            @endif
            @if ($prices)
                <th style="width:19%" class="amt">{{ __('سعر الوحدة') }}</th>
                <th style="width:21%" class="amt">{{ __('الإجمالي') }}</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @forelse ($items as $i => $item)
            <tr>
                <td class="num faint">{{ $i + 1 }}</td>
                {{--
                    والاتّجاهُ محسوبٌ لاسم الصنف: تاجرٌ عربيٌّ يبيع أصنافًا
                    بأسماءٍ إنجليزية — والعكس. فيتبع كلُّ سطرٍ أوّلَ حرفٍ
                    قويٍّ فيه ولا تنقلب نقطتُه إلى أوّله. و`dir="auto"` لا
                    يكفي: mpdf لا يعرفه — انظر `Paper::dirOf`.
                --}}
                <td class="bidi" dir="{{ \App\Support\Paper::dirOf($item['name']) }}">
                    {{ $item['name'] }}
                    @if (filled($item['note'] ?? null))
                        <div dir="{{ \App\Support\Paper::dirOf($item['note']) }}" class="sm muted bidi">{{ $item['note'] }}</div>
                    @endif
                </td>
                @if ($ordered)
                    <td class="num muted">{{ $item['ordered'] ?? '—' }}</td>
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
                    <td class="amt muted"><span dir="ltr">{{ $item['unit'] ?? '—' }}</span></td>
                    <td class="amt b"><span dir="ltr">{{ $item['total'] ?? '—' }}</span></td>
                @endif
            </tr>
        @empty
            <tr><td class="empty" colspan="{{ $cols }}">{{ __('لا أصناف على هذا المستند') }}</td></tr>
        @endforelse
    </tbody>
</table>
