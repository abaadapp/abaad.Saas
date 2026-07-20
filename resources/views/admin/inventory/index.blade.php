@php
    $inventory = \App\Support\Demo::inventory();
    $available = collect($inventory)->where('status', 'متوفر')->count();
    $low = collect($inventory)->where('status', 'منخفض')->count();
    $out = collect($inventory)->where('status', 'نفد المخزون')->count();
@endphp

<x-layouts::admin title="المخزون">
    <x-page-header
        title="إدارة المخزون"
        subtitle="متابعة كميات المنتجات وحالات المخزون والتنبيهات"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المخزون' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="clipboard-list" :href="route('admin.export.inventory')">جرد المخزون (CSV)</x-button>
            <x-button variant="primary" size="md" icon="repeat" :href="route('admin.inventory.movements')">حركة مخزون جديدة</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- الأقسام المرتبطة بالمخزون --}}
    @php
        $inventorySections = [
            [
                'title' => 'المورّدون',
                'desc' => 'إدارة موردي البضاعة وبيانات التواصل',
                'icon' => 'truck',
                'route' => route('admin.suppliers.index'),
                'color' => 'bg-primary-50 text-primary-600',
            ],
            [
                'title' => 'أوامر الشراء',
                'desc' => 'طلبات التوريد واستلام البضاعة',
                'icon' => 'clipboard-list',
                'route' => route('admin.purchases.index'),
                'color' => 'bg-info-50 text-info-600',
            ],
            [
                'title' => 'حركات المخزون',
                'desc' => 'سجل الإضافات والخصومات على الكميات',
                'icon' => 'repeat',
                'route' => route('admin.inventory.movements'),
                'color' => 'bg-secondary-50 text-secondary-600',
            ],
        ];
    @endphp
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        @foreach ($inventorySections as $s)
            <a href="{{ $s['route'] }}" class="group block bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md hover:border-gray-300 transition">
                <div class="flex items-center justify-between">
                    <span class="w-11 h-11 rounded-xl flex items-center justify-center {{ $s['color'] }}">
                        <x-icon :name="$s['icon']" class="w-5 h-5" />
                    </span>
                    <x-icon name="chevron-left" class="w-4 h-4 text-gray-300 group-hover:text-gray-500 transition" />
                </div>
                <h3 class="mt-4 font-bold text-gray-800">{{ $s['title'] }}</h3>
                <p class="mt-1 text-xs text-gray-500 leading-relaxed">{{ $s['desc'] }}</p>
            </a>
        @endforeach
    </div>

    {{-- بطاقات الحالة --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat-card label="منتجات متوفرة" :value="$available" icon="package-check" color="success" />
        <x-stat-card label="مخزون منخفض" :value="$low" icon="alert-triangle" color="warning" />
        <x-stat-card label="نفد المخزون" :value="$out" icon="package-x" color="danger" />
    </div>

    {{-- تنبيه المخزون --}}
    @if ($low + $out > 0)
        <div class="mb-6">
            <x-alert type="warning" title="تنبيه المخزون">
                يوجد <span class="font-semibold">{{ $low }}</span> منتج بمخزون منخفض و<span class="font-semibold">{{ $out }}</span> منتج نفد من المخزون. يُرجى مراجعة الكميات وإعادة التزويد.
            </x-alert>
        </div>
    @endif

    <div x-data="listFilter()" x-ref="list">
    {{-- شريط الفلاتر --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="flex flex-col md:flex-row md:items-center gap-3">
            <div class="flex-1">
                <x-input name="search" placeholder="ابحث باسم المنتج أو رمز SKU..." icon="search" x-model="q" @input="apply()" />
            </div>
            <div class="w-full md:w-56">
                <x-select name="status" placeholder="كل الحالات" x-model="tag" @change="apply()" :options="[
                    'متوفر' => 'متوفر',
                    'منخفض' => 'منخفض',
                    'نفد المخزون' => 'نفد المخزون',
                ]" />
            </div>
            <x-button variant="light" size="md" icon="filter" @click="apply()">تصفية</x-button>
        </div>
    </div>

    {{-- جدول المخزون --}}
    <div x-data="{ sel: { id: '', name: '', sku: '', qty: 0 } }">
    <x-table :headers="['المنتج', 'SKU', 'الكمية الحالية', 'الحد الأدنى', 'حالة المخزون', 'آخر تحديث', 'إجراء']">
        @foreach ($inventory as $item)
            <tr class="hover:bg-gray-50" data-row data-tag="{{ $item['status'] }}" data-search="{{ $item['name'] }} {{ $item['sku'] }}">
                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $item['name'] }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap font-mono">{{ $item['sku'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="font-semibold {{ $item['qty'] === 0 ? 'text-danger-600' : ($item['qty'] < $item['min'] ? 'text-warning-600' : 'text-gray-800') }}">{{ $item['qty'] }}</span>
                    <span class="text-xs text-gray-400">وحدة</span>
                </td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $item['min'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="$item['status']" /></td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $item['updated'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-button variant="light" size="sm" icon="pencil"
                        x-on:click="sel = { id: {{ $item['id'] }}, name: {{ \Illuminate\Support\Js::from($item['name']) }}, sku: {{ \Illuminate\Support\Js::from($item['sku']) }}, qty: {{ $item['qty'] }} }; $dispatch('open-modal','edit-qty')">تعديل الكمية</x-button>
                </td>
            </tr>
        @endforeach

        <x-slot:footer>
            <x-pagination :total="count($inventory)" :perPage="10" :current="1" />
        </x-slot:footer>
    </x-table>

    {{-- نافذة تعديل الكمية --}}
    <x-modal name="edit-qty" title="تعديل كمية المنتج" maxWidth="max-w-md">
        <form id="adjust-form" method="POST" action="{{ route('admin.inventory.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="product_id" :value="sel.id" />
            <div class="rounded-xl bg-gray-50 border border-gray-100 p-3 text-sm text-gray-600">
                المنتج: <span class="font-semibold text-gray-800" x-text="sel.name"></span> — SKU: <span class="font-mono" x-text="sel.sku"></span>
            </div>
            <x-select label="نوع التعديل" name="type" :options="[
                'تعديل يدوي' => 'تعيين الكمية الجديدة',
                'إضافة كمية' => 'إضافة كمية',
                'خصم كمية' => 'خصم كمية',
            ]" selected="تعديل يدوي" />
            <x-input label="الكمية" name="quantity" type="number" x-bind:value="sel.qty" icon="hash" :required="true" />
            <x-input label="ملاحظة" name="note" placeholder="سبب التعديل (اختياري)" icon="sticky-note" />
        </form>

        <x-slot:footer>
            <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">إلغاء</x-button>
            <x-button variant="primary" size="md" icon="check" type="submit" form="adjust-form">حفظ</x-button>
        </x-slot:footer>
    </x-modal>
    </div>
    </div>
</x-layouts::admin>
