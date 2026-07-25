@props([
    'label' => null,
    'name' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => null,
])

<div>
    @if ($label)
        <label @if($name) for="{{ $name }}" @endif class="block text-sm font-medium text-gray-700 mb-1.5">{{ $label }}</label>
    @endif
    <select
        @if ($name) name="{{ $name }}" id="{{ $name }}" @endif
        {{ $attributes->merge(['class' => 'w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition']) }}
    >
        @if ($placeholder)<option value="">{{ $placeholder }}</option>@endif
        @if (count($options))
            @php $isList = array_is_list($options); @endphp
            @foreach ($options as $key => $val)
                @php $optValue = $isList ? $val : $key; @endphp
                <option value="{{ $optValue }}" @selected((string) $selected === (string) $optValue)>{{ $val }}</option>
            @endforeach
        @else
            {{ $slot }}
        @endif
    </select>
</div>
