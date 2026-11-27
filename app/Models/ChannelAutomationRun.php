<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ‏‏رسالةٌ طابقت أتمتة — خرج ردُّها أو فشل أو مُنع، وبأيّ سبب. سطرٌ واحد لكلّ رسالة (`source_key`).
 */
class ChannelAutomationRun extends Model
{
    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const UPDATED_AT = null;

    protected $guarded = [];
}
