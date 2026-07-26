<x-layouts::admin :title="__('الأقسام')">

    <x-page-header :title="__('الأقسام')" :subtitle="__('تنظيم منتجاتك ضمن أقسام')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المنتجات') => route('admin.products.index'), __('الأقسام') => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="external-link" :href="route('admin.categories.create')">{{ __('صفحة الإضافة') }}</x-button>
            <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal', 'add-category')">{{ __('إضافة قسم') }}</x-button>
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
        $colorHex = ['primary' => '#7c3aed', 'secondary' => '#db2777', 'success' => '#10b981', 'warning' => '#f59e0b', 'info' => '#3b82f6', 'danger' => '#ef4444'];
        $colorNames = ['primary' => __('بنفسجي'), 'secondary' => __('وردي'), 'success' => __('أخضر'), 'warning' => __('برتقالي'), 'info' => __('أزرق'), 'danger' => __('أحمر')];
        // لوحة ألوان كاملة + محوّل لأي لون مخزّن (مفتاح دلالي أو hex مخصص)
        $palette = ['#7c3aed', '#8b5cf6', '#6366f1', '#3b82f6', '#0ea5e9', '#06b6d4', '#14b8a6', '#10b981', '#22c55e', '#84cc16', '#eab308', '#f59e0b', '#f97316', '#ef4444', '#e11d48', '#db2777', '#d946ef', '#a855f7', '#64748b', '#78716c'];
        $resolveHex = fn ($c) => str_starts_with((string) $c, '#') ? $c : ($colorHex[$c] ?? $colorHex['primary']);
        // رمز القسم قد يكون إيموجي (رموز آيفون) أو اسم أيقونة قديمة — نميّزهما عند العرض
        $isEmoji = fn ($icon) => $icon && preg_match('/[^\x00-\x7F]/', $icon);
    @endphp

    @php $categories = \App\Support\Demo::categories(); @endphp

    {{-- قائمة الأقسام --}}
    <div x-data="{ sel: { id: '', name: '', name_en: '', icon: '', color: '' } }">
        @if (count($categories))
            <x-table :headers="[__('القسم'), __('الأيقونة'), __('اللون'), __('عدد المنتجات'), '']">
                @foreach ($categories as $cat)
                    <tr class="hover:bg-gray-50">
                        @php $chex = $resolveHex($cat['color']); @endphp
                        <td class="px-4 py-3 whitespace-nowrap">
                            <div class="flex items-center gap-3">
                                <span class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 text-lg leading-none" style="background: {{ $chex }}1a; color: {{ $chex }};">
                                    @if ($isEmoji($cat['icon']))
                                        {{ $cat['icon'] }}
                                    @else
                                        <x-icon :name="$cat['icon'] ?: 'tag'" class="w-[18px] h-[18px]" />
                                    @endif
                                </span>
                                <span class="font-medium text-gray-800">{{ $cat['name'] }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap font-mono text-xs">{{ $cat['icon'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="inline-flex items-center gap-2 text-sm text-gray-600">
                                <span class="w-4 h-4 rounded-full" style="background: {{ $chex }}"></span>
                                {{ $colorNames[$cat['color']] ?? $cat['color'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ __(':n منتج', ['n' => $cat['products']]) }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-left">
                            <x-dropdown align="left" width="w-40">
                                <x-slot:trigger>
                                    <button type="button" class="w-8 h-8 rounded-full hover:bg-gray-100 text-gray-400 flex items-center justify-center">
                                        <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                                    </button>
                                </x-slot:trigger>
                                <button type="button" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                    x-on:click="sel = { id: {{ $cat['id'] }}, name: {{ \Illuminate\Support\Js::from($cat['name']) }}, name_en: {{ \Illuminate\Support\Js::from($cat['name_en'] ?? '') }}, icon: {{ \Illuminate\Support\Js::from($cat['icon']) }}, color: {{ \Illuminate\Support\Js::from($resolveHex($cat['color'])) }} }; $dispatch('open-modal','edit-category')">
                                    <x-icon name="pencil" class="w-4 h-4" /> {{ __('تعديل') }}
                                </button>
                                <form method="POST" action="{{ route('admin.categories.destroy', $cat['id']) }}" @submit.prevent="if(confirm(@js(__('حذف القسم «:name»؟', ['name' => $cat['name']])))) $el.submit()">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50">
                                        <x-icon name="trash-2" class="w-4 h-4" /> {{ __('حذف') }}
                                    </button>
                                </form>
                            </x-dropdown>
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <div class="px-4 py-3 text-xs text-gray-400">{{ __(':n قسم', ['n' => count($categories)]) }}</div>
                </x-slot:footer>
            </x-table>
        @else
            <x-empty-state icon="tags" :title="__('لا توجد أقسام')" :message="__('أضِف أول قسم لتنظيم منتجاتك.')" />
        @endif

        {{-- نافذة تعديل قسم (مشتركة) --}}
        <x-modal name="edit-category" :title="__('تعديل القسم')" maxWidth="max-w-lg">
            <form id="edit-category-form" method="POST" x-bind:action="'{{ url('admin/categories') }}/' + sel.id" class="space-y-4">
                @csrf @method('PUT')
                <x-input :label="__('اسم القسم')" name="name" x-bind:value="sel.name" :required="true" />
                <x-input :label="__('الاسم بالإنجليزية (اختياري)')" name="name_en" x-bind:value="sel.name_en" placeholder="e.g. Bouquets" dir="ltr" />
                @include('partials.emoji-picker', ['model' => 'sel.icon'])
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('اللون') }}</label>
                    <input type="hidden" name="color" x-model="sel.color" />
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($palette as $hex)
                            <button type="button" @click="sel.color = '{{ $hex }}'"
                                class="w-8 h-8 rounded-full ring-2 ring-offset-2 transition"
                                :class="sel.color === '{{ $hex }}' ? 'ring-gray-800' : 'ring-transparent'"
                                style="background: {{ $hex }}"></button>
                        @endforeach
                        <input type="color" x-model="sel.color" title="{{ __('لون مخصص') }}"
                            class="w-8 h-8 rounded-full cursor-pointer border border-gray-200 bg-transparent p-0.5" />
                    </div>
                </div>
            </form>
            <x-slot:footer>
                <x-button variant="outline" type="button" x-on:click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
                <x-button variant="primary" type="submit" form="edit-category-form" icon="check">{{ __('حفظ') }}</x-button>
            </x-slot:footer>
        </x-modal>
    </div>

    {{-- نافذة إضافة قسم --}}
    <x-modal name="add-category" :title="__('إضافة قسم')" maxWidth="max-w-lg">
        <form id="add-category-form" method="POST" action="{{ route('admin.categories.store') }}" class="space-y-4">
            @csrf
            <x-input :label="__('اسم القسم')" name="name" :placeholder="__('مثال: باقات ورد')" :required="true" />
            <x-input :label="__('الاسم بالإنجليزية (اختياري)')" name="name_en" placeholder="e.g. Bouquets" dir="ltr" />
            <div x-data="{ icon: '🌷' }">
                @include('partials.emoji-picker', ['model' => 'icon'])
            </div>
            <div x-data="{ color: '{{ $palette[0] }}' }">
                <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('اللون') }}</label>
                <input type="hidden" name="color" x-model="color" />
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($palette as $hex)
                        <button type="button" @click="color = '{{ $hex }}'"
                            class="w-8 h-8 rounded-full ring-2 ring-offset-2 transition"
                            :class="color === '{{ $hex }}' ? 'ring-gray-800' : 'ring-transparent'"
                            style="background: {{ $hex }}"></button>
                    @endforeach
                    <input type="color" x-model="color" title="{{ __('لون مخصص') }}"
                        class="w-8 h-8 rounded-full cursor-pointer border border-gray-200 bg-transparent p-0.5" />
                </div>
            </div>
            <button type="submit" class="hidden"></button>
        </form>
        <x-slot:footer>
            <x-button variant="outline" type="button" x-data @click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <x-button variant="primary" type="submit" form="add-category-form" icon="check">{{ __('حفظ') }}</x-button>
        </x-slot:footer>
    </x-modal>

</x-layouts::admin>
