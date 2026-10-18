<?php

namespace App\Support;

use App\Models\CustomerInvoicePreparation;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\PrintJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * طابورُ الطباعة — ورقةٌ في الخادم تنتظر صندوقَها.
 *
 * ═══ لمَ طابور ═══
 *
 * إشعارُ Paymob يصل الخادم، والطابعةُ على شبكة المحلّ لا يبلغها الخادمُ
 * أبدًا (`WebsiteConfirmPrint` يقولها). ولا يستقبل تطبيقُ أبعاد شيئًا وهو
 * مغلقٌ أو في الخلفيّة: يطبع حين تطلب صفحتُه المفتوحة. فالورقةُ تُكتب هنا
 * **مهمّةً** لا تضيع، والصندوقُ المعيَّن يسألها حين تكون صفحتُه أمامه.
 *
 * ═══ ما يُضمن ═══
 *
 * - **مهمّةٌ منطقيّةٌ واحدة** لكلّ ورقة: `dedupe_key` فريدٌ في القاعدة.
 * - **صندوقٌ واحدٌ يحجزها**: الحجزُ شرطٌ في UPDATE واحد (`pending` ← `claimed`)،
 *   فصندوقان يسألان في اللحظة نفسِها يأخذها أحدُهما.
 * - **ولا تُطبع مرّتين في صمت**: حجزٌ لم يُردّ عليه في مهلته قد يكون خرج،
 *   فلا يُعاد وحده — يصير «فشل» ويُعاد بيدٍ بعد أن يُنظر في الورق.
 *   وفشلٌ يقول الصندوقُ إنّه لم يُطبع منه شيء (`may_have_printed = false`)
 *   يعود إلى الطابور، حتى `MAX_ATTEMPTS`.
 * - **ولا تضيع**: صندوقٌ مغلق، أو لا صندوقَ معيَّنًا بعد — تبقى «بانتظار
 *   الطباعة» وتُرى في لوحة التجهيز ومعها زرُّ الطباعة اليدويّة.
 */
final class PrintJobs
{
    /** مهلةُ الحجز — أطولُ من مهلة الطابعة في التطبيق (١٢٠ ث صمت) */
    public const CLAIM_SECONDS = 180;

    /** محاولاتٌ لم يُطبع منها شيء — بعدها يُنتظر إنسان */
    public const MAX_ATTEMPTS = 3;

