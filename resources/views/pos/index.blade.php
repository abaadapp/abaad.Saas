<x-layouts::pos title="نقطة البيع">
    @php
        $products = \App\Support\Demo::products();
        $categories = \App\Support\Demo::posCategories();
        $customers = \App\Support\Demo::customers();
    @endphp

    <div x-data="posCart()" class="h-full flex flex-col lg:flex-row gap-4 p-4 overflow-hidden">

        {{-- ============ جزء المنتجات (يمين) ============ --}}
        <section class="flex-1 lg:w-2/3 flex flex-col min-h-0" x-data="{ cat: 'الكل', q: '' }">

            {{-- شريط البحث والباركود --}}
            <div class="flex flex-col sm:flex-row gap-3 mb-4 shrink-0">
                <div class="flex-1">
                    <x-input name="pos-search" placeholder="ابحث عن منتج بالاسم أو الرمز..." icon="search"
                             x-model="q" />
                </div>
                <div class="flex gap-2">
                    <div class="w-44">
                        <x-input name="pos-barcode" placeholder="امسح الباركود" icon="scan-barcode" />
                    </div>
                    <x-button variant="dark" icon="scan-line" class="shrink-0"
                              @click="$store.toasts.add('جاهز لمسح الباركود', 'info', 1500)">مسح</x-button>
                </div>
            </div>

            {{-- تبويبات التصنيفات --}}
            <div class="flex items-center gap-2 mb-4 overflow-x-auto pb-1 shrink-0">
                @foreach ($categories as $c)
                    <button type="button"
                            @click="cat = '{{ $c }}'"
                            :class="cat === '{{ $c }}' ? 'bg-gray-900 text-white shadow-sm' : 'bg-white text-gray-600 border border-gray-100 hover:bg-gray-50'"
                            class="px-4 py-2 rounded-xl text-sm font-medium whitespace-nowrap transition-colors">
                        {{ $c }}
                    </button>
                @endforeach
            </div>

            {{-- شبكة المنتجات --}}
            <div class="flex-1 overflow-y-auto -mx-1 px-1">
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
                    @foreach ($products as $p)
                        <div x-show="(cat === 'الكل' || cat === '{{ $p['cat'] }}') && ('{{ $p['name'] }}'.indexOf(q) > -1 || q === '')"
                             class="group bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md hover:border-gray-300 transition-all overflow-hidden cursor-pointer select-none text-right"
                             @click="add({ id: {{ $p['id'] }}, name: '{{ $p['name'] }}', price: {{ $p['price'] }}, image: '{{ $p['image'] }}' })">
                            <div class="relative aspect-square bg-gray-50 overflow-hidden">
                                <img src="{{ $p['image'] }}" alt="{{ $p['name'] }}" loading="lazy"
                                     class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
                                <div class="absolute top-2 right-2">
                                    <x-badge :text="$p['stock_status']" />
                                </div>
                            </div>
                            <div class="p-3">
                                <h3 class="font-semibold text-sm text-gray-800 truncate">{{ $p['name'] }}</h3>
                                <p class="text-xs text-gray-400 mt-0.5">المتوفر: {{ $p['qty'] }}</p>
                                <div class="mt-2 flex items-center justify-between gap-1">
                                    <p class="font-bold text-gray-900 text-sm">{{ \App\Support\Demo::money($p['price']) }}</p>
                                    <span class="w-8 h-8 rounded-lg bg-gray-100 text-gray-900 flex items-center justify-center group-hover:bg-gray-900 group-hover:text-white transition-colors shrink-0">
                                        <x-icon name="plus" class="w-4 h-4" />
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ جزء السلة (يسار) ============ --}}
        <aside class="lg:w-1/3 w-full flex flex-col min-h-0 bg-white rounded-2xl border border-gray-100 shadow-sm">

            {{-- رأس السلة --}}
            <div class="px-4 py-3 border-b border-gray-100 shrink-0">
                <div class="flex items-center justify-between">
                    <h2 class="font-bold text-gray-800">طلب #POS-1042</h2>
                    <span class="text-xs text-gray-400" x-text="count + ' عنصر'"></span>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <x-dropdown align="right" width="w-56">
                        <x-slot:trigger>
                            <button type="button" class="flex items-center gap-2 flex-1 bg-gray-50 hover:bg-gray-100 rounded-xl px-3 py-2 text-sm text-gray-700 w-full transition-colors">
                                <x-icon name="user" class="w-4 h-4 text-gray-900" />
                                <span x-text="customer"></span>
                                <x-icon name="chevron-down" class="w-4 h-4 mr-auto text-gray-400" />
                            </button>
                        </x-slot:trigger>
                        <button type="button" @click="customer = 'عميل نقدي'" class="block w-full text-right px-4 py-2 text-sm hover:bg-gray-50">عميل نقدي</button>
                        @foreach ($customers as $cust)
                            <button type="button" @click="customer = '{{ $cust['name'] }}'" class="block w-full text-right px-4 py-2 text-sm hover:bg-gray-50">{{ $cust['name'] }}</button>
                        @endforeach
                    </x-dropdown>
                    <button type="button" @click="$dispatch('open-modal','new-customer')"
                            class="shrink-0 w-10 h-10 flex items-center justify-center rounded-xl bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors" title="عميل جديد">
                        <x-icon name="user-plus" class="w-5 h-5" />
                    </button>
                </div>
            </div>

            {{-- عناصر السلة --}}
            <div class="flex-1 overflow-y-auto px-3 py-3 min-h-0">
                {{-- حالة فارغة --}}
                <div x-show="items.length === 0" class="flex flex-col items-center justify-center text-center h-full py-10">
                    <div class="w-16 h-16 rounded-2xl bg-gray-100 flex items-center justify-center text-gray-400 mb-3">
                        <x-icon name="shopping-cart" class="w-8 h-8" />
                    </div>
                    <p class="font-semibold text-gray-600">السلة فارغة</p>
                    <p class="text-sm text-gray-400 mt-1">اختر منتجات لإضافتها إلى الطلب</p>
                </div>

                <div class="space-y-2" x-show="items.length > 0">
                    <template x-for="item in items" :key="item.id">
                        <div class="bg-gray-50 rounded-xl p-2.5">
                            <div class="flex items-center gap-2.5">
                                <img :src="item.image" :alt="item.name" class="w-12 h-12 rounded-lg object-cover shrink-0" />
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-gray-800 truncate" x-text="item.name"></p>
                                    <p class="text-xs text-gray-900 font-medium" x-text="money(item.price)"></p>
                                </div>
                                <button type="button" @click="remove(item.id)" class="w-7 h-7 flex items-center justify-center rounded-lg text-danger-500 hover:bg-danger-50 transition-colors shrink-0">
                                    <x-icon name="trash-2" class="w-4 h-4" />
                                </button>
                            </div>
                            <div class="flex items-center justify-between gap-2 mt-2">
                                <div class="flex items-center gap-1 bg-white rounded-lg border border-gray-200">
                                    <button type="button" @click="dec(item.id)" class="w-7 h-7 flex items-center justify-center text-gray-500 hover:text-gray-900">
                                        <x-icon name="minus" class="w-4 h-4" />
                                    </button>
                                    <span class="w-7 text-center text-sm font-bold text-gray-800" x-text="item.qty"></span>
                                    <button type="button" @click="inc(item.id)" class="w-7 h-7 flex items-center justify-center text-gray-500 hover:text-gray-900">
                                        <x-icon name="plus" class="w-4 h-4" />
                                    </button>
                                </div>
                                <p class="text-sm font-bold text-gray-800" x-text="money(item.price * item.qty)"></p>
                            </div>
                            <input type="text" x-model="item.note" placeholder="ملاحظة..."
                                   class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-gray-700 placeholder-gray-400 focus:border-gray-900 focus:ring-1 focus:ring-gray-200 focus:outline-none" />
                        </div>
                    </template>
                </div>
            </div>

            {{-- الملخص --}}
            <div class="border-t border-gray-100 p-3 space-y-2.5 shrink-0">
                <div class="flex items-center justify-between text-sm text-gray-600">
                    <span>المجموع الفرعي</span>
                    <span class="font-medium text-gray-800" x-text="money(subtotal)"></span>
                </div>
                <div class="flex items-center justify-between text-sm text-gray-600">
                    <span class="flex items-center gap-1">الخصم <span class="text-xs text-gray-400">%</span></span>
                    <div class="flex items-center gap-2">
                        <input type="number" min="0" max="100" x-model.number="discountPercent"
                               class="w-16 rounded-lg border border-gray-200 px-2 py-1 text-sm text-left focus:border-gray-900 focus:ring-1 focus:ring-gray-200 focus:outline-none" />
                        <span class="text-danger-500 text-xs w-16 text-left" x-text="'- ' + money(discountAmount)"></span>
                    </div>
                </div>
                <div class="flex items-center justify-between text-sm text-gray-600">
                    <span>الضريبة (5%)</span>
                    <span class="font-medium text-gray-800" x-text="money(taxAmount)"></span>
                </div>
                <div class="flex items-center justify-between text-sm text-gray-600">
                    <span>رسوم التوصيل</span>
                    <input type="number" min="0" step="0.5" x-model.number="deliveryFee"
                           class="w-20 rounded-lg border border-gray-200 px-2 py-1 text-sm text-left focus:border-gray-900 focus:ring-1 focus:ring-gray-200 focus:outline-none" />
                </div>
                <div class="flex items-center justify-between pt-2 border-t border-dashed border-gray-200">
                    <span class="font-bold text-gray-800">الإجمالي</span>
                    <span class="text-xl font-extrabold text-gray-900" x-text="money(total)"></span>
                </div>

                {{-- أزرار الإجراءات --}}
                <div class="grid grid-cols-3 gap-2 pt-1">
                    <button type="button"
                            @click="items.length ? fetch('{{ route('pos.hold') }}', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                                body: JSON.stringify({ items: items, customer: customer, total: total })
                            }).then(() => { clear(); $store.toasts.add('تم تعليق الطلب', 'warning'); }) : null"
                            :disabled="items.length === 0"
                            class="flex flex-col items-center gap-1 py-2 rounded-xl bg-warning-50 text-warning-600 hover:bg-warning-100 text-xs font-medium transition-colors disabled:opacity-40">
                        <x-icon name="pause-circle" class="w-5 h-5" /> تعليق
                    </button>
                    <button type="button" @click="clear()"
                            :disabled="items.length === 0"
                            class="flex flex-col items-center gap-1 py-2 rounded-xl bg-danger-50 text-danger-600 hover:bg-danger-100 text-xs font-medium transition-colors disabled:opacity-40">
                        <x-icon name="trash-2" class="w-5 h-5" /> إلغاء
                    </button>
                    <button type="button"
                            @click="items.length ? $store.toasts.add('تم حفظ الطلب', 'success') : null"
                            :disabled="items.length === 0"
                            class="flex flex-col items-center gap-1 py-2 rounded-xl bg-gray-100 text-gray-600 hover:bg-gray-200 text-xs font-medium transition-colors disabled:opacity-40">
                        <x-icon name="save" class="w-5 h-5" /> حفظ
                    </button>
                </div>
                <button type="button"
                        @click="items.length ? $dispatch('open-modal','payment') : $store.toasts.add('السلة فارغة', 'danger', 1500)"
                        class="w-full flex items-center justify-center gap-2 py-3.5 rounded-xl bg-gray-900 hover:bg-gray-800 text-white font-bold text-base shadow-sm transition-colors">
                    <x-icon name="credit-card" class="w-5 h-5" />
                    الدفع
                    <span x-text="money(total)"></span>
                </button>
            </div>
        </aside>

        {{-- ============ نافذة الدفع ============ --}}
        <x-modal name="payment" title="إتمام الدفع" maxWidth="max-w-lg">
            <div x-data="{ step: 'pay', paid: 0, method: 'نقدي', invoice: 'INV-' + Math.floor(78900 + Math.random() * 99) }"
                 @close-modal.window="step = 'pay'; paid = 0">

                {{-- خطوة الدفع --}}
                <div x-show="step === 'pay'" class="space-y-4">
                    <div class="bg-gray-100 rounded-2xl p-4 text-center">
                        <p class="text-sm text-gray-900">الإجمالي المطلوب</p>
                        <p class="text-3xl font-extrabold text-gray-900 mt-1" x-text="money(total)"></p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">المبلغ المدفوع</label>
                        <input type="number" min="0" step="0.001" x-model.number="paid" placeholder="0.000"
                               class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-lg font-bold text-gray-800 focus:border-gray-900 focus:ring-2 focus:ring-gray-200 focus:outline-none" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-gray-50 rounded-xl p-3 text-center">
                            <p class="text-xs text-gray-400">المبلغ المتبقي</p>
                            <p class="font-bold text-danger-600 mt-0.5" x-text="fmt(Math.max(0, displayTotal - paid))"></p>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3 text-center">
                            <p class="text-xs text-gray-400">المبلغ المرتجع</p>
                            <p class="font-bold text-success-600 mt-0.5" x-text="fmt(Math.max(0, paid - displayTotal))"></p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">وسيلة الدفع</label>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach (['نقدي' => 'banknote', 'بطاقة' => 'credit-card', 'تحويل بنكي' => 'landmark', 'دفع متعدد' => 'layers', 'آجل' => 'clock'] as $m => $mi)
                                <button type="button" @click="method = '{{ $m }}'"
                                        :class="method === '{{ $m }}' ? 'border-gray-900 bg-gray-100 text-gray-900' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                                        class="flex flex-col items-center gap-1 border rounded-xl py-2.5 text-xs font-medium transition-colors">
                                    <x-icon name="{{ $mi }}" class="w-5 h-5" />
                                    {{ $m }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <button type="button" @click="
                            fetch('{{ route('pos.checkout') }}', {
                              method: 'POST',
                              headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                              body: JSON.stringify({ items: items, customer: customer, payment_method: method, discount: discountAmount, tax: taxAmount, delivery_fee: Number(deliveryFee||0), total: total })
                            }).then(r => r.json()).then(d => { if (d.invoice) invoice = d.invoice; step = 'success'; }).catch(() => { step = 'success'; })
                            "
                            class="w-full py-3.5 rounded-xl bg-success-600 hover:bg-success-700 text-white font-bold text-base shadow-sm transition-colors">
                        تأكيد الدفع
                    </button>
                </div>

                {{-- خطوة النجاح --}}
                <div x-show="step === 'success'" x-cloak class="text-center py-4 space-y-4">
                    <div class="w-20 h-20 mx-auto rounded-full bg-success-50 flex items-center justify-center text-success-600">
                        <x-icon name="check-circle" class="w-12 h-12" />
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-800">تم الدفع بنجاح</h3>
                        <p class="text-sm text-gray-400 mt-1">تمت معالجة الطلب وإصدار الفاتورة</p>
                    </div>
                    <div class="bg-gray-50 rounded-2xl p-4 text-right space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">رقم الفاتورة</span><span class="font-bold text-gray-800" x-text="invoice"></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">المبلغ</span><span class="font-bold text-gray-800" x-text="money(total)"></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">وسيلة الدفع</span><span class="font-bold text-gray-800" x-text="method"></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">العميل</span><span class="font-bold text-gray-800" x-text="customer"></span></div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" @click="window.print()"
                                class="flex items-center justify-center gap-2 py-3 rounded-xl border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 font-medium transition-colors">
                            <x-icon name="printer" class="w-5 h-5" /> طباعة الفاتورة
                        </button>
                        <button type="button" @click="clear(); step = 'pay'; paid = 0; open = false; $store.toasts.add('طلب جديد جاهز', 'success', 1500)"
                                class="flex items-center justify-center gap-2 py-3 rounded-xl bg-gray-900 hover:bg-gray-800 text-white font-medium transition-colors">
                            <x-icon name="plus" class="w-5 h-5" /> طلب جديد
                        </button>
                    </div>
                </div>
            </div>
        </x-modal>

        {{-- ============ نافذة عميل جديد ============ --}}
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
