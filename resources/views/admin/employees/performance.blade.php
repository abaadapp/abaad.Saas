<x-layouts::admin title="أداء الموظفين">

    <x-page-header title="لوحة أداء الموظفين" subtitle="ترتيب الفريق حسب مبيعات الشهر والعمولات المستحقّة"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'الموظفون' => route('admin.employees.index'), 'الأداء' => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="users" :href="route('admin.employees.index')">كل الموظفين</x-button>
            <x-button variant="outline" icon="download" :href="route('admin.export.employees')">تصدير CSV</x-button>
        </x-slot:actions>
    </x-page-header>

    @php
        $board = \App\Support\Demo::employeeLeaderboard();
        $totalAchieved = collect($board)->sum('achieved');
        $totalCommission = collect($board)->sum('commission');
        $achievers = collect($board)->where('target', '>', 0)->where('pct', '>=', 100)->count();
        $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
    @endphp

    {{-- بطاقات إجمالية --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat-card label="إجمالي مبيعات الفريق (الشهر)" :value="\App\Support\Demo::money($totalAchieved)" icon="trending-up" color="primary" />
        <x-stat-card label="إجمالي العمولات المستحقّة" :value="\App\Support\Demo::money($totalCommission)" icon="badge-percent" color="secondary" />
        <x-stat-card label="حقّقوا هدفهم" :value="$achievers . ' / ' . count($board)" icon="target" color="success" />
    </div>

    {{-- منصّة التتويج (أفضل 3) --}}
    @if (count($board) >= 3)
        <div class="grid grid-cols-3 gap-3 mb-6">
            @foreach ([$board[1] ?? null, $board[0], $board[2] ?? null] as $slot => $emp)
                @continue(!$emp)
                @php $isFirst = $emp['rank'] === 1; @endphp
                <div class="bg-white rounded-2xl border {{ $isFirst ? 'border-warning-200 shadow-md -mt-2' : 'border-gray-100 shadow-sm' }} p-5 text-center">
                    <div class="text-3xl mb-1">{{ $medals[$emp['rank']] ?? '' }}</div>
                    <img src="{{ $emp['avatar'] }}" class="w-14 h-14 rounded-full object-cover mx-auto ring-2 {{ $isFirst ? 'ring-warning-300' : 'ring-gray-100' }}" />
                    <p class="font-bold text-gray-800 mt-2 truncate">{{ $emp['name'] }}</p>
                    <p class="text-xs text-gray-400">{{ $emp['role'] }}</p>
                    <p class="mt-2 font-bold text-primary-600">{{ \App\Support\Demo::money($emp['achieved']) }}</p>
                    <p class="text-xs text-secondary-600">عمولة {{ \App\Support\Demo::money($emp['commission']) }}</p>
                </div>
            @endforeach
        </div>
    @endif

    {{-- جدول الترتيب الكامل --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100"><h3 class="font-bold text-gray-800">الترتيب الكامل — {{ now()->translatedFormat('F Y') }}</h3></div>
        <x-table :headers="['#', 'الموظف', 'مبيعات الشهر', 'الهدف', 'الإنجاز', 'العمولة', 'كشف']">
            @forelse ($board as $emp)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-bold text-gray-400 w-12">{{ $medals[$emp['rank']] ?? $emp['rank'] }}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <img src="{{ $emp['avatar'] }}" class="w-9 h-9 rounded-full object-cover ring-1 ring-gray-100" />
                            <div>
                                <p class="font-semibold text-gray-800">{{ $emp['name'] }}</p>
                                <p class="text-xs text-gray-400">{{ $emp['role'] }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($emp['achieved']) }}</td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $emp['target'] > 0 ? \App\Support\Demo::money($emp['target']) : '—' }}</td>
                    <td class="px-4 py-3 w-40">
                        @if ($emp['target'] > 0)
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden">
                                    <div class="h-2 rounded-full {{ $emp['pct'] >= 100 ? 'bg-success-500' : ($emp['pct'] >= 60 ? 'bg-primary-500' : 'bg-warning-500') }}" style="width: {{ $emp['pct'] }}%"></div>
                                </div>
                                <span class="text-xs text-gray-500 w-10">{{ $emp['pct'] }}%</span>
                            </div>
                        @else
                            <span class="text-xs text-gray-400">بلا هدف</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="text-secondary-600 font-medium">{{ \App\Support\Demo::money($emp['commission']) }}</span>
                        <span class="text-xs text-gray-400">({{ $emp['commission_rate'] }}%)</span>
                    </td>
                    <td class="px-4 py-3">
                        <x-button variant="ghost" size="sm" icon="file-text" :href="route('admin.employees.commission', $emp['id'])" target="_blank">PDF</x-button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">لا يوجد موظفون بعد.</td></tr>
            @endforelse
        </x-table>
    </div>

</x-layouts::admin>
