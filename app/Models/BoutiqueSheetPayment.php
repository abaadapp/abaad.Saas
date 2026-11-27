<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** سدادُ ورقةِ بوتيكٍ قرأ ما عُلّق منها — وبغير صفّه تردّ قاعدةُ البيانات سدادَ ورقةٍ فيها بنودُ فواتير. انظر `BoutiqueHolds::paying` */
class BoutiqueSheetPayment extends Model
{
    protected $guarded = [];
}
