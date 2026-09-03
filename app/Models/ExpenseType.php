<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نوع مصروف — واسمُه هو ما يربطه بمصروفاته.
 *
 * `expenses.type` نصٌّ منسوخ لا مفتاحٌ أجنبيّ: المصروف يحتفظ باسم نوعه وإن
 * حُذف النوع، فلا يفقد تصنيفَه. والربط بالحساب يُقرأ بالاسم لهذا السبب.
 */
class ExpenseType extends Model
{
    protected $guarded = [];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    /**
     * حسابُ هذا النوع في شجرة الحسابات — اختياريّ.
     *
     * يضبطه من يملك «المحاسبة المتقدّمة» وحده، ولا يراه من يسجّل المصروف:
     * الموظّف يختار «إيجار» والنظام يعرف أنّها 5300. وبلا ربطٍ يقع المصروف
     * في «مصروفات أخرى» كما كان — انظر Books::expenseAccountFor.
     */
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
}
