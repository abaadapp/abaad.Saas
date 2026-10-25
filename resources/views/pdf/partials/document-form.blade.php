{{--
    أقسامُ نموذج المستند في الورقة — جداولُ وحدها. ما كتبه الفريقُ وما أجاب به
    العميلُ كما حُفظا، والفارغُ سطرٌ يُكتب عليه، وقسمُ «بعد التنفيذ» فارغٌ يُعبّأ
    عند الاستلام. انظر `DocumentForms`.
--}}
@php
    $blank = '______________';
    $team = $doc->team ?? [];
    $answers = $doc->answers ?? [];
@endphp
@foreach ($doc->form['sections'] as $section)
    @php $after = ! empty($section['after']); @endphp
    <h2>{{ str_pad((string) $sectionNo++, 2, '0', STR_PAD_LEFT) }} — {{ $section['title'] }}</h2>
    @if ($after)
        <div class="muted small" style="margin-bottom:4pt">{{ __('يُعبّأ هذا القسم بعد تنفيذ الخدمة') }}</div>
    @endif
    @foreach ($section['parts'] as $p)
        @php $v = $after ? null : (($p['by'] ?? '') === 'team' ? ($team[$p['k']] ?? null) : ($answers[$p['k'] ?? ''] ?? null)); @endphp
        @switch($p['kind'])
            @case('p')
                <div style="margin-bottom:4pt">{{ $p['text'] }}</div>
                @break
            @case('list')
                <table class="grid" style="margin-bottom:4pt">
                    @foreach ($p['items'] as $it)
                        <tr><td style="width:4%; vertical-align:top" class="muted">•</td><td>{{ $it }}</td></tr>
                    @endforeach
                </table>
                @break
            @case('note')
                <div class="note small">{{ $p['text'] }}</div>
                @break
            @case('cols')
                <table class="grid" style="margin-bottom:4pt">
                    <tr>
                        @foreach ($p['cols'] as $col)
                            <td style="vertical-align:top; width:{{ floor(100 / max(1, count($p['cols']))) }}%">
                                @if ($col['head'] ?? '')<div class="b">{{ $col['head'] }}</div>@endif
                                @foreach ($col['items'] as $it)<div class="small">• {{ $it }}</div>@endforeach
                            </td>
                        @endforeach
                    </tr>
                </table>
                @break
            @case('amount')
                @if ($doc->amount !== null)
                    <table class="grid" style="margin-bottom:4pt">
                        <tr>
                            <td class="b">{{ $doc->amount_label ?: $p['label'] }}</td>
                            <td class="amt" style="width:30%"><span dir="ltr">{{ number_format((float) $doc->amount, 3) }}</span> {{ __('ر.ع') }}</td>
                        </tr>
                    </table>
                @endif
                @break
            @case('field')
                <table class="grid">
                    <tr>
                        <td style="width:40%" class="muted small">{{ $p['label'] }}</td>
                        <td @if (($p['dir'] ?? '') === 'ltr') dir="ltr" @endif>{{ ($v ?? '') !== '' ? $v : $blank }}</td>
                    </tr>
                </table>
                @break
            @case('checks')
                <table class="grid" style="margin-bottom:4pt">
                    @if ($p['head'] ?? '')<tr><td colspan="2" class="b">{{ $p['head'] }}</td></tr>@endif
                    @foreach ($p['items'] as $i => $it)
                        <tr>
                            <td style="width:6%; text-align:center">{{ in_array($i, array_map('intval', (array) ($v ?? [])), true) ? '☑' : '☐' }}</td>
                            <td>{{ $it }}</td>
                        </tr>
                    @endforeach
                </table>
                @break
            @case('table')
                <table class="grid" style="margin-bottom:4pt">
                    <thead><tr>@foreach ($p['head'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($p['rows'] as $r => $rowLabel)
                            <tr>
                                <td>{{ $rowLabel }}</td>
                                @foreach ($p['types'] as $c => $type)
                                    @php $cell = (string) (((array) ($v ?? []))[$r][$c] ?? ''); @endphp
                                    <td>{{ $type === 'check' ? ($cell === '1' ? '☑' : '☐') : $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @break
            @case('yesno')
                <div class="small" style="margin:4pt 0">{{ $p['label'] }}:
                    {{ $v === 'yes' ? '☑' : '☐' }} {{ __('نعم') }} &nbsp; {{ $v === 'no' ? '☑' : '☐' }} {{ __('لا') }}</div>
                @break
        @endswitch
    @endforeach
@endforeach
