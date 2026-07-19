@props(['title' => '', 'subtitle' => null, 'breadcrumbs' => []])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-4 md:flex-row md:items-center md:justify-between mb-6']) }}>
    <div>
        @if (count($breadcrumbs))
            <nav class="flex items-center gap-1.5 text-xs text-gray-400 mb-1.5">
                @foreach ($breadcrumbs as $label => $url)
                    @if (! $loop->last)
                        <a href="{{ $url }}" class="hover:text-primary-600">{{ $label }}</a>
                        <x-icon name="chevron-left" class="w-3.5 h-3.5" />
                    @else
                        <span class="text-gray-600">{{ $label }}</span>
                    @endif
                @endforeach
            </nav>
        @endif
        <h1 class="text-xl md:text-2xl font-bold text-gray-800">{{ $title }}</h1>
        @if ($subtitle)<p class="mt-1 text-sm text-gray-500">{{ $subtitle }}</p>@endif
    </div>
    @if (isset($actions))
        <div class="flex items-center gap-2 flex-wrap">{{ $actions }}</div>
    @endif
</div>
