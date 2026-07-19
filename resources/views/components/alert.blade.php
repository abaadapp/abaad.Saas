@props(['type' => 'info', 'title' => null, 'dismissible' => true])

@php
    $map = [
        'success' => ['bg' => 'bg-success-50 border-success-500/30', 'text' => 'text-success-700', 'icon' => 'circle-check'],
        'warning' => ['bg' => 'bg-warning-50 border-warning-500/30', 'text' => 'text-warning-600', 'icon' => 'alert-triangle'],
        'danger' => ['bg' => 'bg-danger-50 border-danger-500/30', 'text' => 'text-danger-600', 'icon' => 'circle-x'],
        'info' => ['bg' => 'bg-info-50 border-info-500/30', 'text' => 'text-info-600', 'icon' => 'info'],
    ];
    $s = $map[$type] ?? $map['info'];
@endphp

<div x-data="{ show: true }" x-show="show" x-cloak
     {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-xl border p-4 ' . $s['bg']]) }}>
    <x-icon :name="$s['icon']" class="w-5 h-5 shrink-0 mt-0.5 {{ $s['text'] }}" />
    <div class="flex-1 min-w-0">
        @if ($title)<p class="font-semibold text-sm {{ $s['text'] }}">{{ $title }}</p>@endif
        <div class="text-sm text-gray-600 {{ $title ? 'mt-0.5' : '' }}">{{ $slot }}</div>
    </div>
    @if ($dismissible)
        <button type="button" @click="show = false" class="shrink-0 text-gray-400 hover:text-gray-600">
            <x-icon name="x" class="w-4 h-4" />
        </button>
    @endif
</div>