    /**
     * ورقةُ «فاتورة عميل — نسخة داخلية» لمهمّةٍ ظهرت — مرّةً واحدة.
     *
     * والصندوقُ المعيَّن: طابعةٌ فعّالةٌ عليها «فواتير العملاء المسدَّدة»، في
     * صندوقٍ فعّالٍ من فرع المهمّة. وإن لم يكن بعدُ فالورقةُ لفرعها كلّه:
     * أيُّ صندوقٍ يُعيَّن فيه لاحقًا يستلمها.
     */
    public static function queueInvoiceInternal(CustomerInvoicePreparation $prep): PrintJob
    {
        $key = self::invoiceKey($prep);

        $existing = PrintJob::where('dedupe_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        $device = self::designatedDevices((int) $prep->business_id, $prep->branch_id ? (int) $prep->branch_id : null)->first();

        $job = PrintJob::create([
            'business_id' => (int) $prep->business_id,
            'branch_id' => $prep->branch_id,
            'pos_device_id' => $device?->id,
            'kind' => PrintJob::KIND_INVOICE_INTERNAL,
            'subject_type' => 'customer_invoice_preparation',
            'subject_id' => $prep->id,
            'dedupe_key' => $key,
            'status' => PrintJob::PENDING,
        ]);

        Activity::log('created', 'كُتبت ورقة «فاتورة عميل — نسخة داخلية» لطابعة الصندوق', [
            'subject_id' => $prep->id, 'subject_type' => 'customer_invoice_preparation', 'business_id' => (int) $prep->business_id,
        ]);

        return $job;
    }

    public static function invoiceKey(CustomerInvoicePreparation $prep): string
    {
        return 'customer-invoice-internal:'.$prep->id;
    }

    /** آخرُ ورقةٍ لمهمّة — لتُقرأ حالُها في اللوحة */
    public static function forPreparation(CustomerInvoicePreparation $prep): ?PrintJob
    {
        return PrintJob::where('business_id', $prep->business_id)
            ->where('subject_type', 'customer_invoice_preparation')
            ->where('subject_id', $prep->id)
            ->latest('id')
            ->first();
    }

    /**
     * صناديقُ الفرع التي تستلم فواتيرَ العملاء — فعّالةً، بطابعةٍ فعّالة.
     *
     * @return Collection<int, PosDevice>
     */
    public static function designatedDevices(int $businessId, ?int $branchId)
    {
        return PosDevice::where('pos_devices.business_id', $businessId)
            ->where('pos_devices.status', PosDevice::ACTIVE)
            ->when($branchId, fn ($w) => $w->where('pos_devices.branch_id', $branchId))
            ->whereHas('peripherals', fn ($p) => self::designatedPrinter($p))
            ->orderBy('pos_devices.id')
            ->get();
    }

    /** طابعةُ هذا الصندوق التي تستلم فواتيرَ العملاء — إن كانت */
    public static function printerOf(PosDevice $device): ?PosPeripheral
    {
        return self::designatedPrinter($device->peripherals()->getQuery())->orderBy('id')->first();
    }

    private static function designatedPrinter($q)
    {
        return $q->where('active', true)
            ->where('type', PosPeripheral::PRINTER)
            ->where('auto_print_customer_invoices', true);
    }

    /** أيستلم هذا الصندوق هذه الورقة؟ — متجرُها، وصندوقُها أو فرعُها */
    public static function belongsTo(PrintJob $job, PosDevice $device): bool
    {
        if ((int) $job->business_id !== (int) $device->business_id) {
            return false;
        }
        if ($job->pos_device_id !== null) {
            return (int) $job->pos_device_id === (int) $device->id;
        }

        return $job->branch_id === null || (int) $job->branch_id === (int) $device->branch_id;
    }

    /** ما ينتظر هذا الصندوق — لا يحجز شيئًا */
    public static function waitingFor(PosDevice $device): int
    {
        if (! self::printerOf($device)) {
            return 0;
        }

        return self::candidates($device)->count();
    }

    private static function candidates(PosDevice $device)
    {
        return PrintJob::where('business_id', $device->business_id)
            ->where('status', PrintJob::PENDING)
            ->where(function ($w) use ($device) {
                $w->where('pos_device_id', $device->id)
                    ->orWhere(fn ($u) => $u->whereNull('pos_device_id')->where(
                        fn ($b) => $b->whereNull('branch_id')->orWhere('branch_id', $device->branch_id),
                    ));
            });
    }

    /**
     * يحجز الصندوقُ ورقتَه التالية — أو لا شيء.
     *
     * والحجزُ شرطٌ في UPDATE واحد: `status = pending` وقت الكتابة. فصندوقان
     * يسألان معًا عن الورقة نفسِها: يكتب أحدُهما، ويجد الآخرُ الحالَ تغيّرت
     * فيمضي إلى غيرها. ولا قفلَ يُنتظر.
     */
    public static function claim(PosDevice $device): ?PrintJob
    {
        self::expireClaims((int) $device->business_id);

        if (! self::printerOf($device)) {
            return null;
        }

        foreach (self::candidates($device)->orderBy('id')->limit(10)->pluck('id') as $id) {
            $won = PrintJob::whereKey($id)
                ->where('status', PrintJob::PENDING)
                ->update([
                    'status' => PrintJob::CLAIMED,
                    'claimed_by_device_id' => $device->id,
                    'claimed_at' => now(),
                    'claim_expires_at' => now()->addSeconds(self::CLAIM_SECONDS),
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);

            if ($won === 1) {
                return PrintJob::find($id);
            }
        }

        return null;
    }

    /**
     * يقول الصندوقُ ما جرى للورقة التي حجزها.
     *
     * - خرجت ← `printed` (و`via` يقول كيف: طابعةُ الشبكة أم حوارُ المتصفّح).
     * - لم تخرج، ويقول إنّه لم يُرسل منها شيء ← تعود إلى الطابور، حتى
     *   `MAX_ATTEMPTS`؛ ثمّ `failed`.
     * - لم تخرج، وقد يكون خرج بعضُها ← `failed`: لا تُعاد وحدها.
     *
     * ولا يقول إلّا صندوقُها: حجزُ غيره لا يُكتب عليه.
     */
    public static function acknowledge(PrintJob $job, PosDevice $device, bool $ok, ?string $via, ?string $error, bool $mayHavePrinted): bool
    {
        return DB::transaction(function () use ($job, $device, $ok, $via, $error, $mayHavePrinted) {
            $locked = PrintJob::whereKey($job->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== PrintJob::CLAIMED || (int) $locked->claimed_by_device_id !== (int) $device->id) {
                return false;
            }

            $via = in_array($via, ['native', 'system', 'page', 'share', 'browser'], true) ? $via : null;

            if ($ok) {
                $locked->forceFill([
                    'status' => PrintJob::PRINTED,
                    'printed_at' => now(),
                    'via' => $via,
                    'last_error' => null,
                    'claim_expires_at' => null,
                ])->save();

                return true;
            }

            $retry = ! $mayHavePrinted && $locked->attempts < self::MAX_ATTEMPTS;

            $locked->forceFill([
                'status' => $retry ? PrintJob::PENDING : PrintJob::FAILED,
                'failed_at' => $retry ? null : now(),
                'via' => $via,
                'last_error' => mb_substr((string) ($error ?: __('لم تُطبع الورقة')), 0, 255),
                'claimed_by_device_id' => $retry ? null : $locked->claimed_by_device_id,
                'claim_expires_at' => null,
            ])->save();

            return true;
        });
    }

    /**
     * حجوزٌ لم يُردّ عليها في مهلتها — «فشل» لا عودةٌ صامتة.
     *
     * صندوقٌ حجز ورقةً ثمّ انقطع: لا يُعرف أخرجت أم لا. وإعادتُها وحدها قد
     * تطبعها مرّتين — فتُرى في اللوحة «لم يؤكَّد خروجُها» ويقرّر إنسان.
     */
    public static function expireClaims(int $businessId): int
    {
        return PrintJob::where('business_id', $businessId)
            ->where('status', PrintJob::CLAIMED)
            ->where('claim_expires_at', '<', now())
            ->update([
                'status' => PrintJob::FAILED,
                'failed_at' => now(),
                'last_error' => __('لم يؤكّد الصندوق خروج الورقة في مهلتها — تحقّق من الطابعة ثم أعد الإرسال.'),
                'claim_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * يُعيدها إلى الطابور بيدٍ مأذونة — بعد أن نظر في الورق.
     *
     * ولا تُعاد ورقةٌ يحجزها صندوقٌ الآن: قد تكون تخرج في هذه اللحظة.
     */
    public static function requeue(PrintJob $job, int $userId): bool
    {
        $done = PrintJob::whereKey($job->id)
            ->whereIn('status', [PrintJob::FAILED, PrintJob::PRINTED])
            ->update([
                'status' => PrintJob::PENDING,
                'attempts' => 0,
                'claimed_by_device_id' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
                'failed_at' => null,
                'requeued_by' => $userId,
                'updated_at' => now(),
            ]);

        return $done === 1;
    }

    /** حالُ الورقة كما تُقرأ في اللوحة — بلا وعدٍ بما لا يُعرف */
    public static function label(?PrintJob $job): ?string
    {
        if (! $job) {
            return null;
        }

        return match ($job->status) {
            PrintJob::PENDING => __('بانتظار الطباعة على الصندوق'),
            PrintJob::CLAIMED => __('يطبعها الصندوق الآن'),
            PrintJob::PRINTED => $job->via === 'native'
                ? __('طُبعت على طابعة الصندوق')
                : __('طُلب حوار الطباعة على الصندوق'),
            PrintJob::FAILED => __('لم تُطبع — أعد الإرسال إلى الصندوق أو اطبعها يدويًا'),
            default => null,
        };
    }
}
