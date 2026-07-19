<?php

namespace App\Console\Commands;

use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use App\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * تذكير أصحاب المتاجر بمواعيد الغد المجدولة.
 * يُجدول يوميًا في routes/console.php. تشغيل يدوي: php artisan appointments:remind
 */
class AppointmentReminders extends Command
{
    protected $signature = 'appointments:remind';

    protected $description = 'إرسال تذكير بمواعيد الغد المجدولة لكل متجر';

    public function handle(): int
    {
        $sent = 0;
        $from = now()->addDay()->startOfDay();
        $to = now()->addDay()->endOfDay();

        Business::whereNotNull('email')->each(function (Business $business) use (&$sent, $from, $to) {
            $appts = Appointment::where('business_id', $business->id)
                ->whereBetween('scheduled_at', [$from, $to])
                ->whereNotIn('status', ['ملغي', 'مكتمل'])
                ->orderBy('scheduled_at')->get();

            if ($appts->isEmpty()) {
                return;
            }

            Mail::to($business->email)->send(new AppointmentReminderMail($business->name, $appts));
            Appointment::whereIn('id', $appts->pluck('id'))->update(['reminded' => true]);
            $this->line("✓ {$business->name} — {$appts->count()} موعد → {$business->email}");
            $sent++;
        });

        $this->info($sent ? "تم إرسال {$sent} تذكير." : 'لا توجد مواعيد غدًا.');

        return self::SUCCESS;
    }
}
