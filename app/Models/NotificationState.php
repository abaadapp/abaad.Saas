<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ما فعله مستخدمٌ بتنبيهٍ — لا التنبيهُ نفسُه.
 *
 * التنبيهُ مشتقٌّ من البيانات الحيّة في كلّ استطلاع (`Demo::buildNotifications`).
 * وهذا الصفُّ يقول: أجّلتُه إلى كذا، أو أنجزتُه في كذا، أو فتحتُه فقرأتُه.
 * وحين يزول سببُ التنبيه تُغلق الدورةُ (`resolved_at`) ويبقى الصفُّ سجلًّا.
 */
class NotificationState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'seen_at' => 'datetime',
            'snooze_until' => 'datetime',
            'done_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** الدورةُ ما زالت مفتوحة — لم يزل سببُها ولم تُغلق */
    public function open(): bool
    {
        return $this->resolved_at === null;
    }

    /** مؤجَّلٌ الآن — والمدّةُ تُقاس عند القراءة لا بمجدول */
    public function snoozing(): bool
    {
        return $this->snooze_until !== null && $this->snooze_until->isFuture();
    }
}
