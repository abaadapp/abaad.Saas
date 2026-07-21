@props(['title' => '', 'user' => 'أحمد محمد', 'role' => 'مدير النظام', 'avatar' => null])

<header class="sticky top-0 z-20 h-16 bg-white/80 backdrop-blur border-b border-gray-100 flex items-center gap-3 px-4 lg:px-6">
    {{-- زر فتح القائمة على الجوال --}}
    <button type="button" @click="$store.sidebar.toggle()" class="lg:hidden w-10 h-10 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-600">
        <x-icon name="menu" class="w-5 h-5" />
    </button>

    <div class="min-w-0 flex-1">
        <h2 class="font-bold text-gray-800 truncate">{{ $title }}</h2>
    </div>

    {{-- بحث سريع موحّد --}}
    @auth
        @php $searchUrl = auth()->user()->isSuperAdmin() ? route('super-admin.search') : (auth()->user()->business_id ? route('admin.search') : null); @endphp
        @if ($searchUrl)
            <div class="hidden md:block relative w-72" x-data="unifiedSearch('{{ $searchUrl }}')" @click.outside="open = false">
                <span class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 pointer-events-none">
                    <x-icon name="search" class="w-4 h-4" />
                </span>
                <input type="text" x-model="q" @input.debounce.300ms="run()" @focus="open = results.length > 0" placeholder="ابحث في المنتجات والطلبات والعملاء…"
                    class="w-full rounded-xl border border-gray-200 bg-gray-50 pr-9 pl-8 py-2 text-sm focus:bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100 focus:outline-none transition" />
                <span x-show="loading" x-cloak class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                    <x-icon name="loader-circle" class="w-4 h-4 animate-spin" />
                </span>

                <div x-show="open" x-cloak x-transition
                    class="absolute z-30 mt-2 w-96 -left-24 bg-white rounded-2xl border border-gray-100 shadow-xl overflow-hidden max-h-96 overflow-y-auto">
                    <template x-if="!loading && results.length === 0 && q.length >= 2">
                        <div class="px-4 py-8 text-center text-sm text-gray-400">لا توجد نتائج لـ «<span x-text="q"></span>»</div>
                    </template>
                    <template x-for="group in results" :key="group.title">
                        <div>
                            <div class="px-4 py-2 bg-gray-50 text-xs font-semibold text-gray-500 flex items-center gap-2">
                                <i :data-lucide="group.icon" class="w-3.5 h-3.5"></i><span x-text="group.title"></span>
                            </div>
                            <template x-for="item in group.items" :key="item.url">
                                <a :href="item.url" class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-gray-50 border-b border-gray-50 last:border-0">
                                    <span class="text-sm text-gray-800 truncate" x-text="item.label"></span>
                                    <span class="text-xs text-gray-400 truncate shrink-0" x-text="item.meta"></span>
                                </a>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        @endif
    @endauth

    {{-- مبدّل الفرع (للنشاط التجاري فقط) --}}
    @auth
        @if (auth()->user()->business_id)
            @php $branches = \App\Support\Demo::branches(); @endphp
            <x-dropdown align="left" width="w-52">
                <x-slot:trigger>
                    <button class="flex items-center gap-2 rounded-full border border-gray-200 bg-white hover:bg-gray-50 px-3 py-2 text-sm text-gray-700">
                        <x-icon name="git-branch" class="w-4 h-4 text-primary-600" />
                        <span class="hidden sm:inline max-w-24 truncate">{{ \App\Support\Demo::currentBranchName() }}</span>
                        <x-icon name="chevron-down" class="w-4 h-4 text-gray-400" />
                    </button>
                </x-slot:trigger>
                <a href="{{ route('admin.branch.switch', 'all') }}" class="flex items-center gap-2 px-4 py-2.5 text-sm {{ ! \App\Support\Demo::currentBranchId() ? 'text-primary-600 bg-primary-50' : 'text-gray-600 hover:bg-gray-50' }}">
                    <x-icon name="layers" class="w-4 h-4" /> كل الفروع
                </a>
                @foreach ($branches as $br)
                    <a href="{{ route('admin.branch.switch', $br['id']) }}" class="flex items-center gap-2 px-4 py-2.5 text-sm {{ \App\Support\Demo::currentBranchId() === $br['id'] ? 'text-primary-600 bg-primary-50' : 'text-gray-600 hover:bg-gray-50' }}">
                        <x-icon name="store" class="w-4 h-4" /> {{ $br['name'] }}
                    </a>
                @endforeach
            </x-dropdown>
        @endif
    @endauth

    {{-- مبدّل عملة العرض (للنشاط التجاري) --}}
    @auth
        @if (auth()->user()->business_id)
            @php $curList = collect(\App\Support\Demo::currencies())->where('active', true); $curNow = \App\Support\Demo::displayCurrency(); @endphp
            @if ($curList->count() > 1)
                <x-dropdown align="left" width="w-52">
                    <x-slot:trigger>
                        <button class="flex items-center gap-2 rounded-full border border-gray-200 bg-white hover:bg-gray-50 px-3 py-2 text-sm text-gray-700">
                            <x-icon name="coins" class="w-4 h-4 text-warning-500" />
                            <span class="font-medium">{{ $curNow['code'] }}</span>
                            <x-icon name="chevron-down" class="w-4 h-4 text-gray-400" />
                        </button>
                    </x-slot:trigger>
                    <div class="px-4 py-2 text-xs text-gray-400 border-b border-gray-50">عملة العرض</div>
                    @foreach ($curList as $c)
                        <a href="{{ route('admin.currency.switch', $c['is_base'] ? 'base' : $c['code']) }}"
                           class="flex items-center justify-between gap-2 px-4 py-2.5 text-sm {{ $curNow['code'] === $c['code'] ? 'text-primary-600 bg-primary-50' : 'text-gray-600 hover:bg-gray-50' }}">
                            <span class="flex items-center gap-2"><span class="font-mono text-xs">{{ $c['code'] }}</span> {{ $c['name'] }}</span>
                            @if ($c['is_base'])<span class="text-[10px] bg-primary-100 text-primary-700 px-1.5 py-0.5 rounded">أساسية</span>@endif
                        </a>
                    @endforeach
                </x-dropdown>
            @endif
        @endif
    @endauth

    {{-- الإشعارات (حقيقية من قاعدة البيانات) --}}
    @php
        $notifications = \App\Support\Demo::notifications();
        $notifColors = [
            'danger' => 'bg-danger-50 text-danger-600', 'warning' => 'bg-warning-50 text-warning-600',
            'info' => 'bg-info-50 text-info-600', 'success' => 'bg-success-50 text-success-600',
            'primary' => 'bg-primary-50 text-primary-600',
        ];
    @endphp
    <x-dropdown align="left" width="w-80">
        <x-slot:trigger>
            <button class="relative w-10 h-10 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-600">
                <x-icon name="bell" class="w-5 h-5" />
                <span data-notif-count class="absolute -top-0.5 -left-0.5 min-w-4 h-4 px-1 flex items-center justify-center bg-secondary-600 text-white text-[10px] font-bold rounded-full" @style(['display:none' => ! count($notifications)])>{{ count($notifications) > 9 ? '9+' : count($notifications) }}</span>
            </button>
        </x-slot:trigger>
        <div class="px-4 py-2.5 border-b border-gray-100 flex items-center justify-between">
            <p class="font-semibold text-sm text-gray-800">الإشعارات</p>
            <button type="button" @click="window.enableBrowserNotifications()" class="inline-flex items-center gap-1 text-xs text-primary-600 hover:text-primary-700 font-medium">
                <x-icon name="bell-ring" class="w-3.5 h-3.5" /> تفعيل إشعارات المتصفح
            </button>
        </div>
        <div class="max-h-80 overflow-y-auto">
            @forelse ($notifications as $n)
                <a href="{{ $n['url'] ?? '#' }}" class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 {{ $notifColors[$n['color']] ?? $notifColors['primary'] }}">
                        <x-icon :name="$n['icon'] ?? 'bell'" class="w-4 h-4" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm text-gray-700">{{ $n['text'] }}</p>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $n['time'] ?? '' }}</p>
                    </div>
                </a>
            @empty
                <div class="px-4 py-8 text-center text-sm text-gray-400">لا توجد إشعارات جديدة</div>
            @endforelse
        </div>
    </x-dropdown>

    {{-- المستخدم --}}
    <x-dropdown align="left" width="w-56">
        <x-slot:trigger>
            <button class="flex items-center gap-2.5 hover:bg-gray-100 rounded-full p-1.5 pl-2.5">
                <img src="{{ $avatar ?? 'https://picsum.photos/seed/topbaruser/80/80' }}" class="w-9 h-9 rounded-lg object-cover" alt="{{ $user }}" />
                <div class="hidden sm:block text-right">
                    <p class="text-sm font-semibold text-gray-800 leading-tight">{{ $user }}</p>
                    <p class="text-xs text-gray-400">{{ $role }}</p>
                </div>
                <x-icon name="chevron-down" class="w-4 h-4 text-gray-400 hidden sm:block" />
            </button>
        </x-slot:trigger>
        <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50"><x-icon name="user" class="w-4 h-4" /> الملف الشخصي</a>
        @auth
            @if (auth()->user()->isSuperAdmin())
                <a href="{{ route('super-admin.settings.index') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50"><x-icon name="settings" class="w-4 h-4" /> الإعدادات</a>
            @elseif (auth()->user()->business_id && auth()->user()->allows('settings'))
                <a href="{{ route('admin.settings.index') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50"><x-icon name="settings" class="w-4 h-4" /> الإعدادات</a>
            @endif
        @endauth
        <div class="my-1 border-t border-gray-100"></div>
        <a href="{{ route('logout') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-danger-600 hover:bg-danger-50"><x-icon name="log-out" class="w-4 h-4" /> تسجيل الخروج</a>
    </x-dropdown>
</header>
