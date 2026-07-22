<x-layouts::admin :title="__('أمر شراء جديد')">

    <x-page-header :title="__('أمر شراء جديد')" :subtitle="__('حدّد المورّد والأصناف المطلوبة')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('أوامر الشراء') => route('admin.purchases.index'), __('جديد') => '#']" />

    @php
        $suppliers = \App\Support\Demo::suppliers();
        $products = \App\Support\Demo::products();
        $prefill = request('from') === 'reorder'
            ? collect(\App\Support\Demo::reorderSuggestions())->map(fn ($r) => ['product_id' => $r['id'], 'name' => $r['name'], 'cost' => $r['cost'], 'quantity' => $r['suggested']])->values()
            : collect([['product_id' => '', 'name' => '', 'cost' => 0, 'quantity' => 1]]);
    @endphp

    <form method="POST" action="{{ route('admin.purchases.store') }}" enctype="multipart/form-data"
        x-data='{
            items: @json($prefill),
            products: @json(collect($products)->map(fn($p) => ["id" => $p["id"], "name" => $p["name"], "cost" => $p["cost"]])->values()),
            addRow() { this.items.push({ product_id: "", name: "", cost: 0, quantity: 1 }); },
            removeRow(i) { this.items.splice(i, 1); if (!this.items.length) this.addRow(); },
            onProduct(i) {
                const p = this.products.find(x => x.id == this.items[i].product_id);
                if (p) { this.items[i].name = p.name; this.items[i].cost = p.cost; }
            },
            get total() { return this.items.reduce((s, it) => s + (parseFloat(it.cost) || 0) * (parseInt(it.quantity) || 0), 0); },
            fmt(v) { return v.toLocaleString("ar", { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + " {{ __('ر.ع') }}"; },
        }'>
        @csrf
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-4">{{ __('الأصناف') }}</h3>
                    <div class="space-y-3">
                        <template x-for="(item, i) in items" :key="i">
                            <div class="grid grid-cols-12 gap-2 items-end">
                                <div class="col-span-12 sm:col-span-5">
                                    <label class="block text-xs text-gray-500 mb-1" x-show="i===0">{{ __('المنتج') }}</label>
                                    <select :name="`items[${i}][product_id]`" x-model="item.product_id" @change="onProduct(i)" class="w-full rounded-lg border-gray-200 text-sm">
                                        <option value="">{{ __('— صنف يدوي —') }}</option>
                                        <template x-for="p in products" :key="p.id">
                                            <option :value="p.id" x-text="p.name"></option>
                                        </template>
                                    </select>
                                    <input type="text" :name="`items[${i}][name]`" x-model="item.name" placeholder="{{ __('اسم الصنف') }}" class="mt-1 w-full rounded-lg border-gray-200 text-sm" required />
                                </div>
                                <div class="col-span-5 sm:col-span-3">
                                    <label class="block text-xs text-gray-500 mb-1" x-show="i===0">{{ __('تكلفة الوحدة') }}</label>
                                    <input type="text" inputmode="decimal" data-money min="0" :name="`items[${i}][cost]`" x-model="item.cost" class="w-full rounded-lg border-gray-200 text-sm" required />
                                </div>
                                <div class="col-span-4 sm:col-span-2">
                                    <label class="block text-xs text-gray-500 mb-1" x-show="i===0">{{ __('الكمية') }}</label>
                                    <input type="number" min="1" :name="`items[${i}][quantity]`" x-model="item.quantity" class="w-full rounded-lg border-gray-200 text-sm" required />
                                </div>
                                <div class="col-span-3 sm:col-span-2 flex items-center gap-1">
                                    <span class="text-xs text-gray-500 flex-1" x-text="fmt((parseFloat(item.cost)||0)*(parseInt(item.quantity)||0))"></span>
                                    <button type="button" @click="removeRow(i)" class="p-1.5 rounded-full text-danger-600 hover:bg-danger-50"><x-icon name="trash-2" class="w-4 h-4" /></button>
                                </div>
                            </div>
                        </template>
                    </div>
                    <button type="button" @click="addRow()" class="mt-4 inline-flex items-center gap-1.5 text-sm text-primary-600 hover:text-primary-700 font-medium"><x-icon name="plus" class="w-4 h-4" /> {{ __('إضافة صنف') }}</button>
                </div>
            </div>

            <div class="space-y-4">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-4">{{ __('تفاصيل الأمر') }}</h3>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('الفرع') }} <span class="text-danger-500">*</span></label>
                    <select name="branch_id" required class="w-full rounded-lg border-gray-200 text-sm mb-4">
                        <option value="">{{ __('اختر الفرع...') }}</option>
                        @foreach (\App\Support\Demo::branches() as $b)
                            <option value="{{ $b['id'] }}" @selected(\App\Support\Demo::currentBranchId() == $b['id'])>{{ $b['name'] }}</option>
                        @endforeach
                    </select>
                    @error('branch_id')<p class="-mt-3 mb-3 text-xs text-danger-500">{{ $message }}</p>@enderror
                    <label class="block text-sm text-gray-600 mb-1">{{ __('المورّد') }}</label>
                    <select name="supplier_id" class="w-full rounded-lg border-gray-200 text-sm mb-4">
                        <option value="">{{ __('— بدون مورّد —') }}</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s['id'] }}">{{ $s['name'] }}</option>
                        @endforeach
                    </select>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('ملاحظات') }}</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-200 text-sm"></textarea>

                    <label class="block text-sm text-gray-600 mt-4 mb-1">{{ __('إيصال الدفع') }}</label>
                    <label class="flex flex-col items-center justify-center gap-1.5 border-2 border-dashed border-gray-200 rounded-xl p-4 text-center cursor-pointer hover:border-gray-300 transition"
                           x-data="{ fileName: '' }">
                        <x-icon name="upload-cloud" class="w-6 h-6 text-gray-400" />
                        <span class="text-xs text-gray-500" x-text="fileName || @js(__('اختر ملف الإيصال'))"></span>
                        <span class="text-[11px] text-gray-400">{{ __('JPG · PNG · PDF · WEBP · HEIC — حتى 10 ميجابايت') }}</span>
                        <input type="file" name="receipt" class="hidden"
                               accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"
                               @change="fileName = $event.target.files[0]?.name || ''" />
                    </label>
                    @error('receipt')<p class="mt-1.5 text-xs text-danger-500">{{ $message }}</p>@enderror

                    <div class="mt-4 pt-4 border-t border-gray-100 flex items-center justify-between">
                        <span class="text-gray-500">{{ __('الإجمالي') }}</span>
                        <span class="text-xl font-bold text-primary-600" x-text="fmt(total)"></span>
                    </div>
                    <button type="submit" class="mt-4 w-full bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-full py-3">{{ __('إنشاء أمر الشراء') }}</button>
                    <a href="{{ route('admin.purchases.index') }}" class="mt-2 block text-center text-sm text-gray-500 hover:text-gray-700">{{ __('إلغاء') }}</a>
                </div>
            </div>
        </div>
    </form>

</x-layouts::admin>
