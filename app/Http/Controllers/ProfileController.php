<?php

namespace App\Http\Controllers;

use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function edit(): \Inertia\Response
    {
        $user = auth()->user();

        // القشرة تتبع الدور: لكل لوحة قائمتها، فلا تُعرض لمدير المنصة قائمة متجر
        $shell = match (true) {
            $user->isSuperAdmin() => 'platform',
            $user->role === 'cashier' => 'pos',
            default => 'admin',
        };

        return \Inertia\Inertia::render('Profile/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'roleLabel' => $user->roleLabel(),
            ],
            'shell' => $shell,
            'limited' => $user->role === 'cashier',
        ]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();

        // الكاشير: صلاحيات محدودة — الهاتف والصورة فقط (الاسم والبريد وكلمة المرور محجوبة)
        if ($user->role === 'cashier') {
            $data = $request->validate([
                'phone' => ['nullable', 'string', 'max:50'],
                'avatar' => ['nullable', 'image', 'max:2048'],
            ]);

            $user->phone = $data['phone'] ?? $user->phone;
            if ($request->hasFile('avatar')) {
                $user->avatar = $request->file('avatar')->store('avatars', 'public');
            }
            $user->save();

            \App\Support\Activity::log('updated', 'حدّث ملفه الشخصي', ['subject_id' => $user->id]);

            return back()->with('toast', ['msg' => __('تم تحديث الملف الشخصي بنجاح'), 'type' => 'success']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            /*
             * الرسالة تُكتب هنا لا تُترك للافتراضية: المستخدم يرى «ما تغيّر
             * شيء» حين يضع عنوانًا يملكه حسابٌ آخر، لأن النصّ الافتراضي لا
             * يقول أين ذهب العنوان ولا ماذا يفعل.
             */
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($user->id),
                /*
                 * النطاق يُفرض على العنوان الجديد وحده.
                 *
                 * لو فُرض على كل حفظ لصار مدير المنصة القائم — وبريده خارج
                 * النطاق — عاجزًا عن تعديل اسمه أو صورته أو كلمة مروره: يضغط
                 * «حفظ» فيُرفض بسبب حقلٍ لم يلمسه. القاعدة تمنع الانتقال إلى
                 * الخارج، لا تعاقب من كان هناك قبلها.
                 */
                Rule::when(
                    $user->isSuperAdmin() && $request->input('email') !== $user->email,
                    [new \App\Rules\PlatformEmailDomain],
                ),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            /*
             * ═══ وقفلُ هذا الباب قفلُ باب التسجيل نفسُه ═══
             *
             * كانت هنا `min:6` وحدها: لا حرفَ يُشترط ولا رقم. وبابُ التسجيل
             * يشترط ثمانيةً فيها حرفٌ ورقم (`RegisterController`) — فصاحبُ
             * النشاط يسجّل بكلمةٍ قويّة كما فُرض عليه، ثمّ يفتح ملفَّه
             * الشخصيّ ويضعها `123456` بضغطتين. **والحسابُ هو هو.**
             *
             * فالشرطُ الأقوى كان يُلتفّ عليه من داخل النظام لا من خارجه: بابٌ
             * يُقفل وآخرُ إلى الغرفة نفسِها مفتوح.
             *
             * وليست سياسةً جديدة تُخترع هنا — هي سياسةُ المستودع المعلنة
             * تُطبَّق على البابين: ثمانيةٌ، وحرفٌ، ورقم، و٧٢ حدًّا أعلى
             * (bcrypt يقطع ما بعدها، فكلمةٌ أطول تُقبل ثمّ تُبتر صامتة).
             *
             * وما كُتب من كلماتٍ قديمة لا يُمسّ: الشرطُ على ما يُكتب بعده.
             */
            'password' => ['nullable', 'confirmed', 'string', 'min:8', 'max:72',
                'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ], [
            'current_password.current_password' => __('كلمة المرور الحالية غير صحيحة.'),
            'email.unique' => __('هذا البريد مستعمل في حساب آخر — اختر غيره.'),
            // وبنصّ الشاشة نفسِه في التسجيل: شرطٌ يُفرض ولا يُقال يجعله يجرّب ويُردّ
            'password.regex' => __('كلمة المرور تحتاج حرفًا ورقمًا على الأقل.'),
            'password.min' => __('كلمة المرور ثمانية أحرف على الأقل.'),
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
