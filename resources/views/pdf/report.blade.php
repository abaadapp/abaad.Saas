{{--
    قالبُ تقريرٍ واحد لتقارير القسم كلّها.

    والأعمدةُ تأتي من `Support\ReportColumns` لا تُكتب هنا: قالبٌ لكلّ
    تقرير يعني ستّةَ عشرَ ملفًّا تتفرّق أعمدتُها عن أعمدة الشاشة واحدًا
    بعد واحد، ولا يُكتشف الفرق إلا حين يُقارَن ملفٌّ بشاشته.
--}}
@extends('pdf.layout')

@section('title', $title)

@section('meta')
    <div>{{ $branch }}</div>
    {{-- الفترة تُطبع دائمًا: ورقةٌ لا تقول مدّتها تُقرأ على أنها عمر المتجر --}}
    <div><span class="k">{{ __('الفترة') }}:</span> {{ $rangeLabel }}</div>
    <div><span class="k">{{ __('تاريخ الإصدار') }}:</span> <span dir="ltr">{{ $generatedAt }}</span></div>
@endsection

@section('body')
    @if (count($cards))
        <table class="cards"><tr>
            @foreach ($cards as $card)
                <td>
                    <div class="lbl">{{ $card['label'] }}</div>
                    <div class="val">{{ $card['value'] }}</div>
                </td>
            @endforeach
        </tr></table>
    @endif

    @if (count($sections ?? []))
        {{-- تقريرٌ ليس جدولًا واحدًا: كلُّ قراءةٍ بعنوانها، وإلّا التصق جدولٌ بجدول --}}
        @foreach ($sections as $section)
            <h2>{{ $section['title'] }}</h2>
            <table class="grid">
                <thead><tr>@foreach ($section['headings'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($section['rows'] as $row)
                        <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                    @empty
                        <tr><td class="empty" colspan="{{ max(1, count($section['headings'])) }}">
                            {{ __('لا بيانات في هذه الفترة') }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        @endforeach
    @else
        <table class="grid">
            <thead><tr>@foreach ($headings as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                @empty
                    <tr><td class="empty" colspan="{{ max(1, count($headings)) }}">
                        {{ __('لا بيانات في هذه الفترة') }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    {{-- البتر يُقال على الورق كما يُقال على الشاشة، وإلّا قُرئت الورقة على أنها الكلّ --}}
    @if ($truncated)
        <p class="small" style="color:#9a3412">
            {{ __('تُعرض :shown من :total صفًّا.', ['shown' => $truncated['shown'], 'total' => $truncated['total']]) }}
        </p>
    @endif
@endsection

@section('foot')
    <div class="c">{{ __('صدر من نظام أبعاد') }} — <span dir="ltr">{{ $generatedAt }}</span></div>
@endsection
