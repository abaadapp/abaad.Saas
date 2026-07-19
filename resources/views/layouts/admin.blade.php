<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'لوحة النشاط' }} — Abad POS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-ui">
    @php
        $menu = [
            ['label' => 'الرئيسية', 'icon' => 'layout-dashboard', 'route' => 'admin.dashboard'],
            ['heading' => 'المتجر'],
            ['label' => 'المنتجات', 'icon' => 'package', 'route' => 'admin.products.index'],
            ['label' => 'التصنيفات', 'icon' => 'tags', 'route' => 'admin.categories.index'],
            ['label' => 'الطلبات', 'icon' => 'shopping-cart', 'route' => 'admin.orders.index'],
            ['label' => 'العملاء', 'icon' => 'users', 'route' => 'admin.customers.index'],
            ['label' => 'التسويق والكوبونات', 'icon' => 'megaphone', 'route' => 'admin.marketing.index'],
            ['heading' => 'الإدارة'],
            ['label' => 'الفروع', 'icon' => 'git-branch', 'route' => 'admin.branches.index'],
            ['label' => 'الموظفون', 'icon' => 'user-cog', 'route' => 'admin.employees.index'],
            ['label' => 'المخزون', 'icon' => 'boxes', 'route' => 'admin.inventory.index'],
            ['label' => 'المورّدون', 'icon' => 'truck', 'route' => 'admin.suppliers.index'],
            ['label' => 'أوامر الشراء', 'icon' => 'clipboard-list', 'route' => 'admin.purchases.index'],
            ['label' => 'المالية', 'icon' => 'wallet', 'route' => 'admin.finance.index'],
            ['label' => 'المصروفات', 'icon' => 'arrow-down-circle', 'route' => 'admin.expenses.index'],
            ['label' => 'ضريبة القيمة المضافة', 'icon' => 'landmark', 'route' => 'admin.vat.index'],
            ['label' => 'التقارير', 'icon' => 'bar-chart-3', 'route' => 'admin.reports.index'],
            ['label' => 'تحليلات متقدمة', 'icon' => 'chart-line', 'route' => 'admin.analytics.index'],
            ['label' => 'الربحية', 'icon' => 'trending-up', 'route' => 'admin.profitability.index'],
            ['label' => 'سجل النشاط', 'icon' => 'history', 'route' => 'admin.activity.index'],
            ['label' => 'الإعدادات', 'icon' => 'settings', 'route' => 'admin.settings.index'],
            ['heading' => 'نقطة البيع'],
            ['label' => 'فتح نقطة البيع', 'icon' => 'store', 'route' => 'pos.index'],
        ];
    @endphp

    {{-- خلفية معتمة على الجوال --}}
    <div x-show="$store.sidebar.open" x-cloak @click="$store.sidebar.open = false"
         class="fixed inset-0 z-30 bg-black/20 backdrop-blur-sm lg:hidden"
         x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"></div>

    {{-- ===== الشريط الجانبي ===== --}}
    <aside class="fixed z-40 inset-y-0 right-0 w-64 bg-white border-l flex flex-col transition-transform duration-300 lg:translate-x-0"
           style="border-color: var(--ui-border);"
           :class="$store.sidebar.open ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'">
        {{-- الشعار --}}
        <div class="h-16 flex items-center gap-3 px-5 shrink-0">
            <span class="w-8 h-8 rounded-[10px] bg-[#111] text-white flex items-center justify-center shrink-0">
                <x-icon name="flower" class="w-[18px] h-[18px]" />
            </span>
            <div class="min-w-0 leading-tight">
                <p class="text-[15px] font-semibold text-[#111] truncate">زهرة مسقط</p>
                <p class="text-[11px] text-[#9ca3af] truncate">لوحة صاحب النشاط</p>
            </div>
            <button type="button" @click="$store.sidebar.open = false" class="lg:hidden mr-auto text-[#9ca3af]">
                <x-icon name="x" class="w-5 h-5" />
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-0.5 no-scrollbar">
            @foreach ($menu as $item)
                @if (isset($item['heading']))
                    <p class="px-3 pt-5 pb-1.5 text-[11px] font-semibold text-[#b3b3b0] tracking-wide">{{ $item['heading'] }}</p>
                @else
                    @php
                        $section = \App\Support\Permissions::sectionFromRoute($item['route'] ?? null);
                        $allowed = ! auth()->check() || auth()->user()->allows($section);
                        $isActive = isset($item['route']) && request()->routeIs($item['route']);
                        $url = isset($item['route']) ? route($item['route']) : ($item['url'] ?? '#');
                    @endphp
                    @continue(! $allowed)
                    <a href="{{ $url }}" class="ui-nav-link {{ $isActive ? 'is-active' : '' }}">
                        <x-icon :name="$item['icon'] ?? 'circle'" class="w-[18px] h-[18px] shrink-0" />
                        <span class="truncate">{{ $item['label'] }}</span>
                    </a>
                @endif
            @endforeach
        </nav>

        <div class="p-3 shrink-0" style="border-top: 1px solid var(--ui-border);">
            <a href="{{ route('logout') }}" class="ui-nav-link text-[#dc2626] hover:!bg-[#fef2f2]">
                <x-icon name="log-out" class="w-[18px] h-[18px]" />
                <span>تسجيل الخروج</span>
            </a>
        </div>
    </aside>

    <div class="lg:mr-64 flex flex-col min-h-screen">
        {{-- ===== الشريط العلوي ===== --}}
        <header class="sticky top-0 z-20 h-16 bg-[#f7f7f5]/80 backdrop-blur-xl flex items-center gap-3 px-4 lg:px-8"
                style="border-bottom: 1px solid var(--ui-border);">
            <button type="button" @click="$store.sidebar.toggle()" class="lg:hidden w-9 h-9 flex items-center justify-center rounded-xl hover:bg-black/5 text-[#111]">
                <x-icon name="menu" class="w-5 h-5" />
            </button>

            <h1 class="text-[17px] font-semibold text-[#111] truncate min-w-0">{{ $title ?? 'لوحة النشاط' }}</h1>

            {{-- بحث موحّد (منتصف) --}}
            @auth
                @php $searchUrl = auth()->user()->business_id ? route('admin.search') : null; @endphp
                @if ($searchUrl)
                    <div class="hidden md:block relative w-full max-w-sm mx-auto" x-data="unifiedSearch('{{ $searchUrl }}')" @click.outside="open = false">
                        <span class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-[#9ca3af] pointer-events-none">
                            <x-icon name="search" class="w-4 h-4" />
                        </span>
                        <input type="text" x-model="q" @input.debounce.300ms="run()" @focus="open = results.length > 0"
                            placeholder="ابحث في المنتجات والطلبات والعملاء…"
                            class="w-full h-10 rounded-xl bg-white pr-10 pl-9 text-sm text-[#111] placeholder-[#9ca3af] focus:outline-none transition"
                            style="border: 1px solid var(--ui-border-strong);"
                            onfocus="this.style.boxShadow='0 0 0 4px rgba(17,17,17,0.06)'; this.style.borderColor='#111';"
                            onblur="this.style.boxShadow='none'; this.style.borderColor='var(--ui-border-strong)';" />
                        <span x-show="loading" x-cloak class="absolute inset-y-0 left-0 flex items-center pl-3 text-[#9ca3af]">
                            <x-icon name="loader-circle" class="w-4 h-4 animate-spin" />
                        </span>
                        <div x-show="open" x-cloak x-transition
                            class="absolute z-30 mt-2 w-96 left-1/2 -translate-x-1/2 bg-white rounded-2xl shadow-lg overflow-hidden max-h-96 overflow-y-auto"
                            style="border: 1px solid var(--ui-border);">
                            <template x-if="!loading && results.length === 0 && q.length >= 2">
                                <div class="px-4 py-8 text-center text-sm text-[#9ca3af]">لا توجد نتائج لـ «<span x-text="q"></span>»</div>
                            </template>
                            <template x-for="group in results" :key="group.title">
                                <div>
                                    <div class="px-4 py-2 bg-[#fbfbfa] text-xs font-semibold text-[#6b7280] flex items-center gap-2" style="border-bottom:1px solid var(--ui-border);">
                                        <i :data-lucide="group.icon" class="w-3.5 h-3.5"></i><span x-text="group.title"></span>
                                    </div>
                                    <template x-for="item in group.items" :key="item.url">
                                        <a :href="item.url" class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-[#fafafa]" style="border-bottom:1px solid #f2f2f0;">
                                            <span class="text-sm text-[#111] truncate" x-text="item.label"></span>
                                            <span class="text-xs text-[#9ca3af] truncate shrink-0" x-text="item.meta"></span>
                                        </a>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                @endif
            @endauth

            <div class="flex items-center gap-1.5 mr-auto">
                {{-- مبدّل الفرع --}}
                @auth
                    @if (auth()->user()->business_id)
                        @php $branches = \App\Support\Demo::branches(); @endphp
                        <x-dropdown align="left" width="w-52">
                            <x-slot:trigger>
                                <button class="hidden sm:flex items-center gap-2 rounded-xl bg-white hover:bg-[#f7f7f5] px-3 h-9 text-sm text-[#111] transition" style="border:1px solid var(--ui-border-strong);">
                                    <x-icon name="git-branch" class="w-4 h-4 text-[#6b7280]" />
                                    <span class="max-w-24 truncate">{{ \App\Support\Demo::currentBranchName() }}</span>
                                    <x-icon name="chevron-down" class="w-4 h-4 text-[#9ca3af]" />
                                </button>
                            </x-slot:trigger>
                            <a href="{{ route('admin.branch.switch', 'all') }}" class="flex items-center gap-2 px-4 py-2.5 text-sm {{ ! \App\Support\Demo::currentBranchId() ? 'text-[#111] bg-[#f2f2f0]' : 'text-[#6b7280] hover:bg-[#fafafa]' }}">
                                <x-icon name="layers" class="w-4 h-4" /> كل الفروع
                            </a>
                            @foreach ($branches as $br)
                                <a href="{{ route('admin.branch.switch', $br['id']) }}" class="flex items-center gap-2 px-4 py-2.5 text-sm {{ \App\Support\Demo::currentBranchId() === $br['id'] ? 'text-[#111] bg-[#f2f2f0]' : 'text-[#6b7280] hover:bg-[#fafafa]' }}">
                                    <x-icon name="store" class="w-4 h-4" /> {{ $br['name'] }}
                                </a>
                            @endforeach
                        </x-dropdown>
                    @endif
                @endauth

                {{-- مبدّل العملة --}}
                @auth
                    @if (auth()->user()->business_id)
                        @php $curList = collect(\App\Support\Demo::currencies())->where('active', true); $curNow = \App\Support\Demo::displayCurrency(); @endphp
                        @if ($curList->count() > 1)
                            <x-dropdown align="left" width="w-52">
                                <x-slot:trigger>
                                    <button class="hidden sm:flex items-center gap-2 rounded-xl bg-white hover:bg-[#f7f7f5] px-3 h-9 text-sm text-[#111] transition" style="border:1px solid var(--ui-border-strong);">
                                        <x-icon name="coins" class="w-4 h-4 text-[#6b7280]" />
                                        <span class="font-medium">{{ $curNow['code'] }}</span>
                                        <x-icon name="chevron-down" class="w-4 h-4 text-[#9ca3af]" />
                                    </button>
                                </x-slot:trigger>
                                <div class="px-4 py-2 text-xs text-[#9ca3af]" style="border-bottom:1px solid var(--ui-border);">عملة العرض</div>
                                @foreach ($curList as $c)
                                    <a href="{{ route('admin.currency.switch', $c['is_base'] ? 'base' : $c['code']) }}"
                                       class="flex items-center justify-between gap-2 px-4 py-2.5 text-sm {{ $curNow['code'] === $c['code'] ? 'text-[#111] bg-[#f2f2f0]' : 'text-[#6b7280] hover:bg-[#fafafa]' }}">
                                        <span class="flex items-center gap-2"><span class="font-mono text-xs">{{ $c['code'] }}</span> {{ $c['name'] }}</span>
                                        @if ($c['is_base'])<span class="text-[10px] bg-[#f2f2f0] text-[#111] px-1.5 py-0.5 rounded">أساسية</span>@endif
                                    </a>
                                @endforeach
                            </x-dropdown>
                        @endif
                    @endif
                @endauth

                {{-- الإشعارات --}}
                @php
                    $notifications = \App\Support\Demo::notifications();
                    $notifColors = [
                        'danger' => 'bg-[#fef2f2] text-[#dc2626]', 'warning' => 'bg-[#fffbeb] text-[#d97706]',
                        'info' => 'bg-[#f2f2f0] text-[#111]', 'success' => 'bg-[#ecfdf5] text-[#059669]',
                        'primary' => 'bg-[#f2f2f0] text-[#111]',
                    ];
                @endphp
                <x-dropdown align="left" width="w-80">
                    <x-slot:trigger>
                        <button class="relative w-9 h-9 flex items-center justify-center rounded-xl hover:bg-black/5 text-[#111]">
                            <x-icon name="bell" class="w-[18px] h-[18px]" />
                            <span data-notif-count class="absolute top-1 left-1 min-w-4 h-4 px-1 flex items-center justify-center bg-[#111] text-white text-[10px] font-bold rounded-full" @style(['display:none' => ! count($notifications)])>{{ count($notifications) > 9 ? '9+' : count($notifications) }}</span>
                        </button>
                    </x-slot:trigger>
                    <div class="px-4 py-2.5 flex items-center justify-between" style="border-bottom:1px solid var(--ui-border);">
                        <p class="font-semibold text-sm text-[#111]">الإشعارات</p>
                        <button type="button" @click="window.enableBrowserNotifications()" class="inline-flex items-center gap-1 text-xs text-[#111] hover:text-black font-medium">
                            <x-icon name="bell-ring" class="w-3.5 h-3.5" /> تفعيل التنبيهات
                        </button>
                    </div>
                    <div class="max-h-80 overflow-y-auto">
                        @forelse ($notifications as $n)
                            <a href="{{ $n['url'] ?? '#' }}" class="flex items-start gap-3 px-4 py-3 hover:bg-[#fafafa]">
                                <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 {{ $notifColors[$n['color']] ?? $notifColors['primary'] }}">
                                    <x-icon :name="$n['icon'] ?? 'bell'" class="w-4 h-4" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm text-[#111]">{{ $n['text'] }}</p>
                                    <p class="text-xs text-[#9ca3af] mt-0.5">{{ $n['time'] ?? '' }}</p>
                                </div>
                            </a>
                        @empty
                            <div class="px-4 py-8 text-center text-sm text-[#9ca3af]">لا توجد إشعارات جديدة</div>
                        @endforelse
                    </div>
                </x-dropdown>

                {{-- المستخدم --}}
                <x-dropdown align="left" width="w-56">
                    <x-slot:trigger>
                        <button class="flex items-center gap-2.5 hover:bg-black/5 rounded-xl p-1 pl-2.5">
                            <img src="https://picsum.photos/seed/adminuser/80/80" class="w-8 h-8 rounded-full object-cover" alt="صاحب النشاط" />
                            <div class="hidden sm:block text-right leading-tight">
                                <p class="text-[13px] font-semibold text-[#111]">صاحب النشاط</p>
                                <p class="text-[11px] text-[#9ca3af]">مدير</p>
                            </div>
                            <x-icon name="chevron-down" class="w-4 h-4 text-[#9ca3af] hidden sm:block" />
                        </button>
                    </x-slot:trigger>
                    <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-[#6b7280] hover:bg-[#fafafa]"><x-icon name="user" class="w-4 h-4" /> الملف الشخصي</a>
                    @auth
                        @if (auth()->user()->business_id && auth()->user()->allows('settings'))
                            <a href="{{ route('admin.settings.index') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-[#6b7280] hover:bg-[#fafafa]"><x-icon name="settings" class="w-4 h-4" /> الإعدادات</a>
                        @endif
                    @endauth
                    <div class="my-1" style="border-top:1px solid var(--ui-border);"></div>
                    <a href="{{ route('logout') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-[#dc2626] hover:bg-[#fef2f2]"><x-icon name="log-out" class="w-4 h-4" /> تسجيل الخروج</a>
                </x-dropdown>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-8">
            {{ $slot }}
        </main>
    </div>

    <x-toasts />
    <div x-data x-init="window.startOrderAlerts('{{ route('admin.notifications.feed') }}')"></div>
</body>
</html>
