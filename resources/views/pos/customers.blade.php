<x-layouts::pos title="العملاء">
    @php $customers = \App\Support\Demo::customers(); @endphp

    <div class="h-full overflow-y-auto p-4 sm:p-6" x-data="{ q: '' }">
        {{-- عنوان + بحث --}}
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div class="flex items-center gap-3">
                <span class="w-11 h-11 rounded-xl bg-info-50 text-info-600 flex items-center justify-center">
                    <x-icon name="users" class="w-6 h-6" />
                </span>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">العملاء</h1>
                    <p class="text-sm text-gray-400">{{ count($customers) }} عميل مسجّل</p>
                </div>
            </div>
            <div class="flex items-center gap-2 w-full sm:w-auto">
                <div class="flex-1 sm:w-64">
                    <x-input name="cust-search" placeholder="ابحث بالاسم أو الهاتف..." icon="search" x-model="q" />
                </div>
                <x-button variant="dark" icon="user-plus" class="shrink-0"
                          @click="$dispatch('open-modal','new-customer')">عميل جديد</x-button>
            </div>
        </div>

        {{-- شبكة العملاء --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach ($customers as $c)
                <div x-show="'{{ $c['name'] }}'.indexOf(q) > -1 || '{{ $c['phone'] }}'.indexOf(q) > -1 || q === ''"
                     class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all p-4">
                    <div class="flex items-center gap-3 mb-3">
                        <img src="{{ $c['avatar'] }}" class="w-12 h-12 rounded-xl object-cover" alt="{{ $c['name'] }}" />
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-800 truncate">{{ $c['name'] }}</p>
                            <p class="text-xs text-gray-400 flex items-center gap-1"><x-icon name="phone" class="w-3 h-3" /> {{ $c['phone'] }}</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 mb-3">
                        <div class="bg-gray-50 rounded-xl px-3 py-2 text-center">
                            <p class="text-xs text-gray-400">الطلبات</p>
                            <p class="font-bold text-gray-800">{{ $c['orders'] }}</p>
                        </div>
                        <div class="bg-gray-100 rounded-xl px-3 py-2 text-center">
                            <p class="text-xs text-gray-500">النقاط</p>
                            <p class="font-bold text-gray-900">{{ $c['points'] }}</p>
                        </div>
                    </div>
                    <x-button variant="light" size="sm" icon="check" class="w-full" :href="route('pos.index')">اختيار العميل</x-button>
                </div>
            @endforeach
        </div>

        {{-- نافذة عميل جديد --}}
        <x-modal name="new-customer" title="إضافة عميل جديد" maxWidth="max-w-md">
            <form id="pos-new-customer" method="POST" action="{{ route('pos.customers.store') }}" class="space-y-4">
                @csrf
                <x-input label="الاسم الكامل" name="name" placeholder="اسم العميل" icon="user" :required="true" />
                <x-input label="رقم الهاتف" name="phone" type="tel" placeholder="+968 9xxxxxxx" icon="phone" />
                <x-input label="البريد الإلكتروني" name="email" type="email" placeholder="example@mail.com" icon="mail" />
            </form>
            <x-slot:footer>
                <x-button variant="outline" @click="$dispatch('close-modal')">إلغاء</x-button>
                <x-button variant="dark" icon="user-plus" type="submit" form="pos-new-customer">حفظ العميل</x-button>
            </x-slot:footer>
        </x-modal>
    </div>
</x-layouts::pos>
