<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ‏‏رقمٌ طلب ألّا تصله عروضُ المتجر — لا تُرسل إليه حملةٌ بعدها (`App\Support\WhatsAppOptOuts`).
 */
class WhatsAppOptOut extends Model
{
    /** ‏‏ضغط زرّ «إيقاف الرسائل» أو كتبها */
    public const CUSTOMER = 'customer';

    /** ‏‏أوقف العروض من إعدادات واتساب عنده */
    public const META = 'meta';

    /** ‏‏أضافه فريقُ المتجر بطلبه */
    public const STAFF = 'staff';

    public const UPDATED_AT = null;

    protected $table = 'whatsapp_optouts';

    protected $guarded = [];
}
