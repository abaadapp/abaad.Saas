<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    /** تبديل الفرع الحالي (يُحفظ في الجلسة) */
    public function switch(Request $request, $id)
    {
        if ($id === 'all') {
            $request->session()->forget('current_branch');
        } else {
            $request->session()->put('current_branch', (int) $id);
        }

        return back();
    }
}
