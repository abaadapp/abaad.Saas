{{--
    أقسامُ نموذج المستند في صفحة التوقيع — انظر `DocumentForms`.

    إدخالاتُ العميل حقولٌ حيّة ما دام المستندُ للتوقيع (`answers[...]`، يرسلها
    النموذجُ مع التوقيع)، وما كتبه الفريقُ يُقرأ ولا يُعدَّل. وقسمُ «بعد التنفيذ»
    يُعرض فارغًا: يُعبّأ عند الاستلام لا هنا.
--}}
@php
    $blank = '______';
    $value = fn (array $p) => ($p['by'] ?? '') === 'team' ? ($team[$p['k']] ?? null) : ($answers[$p['k']] ?? null);
@endphp
@foreach ($form['sections'] as $si => $section)
    @php $after = ! empty($section['after']); @endphp
    <div class="pad rule form-section" data-section="{{ $si }}">
        <h2><span class="n">{{ $next() }} —</span> {{ $section['title'] }}</h2>
        @if ($after)
            <div class="after">يُعبّأ هذا القسم بعد تنفيذ الخدمة · Completed on delivery</div>
        @endif
        @foreach ($section['parts'] as $pi => $p)
            @php
                $kind = $p['kind'];
                $live = $open && ! $after && ($p['by'] ?? '') === 'client';
                $v = $after || ! isset($p['k']) ? null : $value($p);
            @endphp
            @switch($kind)
                @case('p')
                    <p class="para">{{ $p['text'] }}</p>
                    @break
                @case('list')
                    <ul class="items">@foreach ($p['items'] as $it)<li>{{ $it }}</li>@endforeach</ul>
                    @break
                @case('note')
                    <div class="note">{{ $p['text'] }}</div>
                    @break
                @case('cols')
                    <div class="cols">
                        @foreach ($p['cols'] as $col)
                            <div class="col">
                                @if ($col['head'] ?? '')<div class="col-head">{{ $col['head'] }}</div>@endif
                                <ul class="items">@foreach ($col['items'] as $it)<li>{{ $it }}</li>@endforeach</ul>
                            </div>
                        @endforeach
                    </div>
                    @break
                @case('amount')
                    @if ($doc->amount !== null)
                        <div class="plan" style="margin:8px 0">
                            <div class="name">{{ $doc->amount_label ?: $p['label'] }}</div>
                            <div class="price"><span class="ltr">{{ number_format((float) $doc->amount, 3) }}</span> <small>ر.ع</small></div>
                        </div>
                    @endif
                    @break
                @case('field')
                    <label class="f form-field">
                        {{ $p['label'] }}@if (! empty($p['req']) && $live)<span class="req"> *</span>@endif
                        @if ($live)
                            <input class="t" name="answers[{{ $p['k'] }}]" value="{{ $v }}" type="{{ in_array($p['type'] ?? 'text', ['tel', 'email', 'date', 'number', 'time']) ? $p['type'] : 'text' }}"
                                   dir="{{ $p['dir'] ?? 'rtl' }}" placeholder="{{ $p['ph'] ?? '' }}" maxlength="500" autocomplete="off" @if (! empty($p['req'])) required @endif>
                        @else
                            <div class="ro" dir="{{ $p['dir'] ?? 'rtl' }}">{{ ($v ?? '') !== '' ? $v : $blank }}</div>
                        @endif
                    </label>
                    @break
                @case('checks')
                    <div class="checks">
                        @if ($p['head'] ?? '')<div class="col-head">{{ $p['head'] }}</div>@endif
                        @foreach ($p['items'] as $i => $it)
                            @php $on = in_array($i, array_map('intval', (array) ($v ?? [])), true); @endphp
                            <label class="check">
                                @if ($live)
                                    <input type="checkbox" name="answers[{{ $p['k'] }}][]" value="{{ $i }}" @checked($on)>
                                @else
                                    <span class="box">{{ $on ? '✓' : '' }}</span>
                                @endif
                                <span>{{ $it }}</span>
                            </label>
                        @endforeach
                    </div>
                    @break
                @case('table')
                    <div class="grid-wrap">
                        <table class="grid">
                            <thead><tr>@foreach ($p['head'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
                            <tbody>
                            @foreach ($p['rows'] as $r => $rowLabel)
                                <tr>
                                    <td class="row-label">{{ $rowLabel }}</td>
                                    @foreach ($p['types'] as $c => $type)
                                        @php $cell = (string) (((array) ($v ?? []))[$r][$c] ?? ''); @endphp
                                        <td>
                                            @if ($live && $type === 'check')
                                                <input type="checkbox" name="answers[{{ $p['k'] }}][{{ $r }}][{{ $c }}]" value="1" @checked($cell === '1')>
                                            @elseif ($live && $type === 'pick')
                                                <select name="answers[{{ $p['k'] }}][{{ $r }}][{{ $c }}]" class="t">
                                                    <option value=""></option>
                                                    @foreach ($p['picks'] as $pick)<option value="{{ $pick }}" @selected($cell === $pick)>{{ $pick }}</option>@endforeach
                                                </select>
                                            @elseif ($live)
                                                <input class="t" name="answers[{{ $p['k'] }}][{{ $r }}][{{ $c }}]" value="{{ $cell }}" placeholder="{{ $p['ph'][$c] ?? '' }}" maxlength="200" autocomplete="off">
                                            @elseif ($type === 'check')
                                                {{ $cell === '1' ? '✓' : '' }}
                                            @else
                                                {{ $cell !== '' ? $cell : '' }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @break
                @case('yesno')
                    <div class="yesno">
                        <span>{{ $p['label'] }}:</span>
                        @foreach (['yes' => 'نعم', 'no' => 'لا'] as $opt => $optLabel)
                            <label class="check">
                                @if ($live)
                                    <input type="radio" name="answers[{{ $p['k'] }}]" value="{{ $opt }}" @checked($v === $opt)>
                                @else
                                    <span class="box">{{ $v === $opt ? '✓' : '' }}</span>
                                @endif
                                <span>{{ $optLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                    @break
            @endswitch
        @endforeach
    </div>
@endforeach
