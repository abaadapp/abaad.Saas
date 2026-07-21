<x-layouts::auth :title="__('تسجيل الدخول')">
    <div class="w-full max-w-md">
        {{-- الشعار --}}
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-primary-600 text-white shadow-lg shadow-primary-600/30 mb-4">
                <x-icon name="flower" class="w-9 h-9" />
            </div>
            <h1 class="text-2xl font-bold text-gray-800">Abad POS</h1>
            <p class="text-sm text-gray-500 mt-1">{{ __('نظام نقاط البيع متعدد المتاجر') }}</p>
        </div>

        {{-- البطاقة --}}
        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-6 sm:p-8">
            <h2 class="text-lg font-bold text-gray-800 mb-1">{{ __('مرحبًا بعودتك') }} 👋</h2>
            <p class="text-sm text-gray-500 mb-6">{{ __('سجّل الدخول للمتابعة إلى لوحة التحكم') }}</p>

            @if ($errors->any())
                <div class="mb-4 flex items-start gap-2 rounded-xl bg-danger-50 border border-danger-500/30 p-3 text-sm text-danger-600">
                    <x-icon name="circle-x" class="w-5 h-5 shrink-0 mt-0.5" />
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form action="{{ route('login.attempt') }}" method="POST" class="space-y-4"
                  x-data="{ loading: false }" @submit="loading = true">
                @csrf
                <x-input :label="__('البريد الإلكتروني')" name="email" type="email" icon="mail" placeholder="you@example.com" value="{{ old('email', 'admin@abadpos.com') }}" required />

                <div x-data="{ show: false }">
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('كلمة المرور') }} <span class="text-danger-500">*</span></label>
                    <div class="relative">
                        <span class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 pointer-events-none">
                            <x-icon name="lock" class="w-5 h-5" />
                        </span>
                        <input :type="show ? 'text' : 'password'" name="password" id="password" value="password"
                               class="w-full rounded-xl border border-gray-300 bg-white py-2.5 pr-10 pl-10 text-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition" />
                        <button type="button" @click="show = !show" class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400 hover:text-gray-600">
                            <x-icon x-show="!show" name="eye" class="w-5 h-5" />
                            <x-icon x-show="show" name="eye-off" class="w-5 h-5" x-cloak />
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                        <input type="checkbox" name="remember" value="1" class="rounded border-gray-300 text-primary-600 focus:ring-primary-200" checked>
                        {{ __('تذكرني') }}
                    </label>
                    <a href="mailto:support@abadpos.com?subject={{ rawurlencode(__('إعادة تعيين كلمة المرور')) }}" class="text-sm text-primary-600 hover:text-primary-700">{{ __('نسيت كلمة المرور؟') }}</a>
                </div>

                <x-button type="submit" variant="primary" class="w-full" x-bind:disabled="loading">
                    <span x-show="!loading">{{ __('تسجيل الدخول') }}</span>
                    <span x-show="loading" x-cloak class="flex items-center gap-2">
                        <x-icon name="loader-circle" class="w-4 h-4 animate-spin" /> {{ __('جارٍ الدخول…') }}
                    </span>
                </x-button>
            </form>

            {{-- دخول تجريبي --}}
            <div class="mt-6">
                <div class="relative flex items-center">
                    <div class="flex-grow border-t border-gray-100"></div>
                    <span class="mx-3 text-xs text-gray-400">{{ __('دخول تجريبي سريع') }}</span>
                    <div class="flex-grow border-t border-gray-100"></div>
                </div>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('demo.login', 'super-admin') }}" class="flex items-center gap-3 w-full p-3 rounded-xl border border-gray-200 hover:border-primary-300 hover:bg-primary-50/50 transition group">
                        <span class="w-10 h-10 rounded-xl bg-primary-50 text-primary-600 flex items-center justify-center"><x-icon name="shield-check" class="w-5 h-5" /></span>
                        <div class="text-right flex-1">
                            <p class="text-sm font-semibold text-gray-800">{{ __('مدير المنصة') }}</p>
                            <p class="text-xs text-gray-400">{{ __('Super Admin — إدارة منصة SaaS') }}</p>
                        </div>
                        <x-icon name="chevron-left" class="w-5 h-5 text-gray-300 group-hover:text-primary-500" />
                    </a>
                    <a href="{{ route('demo.login', 'admin') }}" class="flex items-center gap-3 w-full p-3 rounded-xl border border-gray-200 hover:border-secondary-300 hover:bg-secondary-50/50 transition group">
                        <span class="w-10 h-10 rounded-xl bg-secondary-50 text-secondary-600 flex items-center justify-center"><x-icon name="store" class="w-5 h-5" /></span>
                        <div class="text-right flex-1">
                            <p class="text-sm font-semibold text-gray-800">{{ __('صاحب النشاط') }}</p>
                            <p class="text-xs text-gray-400">{{ __('Admin — إدارة المتجر') }}</p>
                        </div>
                        <x-icon name="chevron-left" class="w-5 h-5 text-gray-300 group-hover:text-secondary-500" />
                    </a>
                    <a href="{{ route('demo.login', 'pos') }}" class="flex items-center gap-3 w-full p-3 rounded-xl border border-gray-200 hover:border-success-300 hover:bg-success-50/50 transition group">
                        <span class="w-10 h-10 rounded-xl bg-success-50 text-success-600 flex items-center justify-center"><x-icon name="scan-barcode" class="w-5 h-5" /></span>
                        <div class="text-right flex-1">
                            <p class="text-sm font-semibold text-gray-800">{{ __('الموظف / الكاشير') }}</p>
                            <p class="text-xs text-gray-400">{{ __('Employee POS — نقطة البيع') }}</p>
                        </div>
                        <x-icon name="chevron-left" class="w-5 h-5 text-gray-300 group-hover:text-success-500" />
                    </a>
                </div>
            </div>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6">© 2026 Abad POS — {{ __('جميع الحقوق محفوظة') }}</p>
    </div>
</x-layouts::auth>
