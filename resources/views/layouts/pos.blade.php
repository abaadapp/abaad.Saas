<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php $dc = \App\Support\Demo::displayCurrency(); $dcDec = in_array($dc['code'], ['OMR', 'KWD', 'BHD']) ? 3 : 2; @endphp
    <meta name="app-currency" content='@json(['rate' => $dc['rate'], 'symbol' => $dc['symbol'], 'decimals' => $dcDec])'>

    <title>{{ $title ?? __('نقطة البيع') }} — Abad POS</title>
    <link rel="stylesheet" href="{{ asset('fonts/ibm-plex-arabic.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="pos-scope h-screen flex flex-col overflow-hidden text-gray-900" x-data>
    @php
        $nav = [
            ['label' => __('نقطة البيع'), 'icon' => 'store', 'route' => 'pos.index'],
            ['label' => __('الطلبات'), 'icon' => 'receipt-text', 'route' => 'pos.orders'],
            ['label' => __('الفواتير'), 'icon' => 'receipt', 'route' => 'pos.receipts'],
            ['label' => __('العملاء'), 'icon' => 'users', 'route' => 'pos.customers'],
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
                            <button class="flex items-center gap-1.5 h-9 px-3 rounded-full bg-gray-50 hover:bg-gray-100 text-gray-700 text-sm font-medium transition-colors" title="{{ __('عملة العرض') }}">
                                <x-icon name="coins" class="w-4 h-4 text-amber-500" />
                                <span>{{ $dc['code'] }}</span>
                            </button>
                        </x-slot:trigger>
                        <div class="px-4 py-2 text-xs text-gray-400 border-b border-gray-50">{{ __('عملة العرض') }}</div>
                        @foreach ($posCurrencies as $c)
                            <a href="{{ route('pos.currency.switch', $c['is_base'] ? 'base' : $c['code']) }}"
                               class="flex items-center justify-between gap-2 px-4 py-2.5 text-sm {{ $dc['code'] === $c['code'] ? 'text-gray-900 bg-gray-100 font-semibold' : 'text-gray-600 hover:bg-gray-50' }}">
                                <span class="flex items-center gap-2"><span class="font-mono text-xs">{{ $c['code'] }}</span> {{ $c['name'] }}</span>
                                @if ($c['is_base'])<span class="text-[10px] bg-gray-200 text-gray-700 px-1.5 py-0.5 rounded">{{ __('أساسية') }}</span>@endif
                            </a>
                        @endforeach
                    </x-dropdown>
                @endif
                @php $posUser = auth()->user(); @endphp
                <x-dropdown align="left" width="w-56">
                    <x-slot:trigger>
                        <button class="flex items-center gap-1.5 pr-1 pl-1.5 py-1 rounded-full hover:bg-gray-50 transition-colors">
                            @if ($posUser?->avatar)
                                <img src="{{ $posUser->avatar }}" class="w-8 h-8 rounded-full object-cover ring-1 ring-gray-100" alt="{{ $posUser->name }}" />
                            @else
                                <span class="w-8 h-8 rounded-full bg-gray-900 text-white flex items-center justify-center text-xs font-bold ring-1 ring-gray-100">{{ mb_substr($posUser?->name ?? '؟', 0, 1) }}</span>
                            @endif
                            <x-icon name="chevron-down" class="w-4 h-4 text-gray-400" />
                        </button>
                    </x-slot:trigger>
                    <div class="px-4 py-2.5 border-b border-gray-100">
                        <p class="text-sm font-semibold text-gray-800 leading-tight">{{ $posUser?->name ?? '—' }}</p>
                        <p class="text-xs text-gray-400">{{ __($posUser?->roleLabel() ?? 'كاشير') }}</p>
                    </div>
                    <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50"><x-icon name="user" class="w-4 h-4" /> {{ __('الملف الشخصي') }}</a>
                    <a href="{{ route('pos.settings') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50"><x-icon name="settings" class="w-4 h-4" /> {{ __('الإعدادات') }}</a>
                    <div class="my-1 border-t border-gray-100"></div>
                    <a href="{{ route('logout') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-danger-600 hover:bg-danger-50"><x-icon name="log-out" class="w-4 h-4" /> {{ __('تسجيل الخروج') }}</a>
                </x-dropdown>
            </div>
        </header>
    </div>

    <main class="flex-1 overflow-hidden">
        {{ $slot }}
    </main>

    <x-toasts />

    {{-- مؤقّت الخمول: يُخرج الموظف تلقائيًا عند عدم النشاط ويعيده لشاشة الرمز --}}
    @php $idleTimeout = 900; $idleWarn = 60; @endphp
    <div x-data="posIdle({{ $idleTimeout }}, {{ $idleWarn }}, '{{ route('logout') }}?to=pin')" x-cloak>
        <div x-show="warning" x-transition.opacity
             class="fixed inset-0 z-[100] bg-gray-900/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 text-center">
                <div class="w-14 h-14 mx-auto rounded-full bg-warning-50 text-warning-600 flex items-center justify-center mb-4">
                    <x-icon name="clock" class="w-7 h-7" />
                </div>
                <h3 class="text-lg font-bold text-gray-800">{{ __('هل ما زلت هنا؟') }}</h3>
                <p class="text-sm text-gray-500 mt-2">
                    {{ __('سيتم تسجيل خروجك تلقائيًا خلال') }}
                    <span class="font-bold text-danger-600 text-base" x-text="remaining"></span>
                    {{ __('ثانية') }}
                </p>
                <button type="button" @click="stay()"
                        class="mt-5 w-full rounded-xl bg-gray-900 text-white py-2.5 text-sm font-semibold hover:bg-gray-800 transition">
                    {{ __('أنا هنا — متابعة') }}
                </button>
            </div>
        </div>
    </div>

    <script>
        function posIdle(timeout, warn, logoutUrl) {
            return {
                idle: 0,
                warning: false,
                remaining: warn,
                timer: null,
                init() {
                    const reset = () => { if (!this.warning) this.idle = 0; };
                    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(
                        (e) => window.addEventListener(e, reset, { passive: true })
                    );
                    this.timer = setInterval(() => {
                        this.idle++;
                        const left = timeout - this.idle;
                        if (left <= warn) { this.warning = true; this.remaining = Math.max(0, left); }
                        if (this.idle >= timeout) { clearInterval(this.timer); window.location.href = logoutUrl; }
                    }, 1000);
                },
                stay() { this.idle = 0; this.warning = false; this.remaining = warn; },
            };
        }
    </script>
</body>
</html>
