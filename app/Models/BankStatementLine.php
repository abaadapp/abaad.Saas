<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطرٌ من كشف البنك المستورد — ومطابقتُه بمعاملةٍ في النظام.
 *
 * وحالتُه تُكتب هنا لا في كلّ شاشةٍ تسألها: كانت الكلمةُ العربية مكتوبةً
 * بيدها في خمسة مواضع، وسادسٌ — تقريرُ البنك — كتب `'matched'` بالإنجليزية.
 * فما طابق **قطُّ** شيئًا: يقرأ التاجرُ كشفَه مطابَقًا في شاشة المالية،
 * ويقرأ في التقرير «مطابق: ٠ · غير مطابق: أربعون». تقريرٌ يقول كذبًا.
 */
class BankStatementLine extends Model
{
    /** طُوبق بمعاملةٍ في النظام */
    public const MATCHED = 'مطابق';

    /** لم يُطابَق بعد — وهو ما يُكتب عند الاستيراد */
    public const UNMATCHED = 'غير مطابق';

    protected $guarded = [];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:3'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
}
