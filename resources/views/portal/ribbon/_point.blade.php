<li class="pt" data-key="{{ $p['key'] }}" data-testid="portal-point">
    <div><strong>{{ $t($p['move']) }}</strong><span class="muted sm num">{{ $p['at'] }}</span></div>
    <div class="amt"><strong class="num {{ $p['points'] >= 0 ? 'plus' : 'minus' }}">{{ $p['points'] > 0 ? '+' : '' }}{{ number_format($p['points']) }}</strong><span class="muted sm">{{ $t('balanceAfter') }} <span class="num">{{ number_format($p['after']) }}</span></span></div>
</li>
