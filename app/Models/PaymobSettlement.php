<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * تسويةُ Paymob — ما حوّلته إلى بنك التاجر ناقصًا رسومَها. انظر `PaymobSettlements`.
 */
class PaymobSettlement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gross' => 'decimal:3',
        'fee' => 'decimal:3',
        'net' => 'decimal:3',
        'settled_on' => 'date',
    ];

    /**
     * ═══ وقاعدةٌ تسمّي مدفوعاتِ تحويلاتها لا تُكتب فيها تسويةٌ بمبلغٍ واحد ═══
     *
     * #168 يجعل التسويةَ تسمّي مدفوعاتِها (`paymob_settlement_items`)، لأنّ مبلغًا
     * واحدًا لا يفرّق بين مالٍ في التسوية ومالِ بيعةٍ قُيّد في البنك يومَ البيع.
     * فإن رُجع عنه إلى هذا الكود والجدولُ قائم، لم يُسجَّل تحويلٌ هنا حتّى يعود: صفٌّ
     * لا يقول حالَه في قاعدةٍ تسمّي المدفوعات هو بالضبط ما لا يُحسن هذا الإصدارُ
     * كتابتَه. ولا يمسّ شيئًا قبل #168 (لا جدول)، ولا الاستعادةَ (تكتب بلا نموذج).
     *
     * ═══ والإيقافُ المؤقّت يسري هنا أيضًا ═══
     *
     * `PAYMOB_SETTLEMENTS_PAUSED` مفتاحُ #168. ويُقرأ في هذا الإصدار كذلك، فيُضبط
     * قبل نشر #168 ويُوقف التسجيلَ في الإصدار القائم نفسه: لا تُكتب تسويةٌ في
     * نافذة الانتقال بين الإصدارين. ولا يمسّ البيعَ ولا الدفعَ ولا إشعاراتِ Paymob.
     */
    protected static function booted(): void
    {
        static::creating(function (self $settlement) {
            if (config('services.paymob.settlements_paused')) {
                throw new \RuntimeException(__('تسجيل تسويات Paymob موقوف مؤقّتًا في كلّ المتاجر — الدفعُ والمبيعاتُ تمضي، ويُسجَّل التحويل حين يُفتح. لم يُكتب شيء.'));
            }

            if ($settlement->getAttribute('status') === null && \Illuminate\Support\Facades\Schema::hasTable('paymob_settlement_items')) {
                throw new \RuntimeException(__('تسجيل تسويات Paymob متوقّف في هذا الإصدار — القاعدة تسمّي مدفوعات التحويلات، فيُسجَّل التحويل حين يعود الإصدار الذي يسمّيها. لم يُكتب شيء.'));
            }
        });
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymobSettlementItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
