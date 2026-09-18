<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * حسابٌ بنكيّ للنشاط — وقد يكون له أكثر من واحد.
 *
 * `account_id` ورقتُه في شجرة الحسابات: الرصيد الحقيقي يُقرأ من الدفتر لا من
 * هذا الجدول، فهذا الجدول يحمل بيانات التعريف (البنك، الآيبان، الافتتاحي)
 * والدفترُ يحمل الحركة.
 */
class BankAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'opening_balance' => 'decimal:3',
        'opening_date' => 'date',
        'active' => 'boolean',
        'is_primary' => 'boolean',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    /** ورقة الحساب في الشجرة — منها يُقرأ الرصيد الدفتري */
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }

    public function lines(): HasMany { return $this->hasMany(BankStatementLine::class); }

    /** اسمٌ يُعرض: ما سمّاه به التاجر، وإلا اسم البنك، وإلا آخر أربعة من الآيبان */
    public function displayName(): string
    {
        if ($this->label) {
            return $this->label;
        }

        if ($this->bank_name) {
            return $this->iban ? $this->bank_name.' ••'.substr($this->iban, -4) : $this->bank_name;
        }

        return $this->account_name ?: __('حساب بنكي');
    }

    /**
     * الرصيد الدفتري — من الدفتر وحده.
     *
     * كان الافتتاحيّ يُجمع هنا من خارج الدفتر: فتقول هذه الشاشة ٦٠٥ وتقول
     * الميزانيةُ ١٠٥، ولا حساب يحمل الفرق. فصار الافتتاحيّ يُقيَّد مقابل
     * حقوق الملكية عند حفظه — انظر `Bank::syncOpening` — ويُقرأ الرصيد من
     * مكانٍ واحد. وجمعُه هنا بعد ذلك يحسبه مرّتين.
     */
    public function balance(): float
    {
        return round($this->account?->balance() ?? 0.0, 3);
    }
}
