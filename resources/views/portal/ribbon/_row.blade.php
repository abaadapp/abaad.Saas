<li class="row" data-key="{{ $row['key'] }}" data-testid="portal-row">
    <div class="who">
        <strong class="num" data-testid="portal-number">{{ $row['number'] }}</strong>
        <span class="muted sm">{{ $t($row['what']) }}@if ($row['cover']) · {{ $t('cover') }} <span class="num">{{ $row['cover']['number'] }}</span>@endif</span>
    </div>
    <div class="when sm muted"><span class="num">{{ $row['date'] }}</span>@if ($row['time']) <span class="num">{{ $row['time'] }}</span>@endif</div>
    <div class="sum">
        <strong>{{ $row['amount'] }}</strong>
        @if ($row['credited'])<span class="muted sm">{{ $t('credited') }} −{{ $row['credited'] }}</span>@endif
    </div>
    <span class="state {{ $row['state'] }}" data-testid="portal-state">{{ $t('state.'.$row['state']) }}</span>
    <div class="acts">
        <a class="btn sm" href="{{ route('my.paper', [$row['kind'], $row['id']], false) }}" data-testid="portal-view">{{ $t('view') }}</a>
        <a class="btn sm text" href="{{ route('my.paper.pdf', [$row['kind'], $row['id'], 'download' => 1], false) }}" data-testid="portal-pdf">PDF</a>
        @if ($row['cover'])
            <a class="btn sm text" href="{{ route('my.paper', ['invoice', $row['cover']['id']], false) }}" data-testid="portal-cover"><span class="num">{{ $row['cover']['number'] }}</span></a>
        @endif
    </div>
</li>
