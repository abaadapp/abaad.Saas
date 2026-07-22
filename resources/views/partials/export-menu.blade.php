{{--
    قائمة تصدير موحّدة بثلاث صيغ: إكسل + PDF + CSV.
    يُمرَّر إليها روابط الصيغ (أي منها اختياري):
      $xlsx, $pdf, $csv  ونص اختياري $label
--}}
@php
    $xlsx = $xlsx ?? null;
    $pdf = $pdf ?? null;
    $csv = $csv ?? null;
    $label = $label ?? __('تصدير');
@endphp
<x-dropdown align="left" width="w-56">
    <x-slot:trigger>
        <span class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-full border border-gray-200 text-gray-700 hover:bg-gray-50 cursor-pointer">
            <x-icon name="download" class="w-4 h-4" /> {{ $label }}
        </span>
    </x-slot:trigger>
    @if ($xlsx)
        <a href="{{ $xlsx }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
            <x-icon name="file-spreadsheet" class="w-4 h-4 text-gray-400" /> {{ __('تصدير كملف إكسل') }}
        </a>
    @endif
    @if ($pdf)
        <a href="{{ $pdf }}" target="_blank" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
            <x-icon name="file-text" class="w-4 h-4 text-gray-400" /> {{ __('تصدير كملف PDF') }}
        </a>
    @endif
    @if ($csv)
        <a href="{{ $csv }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
            <x-icon name="file-down" class="w-4 h-4 text-gray-400" /> {{ __('تصدير كملف CSV') }}
        </a>
    @endif
</x-dropdown>
