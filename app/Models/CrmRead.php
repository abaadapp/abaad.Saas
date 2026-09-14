<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ما قرأه هذا الموظّف من محادثة هذا العميل — صفٌّ لكلّ قارئ */
class CrmRead extends Model
{
    protected $table = 'crm_reads';

    protected $guarded = [];
}
