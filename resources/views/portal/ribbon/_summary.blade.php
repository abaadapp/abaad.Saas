<div class="stats" data-testid="portal-summary">
    <div class="stat hero"><span>{{ $t('points') }}</span><strong><span class="num" data-testid="portal-points">{{ number_format($summary['points']) }}</span> <small>{{ $t('pointsUnit') }}</small></strong></div>
    <div class="stat"><span>{{ $t('totalSpent') }}</span><strong data-testid="portal-total">{{ $summary['total'] }}</strong></div>
    <div class="stat"><span>{{ $t('purchasesCount') }}</span><strong class="num" data-testid="portal-count">{{ number_format($summary['count']) }}</strong></div>
    <div class="stat"><span>{{ $t('lastPurchase') }}</span><strong class="num" data-testid="portal-last">{{ $summary['last'] ?? $t('none') }}</strong></div>
</div>
