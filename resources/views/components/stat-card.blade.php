@props([
    'label' => '',
    'value' => '',
    'icon' => 'bar-chart-3',
    'trend' => null,
    'up' => true,
    'color' => 'primary',
])

@php
    $map = [
        'primary' => 'bg-primary-50 text-primary-600',
        'secondary' => 'bg-secondary-50 text-secondary-600',
        'success' => 'bg-success-50 text-success-600',
        'warning' => 'bg-warning-50 text-warning-600',
        'danger' => 'bg-danger-50 text-danger-600',
        'info' => 'bg-info-50 text-info-600',
    ];
    $iconClasses = $map[$color] ?? $map['primary'];
@endphp

<div {{ $attributes->merge(['class' => 'bg-white rounded-2xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow']) }}>
    <div class="flex items-start justify-between">
        <div class="min-w-0">
            <p class="text-sm text-gray-500 truncate">{{ $label }}</p>
            <p class="mt-2 text-2xl font-bold text-gray-800 truncate transition-colors" data-stat-value="{{ $label }}">{{ $value }}</p>
        </div>
        <span class="shrink-0 w-11 h-11 rounded-xl flex items-center justify-center {{ $iconClasses }}">
            <x-icon :name="$icon" class="w-5 h-5" />
        </span>
    </div>
    @if ($trend)
        <div class="mt-3 flex items-center gap-1 text-xs font-medium {{ $up ? 'text-success-600' : 'text-danger-500' }}">
            <x-icon :name="$up ? 'trending-up' : 'trending-down'" class="w-4 h-4" />
            <span>{{ $trend }}</span>
            <span class="text-gray-400 font-normal">{{ __('مقارنة بالفترة السابقة') }}</span>
        </div>
    @endif
</div>
