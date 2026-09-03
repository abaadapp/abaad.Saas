<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سندُ نقلٍ بين فرعين — **سِجلٌّ لا باب**.
 *
 * حُذفت شاشة «النقل بين الفروع» بطلب صاحب النظام: لا مسارَ يُنشئ سندًا ولا
 * شاشةَ تعرضه ولا ورقةَ تُطبع منه. والنموذجُ وجدولُه باقيان لأنّ ما كُتب
 * فيهما وقائعُ جرت: حركاتُ مخزونٍ تحمل أرقام هذه السندات في `reference`،
 * وحذفُ الصفوف يترك تلك الحركات بلا مصدرٍ يُقرأ.
 *
 * فلا يُقرأ منه شيءٌ في شاشة، ولا يُكتب فيه صفٌّ جديد.
 *
 * والكميّة موجبةٌ دائمًا: الاتجاه في «من» و«إلى» لا في إشارة الرقم. وحقلان
 * يقولان الاتجاه يفترقان يومًا، فيُقرأ صرفٌ إضافةً ويتضاعف الرصيد.
 */
class StockTransfer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'transferred_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function nextNumber(int $businessId): string
    {
        $last = static::where('business_id', $businessId)->orderByDesc('id')->value('number');
        $n = $last ? ((int) substr($last, 4)) + 1 : 1;

        return 'TRF-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
