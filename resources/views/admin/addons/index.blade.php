<x-layouts::admin :title="__('الإضافات')">

    <x-page-header :title="__('الإضافات')" :subtitle="__('عناصر وخدمات تُضاف على المنتج مثل التغليف وبطاقة الإهداء')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المنتجات') => route('admin.products.index'), __('الإضافات') => '#']">
        <x-slot:actions>
            <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal', 'add-addon')">{{ __('إضافة عنصر') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @include('partials.products-tabs')

    @php
        $addons = \App\Support\Demo::addons();
        // رمز الإضافة قد يكون إيموجي (رموز آيفون) أو اسم أيقونة قديمة — نميّزهما عند العرض
        $isEmoji = fn ($icon) => $icon && preg_match('/[^\x00-\x7F]/', $icon);
    @endphp

    <div x-data="{ sel: { id: '', name: '', price: 0, icon: '', active: true } }">
        @if (count($addons))
            <x-table :headers="[__('العنصر'), __('السعر'), __('الحالة'), '']">
                @foreach ($addons as $a)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 whitespace-nowrap">
                            <div class="flex items-center gap-3">
                                <span class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 bg-primary-50 text-primary-600 text-lg leading-none">
                                    @if ($isEmoji($a['icon']))
                                        {{ $a['icon'] }}
                                    @else
                                        <x-icon :name="$a['icon'] ?: 'plus'" class="w-[18px] h-[18px]" />
                                    @endif
                                </span>
                                <span class="font-medium text-gray-800">{{ $a['name'] }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap font-medium">{{ \App\Support\Demo::money($a['price']) }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <x-badge :text="$a['active'] ? 'مفعّل' : 'معطّل'" :type="$a['active'] ? 'success' : 'gray'" />
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-left">
                            <x-dropdown align="left" width="w-40">
                                <x-slot:trigger>
                                    <button type="button" class="w-8 h-8 rounded-full hover:bg-gray-100 text-gray-400 flex items-center justify-center">
                                        <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                                    </button>
                                </x-slot:trigger>
                                <button type="button" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                    x-on:click="sel = { id: {{ $a['id'] }}, name: {{ \Illuminate\Support\Js::from($a['name']) }}, price: {{ (float) $a['price'] }}, icon: {{ \Illuminate\Support\Js::from($a['icon']) }}, active: {{ $a['active'] ? 'true' : 'false' }} }; $dispatch('open-modal','edit-addon')">
                                    <x-icon name="pencil" class="w-4 h-4" /> {{ __('تعديل') }}
                                </button>
                                <form method="POST" action="{{ route('admin.addons.destroy', $a['id']) }}" @submit.prevent="if(confirm(@js(__('حذف الإضافة «:name»؟', ['name' => $a['name']])))) $el.submit()">
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
                    <div class="px-4 py-3 text-xs text-gray-400">{{ __(':n عنصر', ['n' => count($addons)]) }}</div>
                </x-slot:footer>
            </x-table>
        @else
            <x-empty-state icon="plus-circle" :title="__('لا توجد إضافات')" :message="__('أضِف أول عنصر يُضاف على المنتجات (مثل التغليف أو بطاقة الإهداء).')" />
        @endif

        {{-- نافذة تعديل إضافة (مشتركة) --}}
        <x-modal name="edit-addon" :title="__('تعديل الإضافة')" maxWidth="max-w-lg">
            <form id="edit-addon-form" method="POST" x-bind:action="'{{ url('admin/addons') }}/' + sel.id" class="space-y-4">
                @csrf @method('PUT')
                <x-input :label="__('اسم العنصر')" name="name" x-bind:value="sel.name" :required="true" />
                <x-input :label="__('السعر') . ' (' . __('ر.ع') . ')'" name="price" type="number" step="0.001" min="0" x-bind:value="sel.price" />
                @include('partials.emoji-picker', ['model' => 'sel.icon'])
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="active" value="1" x-model="sel.active" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                    {{ __('مفعّل') }}
                </label>
            </form>
            <x-slot:footer>
                <x-button variant="outline" type="button" x-on:click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
                <x-button variant="primary" type="submit" form="edit-addon-form" icon="check">{{ __('حفظ') }}</x-button>
            </x-slot:footer>
        </x-modal>
    </div>

    {{-- نافذة إضافة عنصر --}}
    <x-modal name="add-addon" :title="__('إضافة عنصر')" maxWidth="max-w-lg">
        <form id="add-addon-form" method="POST" action="{{ route('admin.addons.store') }}" class="space-y-4">
            @csrf
            <x-input :label="__('اسم العنصر')" name="name" :placeholder="__('مثال: تغليف هدية')" :required="true" />
            <x-input :label="__('السعر') . ' (' . __('ر.ع') . ')'" name="price" type="number" step="0.001" min="0" placeholder="0.000" />
            <div x-data="{ icon: '🎁' }">
                @include('partials.emoji-picker', ['model' => 'icon'])
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="active" value="1" checked class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                {{ __('مفعّل') }}
            </label>
            <button type="submit" class="hidden"></button>
        </form>
        <x-slot:footer>
            <x-button variant="outline" type="button" x-data @click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <x-button variant="primary" type="submit" form="add-addon-form" icon="check">{{ __('حفظ') }}</x-button>
        </x-slot:footer>
    </x-modal>

</x-layouts::admin>
