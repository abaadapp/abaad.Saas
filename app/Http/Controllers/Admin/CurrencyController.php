<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Support\Demo;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** تبديل عملة العرض (تُحفظ في الجلسة) */
    public function switch(Request $request, $code)
    {
        if ($code === 'base') {
            $request->session()->forget('display_currency');
        } else {
            $request->session()->put('display_currency', strtoupper($code));
        }

        return back();
    }

}
