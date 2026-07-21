@props(['product' => []])

@php
    $p = $product;
    $statusColor = match ($p['stock_status'] ?? 'متوفر') {
        'متوفر' => 'success',
        'منخفض' => 'warning',
        default => 'danger',
    };
@endphp

<div {{ $attributes->merge(['class' => 'group bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all overflow-hidden']) }}>
    <div class="relative aspect-square bg-gray-50 overflow-hidden">
        <img src="{{ $p['image'] ?? '' }}" alt="{{ $p['name'] ?? '' }}" loading="lazy"
             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
        <div class="absolute top-2 right-2">
            <x-badge :text="$p['stock_status'] ?? 'متوفر'" />
        </div>
        @if (! empty($p['discount']))
            <span class="absolute top-2 left-2 bg-secondary-600 text-white text-xs font-bold px-2 py-1 rounded-lg">
                {{ __('خصم :n%', ['n' => $p['discount']]) }}
            </span>
        @endif
    </div>
    <div class="p-4">
        <p class="text-xs text-primary-600 font-medium">{{ $p['cat'] ?? '' }}</p>
        <h3 class="mt-1 font-semibold text-gray-800 truncate">{{ $p['name'] ?? '' }}</h3>
        <p class="mt-0.5 text-xs text-gray-400">{{ $p['sku'] ?? '' }}</p>
        <div class="mt-3 flex items-center justify-between">
            <div>
                <p class="text-lg font-bold text-gray-800">{{ \App\Support\Demo::money($p['price'] ?? 0) }}</p>
                <p class="text-xs text-gray-400">{{ __('الكمية: :n', ['n' => $p['qty'] ?? 0]) }}</p>
            </div>
            {{ $slot ?? '' }}
        </div>
    </div>
</div>
