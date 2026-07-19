@props(['align' => 'left', 'width' => 'w-48'])

@php
    $alignClasses = $align === 'right' ? 'right-0' : 'left-0';
@endphp

<div x-data="{ open: false }" @click.outside="open = false" {{ $attributes->merge(['class' => 'relative']) }}>
    <div @click="open = !open">
        {{ $trigger }}
    </div>
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        class="absolute {{ $alignClasses }} mt-2 {{ $width }} bg-white rounded-xl shadow-lg border border-gray-100 py-1.5 z-40"
    >
        {{ $slot }}
    </div>
</div>
