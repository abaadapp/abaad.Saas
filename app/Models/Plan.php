<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /**
     * رمزُ الباقة الأساسيّة — ثابتٌ لا يغيّره اسمُها.
     *
     * والتسجيلُ الذاتيّ يقرؤه وحده (`Signup::plan`): الاسمُ يُعدَّل من شاشة
     * الباقات، والرمزُ لا يكتبه إلّا الترحيلُ والبذور.
     */
    public const CODE_BASIC = 'basic';

    /**
     * ورمزُ باقة المؤسسات — يكتبه ترحيلُها حين تُعرف بلا لبس.
     *
     * ولا يفتح شيئًا وحده: القدرةُ تُقرأ من `capabilities` لا من الرمز
     * (`PlanFeatures::allowedBy`). يقرؤه الترحيلُ ليعرف أيَّ صفٍّ يمنحه قدراتِ
     * المؤسسات ونصَّها، فلا يُمنح بالاسم ولا بالسعر.
     */
    public const CODE_ENTERPRISE = 'enterprise';

    protected $guarded = [];

    protected $casts = ['features' => 'array', 'capabilities' => 'array', 'is_popular' => 'boolean', 'monthly_price' => 'decimal:3', 'yearly_price' => 'decimal:3'];

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
