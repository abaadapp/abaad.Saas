<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * جهاز نقطة بيع مفعَّل — يعرف متجره وفرعه.
 *
 * هو المصدر الموثوق للفرع داخل نقطة البيع. لا يُقرأ الفرع من الواجهة ولا من
 * جلسة المتصفّح: الجلسة يبدّلها المدير من تبويبٍ آخر، والواجهة يكتبها من شاء.
 */
class PosDevice extends Model
{
    protected $guarded = [];

    public const ACTIVE = 'نشط';

    public const REVOKED = 'ملغى';

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * الحساب البنكيّ الذي يودع فيه جهازُ الشبكة الموصول بهذا الصندوق.
     *
     * فارغٌ يعني الرئيسيّ — انظر `Bank::depositFor`. والعمود `nullOnDelete`:
     * حذفُ الحساب البنكيّ لا يعطّل الصندوق، يُعيده إلى الرئيسيّ.
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * ما باعه هذا الصندوق وما فُتح عليه من ورديات.
     *
     * تُقرأ في موضعين: الحارسُ الذي يمنع حذفَ صندوقٍ باع، والشاشةُ التي
     * تُخفي زرَّ الحذف عنه. وسؤالٌ واحدٌ له قارئٌ واحد — فلا يُعرض زرٌّ
     * يردّه الخادم، ولا يُخفى زرٌّ كان الخادمُ ليقبله.
     *
     * والعمودان `nullOnDelete`: حذفُ الصفّ لا يمحو الفاتورة، يمحو نسبتَها
     * إليه — وهو ما يحرسه المانع.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /** الملحقات: طابعة، ماسح، درج… — تُحذف مع الجهاز (قيد أجنبي) */
    public function peripherals(): HasMany
    {
        return $this->hasMany(PosPeripheral::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
