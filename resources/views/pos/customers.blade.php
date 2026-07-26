<x-layouts::pos :title="__('العملاء')">
    @php $customers = \App\Support\Demo::customers(); @endphp

    <div class="h-full overflow-y-auto p-4 sm:p-6" x-data="{ q: '' }">
        {{-- عنوان + بحث --}}
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div class="flex items-center gap-3">
                <span class="w-11 h-11 rounded-xl bg-info-50 text-info-600 flex items-center justify-center">
                    <x-icon name="users" class="w-6 h-6" />
                </span>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">{{ __('العملاء') }}</h1>
                    <p class="text-sm text-gray-400">{{ __(':n عميل مسجّل', ['n' => count($customers)]) }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2 w-full sm:w-auto">
                <div class="flex-1 sm:w-64">
                    <x-input name="cust-search" :placeholder="__('ابحث بالاسم أو الهاتف...')" icon="search" x-model="q" />
                </div>
                <x-button variant="dark" icon="user-plus" class="shrink-0"
                          @click="$dispatch('open-modal','new-customer')">{{ __('عميل جديد') }}</x-button>
            </div>
        </div>

        {{-- قائمة العملاء --}}
        @if (count($customers))
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-right">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-4 py-3 font-medium">{{ __('العميل') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('الهاتف') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('الطلبات') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('النقاط') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($customers as $c)
                                <tr class="hover:bg-gray-50"
                                    x-show="q === '' || {{ \Illuminate\Support\Js::from(($c['name'] ?? '') . ' ' . ($c['name_en'] ?? '') . ' ' . ($c['phone'] ?? '')) }}.includes(q)">
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex items-center gap-3">
                                            <img src="{{ $c['avatar'] }}" class="w-9 h-9 rounded-full object-cover shrink-0" alt="{{ $c['label'] }}" />
                                            <span class="font-medium text-gray-800">{{ $c['label'] }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap" dir="ltr">{{ $c['phone'] ?: '—' }}</td>
                                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $c['orders'] }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-800">{{ $c['points'] }}</span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-left">
                                        <x-button variant="light" size="sm" icon="check"
                                                  :href="route('pos.index', ['customer' => $c['name']])">{{ __('اختيار') }}</x-button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 text-xs text-gray-400 border-t border-gray-100">{{ __(':n عميل', ['n' => count($customers)]) }}</div>
            </div>
        @else
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
                <x-empty-state icon="users" :title="__('لا يوجد عملاء')" :message="__('أضِف أول عميل من زر «عميل جديد».')" />
            </div>
        @endif

        {{-- نافذة عميل جديد --}}
        <x-modal name="new-customer" :title="__('إضافة عميل جديد')" maxWidth="max-w-md">
            <form id="pos-new-customer" method="POST" action="{{ route('pos.customers.store') }}" class="space-y-4">
                @csrf
                <x-input :label="__('الاسم الكامل')" name="name" :placeholder="__('اسم العميل')" icon="user" :required="true" />
                <x-input :label="__('رقم الهاتف')" name="phone" type="tel" placeholder="+968 9xxxxxxx" icon="phone" />
                <x-input :label="__('البريد الإلكتروني')" name="email" type="email" placeholder="example@mail.com" icon="mail" />
            </form>
            <x-slot:footer>
                <x-button variant="outline" @click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
                <x-button variant="dark" icon="user-plus" type="submit" form="pos-new-customer">{{ __('حفظ العميل') }}</x-button>
            </x-slot:footer>
        </x-modal>
    </div>
</x-layouts::pos>
