<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * صور الموقع — رفعٌ واحد يخدم كلّ حقل صورة.
 *
 * وحقلُ الصورة في مكتبة الأقسام لا يعرف من أين تأتي الصورة: يحمل رابطًا.
 * فرفعُها هنا يردّ رابطًا، ولصقُ رابطٍ من الخارج يعمل كما هو — والحقل واحد
 * في الحالين.
 *
 * والملفّ في مجلّد النشاط لا في مجلّدٍ عامّ: `website/{id}` — فنسخُ متجرٍ
 * احتياطيًّا أو حذفُه يعرف ما يخصّه.
 */
class MediaController extends Controller
{
    use Concerns;

    public function upload(Request $request)
    {
        $this->siteOrFail();

        $request->validate([
            'image' => ['required', 'image', 'max:4096'],
        ], [
            'image.image' => __('الملفّ صورة — PNG أو JPG أو WEBP'),
            'image.max' => __('أقصى حجمٍ للصورة ٤ ميغابايت'),
        ]);

        $path = $request->file('image')->store('website/'.$this->bid(), 'public');

        return back()->with('uploaded', Storage::url($path));
    }
}
