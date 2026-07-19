@props([
    'menu' => [],
    'brand' => 'Abad POS',
    'subtitle' => 'نظام نقاط البيع',
    'accent' => 'primary',
])

@php
    $accentBg = $accent === 'secondary' ? 'bg-secondary-600' : 'bg-primary-600';
    $activeBg = $accent === 'secondary' ? 'bg-secondary-50 text-secondary-700' : 'bg-primary-50 text-primary-700';
    $activeBar = $accent === 'secondary' ? 'bg-secondary-600' : 'bg-primary-600';
@endphp

{{-- خلفية معتمة على الجوال --}}
<div x-show="$store.sidebar.open" x-cloak @click="$store.sidebar.open = false"
     class="fixed inset-0 z-30 bg-gray-900/50 lg:hidden" x-transition:enter="transition-opacity" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"></div>

<aside
    class="fixed z-40 inset-y-0 right-0 w-72 bg-white border-l border-gray-100 flex flex-col transition-transform duration-300 lg:translate-x-0"
    :class="$store.sidebar.open ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'"
>
    {{-- الشعار --}}
    <div class="h-16 flex items-center gap-3 px-5 border-b border-gray-100 shrink-0">
        <span class="w-10 h-10 rounded-xl {{ $accentBg }} text-white flex items-center justify-center font-bold text-lg shrink-0">
            <x-icon name="flower" class="w-6 h-6" />
        </span>
        <div class="min-w-0">
            <p class="font-bold text-gray-800 truncate">{{ $brand }}</p>
            <p class="text-xs text-gray-400 truncate">{{ $subtitle }}</p>
        </div>
        <button type="button" @click="$store.sidebar.open = false" class="lg:hidden mr-auto text-gray-400">
            <x-icon name="x" class="w-5 h-5" />
        </button>
    </div>

    {{-- عناصر القائمة --}}
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
        @foreach ($menu as $item)
            @if (isset($item['heading']))
                <p class="px-3 pt-4 pb-1 text-xs font-semibold text-gray-300 uppercase tracking-wider">{{ $item['heading'] }}</p>
            @else
                @php
                    // إخفاء العناصر التي لا يملك المستخدم صلاحيتها
                    $section = \App\Support\Permissions::sectionFromRoute($item['route'] ?? null);
                    $allowed = ! auth()->check() || auth()->user()->allows($section);
                    $isActive = isset($item['route']) && request()->routeIs($item['route']);
                    $url = isset($item['route']) ? route($item['route']) : ($item['url'] ?? '#');
                @endphp
                @continue(! $allowed)
                <a href="{{ $url }}"
                   class="relative flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ $isActive ? $activeBg : 'text-gray-600 hover:bg-gray-50' }}">
                    @if ($isActive)<span class="absolute right-0 top-1/2 -translate-y-1/2 w-1 h-6 rounded-l-full {{ $activeBar }}"></span>@endif
                    <x-icon :name="$item['icon'] ?? 'circle'" class="w-5 h-5 shrink-0" />
                    <span class="truncate">{{ $item['label'] }}</span>
                    @if (isset($item['badge']))
                        <span class="mr-auto text-xs bg-secondary-100 text-secondary-700 px-2 py-0.5 rounded-full">{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endif
        @endforeach
    </nav>

    {{-- بطاقة المستخدم --}}
    <div class="p-3 border-t border-gray-100 shrink-0">
        <a href="{{ route('logout') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-danger-600 hover:bg-danger-50 transition-colors">
            <x-icon name="log-out" class="w-5 h-5" />
            <span>تسجيل الخروج</span>
        </a>
    </div>
</aside>
