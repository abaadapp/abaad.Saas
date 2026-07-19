@props(['headers' => []])

<div {{ $attributes->merge(['class' => 'bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden']) }}>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-right">
            @if (count($headers))
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/60">
                        @foreach ($headers as $header)
                            <th class="px-4 py-3.5 font-semibold text-gray-500 whitespace-nowrap">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
            @endif
            <tbody class="divide-y divide-gray-50">
                {{ $slot }}
            </tbody>
        </table>
    </div>
    @if (isset($footer))
        <div class="border-t border-gray-100 px-4 py-3">{{ $footer }}</div>
    @endif
</div>
