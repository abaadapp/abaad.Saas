<x-layouts::admin title="المواعيد والطلبات المجدولة">

    <x-page-header title="المواعيد والطلبات المجدولة" subtitle="جدولة الطلبات والمواعيد القادمة مع تذكير آلي"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المواعيد' => '#']">
        <x-slot:actions>
            <x-button variant="primary" icon="plus" @click="$dispatch('open-modal','add-appointment')">موعد جديد</x-button>
        </x-slot:actions>
    </x-page-header>

    @php
        $stats = \App\Support\Demo::appointmentStats();
        $appointments = \App\Support\Demo::appointments();
        $statusColors = ['مجدول' => 'info', 'مؤكد' => 'primary', 'مكتمل' => 'success', 'ملغي' => 'danger'];
        // تجميع حسب التاريخ للعرض الزمني
        $grouped = collect($appointments)->groupBy('date');
    @endphp

    {{-- البطاقات الإحصائية --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="إجمالي المواعيد" value="{{ $stats['total'] }}" icon="calendar" color="primary" />
        <x-stat-card label="قادمة" value="{{ $stats['upcoming'] }}" icon="clock" color="info" />
        <x-stat-card label="اليوم" value="{{ $stats['today'] }}" icon="calendar-check" color="warning" />
        <x-stat-card label="مكتملة" value="{{ $stats['done'] }}" icon="check-circle" color="success" />
    </div>

    @if (count($appointments))
        <div class="space-y-6">
            @foreach ($grouped as $date => $items)
                @php $d = \Illuminate\Support\Carbon::parse($date); @endphp
                <div>
                    <div class="flex items-center gap-3 mb-3">
                        <div class="flex flex-col items-center justify-center w-14 h-14 rounded-2xl bg-primary-50 text-primary-700 shrink-0">
                            <span class="text-lg font-bold leading-none">{{ $d->format('d') }}</span>
                            <span class="text-[11px]">{{ $d->translatedFormat('M') }}</span>
                        </div>
                        <div>
                            <p class="font-bold text-gray-800">{{ $d->translatedFormat('l') }}</p>
                            <p class="text-xs text-gray-400">{{ count($items) }} موعد</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        @foreach ($items as $a)
                            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex items-start gap-4 {{ $a['status'] === 'ملغي' ? 'opacity-60' : '' }}">
                                <div class="flex flex-col items-center justify-center px-3 py-2 rounded-xl bg-gray-50 text-gray-700 shrink-0">
                                    <x-icon name="clock" class="w-4 h-4 text-gray-400 mb-1" />
                                    <span class="text-sm font-bold" dir="ltr">{{ $a['time'] }}</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="font-bold text-gray-800 truncate">{{ $a['title'] }}</p>
                                        <x-badge :type="$statusColors[$a['status']] ?? 'info'" :text="$a['status']" />
                                    </div>
                                    @if ($a['customer'])
                                        <p class="text-sm text-gray-500 mt-1 flex items-center gap-1.5"><x-icon name="user" class="w-3.5 h-3.5" /> {{ $a['customer'] }}
                                            @if ($a['phone'])<span class="text-gray-300">·</span><span dir="ltr">{{ $a['phone'] }}</span>@endif
                                        </p>
                                    @endif
                                    @if ($a['notes'])
                                        <p class="text-xs text-gray-400 mt-1 truncate">{{ $a['notes'] }}</p>
                                    @endif

                                    <div class="flex items-center gap-1.5 mt-3">
                                        @foreach (['مؤكد', 'مكتمل', 'ملغي'] as $st)
                                            @if ($a['status'] !== $st)
                                                <form method="POST" action="{{ route('admin.appointments.status', $a['id']) }}">
                                                    @csrf
                                                    <input type="hidden" name="status" value="{{ $st }}" />
                                                    <button type="submit" class="text-xs px-2.5 py-1 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50">{{ $st }}</button>
                                                </form>
                                            @endif
                                        @endforeach
                                        <form method="POST" action="{{ route('admin.appointments.destroy', $a['id']) }}" onsubmit="return confirm('حذف هذا الموعد؟')" class="mr-auto">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs px-2 py-1 rounded-lg text-danger-600 hover:bg-danger-50"><x-icon name="trash-2" class="w-4 h-4" /></button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <x-empty-state icon="calendar" title="لا توجد مواعيد بعد" message="ابدأ بجدولة أول موعد أو طلب مؤجّل.">
            <x-button variant="primary" icon="plus" @click="$dispatch('open-modal','add-appointment')">موعد جديد</x-button>
        </x-empty-state>
    @endif

    {{-- نافذة إضافة موعد --}}
    <x-modal name="add-appointment" title="جدولة موعد / طلب جديد">
        <form method="POST" action="{{ route('admin.appointments.store') }}" id="appointment-form" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-600 mb-1">العنوان <span class="text-danger-500">*</span></label>
                <input type="text" name="title" required placeholder="مثال: تجهيز باقة ورد لمناسبة" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">اسم العميل</label>
                    <input type="text" name="customer_name" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">الهاتف</label>
                    <input type="text" name="phone" dir="ltr" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">موعد التنفيذ <span class="text-danger-500">*</span></label>
                <input type="datetime-local" name="scheduled_at" required class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">ملاحظات</label>
                <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200"></textarea>
            </div>
        </form>
        <x-slot:footer>
            <x-button variant="light" @click="$dispatch('close-modal')">إلغاء</x-button>
            <button type="submit" form="appointment-form" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg px-4 py-2">جدولة</button>
        </x-slot:footer>
    </x-modal>

</x-layouts::admin>
