<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** جهازٌ ينتظر الاعتماد في بوابة عملاء RIBBON — انظر `RibbonPortal\Devices` */
class CustomerPortalRequest extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash', 'phone_hash'];

    protected $casts = ['tries' => 'integer', 'expires_at' => 'datetime', 'approved_at' => 'datetime', 'closed_at' => 'datetime'];
}
