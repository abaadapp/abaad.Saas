<x-layouts::admin :title="__('الفروع')">
    @php $branchList = \App\Support\Demo::branches(); @endphp

    <div x-data="{ adding: {{ $errors->any() ? 'true' : 'false' }} }">
        <x-page-header
            :title="__('الفروع')"
            :subtitle="__('أضِف فروع نشاطك وأدرها من مكان واحد')"
            :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الفروع') => '#']"
        >
            <x-slot:actions>
                <x-button variant="primary" size="md" icon="plus" type="button" x-on:click="adding = !adding">{{ __('إضافة فرع') }}</x-button>
            </x-slot:actions>
        </x-page-header>

        <div class="space-y-6">
            {{-- بطاقة الإحصائية --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex items-center gap-4">
                    <span class="w-11 h-11 rounded-xl bg-gray-900 text-white flex items-center justify-center shrink-0">
                        <x-icon name="git-branch" class="w-5 h-5" />
                    </span>
                    <div>
                        <p class="text-2xl font-bold text-gray-900">{{ count($branchList) }}</p>
                        <p class="text-xs text-gray-400">{{ __('إجمالي الفروع') }}</p>
                    </div>
                </div>
            </div>

            {{-- نموذج الإضافة --}}
            <div x-show="adding" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div class="flex items-center gap-2 mb-5">
                    <span class="w-9 h-9 rounded-xl bg-gray-900 text-white flex items-center justify-center"><x-icon name="plus" class="w-5 h-5" /></span>
                    <h3 class="text-lg font-bold text-gray-800">{{ __('إضافة فرع جديد') }}</h3>
                </div>
                <form method="POST" action="{{ route('admin.branches.store') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
                    @csrf
                    <x-input :label="__('اسم الفرع')" name="name" :placeholder="__('فرع صحار')" :required="true" />
                    <x-input :label="__('الهاتف')" name="phone" placeholder="+968 2xxxxxxx" />
                    <x-input :label="__('العنوان')" name="address" :placeholder="__('المدينة - الحي')" />
                    <x-button variant="primary" size="md" type="submit" icon="check">{{ __('حفظ الفرع') }}</x-button>
                </form>
            </div>

            {{-- قائمة الفروع --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-5">{{ __('قائمة الفروع') }}</h3>
                @if (count($branchList))
                    <x-table :headers="[__('الفرع'), __('الهاتف'), __('العنوان'), '']">
                        @foreach ($branchList as $branch)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <span class="w-9 h-9 rounded-xl bg-gray-100 text-gray-600 flex items-center justify-center shrink-0">
                                            <x-icon name="store" class="w-4 h-4" />
                                        </span>
                                        {{ $branch['name'] }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-600 whitespace-nowrap" dir="ltr">{{ $branch['phone'] ?: '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $branch['address'] ?: '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-left">
                                    <form method="POST" action="{{ route('admin.branches.destroy', $branch['id']) }}" @submit="if(!confirm(@js(__('حذف هذا الفرع؟')))) $event.preventDefault()">
                                        @csrf @method('DELETE')
                                        <x-button variant="ghost" size="sm" type="submit" icon="trash-2" class="text-danger-600">{{ __('حذف') }}</x-button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </x-table>
                @else
                    <x-empty-state icon="git-branch" :title="__('لا توجد فروع')" :message="__('أضف أول فرع لنشاطك من زر «إضافة فرع» بالأعلى.')" />
                @endif
            </div>
        </div>
    </div>
</x-layouts::admin>
