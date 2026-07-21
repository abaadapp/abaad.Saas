<x-layouts::admin :title="__('التسويق والكوبونات')">

    <x-page-header :title="__('التسويق والكوبونات')" :subtitle="__('أكواد الخصم والعروض لاستهداف العملاء')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التسويق') => '#']">
        <x-slot:actions>
            <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal','add-coupon')">{{ __('كوبون جديد') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @php
        $stats = \App\Support\Demo::couponStats();
        $coupons = \App\Support\Demo::coupons();
        $segment = request('segment', 'all');
        $targets = \App\Support\Demo::marketingSegment($segment);
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat-card :label="__('إجمالي الكوبونات')" value="{{ $stats['total'] }}" icon="ticket" color="primary" />
        <x-stat-card :label="__('كوبونات فعّالة')" value="{{ $stats['active'] }}" icon="badge-check" color="success" />
        <x-stat-card :label="__('مرات الاستخدام')" value="{{ $stats['redemptions'] }}" icon="check-circle" color="info" />
    </div>

    <div>
        {{-- الكوبونات --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-gray-800">{{ __('أكواد الخصم') }}</h3>
                <button type="button" x-data @click="$dispatch('open-modal','add-coupon')" class="text-sm text-primary-600 hover:text-primary-700 font-medium">+ {{ __('إضافة') }}</button>
            </div>
            @if (count($coupons))
                <div class="divide-y divide-gray-50">
                    @foreach ($coupons as $c)
                        <div class="flex items-center justify-between px-5 py-3.5">
                            <div class="flex items-center gap-3">
                                <span class="font-mono font-bold text-primary-700 bg-primary-50 rounded-lg px-2.5 py-1">{{ $c['code'] }}</span>
                                <div>
                                    <p class="text-sm font-semibold text-gray-800">{{ __('خصم :v', ['v' => $c['display']]) }}</p>
                                    <p class="text-xs text-gray-400">
                                        @if ($c['min_order'] > 0){{ __('حد أدنى :v', ['v' => \App\Support\Demo::money($c['min_order'])]) }} · @endif
                                        {{ __('استُخدم :n', ['n' => $c['used_count']]) }}@if ($c['max_uses']) / {{ $c['max_uses'] }}@endif
                                        @if ($c['expires']) · {{ __('ينتهي :d', ['d' => $c['expires']]) }}@endif
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($c['expired'])
                                    <x-badge type="danger" :text="__('منتهٍ')" />
                                @else
                                    <x-badge :type="$c['active'] ? 'success' : 'gray'" :text="$c['active'] ? __('فعّال') : __('موقوف')" />
                                @endif
                                <form method="POST" action="{{ route('admin.coupons.toggle', $c['id']) }}">
                                    @csrf
                                    <button type="submit" class="p-1.5 rounded-full text-gray-400 hover:bg-gray-100" title="{{ $c['active'] ? __('إيقاف') : __('تفعيل') }}"><x-icon :name="$c['active'] ? 'toggle-right' : 'toggle-left'" class="w-5 h-5" /></button>
                                </form>
                                <form method="POST" action="{{ route('admin.coupons.destroy', $c['id']) }}" onsubmit="return confirm(@js(__('حذف الكوبون؟')))">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="p-1.5 rounded-full text-danger-600 hover:bg-danger-50"><x-icon name="trash-2" class="w-4 h-4" /></button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="p-8 text-center text-gray-400 text-sm">{{ __('لا توجد كوبونات بعد. أنشئ أول كود خصم.') }}</div>
            @endif
        </div>

    </div>

    {{-- نافذة إنشاء كوبون --}}
    <x-modal name="add-coupon" :title="__('إنشاء كوبون خصم')">
        <form id="add-coupon-form" method="POST" action="{{ route('admin.coupons.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('الكود') }} <span class="text-danger-500">*</span></label>
                    <input type="text" name="code" required placeholder="SUMMER25" class="w-full rounded-lg border-gray-200 uppercase focus:border-primary-400 focus:ring-primary-200" />
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('النوع') }}</label>
                    <select name="type" class="w-full rounded-lg border-gray-200"><option value="نسبة">{{ __('نسبة %') }}</option><option value="مبلغ">{{ __('مبلغ ثابت') }}</option></select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('القيمة') }} <span class="text-danger-500">*</span></label>
                    <input type="number" step="0.001" min="0" name="value" required class="w-full rounded-lg border-gray-200" />
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('حد أدنى للطلب') }}</label>
                    <input type="number" step="0.001" min="0" name="min_order" value="0" class="w-full rounded-lg border-gray-200" />
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('أقصى عدد استخدامات') }}</label>
                    <input type="number" min="1" name="max_uses" placeholder="{{ __('بلا حدّ') }}" class="w-full rounded-lg border-gray-200" />
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('تاريخ الانتهاء') }}</label>
                    <input type="date" name="expires_at" class="w-full rounded-lg border-gray-200" />
                </div>
            </div>
        </form>
        <x-slot:footer>
            <x-button variant="light" @click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <button type="submit" form="add-coupon-form" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-full px-4 py-2">{{ __('إنشاء') }}</button>
        </x-slot:footer>
    </x-modal>

</x-layouts::admin>
