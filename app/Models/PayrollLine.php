<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سطر موظّفٍ في مسيرة — صافيه يُحسب ولا يُكتب */
class PayrollLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'basic' => 'decimal:3', 'allowances' => 'decimal:3', 'overtime' => 'decimal:3',
        'deductions' => 'decimal:3', 'net' => 'decimal:3',
        'paid' => 'boolean', 'paid_at' => 'datetime',
    ];

    public function run(): BelongsTo { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }

    public function employee(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    /** الصافي = (أساسي + بدلات + إضافي) − خصومات، ولا ينزل تحت الصفر */
    public function computeNet(): float
    {
        $gross = (float) $this->basic + (float) $this->allowances + (float) $this->overtime;

        return round(max(0, $gross - (float) $this->deductions), 3);
    }
}
