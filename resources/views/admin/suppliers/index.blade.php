<x-layouts::admin :title="__('المورّدون')">

    <x-page-header :title="__('المورّدون')" :subtitle="__('إدارة موردي البضاعة وبيانات التواصل معهم')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المورّدون') => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="clipboard-list" :href="route('admin.purchases.index')">{{ __('أوامر الشراء') }}</x-button>
            <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal','add-supplier')">{{ __('مورّد جديد') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @include('partials.inventory-tabs')

    @php $suppliers = \App\Support\Demo::suppliers(); @endphp

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat-card :label="__('إجمالي المورّدين')" value="{{ count($suppliers) }}" icon="truck" color="primary" />
        <x-stat-card :label="__('مورّدون لديهم أوامر')" value="{{ collect($suppliers)->where('orders_count', '>', 0)->count() }}" icon="clipboard-check" color="success" />
        <x-stat-card :label="__('إجمالي أوامر الشراء')" value="{{ collect($suppliers)->sum('orders_count') }}" icon="package" color="info" />
    </div>

    <div x-data="listFilter()" x-ref="list">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-4">
            <x-input name="search" :placeholder="__('ابحث باسم المورّد أو الهاتف...')" icon="search" x-model="q" @input="apply()" />
        </div>

        <div x-data="{ sel: { id: '', name: '', phone: '', contact: '', email: '' } }">
            @if (count($suppliers))
                <x-table :headers="[__('المورّد'), __('الهاتف'), __('الشخص المسؤول'), __('البريد'), __('أوامر الشراء'), __('إجراءات')]">
                    @foreach ($suppliers as $s)
                        <tr class="hover:bg-gray-50" data-row data-search="{{ $s['name'] }} {{ $s['phone'] }} {{ $s['contact'] }}">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="flex items-center gap-3">
                                    <span class="w-9 h-9 rounded-xl bg-primary-50 text-primary-600 flex items-center justify-center shrink-0">
                                        <x-icon name="truck" class="w-4 h-4" />
                                    </span>
                                    <span class="font-medium text-gray-800">{{ $s['name'] }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap" dir="ltr">{{ $s['phone'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $s['contact'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $s['email'] ?: '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <x-badge type="info" :text="__(':count أمر', ['count' => $s['orders_count']])" />
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <x-dropdown align="left" width="w-40">
                                    <x-slot:trigger>
                                        <button type="button" class="w-8 h-8 rounded-full hover:bg-gray-100 text-gray-400 flex items-center justify-center">
                                            <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                                        </button>
                                    </x-slot:trigger>
                                    @if ($s['phone'])
                                        <a href="tel:{{ $s['phone'] }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                            <x-icon name="phone" class="w-4 h-4" /> {{ __('اتصال') }}
                                        </a>
                                    @endif
                                    <button type="button" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                        x-on:click="sel = { id: {{ $s['id'] }}, name: @js($s['name']), phone: @js($s['phone']), contact: @js($s['contact']), email: @js($s['email']) }; $dispatch('open-modal','edit-supplier')">
                                        <x-icon name="pencil" class="w-4 h-4" /> {{ __('تعديل') }}
                                    </button>
                                    <form method="POST" action="{{ route('admin.suppliers.destroy', $s['id']) }}" @submit.prevent="if(confirm(@js(__('حذف المورّد؟')))) $el.submit()">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50">
                                            <x-icon name="trash-2" class="w-4 h-4" /> {{ __('حذف') }}
                                        </button>
                                    </form>
                                </x-dropdown>
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            @else
                <x-empty-state icon="truck" :title="__('لا يوجد مورّدون بعد')" :message="__('أضِف أول مورّد لبدء إنشاء أوامر الشراء.')">
                    <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal','add-supplier')">{{ __('مورّد جديد') }}</x-button>
                </x-empty-state>
            @endif

            {{-- نافذة تعديل المورّد --}}
            <x-modal name="edit-supplier" :title="__('تعديل بيانات المورّد')" maxWidth="max-w-md">
                <form id="edit-supplier-form" method="POST" :action="'{{ url('admin/suppliers') }}/' + sel.id" class="space-y-4">
                    @csrf @method('PUT')
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">{{ __('اسم المورّد') }} <span class="text-danger-500">*</span></label>
                        <input type="text" name="name" x-bind:value="sel.name" required class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">{{ __('الهاتف') }}</label>
                            <input type="text" name="phone" x-bind:value="sel.phone" dir="ltr" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">{{ __('الشخص المسؤول') }}</label>
                            <input type="text" name="contact_person" x-bind:value="sel.contact" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">{{ __('البريد الإلكتروني') }}</label>
                        <input type="email" name="email" x-bind:value="sel.email" dir="ltr" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                    </div>
                </form>
                <x-slot:footer>
                    <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
                    <x-button variant="primary" size="md" icon="check" type="submit" form="edit-supplier-form">{{ __('حفظ') }}</x-button>
                </x-slot:footer>
            </x-modal>
        </div>
    </div>

    {{-- نافذة إضافة مورّد --}}
    <x-modal name="add-supplier" :title="__('إضافة مورّد جديد')">
        <form id="add-supplier-form" method="POST" action="{{ route('admin.suppliers.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('اسم المورّد') }} <span class="text-danger-500">*</span></label>
                <input type="text" name="name" required class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-sm text-gray-600 mb-1">{{ __('الهاتف') }}</label><input type="text" name="phone" dir="ltr" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" /></div>
                <div><label class="block text-sm text-gray-600 mb-1">{{ __('الشخص المسؤول') }}</label><input type="text" name="contact_person" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" /></div>
            </div>
            <div><label class="block text-sm text-gray-600 mb-1">{{ __('البريد الإلكتروني') }}</label><input type="email" name="email" dir="ltr" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" /></div>
            <div><label class="block text-sm text-gray-600 mb-1">{{ __('ملاحظات') }}</label><textarea name="notes" rows="2" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200"></textarea></div>
        </form>
        <x-slot:footer>
            <x-button variant="light" @click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <button type="submit" form="add-supplier-form" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-full px-4 py-2">{{ __('إضافة') }}</button>
        </x-slot:footer>
    </x-modal>

</x-layouts::admin>
