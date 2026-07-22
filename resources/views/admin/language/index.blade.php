<x-layouts::admin :title="__('اللغة')">
    <x-page-header
        :title="__('لغة النظام')"
        :subtitle="__('تُطبَّق على واجهة لوحة التحكم ويتغيّر معها اتجاه الصفحة')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الإعدادات') => route('admin.settings.index'), __('اللغة') => '#']"
    />

    <div class="max-w-2xl">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            @php $currentLocale = app()->getLocale(); @endphp
            <form method="POST" action="{{ route('admin.language.update') }}" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {{-- اسم اللغة يبقى بلغته، أمّا وصف الاتجاه فيتبع لغة الواجهة --}}
                    @foreach ([['ar', 'العربية', __('من اليمين إلى اليسار (RTL)')], ['en', 'English', __('من اليسار إلى اليمين (LTR)')]] as [$code, $label, $hint])
                        <label class="flex items-center justify-between rounded-xl border px-4 py-3.5 cursor-pointer transition
                                      {{ $currentLocale === $code ? 'border-gray-900 bg-gray-50' : 'border-gray-200 hover:bg-gray-50' }}">
                            <span class="flex items-center gap-3">
                                <span class="w-10 h-10 rounded-full bg-gray-100 text-gray-700 flex items-center justify-center font-bold text-xs">
                                    {{ strtoupper($code) }}
                                </span>
                                <span>
                                    <span class="block text-sm font-medium text-gray-800">{{ $label }}</span>
                                    <span class="block text-xs text-gray-400">{{ $hint }}</span>
                                </span>
                            </span>
                            <input type="radio" name="locale" value="{{ $code }}" @checked($currentLocale === $code)
                                   class="w-5 h-5 text-gray-900 focus:ring-gray-300 border-gray-300" />
                        </label>
                    @endforeach
                </div>
                <div class="flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ اللغة') }}</x-button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::admin>
