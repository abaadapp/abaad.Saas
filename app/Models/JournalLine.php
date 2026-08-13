<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطرٌ في قيد: مدينٌ أو دائن، لا كلاهما ولا واحدَ منهما.
 *
 * سطرٌ بمدينٍ ودائنٍ معًا يُخفي حركةً داخل نفسه فلا تظهر في دفتر الأستاذ،
 * وسطرٌ بصفرين يُطيل القيد بلا معنى ويُوهم من يراجعه أن هناك ما فاته.
 */
class JournalLine extends Model
{
    protected $guarded = [];

    protected $casts = ['debit' => 'decimal:3', 'credit' => 'decimal:3'];

    public function entry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }

    public function account(): BelongsTo { return $this->belongsTo(Account::class); }

    public function isValid(): bool
    {
        $d = (float) $this->debit;
        $c = (float) $this->credit;

        return ($d > 0) !== ($c > 0) && $d >= 0 && $c >= 0;
    }
}
