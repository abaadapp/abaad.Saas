<?php

namespace App\Models;

use App\Support\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    protected $casts = [
        'document_snapshot' => 'array',
        'subtotal' => 'decimal:3', 'discount' => 'decimal:3', 'tax' => 'decimal:3',
        'delivery_fee' => 'decimal:3', 'total' => 'decimal:3', 'is_held' => 'boolean',
        'coupon_discount' => 'decimal:3',
        'ordered_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'hide_sender' => 'boolean',
        /*
         * وختمان يُقرآن في الشاشة — وبلا `cast` يعودان نصًّا.
         *
         * `optional('2026-09-11 10:00:00')->toIso8601String()` تُرجع `null`
         * بلا خطأ: `Optional` تستدعي على الكائنات وتصمت على ما سواها. فبقي
         * «طلب التقييم مجددًا» لا يظهر أبدًا، ولا شيء يقول لماذا.
         */
        'review_request_sent_at' => 'datetime',
        'status_notice_at' => 'datetime',
    ];

    /**
     * الحالة التي تعني أن البيعة رُدَّت ولم تعد بيعًا.
     *
     * تبقى هنا وتُشير إلى `OrderStatus`: هذا الثابت مقروءٌ في نطاق `sold`
     * وفي مواضع كثيرة سواه، وحذفُه ليس تنظيمًا بل كسرٌ لِما يعمل. والمصدر
     * واحد — القيمة تُقرأ من هناك لا تُكتب هنا مرّةً ثانية.
     */
    public const CANCELLED = OrderStatus::CANCELLED;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * الفواتيرُ التي تغطّي هذا الطلب — واحدةٌ أو لا شيء.
     *
     * وبها يُسأل: «هل فُوتر هذا الطلب؟» — فتُستبعد بيعةٌ آجلةٌ طُبعت ورقتُها
     * من مجموع «ما لم يُفوتَر»، ولا يُعدّ الدَّين مرّتين.
     */
    public function customerInvoices(): BelongsToMany
    {
        return $this->belongsToMany(CustomerInvoice::class, 'customer_invoice_orders');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * قيود الفاتورة في المالية — دخلُها، وما يُلغى معها.
     *
     * والمالية كلُّها تقرأ `transactions` لا `orders`: فاتورةٌ بلا قيدٍ
     * تظهر تكلفةً بلا إيراد. انظر `finance:repair-order-income`.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * ما بيع فعلًا: لا سلّةً معلّقة ولا طلبًا ملغى.
     *
     * كان الشرط يُكتب بيدٍ في كل استعلام على حدة، فكُتب في ثلاثة مواضع
     * ونُسي في أحدٍ وثلاثين: بطاقات التقارير تجمع الملغى والمخطّط تحتها
     * يستثنيه، فتقرأ الشاشةُ الواحدة رقمين متناقضين عن الفترة نفسها.
     * وأخطرها الإقرار الضريبي — ضريبةٌ تُقرّ على بيعةٍ أُلغيت.
     *
     * فصار موضعًا واحدًا يقرأ منه الجميع: من نسي «الملغى» لا يستطيع أن
     * ينساه، لأن النطاق يحمله معه.
     */
    public function scopeSold($query)
    {
        return $query->where('is_held', false)->where('status', '!=', self::CANCELLED);
    }

    /**
     * أفات موعدُ هذا الطلب وهو حيّ؟
     *
     * والقاعدة هي قاعدةُ مُرشِّح «متأخّر» في `ListFilters::orders` نفسِها:
     * موعدٌ مضى، وحالةٌ لم تُغلق. وهما موضعان يقولان الشيء نفسه — أحدهما
     * يرشّح صفوفًا والآخر يسم الصفَّ المعروض — فيُحرَس اتّفاقُهما باختبارٍ
     * يقارن المجموعتين، لا بالثقة. (انظر `TheSalesListShowsWhatItFiltersBy`)
     */
    public function isLate(): bool
    {
        return $this->scheduled_for !== null
            && ! in_array($this->status, OrderStatus::CLOSED, true)
            && $this->scheduled_for->isPast();
    }

    /**
     * ما ينتظر التجهيز: طلبٌ حيٌّ فيه ما يُصنَع.
     *
     * المغلق يخرج (سُلّم أو استُلم أو اكتمل أو أُلغي)، والمعلَّق يخرج لأنّه
     * سلّةٌ لم تُبَع بعد. وتخرج بيعةُ المنضدة: دُفعت وأُخذت في اللحظة نفسها،
     * ولا شيء فيها يُجهَّز. ولولا ذلك لَامتلأت اللوحة بمئات الفواتير.
     *
     * ═══ والمقياسُ نوعُ التنفيذ لا الموعد ═══
     *
     * كان الشرطُ `scheduled_for IS NOT NULL` — يُقصد به بيعةُ المنضدة،
     * فأصاب معها طلبَ الموقع. لأنّ حقلَ الموعد يملكه صاحبُ المحلّ
     * (`CheckoutFields`): متجرٌ يبيع ما هو جاهزٌ الآن يُطفئه، فيصل طلبُ
     * الزبون كاملًا — له مستلِمٌ وهاتفٌ وعنوانٌ ونوعُ تنفيذ — ولا يراه أحد.
     * يدفع وينتظر، ولا أحد يصنع طلبَه.
     *
     * وبيعةُ المنضدة لا نوعَ تنفيذٍ لها أصلًا، وهو ما كان يُقصد. وطلبُ
     * الصندوق لا يُكتب له نوعُ تنفيذٍ بلا موعد: `FlowerOrder::afterValidation`
     * تردّه في بابَي الإنشاء والتعديل معًا — فالصندوقُ لا يتبدّل عليه شيء.
     *
     * والموعدُ يبقى موعدًا: يصنّف نوافذَ اللوحة ويرتّب بطاقاتها، ولا يقرّر
     * وجودَ الطلب فيها. (انظر `AWebsiteOrderReachesTheBenchWithoutAnHour`)
     */
    public function scopeAwaitingPreparation($query)
    {
        return $query->where('is_held', false)
            ->where(fn ($w) => $w->whereNotNull('scheduled_for')->orWhereNotNull('fulfillment_type'))
            ->whereNotIn('status', OrderStatus::CLOSED);
    }

    /**
     * ما فات موعده ولم يُغلق — يتصدّر لوحة التجهيز.
     *
     * يُقاس بـ`scheduled_for` لا بـ`ordered_at`: طلبٌ سُجّل الاثنين لتسليمه
     * الجمعة ليس متأخّرًا يوم الثلاثاء.
     */
    public function scopeOverdue($query)
    {
        return $query->awaitingPreparation()->where('scheduled_for', '<', now());
    }
}
