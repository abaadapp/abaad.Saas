<?php

namespace App\Http\Controllers;

use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function edit()
    {
        $user = auth()->user();

        return view('profile.edit', [
            'user' => $user,
            'layout' => $user->isSuperAdmin() ? 'layouts::super-admin' : 'layouts::admin',
        ]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', 'min:6'],
        ], [
            'current_password.current_password' => __('كلمة المرور الحالية غير صحيحة.'),
        ]);

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->phone = $data['phone'] ?? $user->phone;

        if ($request->hasFile('avatar')) {
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }
        if (! empty($data['password'])) {
            $user->password = $data['password']; // cast hashed
        }
        $user->save();

        Activity::log('updated', 'حدّث ملفه الشخصي', ['subject_id' => $user->id]);

        return back()->with('toast', ['msg' => __('تم تحديث الملف الشخصي بنجاح'), 'type' => 'success']);
    }
}
