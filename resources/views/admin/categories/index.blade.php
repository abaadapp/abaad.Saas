<x-layouts::admin title="التصنيفات">

    <x-page-header title="التصنيفات" subtitle="تنظيم منتجاتك ضمن تصنيفات"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'التصنيفات' => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="external-link" :href="route('admin.categories.create')">صفحة الإضافة</x-button>
            <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal', 'add-category')">إضافة تصنيف</x-button>
        </x-slot:actions>
    </x-page-header>

    @php
        $colorMap = [
            'primary' => 'bg-primary-50 text-primary-600',
            'secondary' => 'bg-secondary-50 text-secondary-600',
            'success' => 'bg-success-50 text-success-600',
            'warning' => 'bg-warning-50 text-warning-600',
            'danger' => 'bg-danger-50 text-danger-600',
            'info' => 'bg-info-50 text-info-600',
        ];
    @endphp

    {{-- شبكة التصنيفات --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach (\App\Support\Demo::categories() as $cat)
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md transition-shadow">
                <div class="flex items-start justify-between">
                    <span class="w-12 h-12 rounded-xl flex items-center justify-center {{ $colorMap[$cat['color']] ?? $colorMap['primary'] }}">
                        <x-icon :name="$cat['icon']" class="w-6 h-6" />
                    </span>
                    <x-dropdown align="left" width="w-40">
                        <x-slot:trigger>
                            <button type="button" class="w-8 h-8 rounded-lg hover:bg-gray-100 text-gray-400 flex items-center justify-center">
                                <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                            </button>
                        </x-slot:trigger>
                        <button type="button" @click="$dispatch('open-modal', 'add-category')" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <x-icon name="pencil" class="w-4 h-4" /> تعديل
                        </button>
                        <form method="POST" action="{{ route('admin.categories.destroy', $cat['id']) }}" @submit.prevent="if(confirm('حذف التصنيف؟')) $el.submit()">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50">
                                <x-icon name="trash-2" class="w-4 h-4" /> حذف
                            </button>
                        </form>
                    </x-dropdown>
                </div>
                <h3 class="mt-4 font-bold text-gray-800">{{ $cat['name'] }}</h3>
                <p class="mt-1 text-sm text-gray-400">{{ $cat['products'] }} منتج</p>
                <div class="mt-4 flex items-center gap-2">
                    <x-button variant="light" size="sm" icon="pencil" type="button" x-data @click="$dispatch('open-modal', 'add-category')">تعديل</x-button>
                    <form method="POST" action="{{ route('admin.categories.destroy', $cat['id']) }}" @submit.prevent="if(confirm('حذف التصنيف؟')) $el.submit()">
                        @csrf
                        @method('DELETE')
                        <x-button variant="ghost" size="sm" icon="trash-2" type="submit">حذف</x-button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>

    {{-- نافذة إضافة تصنيف --}}
    <x-modal name="add-category" title="إضافة تصنيف" maxWidth="max-w-lg">
        <form id="add-category-form" method="POST" action="{{ route('admin.categories.store') }}" class="space-y-4">
            @csrf
            <x-input label="اسم التصنيف" name="name" placeholder="مثال: باقات ورد" :required="true" />
            <x-select label="الأيقونة" name="icon" :options="['flower' => 'زهرة', 'gift' => 'هدية', 'party-popper' => 'مناسبات', 'candy' => 'شوكولاتة', 'sprout' => 'نبتة', 'sparkles' => 'تنسيق']" placeholder="اختر أيقونة" />
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">اللون</label>
                <div class="flex items-center gap-3">
                    @foreach (['primary' => '#7c3aed', 'secondary' => '#db2777', 'success' => '#10b981', 'warning' => '#f59e0b', 'info' => '#3b82f6'] as $key => $hex)
                        <label class="cursor-pointer">
                            <input type="radio" name="color" value="{{ $key }}" class="sr-only peer" @checked($loop->first) />
                            <span class="block w-8 h-8 rounded-full ring-2 ring-transparent peer-checked:ring-gray-800 peer-checked:ring-offset-2 transition" style="background: {{ $hex }}"></span>
                        </label>
                    @endforeach
                </div>
            </div>
            <button type="submit" class="hidden"></button>
        </form>
        <x-slot:footer>
            <x-button variant="outline" type="button" x-data @click="$dispatch('close-modal')">إلغاء</x-button>
            <x-button variant="primary" type="submit" form="add-category-form" icon="check">حفظ</x-button>
        </x-slot:footer>
    </x-modal>

</x-layouts::admin>
