@props([
    'variant' => 'primary',
    'size' => 'md',
    'icon' => null,
    'href' => null,
    'type' => 'button',
])

@php
    $variants = [
        'primary' => 'bg-primary-600 hover:bg-primary-700 text-white shadow-sm',
        'secondary' => 'bg-secondary-600 hover:bg-secondary-700 text-white shadow-sm',
        'success' => 'bg-success-600 hover:bg-success-700 text-white shadow-sm',
        'danger' => 'bg-danger-600 hover:bg-danger-700 text-white shadow-sm',
        'warning' => 'bg-warning-500 hover:bg-warning-600 text-white shadow-sm',
        'outline' => 'border border-gray-300 bg-white hover:bg-gray-50 text-gray-700',
        'ghost' => 'text-gray-600 hover:bg-gray-100',
        'light' => 'bg-primary-50 text-primary-700 hover:bg-primary-100',
        'dark' => 'bg-gray-900 hover:bg-gray-800 text-white shadow-sm',
    ];
    $sizes = [
        'sm' => 'px-3 py-1.5 text-xs gap-1.5',
        'md' => 'px-4 py-2.5 text-sm gap-2',
        'lg' => 'px-6 py-3 text-base gap-2',
    ];
    $classes = 'inline-flex items-center justify-center font-medium rounded-xl transition-colors focus:outline-none focus:ring-2 focus:ring-primary-300 disabled:opacity-50 disabled:cursor-not-allowed '
        . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" class="w-4 h-4" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" class="w-4 h-4" />@endif
        {{ $slot }}
    </button>
@endif
