<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة الباركود — Abad POS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .label { break-inside: avoid; }
        }
    </style>
</head>
<body class="bg-gray-100">
    @php $products = collect(\App\Support\Demo::products())->filter(fn ($p) => $p['active'])->values(); @endphp

    {{-- شريط التحكم --}}
    <div class="no-print sticky top-0 z-10 bg-white border-b border-gray-200 px-4 lg:px-8 py-3 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.products.index') }}" class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-gray-100 text-gray-600">
                <x-icon name="arrow-right" class="w-5 h-5" />
            </a>
            <h1 class="font-bold text-gray-800">ملصقات الباركود <span class="text-gray-400 font-normal">({{ $products->count() }} منتج)</span></h1>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-2 text-sm text-gray-600" x-data>
                <label>عدد النسخ لكل منتج:</label>
                <select x-data @change="document.querySelectorAll('[data-copies]').forEach(el => {}); location.search = '?copies=' + $event.target.value"
                        class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm">
                    @foreach ([1, 2, 3, 4] as $c)
                        <option value="{{ $c }}" @selected((int) request('copies', 1) === $c)>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 text-sm font-medium shadow-sm">
                <x-icon name="printer" class="w-4 h-4" /> طباعة
            </button>
        </div>
    </div>

    @php $copies = max(1, min(4, (int) request('copies', 1))); @endphp

    @if ($products->isEmpty())
        <div class="max-w-md mx-auto mt-20 text-center text-gray-400">
            <x-icon name="package-x" class="w-12 h-12 mx-auto mb-3" />
            <p>لا توجد منتجات مفعّلة لطباعة الباركود.</p>
        </div>
    @else
        <div class="p-4 lg:p-8">
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                @foreach ($products as $p)
                    @for ($i = 0; $i < $copies; $i++)
                        <div class="label bg-white border border-gray-200 rounded-lg p-3 text-center flex flex-col items-center justify-between">
                            <p class="text-xs font-bold text-gray-800 truncate w-full">{{ $p['name'] }}</p>
                            <p class="text-sm font-extrabold text-primary-700 my-1">{{ \App\Support\Demo::money($p['price']) }}</p>
                            <svg class="barcode w-full" data-code="{{ $p['barcode'] ?: ('SKU' . str_pad($p['id'], 6, '0', STR_PAD_LEFT)) }}"></svg>
                        </div>
                    @endfor
                @endforeach
            </div>
        </div>
    @endif
</body>
</html>
