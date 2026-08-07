<?php

namespace App\Support;

use App\Models\ActivityLog;

/**
 * تسجيل النشاط (Audit Log) — استدعِ Activity::log(...) بعد أي عملية.
 */
class Activity
{
    private const ICONS = [
        'created' => 'plus-circle', 'updated' => 'pencil', 'deleted' => 'trash-2',
        'login' => 'log-in', 'logout' => 'log-out', 'checkout' => 'shopping-cart',
        'status' => 'refresh-cw', 'settings' => 'settings', 'hold' => 'pause-circle',
        'shift' => 'clock', 'report' => 'file-text', 'backup' => 'database-backup', 'restore' => 'database',
        'return' => 'undo-2',
    ];

    private const COLORS = [
        'created' => 'success', 'updated' => 'info', 'deleted' => 'danger',
        'login' => 'primary', 'logout' => 'gray', 'checkout' => 'success',
        'status' => 'warning', 'settings' => 'primary', 'hold' => 'warning',
        'shift' => 'info', 'report' => 'info', 'backup' => 'primary', 'restore' => 'warning',
        'return' => 'danger',
    ];

    public static function log(string $action, string $description, array $opts = []): void
    {
        $u = auth()->user();

        /*
         * من فعلها حقًّا.
         *
         * «الدخول كتاجر» كان يجعل كل عملية تُقيَّد باسم التاجر وحده، فيقول
         * السجلّ إن صاحب المحل حذف فاتورةً حذفها الدعم. الاسم يُنسخ مع
         * المعرّف: حساب موظف دعمٍ يُحذف بعد سنة، ولا يجوز أن يُمحى معه من
         * السجلّ أنّ أحدًا كان هناك.
         */
        $impersonatorId = session('impersonator_id');
        $impersonator = $impersonatorId ? \App\Models\User::find($impersonatorId) : null;

        ActivityLog::create([
            'business_id' => $opts['business_id'] ?? $u?->business_id,
            'user_id' => $u?->id,
            'user_name' => $u?->name ?? 'زائر',
            'impersonator_id' => $impersonator?->id,
            'impersonator_name' => $impersonator?->name,
            'action' => $action,
            'subject_type' => $opts['subject_type'] ?? null,
            'subject_id' => $opts['subject_id'] ?? null,
            'description' => $description,
            'icon' => $opts['icon'] ?? (self::ICONS[$action] ?? 'activity'),
            'color' => $opts['color'] ?? (self::COLORS[$action] ?? 'primary'),
            'ip' => request()->ip(),
        ]);
    }
}
