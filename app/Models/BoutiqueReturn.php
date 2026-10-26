<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * إشعارُ مرتجعٍ لقطعة بوتيكٍ حملتها ورقةُ تسوية — والورقةُ كما صدرت.
 *
 * ثلاثةُ أحوالٍ لا تختلط: ردُّ الزبون يقع بالإلغاء أو بالتصحيح (`order_edit_id`)،
 * والقطعةُ حالُها `condition`، وحصّةُ البوتيك حالُها `status`. ولا يُعدّ
 * أيٌّ منها تمّ حتّى يقع ويُكتب — انظر `BoutiqueReturns`.
 */
class BoutiqueReturn extends Model
{
    public const CANCEL = 'cancel';

    public const QUANTITY = 'quantity';

    /** لم تُستلم القطعةُ بعد — خارجَ الرفّ حتّى تُستلم */
    public const PENDING = 'pending';

    public const SELLABLE = 'sellable';

    public const DAMAGED = 'damaged';

    /** حصّةُ البوتيك تنتظر استلامَ القطعة */
    public const AWAITING = 'awaiting';

    /** تنتظر قرارًا محاسبيًّا — لا شرطَ موثَّق يحسمها */
    public const REVIEW = 'review';

    /** يتحمّلها المتجر — والورقةُ كما هي */
    public const SHOP = 'shop';

    /** خُفّض بها مستحقُّ ورقةٍ لم تُسدَّد */
    public const REDUCED = 'reduced';

    /** سُدّدت الورقة: مطالبةُ استردادٍ مفتوحة على البوتيك */
    public const CLAIM = 'claim';

    public const RECOVERED = 'recovered';

    /** ما دخل أثرُه الدفتر أو سيدخله يومَ سداد ورقته */
    public const BORNE = [self::REDUCED, self::CLAIM, self::RECOVERED];

    /** ما ينتظر أحدًا: القطعةَ، أو المحاسبَ، أو البوتيكَ */
    public const OPEN = [self::AWAITING, self::REVIEW, self::CLAIM];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'unit_price' => 'decimal:3',
            'gross' => 'decimal:3',
            'amount' => 'decimal:3',
            'sold_at' => 'datetime',
            'returned_at' => 'datetime',
            'condition_at' => 'datetime',
            'decided_at' => 'datetime',
            'booked_at' => 'date',
        ];
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(BoutiqueSettlement::class, 'boutique_settlement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(BoutiqueReturnRecovery::class);
    }
}
