{{--
    قالبُ تقريرٍ واحد لتقارير القسم كلّها.

    والأعمدةُ تأتي من `Support\ReportColumns` لا تُكتب هنا: قالبٌ لكلّ
    تقرير يعني ستّةَ عشرَ ملفًّا تتفرّق أعمدتُها عن أعمدة الشاشة واحدًا
    بعد واحد، ولا يُكتشف الفرق إلا حين يُقارَن ملفٌّ بشاشته.
--}}
<style>
    * { font-family: 'dejavusans', sans-serif; }
    body { color: #1f2937; font-size: 11px; }
    .head { border-bottom: 3px solid #111111; padding-bottom: 10px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; color: #111111; }
    .muted { color: #6b7280; font-size: 10px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th { background: #111111; color: #ffffff; text-align: right; padding: 7px; font-size: 10px; }
    td { padding: 7px; border-bottom: 1px solid #f3f4f6; font-size: 10px; }
    .cards td { width: 25%; padding: 4px; border: none; }
    .card { background: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; padding: 10px; }
    .card .lbl { color: #6b7280; font-size: 9px; }
    .card .val { font-size: 14px; font-weight: bold; color: #111827; margin-top: 3px; }
    .note { margin-top: 10px; color: #9a3412; font-size: 10px; }
    .foot { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 9px; text-align: center; }
</style>

<div class="head">
    <table style="border:none;"><tr>
        <td style="border:none; width:60%;">
            <div class="brand">{{ $business['name'] ?? 'Abad POS' }}</div>
            <div class="muted">{{ $business['type'] ?? '' }}</div>
        </td>
        <td style="border:none; text-align:left;">
            <div style="font-size:15px; font-weight:bold;">{{ $title }}</div>
            <div class="muted">{{ $branch }}</div>
            {{-- الفترة تُطبع دائمًا: ورقةٌ لا تقول مدّتها تُقرأ على أنها عمر المتجر --}}
            <div class="muted">{{ __('الفترة') }}: {{ $rangeLabel }}</div>
            <div class="muted">{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
        </td>
    </tr></table>
</div>

@if (count($cards))
    <table class="cards"><tr>
        @foreach ($cards as $card)
            <td>
                <div class="card">
                    <div class="lbl">{{ $card['label'] }}</div>
                    <div class="val">{{ $card['value'] }}</div>
                </div>
            </td>
        @endforeach
    </tr></table>
@endif

<table>
    <thead><tr>@foreach ($headings as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ max(1, count($headings)) }}" style="text-align:center; color:#9ca3af;">
                {{ __('لا بيانات في هذه الفترة') }}
            </td></tr>
        @endforelse
    </tbody>
</table>

{{-- البتر يُقال على الورق كما يُقال على الشاشة، وإلّا قُرئت الورقة على أنها الكلّ --}}
@if ($truncated)
    <p class="note">
        {{ __('تُعرض :shown من :total صفًّا.', ['shown' => $truncated['shown'], 'total' => $truncated['total']]) }}
    </p>
@endif

<div class="foot">{{ __('صدر من نظام أبعاد') }} — {{ $generatedAt }}</div>
