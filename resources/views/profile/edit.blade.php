<x-dynamic-component :component="$layout" title="الملف الشخصي">

    <x-page-header title="الملف الشخصي" subtitle="إدارة بياناتك الشخصية وكلمة المرور" />

    <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data"
          x-data="{ avatarUrl: '{{ $user->avatar }}' }" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @csrf
        @method('PUT')

        {{-- البطاقة الجانبية: الصورة --}}
        <div class="space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 text-center">
                <div class="relative w-28 h-28 mx-auto">
                    <img :src="avatarUrl" alt="{{ $user->name }}" class="w-28 h-28 rounded-2xl object-cover border border-gray-100" />
                    <label class="absolute -bottom-2 -left-2 w-9 h-9 rounded-xl bg-primary-600 text-white flex items-center justify-center cursor-pointer shadow hover:bg-primary-700 transition">
                        <x-icon name="camera" class="w-4 h-4" />
                        <input type="file" name="avatar" class="hidden" accept="image/*"
                               @change="avatarUrl = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : avatarUrl" />
                    </label>
                </div>
                <h3 class="mt-4 font-bold text-gray-800">{{ $user->name }}</h3>
                <p class="text-sm text-gray-400">{{ $user->roleLabel() }}</p>
                @error('avatar')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- البيانات --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">البيانات الشخصية</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input label="الاسم" name="name" :value="old('name', $user->name)" :required="true" />
                    <x-input label="رقم الهاتف" name="phone" icon="phone" :value="old('phone', $user->phone)" />
                    <div class="md:col-span-2">
                        <x-input label="البريد الإلكتروني" name="email" type="email" icon="mail" :value="old('email', $user->email)" :required="true" />
                    </div>
                </div>
                @error('email')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
            </div>

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-1">تغيير كلمة المرور</h3>
                <p class="text-xs text-gray-400 mb-4">اتركها فارغة إن لم ترغب بتغييرها.</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <x-input label="كلمة المرور الحالية" name="current_password" type="password" />
                    <x-input label="كلمة المرور الجديدة" name="password" type="password" />
                    <x-input label="تأكيد كلمة المرور" name="password_confirmation" type="password" />
                </div>
                @error('current_password')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
                @error('password')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
            </div>

            <div class="flex justify-end gap-3">
                <x-button variant="outline" type="button" :href="url()->previous()">إلغاء</x-button>
                <x-button variant="primary" type="submit" icon="check">حفظ التغييرات</x-button>
            </div>
        </div>
    </form>

</x-dynamic-component>
