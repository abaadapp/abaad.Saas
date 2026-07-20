<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php $dc = \App\Support\Demo::displayCurrency(); $dcDec = in_array($dc['code'], ['OMR', 'KWD', 'BHD']) ? 3 : 2; @endphp
    <meta name="app-currency" content='@json(['rate' => $dc['rate'], 'symbol' => $dc['symbol'], 'decimals' => $dcDec])'>

    <title>{{ $title ?? 'نقطة البيع' }} — Abad POS</title>
    <link rel="stylesheet" href="{{ asset('fonts/ibm-plex-arabic.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="pos-scope h-screen flex flex-col overflow-hidden text-gray-900">
    @php
        $nav = [
            ['label' => 'نقطة البيع', 'icon' => 'store', 'route' => 'pos.index'],
            ['label' => 'الطلبات المعلقة', 'icon' => 'pause-circle', 'route' => 'pos.orders'],
            ['label' => 'الفواتير', 'icon' => 'receipt', 'route' => 'pos.receipts'],
            ['label' => 'العملاء', 'icon' => 'users', 'route' => 'pos.customers'],
            ['label' => 'الوردية', 'icon' => 'clock', 'route' => 'pos.shift'],
        ];
    @endphp

    {{-- شريط علوي — بتصميم شريط دائري عائم (نمط Mondly) --}}
    <div class="px-3 sm:px-4 pt-3 shrink-0">
        <header class="h-14 bg-white/80 backdrop-blur-xl rounded-2xl ring-1 ring-black/5 shadow-[0_1px_3px_rgba(0,0,0,0.04)] flex items-center gap-3 px-3 sm:px-5">
            {{-- الشعار --}}
            <div class="flex items-center gap-2 shrink-0">
                <span class="w-8 h-8 rounded-lg bg-gray-900 text-white flex items-center justify-center">
                    <x-icon name="flower" class="w-5 h-5" />
                </span>
                <span class="font-bold text-gray-900 text-lg tracking-tight hidden sm:block">Abad POS</span>
            </div>

            {{-- التنقل في المنتصف (التبويب النشط بخلفية سوداء) --}}
            <nav class="flex-1 flex items-center justify-center gap-1 overflow-x-auto no-scrollbar">
                @foreach ($nav as $item)
                    @php $active = request()->routeIs($item['route']); @endphp
                    <a href="{{ route($item['route']) }}"
                       class="px-4 py-2 rounded-full text-sm font-medium whitespace-nowrap transition-colors {{ $active ? 'bg-gray-900 text-white shadow-sm' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>

            {{-- أدوات الجانب --}}
            <div class="flex items-center gap-2 shrink-0">
                {{-- مبدّل عملة العرض --}}
                @php $posCurrencies = collect(\App\Support\Demo::currencies())->where('active', true); @endphp
                @if ($posCurrencies->count() > 1)
                    <x-dropdown align="left" width="w-48">
                        <x-slot:trigger>
                            <button class="flex items-center gap-1.5 h-9 px-3 rounded-full bg-gray-50 hover:bg-gray-100 text-gray-700 text-sm font-medium transition-colors" title="عملة العرض">
                                <x-icon name="coins" class="w-4 h-4 text-amber-500" />
                                <span>{{ $dc['code'] }}</span>
                            </button>
                        </x-slot:trigger>
                        <div class="px-4 py-2 text-xs text-gray-400 border-b border-gray-50">عملة العرض</div>
                        @foreach ($posCurrencies as $c)
                            <a href="{{ route('pos.currency.switch', $c['is_base'] ? 'base' : $c['code']) }}"
                               class="flex items-center justify-between gap-2 px-4 py-2.5 text-sm {{ $dc['code'] === $c['code'] ? 'text-gray-900 bg-gray-100 font-semibold' : 'text-gray-600 hover:bg-gray-50' }}">
                                <span class="flex items-center gap-2"><span class="font-mono text-xs">{{ $c['code'] }}</span> {{ $c['name'] }}</span>
                                @if ($c['is_base'])<span class="text-[10px] bg-gray-200 text-gray-700 px-1.5 py-0.5 rounded">أساسية</span>@endif
                            </a>
                        @endforeach
                    </x-dropdown>
                @endif
                <button class="w-9 h-9 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 text-gray-600 transition-colors" title="بحث">
                    <x-icon name="search" class="w-5 h-5" />
                </button>
                <button class="relative w-9 h-9 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 text-gray-600 transition-colors" title="الإشعارات">
                    <x-icon name="bell" class="w-5 h-5" />
                    <span class="absolute top-1.5 right-2 w-2 h-2 bg-red-500 rounded-full ring-2 ring-white"></span>
                </button>
                <x-dropdown align="left" width="w-56">
                    <x-slot:trigger>
                        <button class="flex items-center gap-1.5 pr-1 pl-1.5 py-1 rounded-full hover:bg-gray-50 transition-colors">
                            <img src="https://picsum.photos/seed/posuser/80/80" class="w-8 h-8 rounded-full object-cover ring-1 ring-gray-100" alt="الموظف" />
                            <x-icon name="chevron-down" class="w-4 h-4 text-gray-400" />
                        </button>
                    </x-slot:trigger>
                    <div class="px-4 py-2.5 border-b border-gray-100">
                        <p class="text-sm font-semibold text-gray-800 leading-tight">سارة حسن</p>
                        <p class="text-xs text-gray-400">كاشير</p>
                    </div>
                    <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50"><x-icon name="user" class="w-4 h-4" /> الملف الشخصي</a>
                    <a href="{{ route('pos.shift') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50"><x-icon name="clock" class="w-4 h-4" /> الوردية</a>
                    <div class="my-1 border-t border-gray-100"></div>
                    <a href="{{ route('logout') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-danger-600 hover:bg-danger-50"><x-icon name="log-out" class="w-4 h-4" /> تسجيل الخروج</a>
                </x-dropdown>
            </div>
        </header>
    </div>

    <main class="flex-1 overflow-hidden">
        {{ $slot }}
    </main>

    <x-toasts />
</body>
</html>
