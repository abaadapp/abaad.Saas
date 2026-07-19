<x-layouts::admin title="المورّدون">

    <x-page-header title="المورّدون" subtitle="إدارة موردي البضاعة وبيانات التواصل معهم"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المورّدون' => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="clipboard-list" :href="route('admin.purchases.index')">أوامر الشراء</x-button>
            <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal','add-supplier')">مورّد جديد</x-button>
        </x-slot:actions>
    </x-page-header>

    @php $suppliers = \App\Support\Demo::suppliers(); @endphp

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat-card label="إجمالي المورّدين" value="{{ count($suppliers) }}" icon="truck" color="primary" />
        <x-stat-card label="مورّدون لديهم أوامر" value="{{ collect($suppliers)->where('orders_count', '>', 0)->count() }}" icon="clipboard-check" color="success" />
        <x-stat-card label="إجمالي أوامر الشراء" value="{{ collect($suppliers)->sum('orders_count') }}" icon="package" color="info" />
    </div>

    <div x-data="listFilter()" x-ref="list">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-4">
            <x-input name="search" placeholder="ابحث باسم المورّد أو الهاتف..." icon="search" x-model="q" @input="apply()" />
        </div>

        @if (count($suppliers))
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($suppliers as $s)
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5" data-row data-search="{{ $s['name'] }} {{ $s['phone'] }} {{ $s['contact'] }}"
                        x-data='{ edit: false, s: @json($s) }'>
                        <div x-show="!edit">
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <span class="w-11 h-11 rounded-xl bg-primary-50 text-primary-600 flex items-center justify-center"><x-icon name="truck" class="w-5 h-5" /></span>
                                    <div>
                                        <p class="font-bold text-gray-800">{{ $s['name'] }}</p>
                                        <p class="text-xs text-gray-400">{{ $s['orders_count'] }} أمر شراء</p>
                                    </div>
                                </div>
                                <x-dropdown align="left" width="w-36">
                                    <x-slot:trigger>
                                        <button type="button" class="w-8 h-8 rounded-lg hover:bg-gray-100 text-gray-400 flex items-center justify-center"><x-icon name="ellipsis-vertical" class="w-5 h-5" /></button>
                                    </x-slot:trigger>
                                    <button type="button" @click="edit = true" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"><x-icon name="pencil" class="w-4 h-4" /> تعديل</button>
                                    <form method="POST" action="{{ route('admin.suppliers.destroy', $s['id']) }}" @submit.prevent="if(confirm('حذف المورّد؟')) $el.submit()">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50"><x-icon name="trash-2" class="w-4 h-4" /> حذف</button>
                                    </form>
                                </x-dropdown>
                            </div>
                            <ul class="mt-4 space-y-2 text-sm border-t border-gray-50 pt-3">
                                <li class="flex items-center justify-between"><span class="text-gray-500 flex items-center gap-2"><x-icon name="phone" class="w-4 h-4" /> الهاتف</span><span class="text-gray-700" dir="ltr">{{ $s['phone'] ?: '—' }}</span></li>
                                <li class="flex items-center justify-between"><span class="text-gray-500 flex items-center gap-2"><x-icon name="user" class="w-4 h-4" /> المسؤول</span><span class="text-gray-700">{{ $s['contact'] ?: '—' }}</span></li>
                                <li class="flex items-center justify-between"><span class="text-gray-500 flex items-center gap-2"><x-icon name="mail" class="w-4 h-4" /> البريد</span><span class="text-gray-700 truncate max-w-[140px]" dir="ltr">{{ $s['email'] ?: '—' }}</span></li>
                            </ul>
                            @if ($s['phone'])
                                <a href="tel:{{ $s['phone'] }}" class="mt-3 inline-flex items-center gap-1.5 text-sm text-primary-600 hover:text-primary-700"><x-icon name="phone" class="w-4 h-4" /> اتصال</a>
                            @endif
                        </div>
                        {{-- تعديل inline --}}
                        <form x-show="edit" x-cloak method="POST" action="{{ route('admin.suppliers.update', $s['id']) }}" class="space-y-2">
                            @csrf @method('PUT')
                            <input type="text" name="name" :value="s.name" required placeholder="الاسم" class="w-full rounded-lg border-gray-200 text-sm" />
                            <input type="text" name="phone" :value="s.phone" placeholder="الهاتف" dir="ltr" class="w-full rounded-lg border-gray-200 text-sm" />
                            <input type="text" name="contact_person" :value="s.contact" placeholder="الشخص المسؤول" class="w-full rounded-lg border-gray-200 text-sm" />
                            <input type="email" name="email" :value="s.email" placeholder="البريد" dir="ltr" class="w-full rounded-lg border-gray-200 text-sm" />
                            <div class="flex gap-2 pt-1">
                                <button type="submit" class="flex-1 bg-primary-600 hover:bg-primary-700 text-white text-sm rounded-lg py-2">حفظ</button>
                                <button type="button" @click="edit = false" class="px-3 text-sm text-gray-500">إلغاء</button>
                            </div>
                        </form>
                    </div>
                @endforeach
            </div>
        @else
            <x-empty-state icon="truck" title="لا يوجد مورّدون بعد" message="أضِف أول مورّد لبدء إنشاء أوامر الشراء.">
                <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal','add-supplier')">مورّد جديد</x-button>
            </x-empty-state>
        @endif
    </div>

    {{-- نافذة إضافة مورّد --}}
    <x-modal name="add-supplier" title="إضافة مورّد جديد">
        <form id="add-supplier-form" method="POST" action="{{ route('admin.suppliers.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-600 mb-1">اسم المورّد <span class="text-danger-500">*</span></label>
                <input type="text" name="name" required class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-sm text-gray-600 mb-1">الهاتف</label><input type="text" name="phone" dir="ltr" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" /></div>
                <div><label class="block text-sm text-gray-600 mb-1">الشخص المسؤول</label><input type="text" name="contact_person" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" /></div>
            </div>
            <div><label class="block text-sm text-gray-600 mb-1">البريد الإلكتروني</label><input type="email" name="email" dir="ltr" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" /></div>
            <div><label class="block text-sm text-gray-600 mb-1">ملاحظات</label><textarea name="notes" rows="2" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200"></textarea></div>
        </form>
        <x-slot:footer>
            <x-button variant="light" @click="$dispatch('close-modal')">إلغاء</x-button>
            <button type="submit" form="add-supplier-form" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg px-4 py-2">إضافة</button>
        </x-slot:footer>
    </x-modal>

</x-layouts::admin>
