@props([
    'icon' => 'inbox',
    'title' => 'لا توجد بيانات',
    'message' => 'لم يتم العثور على أي عناصر لعرضها هنا حتى الآن.',
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center text-center py-16 px-6']) }}>
    <div class="w-16 h-16 rounded-2xl bg-gray-100 flex items-center justify-center text-gray-400 mb-4">
        <x-icon :name="$icon" class="w-8 h-8" />
    </div>
    <h3 class="text-base font-semibold text-gray-700">{{ $title }}</h3>
    <p class="mt-1 text-sm text-gray-400 max-w-sm">{{ $message }}</p>
    @if (isset($action))<div class="mt-5">{{ $action }}</div>@endif
</div>
