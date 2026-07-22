<x-layouts::admin :title="__('النسخ الاحتياطي')">
    <x-page-header
        :title="__('النسخ الاحتياطي')"
        :subtitle="__('نزّل نسخة كاملة من بيانات متجرك أو استعِدها عند الحاجة')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الإعدادات') => route('admin.settings.index'), __('النسخ الاحتياطي') => '#']"
    />

    <div class="space-y-6">
        {{-- تنزيل نسخة احتياطية --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('تنزيل نسخة احتياطية') }}</h3>
            <p class="text-sm text-gray-500 mb-5">{{ __('يشمل الملف كامل بيانات متجرك: المنتجات، التصنيفات، العملاء، الطلبات، المصروفات، المعاملات، حركات المخزون، والإعدادات — بصيغة JSON.') }}</p>
            <div class="flex justify-end">
                <x-button variant="primary" size="md" icon="database-backup" :href="route('admin.backup.download')">{{ __('تنزيل النسخة الآن') }}</x-button>
            </div>
        </div>

        {{-- استعادة من نسخة --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('استعادة من نسخة احتياطية') }}</h3>
            <div class="flex items-start gap-2 bg-danger-50 text-danger-700 text-sm rounded-xl p-3 mb-5">
                <x-icon name="alert-triangle" class="w-5 h-5 shrink-0 mt-0.5" />
                <span>{{ __('تحذير: ستحل بيانات النسخة محل بيانات متجرك الحالية بالكامل. لا يمكن التراجع — نوصي بتنزيل نسخة حديثة أولًا.') }}</span>
            </div>
            <form method="POST" action="{{ route('admin.backup.restore') }}" enctype="multipart/form-data"
                  x-data="{ fileName: '' }" @submit="if(!fileName){ $event.preventDefault(); $store.toasts.add(@js(__('اختر ملف النسخة أولًا')),'warning'); }">
                @csrf
                <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-200 rounded-2xl p-6 text-center cursor-pointer hover:border-danger-300 transition">
                    <x-icon name="upload-cloud" class="w-8 h-8 text-gray-400" />
                    <span class="text-sm font-medium text-gray-700" x-text="fileName || @js(__('اختر ملف النسخة الاحتياطية (JSON)'))"></span>
                    <input type="file" name="backup" accept=".json,application/json" class="hidden"
                           @change="fileName = $event.target.files[0]?.name || ''" />
                </label>
                @error('backup')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
                <div class="mt-6 flex justify-end">
                    <x-button variant="danger" size="md" icon="database" type="submit">{{ __('استعادة البيانات') }}</x-button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::admin>
