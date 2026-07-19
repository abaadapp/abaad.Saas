@props(['name' => 'modal', 'title' => null, 'maxWidth' => 'max-w-lg'])

{{--
    نافذة منبثقة تعتمد على Alpine.
    الفتح: $dispatch('open-modal', 'name') أو @click="$dispatch('open-modal','name')"
--}}
<div
    x-data="{ open: false }"
    x-show="open"
    x-cloak
    @open-modal.window="if ($event.detail === '{{ $name }}') open = true"
    @close-modal.window="open = false"
    @keydown.escape.window="open = false"
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
>
    <div x-show="open" x-transition.opacity @click="open = false" class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm"></div>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        class="relative w-full {{ $maxWidth }} bg-white rounded-2xl shadow-xl max-h-[90vh] flex flex-col"
    >
        @if ($title)
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h3 class="text-lg font-bold text-gray-800">{{ $title }}</h3>
                <button type="button" @click="open = false" class="text-gray-400 hover:text-gray-600">
                    <x-icon name="x" class="w-5 h-5" />
                </button>
            </div>
        @endif

        <div class="px-6 py-5 overflow-y-auto">{{ $slot }}</div>

        @if (isset($footer))
            <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-gray-100 bg-gray-50/60 rounded-b-2xl">
                {{ $footer }}
            </div>
        @endif
    </div>
</div>
