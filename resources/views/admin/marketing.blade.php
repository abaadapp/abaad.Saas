<x-layouts::admin title="التسويق والكوبونات">

    <x-page-header title="التسويق والكوبونات" subtitle="أكواد الخصم وحملات واتساب لاستهداف العملاء"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'التسويق' => '#']">
        <x-slot:actions>
            <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal','add-coupon')">كوبون جديد</x-button>
        </x-slot:actions>
    </x-page-header>

    @php
        $stats = \App\Support\Demo::couponStats();
        $coupons = \App\Support\Demo::coupons();
        $segment = request('segment', 'all');
        $targets = \App\Support\Demo::marketingSegment($segment);
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat-card label="إجمالي الكوبونات" value="{{ $stats['total'] }}" icon="ticket" color="primary" />
        <x-stat-card label="كوبونات فعّالة" value="{{ $stats['active'] }}" icon="badge-check" color="success" />
        <x-stat-card label="مرات الاستخدام" value="{{ $stats['redemptions'] }}" icon="check-circle" color="info" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- الكوبونات --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-gray-800">أكواد الخصم</h3>
                <button type="button" x-data @click="$dispatch('open-modal','add-coupon')" class="text-sm text-primary-600 hover:text-primary-700 font-medium">+ إضافة</button>
            </div>
            @if (count($coupons))
                <div class="divide-y divide-gray-50">
                    @foreach ($coupons as $c)
                        <div class="flex items-center justify-between px-5 py-3.5">
                            <div class="flex items-center gap-3">
                                <span class="font-mono font-bold text-primary-700 bg-primary-50 rounded-lg px-2.5 py-1">{{ $c['code'] }}</span>
                                <div>
                                    <p class="text-sm font-semibold text-gray-800">خصم {{ $c['display'] }}</p>
                                    <p class="text-xs text-gray-400">
                                        @if ($c['min_order'] > 0)حد أدنى {{ \App\Support\Demo::money($c['min_order']) }} · @endif
                                        استُخدم {{ $c['used_count'] }}@if ($c['max_uses']) / {{ $c['max_uses'] }}@endif
                                        @if ($c['expires']) · ينتهي {{ $c['expires'] }}@endif
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($c['expired'])
                                    <x-badge type="danger" text="منتهٍ" />
                                @else
                                    <x-badge :type="$c['active'] ? 'success' : 'gray'" :text="$c['active'] ? 'فعّال' : 'موقوف'" />
                                @endif
                                <form method="POST" action="{{ route('admin.coupons.toggle', $c['id']) }}">
                                    @csrf
                                    <button type="submit" class="p-1.5 rounded-full text-gray-400 hover:bg-gray-100" title="{{ $c['active'] ? 'إيقاف' : 'تفعيل' }}"><x-icon :name="$c['active'] ? 'toggle-right' : 'toggle-left'" class="w-5 h-5" /></button>
                                </form>
                                <form method="POST" action="{{ route('admin.coupons.destroy', $c['id']) }}" onsubmit="return confirm('حذف الكوبون؟')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="p-1.5 rounded-full text-danger-600 hover:bg-danger-50"><x-icon name="trash-2" class="w-4 h-4" /></button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="p-8 text-center text-gray-400 text-sm">لا توجد كوبونات بعد. أنشئ أول كود خصم.</div>
            @endif
        </div>

        {{-- حملة واتساب --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5"
            x-data="{
                msg: 'مرحبًا {name}، لدينا عروض مميزة في متجرنا! استخدم كود الخصم عند الشراء 🌸',
                waLink(phone, name) {
                    const text = encodeURIComponent(this.msg.replace('{name}', name));
                    return `https://wa.me/${phone}?text=${text}`;
                }
            }">
            <div class="flex items-center gap-2 mb-4">
                <span class="w-9 h-9 rounded-xl bg-success-50 text-success-600 flex items-center justify-center"><x-icon name="message-circle" class="w-5 h-5" /></span>
                <h3 class="font-bold text-gray-800">حملة واتساب</h3>
            </div>

            <div class="flex items-center gap-2 mb-3">
                @foreach (['all' => 'كل العملاء', 'inactive' => 'غير النشطين', 'top' => 'الأكثر إنفاقًا'] as $key => $lbl)
                    <a href="{{ route('admin.marketing.index', ['segment' => $key]) }}"
                       class="px-3 py-1.5 rounded-lg text-xs font-medium border {{ $segment === $key ? 'bg-primary-600 text-white border-primary-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50' }}">{{ $lbl }}</a>
                @endforeach
                <span class="mr-auto text-xs text-gray-400">{{ count($targets) }} عميل</span>
            </div>

            <label class="block text-sm text-gray-600 mb-1">نص الرسالة <span class="text-gray-400">(استخدم {name} لاسم العميل)</span></label>
            <textarea x-model="msg" rows="3" class="w-full rounded-lg border-gray-200 text-sm mb-3"></textarea>

            <div class="max-h-72 overflow-y-auto divide-y divide-gray-50 border border-gray-100 rounded-xl">
                @forelse ($targets as $t)
                    <div class="flex items-center justify-between px-3 py-2.5">
                        <div>
                            <p class="text-sm font-medium text-gray-800">{{ $t['name'] }}</p>
                            <p class="text-xs text-gray-400" dir="ltr">{{ $t['phone'] }} · آخر شراء {{ $t['last_order'] }}</p>
                        </div>
                        <a :href="waLink('{{ $t['wa'] }}', @js($t['name']))" target="_blank" class="inline-flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg bg-success-600 text-white hover:bg-success-700">
                            <x-icon name="send" class="w-3.5 h-3.5" /> إرسال
                        </a>
                    </div>
                @empty
                    <div class="p-6 text-center text-gray-400 text-sm">لا يوجد عملاء بأرقام هواتف في هذه الشريحة.</div>
                @endforelse
            </div>
            <p class="text-xs text-gray-400 mt-2">يفتح واتساب برسالة جاهزة لكل عميل (واتساب ويب/الهاتف).</p>
        </div>
    </div>

    {{-- نافذة إنشاء كوبون --}}
    <x-modal name="add-coupon" title="إنشاء كوبون خصم">
        <form id="add-coupon-form" method="POST" action="{{ route('admin.coupons.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">الكود <span class="text-danger-500">*</span></label>
                    <input type="text" name="code" required placeholder="SUMMER25" class="w-full rounded-lg border-gray-200 uppercase focus:border-primary-400 focus:ring-primary-200" />
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">النوع</label>
                    <select name="type" class="w-full rounded-lg border-gray-200"><option value="نسبة">نسبة %</option><option value="مبلغ">مبلغ ثابت</option></select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">القيمة <span class="text-danger-500">*</span></label>
                    <input type="number" step="0.001" min="0" name="value" required class="w-full rounded-lg border-gray-200" />
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">حد أدنى للطلب</label>
                    <input type="number" step="0.001" min="0" name="min_order" value="0" class="w-full rounded-lg border-gray-200" />
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">أقصى عدد استخدامات</label>
                    <input type="number" min="1" name="max_uses" placeholder="بلا حدّ" class="w-full rounded-lg border-gray-200" />
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">تاريخ الانتهاء</label>
                    <input type="date" name="expires_at" class="w-full rounded-lg border-gray-200" />
                </div>
            </div>
        </form>
        <x-slot:footer>
            <x-button variant="light" @click="$dispatch('close-modal')">إلغاء</x-button>
            <button type="submit" form="add-coupon-form" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-full px-4 py-2">إنشاء</button>
        </x-slot:footer>
    </x-modal>

</x-layouts::admin>
