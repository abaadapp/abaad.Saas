<x-layouts::admin title="التصنيفات">

    <x-page-header title="التصنيفات" subtitle="تنظيم منتجاتك ضمن تصنيفات"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المنتجات' => route('admin.products.index'), 'التصنيفات' => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="external-link" :href="route('admin.categories.create')">صفحة الإضافة</x-button>
            <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal', 'add-category')">إضافة تصنيف</x-button>
        </x-slot:actions>
    </x-page-header>

    @include('partials.products-tabs')

    @php
        $colorMap = [
            'primary' => 'bg-primary-50 text-primary-600',
            'secondary' => 'bg-secondary-50 text-secondary-600',
            'success' => 'bg-success-50 text-success-600',
            'warning' => 'bg-warning-50 text-warning-600',
            'danger' => 'bg-danger-50 text-danger-600',
            'info' => 'bg-info-50 text-info-600',
        ];
        $colorHex = ['primary' => '#7c3aed', 'secondary' => '#db2777', 'success' => '#10b981', 'warning' => '#f59e0b', 'info' => '#3b82f6'];
        $colorNames = ['primary' => 'بنفسجي', 'secondary' => 'وردي', 'success' => 'أخضر', 'warning' => 'برتقالي', 'info' => 'أزرق', 'danger' => 'أحمر'];
        $iconOptions = ['flower' => 'زهرة', 'gift' => 'هدية', 'party-popper' => 'مناسبات', 'candy' => 'شوكولاتة', 'sprout' => 'نبتة', 'sparkles' => 'تنسيق', 'tag' => 'وسم'];
        // أضِف أي أيقونة مستخدمة فعلًا وغير مدرجة، وإلا غيّرها التعديل بصمت
        foreach (\App\Support\Demo::categories() as $c) {
            if ($c['icon'] && ! isset($iconOptions[$c['icon']])) {
                $iconOptions[$c['icon']] = $c['icon'];
            }
        }
    @endphp

    @php $categories = \App\Support\Demo::categories(); @endphp

    {{-- قائمة التصنيفات --}}
    <div x-data="{ sel: { id: '', name: '', icon: '', color: '' } }">
        @if (count($categories))
            <x-table :headers="['التصنيف', 'الأيقونة', 'اللون', 'عدد المنتجات', '']">
                @foreach ($categories as $cat)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 whitespace-nowrap">
                            <div class="flex items-center gap-3">
                                <span class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 {{ $colorMap[$cat['color']] ?? $colorMap['primary'] }}">
                                    <x-icon :name="$cat['icon']" class="w-[18px] h-[18px]" />
                                </span>
                                <span class="font-medium text-gray-800">{{ $cat['name'] }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap font-mono text-xs">{{ $cat['icon'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="inline-flex items-center gap-2 text-sm text-gray-600">
                                <span class="w-4 h-4 rounded-full" style="background: {{ $colorHex[$cat['color']] ?? $colorHex['primary'] }}"></span>
                                {{ $colorNames[$cat['color']] ?? $cat['color'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $cat['products'] }} منتج</td>
                        <td class="px-4 py-3 whitespace-nowrap text-left">
                            <x-dropdown align="left" width="w-40">
                                <x-slot:trigger>
                                    <button type="button" class="w-8 h-8 rounded-full hover:bg-gray-100 text-gray-400 flex items-center justify-center">
                                        <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                                    </button>
                                </x-slot:trigger>
                                <button type="button" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                    x-on:click="sel = { id: {{ $cat['id'] }}, name: {{ \Illuminate\Support\Js::from($cat['name']) }}, icon: {{ \Illuminate\Support\Js::from($cat['icon']) }}, color: {{ \Illuminate\Support\Js::from($cat['color']) }} }; $dispatch('open-modal','edit-category')">
                                    <x-icon name="pencil" class="w-4 h-4" /> تعديل
                                </button>
                                <form method="POST" action="{{ route('admin.categories.destroy', $cat['id']) }}" @submit.prevent="if(confirm('حذف التصنيف «{{ $cat['name'] }}»؟')) $el.submit()">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50">
                                        <x-icon name="trash-2" class="w-4 h-4" /> حذف
                                    </button>
                                </form>
                            </x-dropdown>
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <div class="px-4 py-3 text-xs text-gray-400">{{ count($categories) }} تصنيف</div>
                </x-slot:footer>
            </x-table>
        @else
            <x-empty-state icon="tags" title="لا توجد تصنيفات" message="أضِف أول تصنيف لتنظيم منتجاتك." />
        @endif

        {{-- نافذة تعديل تصنيف (مشتركة) --}}
        <x-modal name="edit-category" title="تعديل التصنيف" maxWidth="max-w-lg">
            <form id="edit-category-form" method="POST" x-bind:action="'{{ url('admin/categories') }}/' + sel.id" class="space-y-4">
                @csrf @method('PUT')
                <x-input label="اسم التصنيف" name="name" x-bind:value="sel.name" :required="true" />
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">الأيقونة</label>
                    <select name="icon" x-model="sel.icon" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
                        @foreach ($iconOptions as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">اللون</label>
                    <div class="flex items-center gap-3">
                        @foreach ($colorHex as $key => $hex)
                            <label class="cursor-pointer">
                                <input type="radio" name="color" value="{{ $key }}" class="sr-only peer" x-model="sel.color" />
                                <span class="block w-8 h-8 rounded-full ring-2 ring-transparent peer-checked:ring-gray-800 peer-checked:ring-offset-2 transition" style="background: {{ $hex }}"></span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </form>
            <x-slot:footer>
                <x-button variant="outline" type="button" x-on:click="$dispatch('close-modal')">إلغاء</x-button>
                <x-button variant="primary" type="submit" form="edit-category-form" icon="check">حفظ</x-button>
            </x-slot:footer>
        </x-modal>
    </div>

    {{-- نافذة إضافة تصنيف --}}
    <x-modal name="add-category" title="إضافة تصنيف" maxWidth="max-w-lg">
        <form id="add-category-form" method="POST" action="{{ route('admin.categories.store') }}" class="space-y-4">
            @csrf
            <x-input label="اسم التصنيف" name="name" placeholder="مثال: باقات ورد" :required="true" />
            <x-select label="الأيقونة" name="icon" :options="$iconOptions" placeholder="اختر أيقونة" />
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">اللون</label>
                <div class="flex items-center gap-3">
                    @foreach ($colorHex as $key => $hex)
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
