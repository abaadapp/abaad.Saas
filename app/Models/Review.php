<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تقييم عميل.
 *
 * يبدأ معلّقًا ولا يُنشر إلا بقرار: تقييمٌ يظهر على الموقع لحظة كتابته يجعل
 * صفحة المنتج بابًا مفتوحًا لأي رسالةٍ يكتبها أيّ أحد.
 */
class Review extends Model
{
    /** رأيٌ في الطلب أو المتجر — كلُّ ما كُتب قبل آراء الأصناف، وما يُسجَّل باليد */
    public const TYPE_ORDER = 'order';

    /** رأيٌ في بندٍ اشتُري فعلًا — ومنه صنفُه (انظر `ReviewInvite::item`) */
    public const TYPE_PRODUCT = 'product';

    /** ما يُنشر على الموقع — والمعلّقُ والمرفوضُ محجوبان */
    public const PUBLISHED = 'منشور';

    protected $guarded = [];

    protected $casts = ['rating' => 'integer', 'replied_at' => 'datetime'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }

    /**
     * آراءُ صنفٍ كما تُقرأ على صفحته — المنشورةُ من نوع «product» وحدها.
     *
     * ورأيُ الطلب لا يدخلها ولو حمل `product_id` (رأيٌ سُجّل باليد): لا يُعرف
     * أنّ كاتبَه اشترى هذا الصنف. والمتجرُ شرطٌ مع الصنف: رقمُ صنفٍ لا يكفي.
     */
    public function scopeForProductPage(Builder $query, int $businessId, int $productId): Builder
    {
        return $query->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->where('type', self::TYPE_PRODUCT)
            ->where('status', self::PUBLISHED);
    }

    /**
     * شراءٌ موثَّق؟ — يُقرأ من الصفّ لا يُكتب عمودًا يرسله أحد.
     *
     * رأيُ صنفٍ كُتب عن بندٍ في طلبٍ بعينه — ولا طريقَ إلى كتابته إلّا من
     * رمز دعوة ذلك الطلب.
     */
    public function verifiedPurchase(): bool
    {
        return $this->type === self::TYPE_PRODUCT
            && $this->order_id !== null
            && $this->order_item_id !== null;
    }

    /**
     * ما يصلح لعرضه على الموقع — والتعريفُ هنا وحدَه.
     *
     * ═══ ولمَ صار واحدًا ═══
     *
     * ثلاثةُ مواضعَ كانت تسأل السؤالَ نفسه بجوابين: بانِي المواقع يعدّ
     * **كلّ منشور**، وشرطُ عرضِ القسم يقرأ **كلّ منشور**، والرسمُ يعرض
     * المنشورَ **الذي له تعليق**.
     *
     * فمتجرٌ نشر خمسةَ تقييماتٍ بنجومٍ بلا كلام يرى «٥ تقييمات» ويُعرض
     * عليه قسمُ الآراء، ثمّ تخرج صفحتُه وفيه **لا شيء**. ولا يعرف لماذا:
     * العدّادُ يقول خمسة.
     *
     * والنجومُ بلا كلامٍ ليست شهادةً تُعرض — هي رقمٌ في المعدّل. فالشرطُ
     * هنا، ومنه تقرأ المواضعُ الثلاثة.
     */
    public function scopeShowable(Builder $query): Builder
    {
        return $query->where('status', 'منشور')
            ->whereNotNull('comment')
            ->where('comment', '!=', '');
    }

    /** الاسم المعروض: العميل المسجَّل، أو ما كتبه الزائر، أو مجهول */
    public function displayName(): string
    {
        return $this->customer?->name ?: ($this->author_name ?: __('زائر'));
    }
}
