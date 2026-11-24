<div class="trio">
    <div><span class="muted sm">{{ $t('pointsBalance') }}</span><strong class="num" data-testid="portal-balance">{{ number_format($points['balance']) }}</strong></div>
    <div><span class="muted sm">{{ $t('pointsEarned') }}</span><strong class="num">{{ number_format($points['earned']) }}</strong></div>
    <div><span class="muted sm">{{ $t('pointsUsed') }}</span><strong class="num">{{ number_format($points['used']) }}</strong></div>
</div>
<div class="rules" data-testid="portal-rules">
    <strong>{{ $t('pointsHow') }}</strong>
    <ul>@foreach ($rules as $line)<li>{{ $line }}</li>@endforeach</ul>
</div>
<h3>{{ $t('pointsHistory') }}</h3>
@if ($points['rows'] === [])
    <p class="muted" style="margin:0">{{ $t('pointsNone') }}</p>
@endif
<ul class="list" id="pts">@foreach ($points['rows'] as $p)@include('portal.ribbon._point')@endforeach</ul>
<button class="btn sm" type="button" id="pts-more" @if (! $points['more']) hidden @endif>{{ $t('more') }}</button>
