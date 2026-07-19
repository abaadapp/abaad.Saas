@props(['name' => 'circle', 'class' => 'w-5 h-5'])
{{-- أيقونة Lucide — تُرسم بواسطة JS (resources/js/app.js) --}}
<i data-lucide="{{ $name }}" {{ $attributes->merge(['class' => $class]) }}></i>
