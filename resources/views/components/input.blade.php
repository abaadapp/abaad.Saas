@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'value' => '',
    'placeholder' => '',
    'icon' => null,
    'hint' => null,
    'required' => false,
])

<div>
    @if ($label)
        <label @if($name) for="{{ $name }}" @endif class="block text-sm font-medium text-gray-700 mb-1.5">
            {{ $label }}
            @if ($required)<span class="text-danger-500">*</span>@endif
        </label>
    @endif
    <div class="relative">
        @if ($icon)
            <span class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 pointer-events-none">
                <x-icon :name="$icon" class="w-5 h-5" />
            </span>
        @endif
        <input
            type="{{ $type }}"
            @if ($name) name="{{ $name }}" id="{{ $name }}" @endif
            value="{{ $value }}"
            placeholder="{{ $placeholder }}"
            {{ $attributes->merge(['class' => 'w-full rounded-xl border border-gray-300 bg-white py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition ' . ($icon ? 'pr-10 pl-3' : 'px-3')]) }}
        />
    </div>
    @if ($hint)<p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>@endif
</div>
