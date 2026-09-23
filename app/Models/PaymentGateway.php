<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بوّابةُ دفعِ محلٍّ — مفاتيحُه هو، لا مفاتيحُ أبعاد.
 *
 * والسرّان مشفَّران في العمود لا مقروءَين: من نسخ قاعدة البيانات لا ينسخ
 * معهما مفتاحَ القبض. و`$hidden` فوق ذلك حتى لا يتسرّبا في `toArray` —
 * وهي الطريق التي تسلكها خصائص Inertia كلُّها إلى المتصفّح.
 *
 * وهي قاعدةُ `WhatsAppConnection` نفسُها في هذا المستودع.
 */
class PaymentGateway extends Model
{
    public const PAYMOB = 'paymob';

    protected $guarded = [];

    protected $casts = [
        'secret_key' => 'encrypted',
        'hmac_secret' => 'encrypted',
        'active' => 'boolean',
    ];

    /**
     * ما لا يخرج من هذا النموذج أبدًا.
     *
     * والمفتاحُ العامّ ليس منها: هو يُكتب في رابط صفحة الدفع ويراه الزائر،
     * وذاك موضعُه. أمّا هذان فبهما يُقبض المالُ ويُصدَّق الإشعار.
     */
    protected $hidden = ['secret_key', 'hmac_secret'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * أتكتمل هذه البوّابة فتُستعمل؟
     *
     * وأربعةٌ لا ثلاثة: بلا `hmac_secret` لا يُصدَّق إشعارٌ، فيُقبض المالُ
     * ولا يُنشأ طلب. وبوّابةٌ ناقصةٌ تُعرض للزبون أسوأُ من بوّابةٍ مُطفأة.
     */
    public function ready(): bool
    {
        return $this->active
            && filled($this->public_key)
            && filled($this->secret_key)
            && filled($this->hmac_secret)
            && filled($this->card_integration_id);
    }
}
