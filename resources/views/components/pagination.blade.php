@props(['total' => 128, 'perPage' => 10, 'current' => 1, 'paginator' => null])

@php
    $isReal = $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    if ($isReal) {
        $total = $paginator->total();
        $perPage = $paginator->perPage();
        $current = $paginator->currentPage();
        $pages = $paginator->lastPage();
        $from = $paginator->firstItem() ?? 0;
        $to = $paginator->lastItem() ?? 0;
    } else {
        $pages = (int) ceil($total / max(1, $perPage));
        $from = $total ? ($current - 1) * $perPage + 1 : 0;
        $to = min($current * $perPage, $total);
    }
    $window = [];
    for ($i = max(1, $current - 2); $i <= min($pages, $current + 2); $i++) { $window[] = $i; }
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col sm:flex-row items-center justify-between gap-3']) }}>
    <p class="text-sm text-gray-500">
        عرض <span class="font-medium text-gray-700">{{ $from }}</span>
        إلى <span class="font-medium text-gray-700">{{ $to }}</span>
        من <span class="font-medium text-gray-700">{{ $total }}</span> نتيجة
    </p>
    <nav class="flex items-center gap-1">
        {{-- السابق --}}
        @php $prevUrl = $isReal ? $paginator->previousPageUrl() : null; @endphp
        <a @if ($prevUrl) href="{{ $prevUrl }}" @endif
           class="w-9 h-9 flex items-center justify-center rounded-lg border border-gray-200 {{ $prevUrl ? 'text-gray-600 hover:bg-gray-50' : 'text-gray-300 cursor-not-allowed' }}">
            <x-icon name="chevron-right" class="w-4 h-4" />
        </a>

        @if ($window && $window[0] > 1)
            <a @if ($isReal) href="{{ $paginator->url(1) }}" @endif class="w-9 h-9 flex items-center justify-center rounded-lg text-sm font-medium border border-gray-200 text-gray-600 hover:bg-gray-50">1</a>
            @if ($window[0] > 2)<span class="px-1 text-gray-400">…</span>@endif
        @endif

        @foreach ($window as $i)
            <a @if ($isReal) href="{{ $paginator->url($i) }}" @endif
               class="w-9 h-9 flex items-center justify-center rounded-lg text-sm font-medium {{ $i === $current ? 'bg-primary-600 text-white' : 'border border-gray-200 text-gray-600 hover:bg-gray-50' }}">
                {{ $i }}
            </a>
        @endforeach

        @if ($window && end($window) < $pages)
            @if (end($window) < $pages - 1)<span class="px-1 text-gray-400">…</span>@endif
            <a @if ($isReal) href="{{ $paginator->url($pages) }}" @endif class="w-9 h-9 flex items-center justify-center rounded-lg text-sm font-medium border border-gray-200 text-gray-600 hover:bg-gray-50">{{ $pages }}</a>
        @endif

        {{-- التالي --}}
        @php $nextUrl = $isReal ? $paginator->nextPageUrl() : null; @endphp
        <a @if ($nextUrl) href="{{ $nextUrl }}" @endif
           class="w-9 h-9 flex items-center justify-center rounded-lg border border-gray-200 {{ $nextUrl ? 'text-gray-600 hover:bg-gray-50' : 'text-gray-300 cursor-not-allowed' }}">
            <x-icon name="chevron-left" class="w-4 h-4" />
        </a>
    </nav>
</div>
