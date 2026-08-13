<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $guarded = [];

    public function purchaseOrders(): HasMany { return $this->hasMany(PurchaseOrder::class); }

    public function invoices(): HasMany { return $this->hasMany(SupplierInvoice::class); }

    /** ما له علينا الآن — مجموع ما لم يُسدَّد من سنداته */
    public function outstanding(): float
    {
        return round((float) $this->invoices()->sum('total') - (float) $this->invoices()->sum('paid'), 3);
    }
}
