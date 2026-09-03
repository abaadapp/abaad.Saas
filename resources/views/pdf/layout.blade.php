{{--
    هيكلُ ورقة A4 — ترويسةٌ واحدة وتذييلٌ واحد لكلّ ما يُطبع.

    وكانت كلُّ ورقةٍ ترسم ترويستَها بيدها: جدولٌ بخليّتين، اسمٌ يمينًا
    وعنوانٌ يسارًا، ونسخةٌ من ذلك في اثنين وعشرين ملفًّا. فتُصلَح واحدةٌ
    وتبقى إحدى وعشرون، ويُضاف الرقمُ الضريبيّ إلى ثلاثٍ ويُنسى في تسع
    عشرة — وفاتورةٌ بلا رقمٍ ضريبيّ ليست فاتورةً ضريبية.

    والقسمُ الذي يملؤه القالب ثلاثة: العنوان (`title`)، وسطورُ التعريف
    تحته (`meta`)، والجسد (`body`). وما عدا ذلك يقع هنا مرّةً واحدة.

    وترقيمُ الصفحات ليس هنا: يضعه المحرّك على كل ورقةٍ بلا أن يعرف القالبُ
    به — انظر App\Support\Pdf::pageNumbers.
--}}
@php
    $brand = \App\Support\Paper::brand($business ?? null, $vatNumber ?? '');
@endphp
@include('pdf.partials.style')

<table class="p-head">
    <tr>
        <td style="width:58%">
            @if ($brand['logo'])
                <img src="{{ $brand['logo'] }}" style="max-height:42pt; margin-bottom:3pt;" alt="">
            @endif
            <div class="p-brand">{{ $brand['name'] }}</div>
            @if ($brand['sub'] !== '')
                <div class="muted small">{{ $brand['sub'] }}</div>
            @endif
            {{--
                «سطرٌ تحت اسم المتجر» من «قوالب الأوراق» — تحت الاسم لا في
                عمود الترقيم: التاجر يكتب فيه شعارَه أو تخصّصَه، ومكانُه حيث
                يقرأه من يقرأ الاسم.
            --}}
            @if (trim((string) ($headerNote ?? '')) !== '')
                <div class="muted small">{{ $headerNote }}</div>
            @endif
            @foreach ($brand['lines'] as $line)
                <div class="muted small">{{ $line }}</div>
            @endforeach
        </td>
        <td style="text-align:left">
            <div class="p-title">@yield('title')</div>
            <div class="p-meta">@yield('meta')</div>
        </td>
    </tr>
</table>

@yield('body')

@hasSection('foot')
    <div class="p-foot muted small">@yield('foot')</div>
@endif
