<x-layouts::admin title="المنتجات">

    <x-page-header title="المنتجات" subtitle="إدارة منتجات محل الورود"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المنتجات' => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="barcode" :href="route('admin.products.barcodes')">طباعة الباركود</x-button>
            <x-button variant="outline" icon="download" :href="route('admin.products.xlsx')">تصدير Excel</x-button>
            <x-button variant="primary" icon="plus" :href="route('admin.products.create')">إضافة منتج</x-button>
        </x-slot:actions>
    </x-page-header>

    <div x-data="{ view: 'table' }">

        {{-- شريط الفلاتر --}}
        <form method="GET" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
                <div class="lg:col-span-2">
                    <x-input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ابحث بالاسم أو SKU..." icon="search" />
                </div>
                <x-select name="category" :options="collect(\App\Support\Demo::categories())->pluck('name', 'name')->toArray()" placeholder="كل التصنيفات" selected="{{ $filters['category'] ?? '' }}" />
                <x-select name="status" :options="['active' => 'مفعّل', 'inactive' => 'غير مفعّل']" placeholder="كل الحالات" selected="{{ $filters['status'] ?? '' }}" />
                <x-select name="stock" :options="['متوفر' => 'متوفر', 'منخفض' => 'منخفض', 'نفد المخزون' => 'نفد المخزون']" placeholder="حالة المخزون" selected="{{ $filters['stock'] ?? '' }}" />
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mt-4">
                {{-- مبدّل العرض على شكل تبويبات بأيقونة ونص وخط سفلي --}}
                <div class="flex items-center gap-1 border-b border-gray-100 -mb-px">
                    <button type="button" @click="view = 'grid'"
                        :class="view === 'grid' ? 'text-primary-600 border-primary-600' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="flex items-center gap-2 px-3 py-2.5 text-sm font-medium border-b-2 transition-colors">
                        <x-icon name="layout-grid" class="w-4 h-4" />
                        عرض شبكي
                    </button>
                    <button type="button" @click="view = 'table'"
                        :class="view === 'table' ? 'text-primary-600 border-primary-600' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="flex items-center gap-2 px-3 py-2.5 text-sm font-medium border-b-2 transition-colors">
                        <x-icon name="list" class="w-4 h-4" />
                        عرض جدول
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <x-button type="submit" icon="filter">تصفية</x-button>
                    <x-button variant="outline" :href="url()->current()">تفريغ</x-button>
                    <p class="text-sm text-gray-500">{{ $products->total() }} منتج</p>
                </div>
            </div>
        </form>

        {{-- عرض الشبكة --}}
        <div x-show="view === 'grid'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mb-6">
            @foreach ($products as $p)
                <x-product-card :product="$p">
                    <x-dropdown align="left" width="w-44">
                        <x-slot:trigger>
                            <button type="button" class="w-9 h-9 rounded-lg bg-gray-50 hover:bg-gray-100 text-gray-500 flex items-center justify-center transition">
                                <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                            </button>
                        </x-slot:trigger>
                        <a href="{{ route('admin.products.show', $p['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <x-icon name="eye" class="w-4 h-4" /> عرض
                        </a>
                        <a href="{{ route('admin.products.edit', $p['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <x-icon name="pencil" class="w-4 h-4" /> تعديل
                        </a>
                        <form method="POST" action="{{ route('admin.products.destroy', $p['id']) }}" @submit.prevent="if(confirm('حذف المنتج؟')) $el.submit()">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50">
                                <x-icon name="trash-2" class="w-4 h-4" /> حذف
                            </button>
                        </form>
                    </x-dropdown>
                </x-product-card>
            @endforeach
        </div>

        {{-- عرض الجدول --}}
        <div x-show="view === 'table'">
            <x-table :headers="['الصورة', 'الاسم', 'SKU', 'التصنيف', 'السعر', 'سعر التكلفة', 'الكمية', 'المخزون', 'الحالة', 'إجراءات']">
                @foreach ($products as $p)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <img src="{{ $p['image'] }}" alt="{{ $p['name'] }}" class="w-11 h-11 rounded-lg object-cover border border-gray-100" />
                        </td>
                        <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $p['name'] }}</td>
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $p['sku'] }}</td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $p['cat'] }}</td>
                        <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($p['price']) }}</td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ \App\Support\Demo::money($p['cost']) }}</td>
                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $p['qty'] }}</td>
                        <td class="px-4 py-3"><x-badge :text="$p['stock_status']" /></td>
                        <td class="px-4 py-3">
                            <x-badge :type="$p['active'] ? 'success' : 'gray'">{{ $p['active'] ? 'مفعّل' : 'غير مفعّل' }}</x-badge>
                        </td>
                        <td class="px-4 py-3">
                            <x-dropdown align="left" width="w-40">
                                <x-slot:trigger>
                                    <button type="button" class="w-8 h-8 rounded-lg hover:bg-gray-100 text-gray-500 flex items-center justify-center">
                                        <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                                    </button>
                                </x-slot:trigger>
                                <a href="{{ route('admin.products.show', $p['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                    <x-icon name="eye" class="w-4 h-4" /> عرض
                                </a>
                                <a href="{{ route('admin.products.edit', $p['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                    <x-icon name="pencil" class="w-4 h-4" /> تعديل
                                </a>
                                <form method="POST" action="{{ route('admin.products.destroy', $p['id']) }}" @submit.prevent="if(confirm('حذف المنتج؟')) $el.submit()">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50">
                                        <x-icon name="trash-2" class="w-4 h-4" /> حذف
                                    </button>
                                </form>
                            </x-dropdown>
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <x-pagination :paginator="$products" />
                </x-slot:footer>
            </x-table>
        </div>

        {{-- ترقيم صفحات الشبكة --}}
        <div x-show="view === 'grid'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm px-4 py-3">
            <x-pagination :paginator="$products" />
        </div>

    </div>

</x-layouts::admin>
