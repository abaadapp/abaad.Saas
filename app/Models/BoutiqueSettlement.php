<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تسويةُ شهرٍ لبوتيك — ما بِيع له، وما أخذه المحلُّ، وما بقي له.
 *
 * وهي مستندٌ مجمَّد: أرقامُها تُحسب مرّةً وتُحفظ، ولا تُعاد قراءتُها من
 * البيعات بعد الإصدار. فبيعةٌ تُلغى في الشهر التالي — أو صنفٌ يُنقل إلى
 * بوتيكٍ آخر — لا يُغيّران ورقةً وُقّعت واتُّفق عليها.
 *
 * والمالُ يخرج من بابه: `expense_id` يربطها بمصروفٍ يدخل «المبالغ المستحقة»
 * ويُسدَّد كما يُسدَّد كلُّ مستحقٍّ على المحلّ.
 */
class BoutiqueSettlement extends Model
{
    public const SETTLEMENT = 'settlement';

    /** سندُ استرداد رصيد المتجر على البوتيك — لا بيعَ فيه */
    public const REFUND = 'refund';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'gross' => 'decimal:3',
            'commission' => 'decimal:3',
            'net' => 'decimal:3',
            'components_due' => 'decimal:3',
            'components_credit' => 'decimal:3',
            'components_net' => 'decimal:3',
            'components_paid_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** أسُدِّد ما عليه؟ — يُقرأ من المصروف لا من عمودٍ ثانٍ يُنسى */
    /**
     * سُدِّدت الورقةُ بجزأيها: مصروفُ المنفرد (إن كان لها) وجزءُ المكوّنات (إن كان لها).
     *
     * ورقةٌ للمنفرد وحده كما كانت: سدادُها سدادُ مصروفها.
     */
    public function paid(): bool
    {
        // و`lines_count` بنودُ المنفرد وحدها — ورقةٌ بلا منفردٍ لا مصروفَ لها يُنتظر
        $standalone = (int) $this->lines_count === 0
            || ($this->expense !== null && $this->expense->status === Expense::PAID);

        $components = (float) $this->components_due <= 0 || $this->components_paid_at !== null;

        return $standalone && $components;
    }
}
