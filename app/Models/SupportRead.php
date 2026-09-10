<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ما قرأه قارئٌ واحد من محادثة.
 *
 * صفٌّ لكلّ قارئٍ لا عمودٌ على المحادثة: الدعمُ أكثرُ من واحد، ومقروءٌ
 * واحدٌ يعني أنّ فتحَ زميلٍ للمحادثة يُطفئ الشارةَ عن الجميع فتُنسى.
 */
class SupportRead extends Model
{
    protected $guarded = [];

    protected $casts = ['last_read_message_id' => 'integer'];
}
